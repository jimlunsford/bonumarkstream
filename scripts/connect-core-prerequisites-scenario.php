<?php
/** Direct core regressions, using the disposable API smoke database. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

function bms_api_smoke_connect_core_prerequisites(): void
{
    $assert = static function (bool $ok, string $message): void {
        if (!$ok) { throw new RuntimeException($message); }
    };
    $throws = static function (callable $work, string $class) use ($assert): Throwable {
        try { $work(); } catch (Throwable $e) {
            $assert($e instanceof $class, 'Wrong failure class: ' . get_class($e));
            return $e;
        }
        throw new RuntimeException('Expected failure: ' . $class);
    };
    // Poison transport globals: direct services must use only explicit arguments.
    $oldGet = $_GET; $oldPost = $_POST;
    $_GET = ['id' => 'invalid', 'status' => 'draft', 'page' => -20];
    $_POST = ['stream_slug' => 'not-the-command-slug', 'collision_policy' => 'allocate_retry'];
    $pdo = bms_db();
    try {
        $empty = bms_stream_query_published(['page' => 9]);
        $assert($empty['rows'] === [] && $empty['pagination'] === [
            'page' => 9, 'per_page' => 50, 'returned' => 0, 'total' => 0, 'total_pages' => 1,
        ], 'Empty published catalog behavior changed.');
        $intent = static fn(string $slug, string $status): array => [
            'title' => 'Prepared ' . $slug, 'slug' => $slug, 'status' => $status,
            'content_type' => 'stream', 'date' => '2026-10-04',
            'stream_created_at' => '2026-10-04 12:00:00', 'scheduled_at' => '2099-01-01 12:00:00',
            'category' => 'Core category', 'tags' => ['Core tag'],
            'location_name' => 'Private exact venue', 'location_area' => 'Private area',
            'location_locality' => 'Public city', 'location_region' => 'Public region',
            'location_place_id' => '9876', 'location_display_mode' => 'city',
        ];
        $created = [];
        foreach (['reject_prepared', 'allocate_retry'] as $policy) {
            foreach (['draft', 'scheduled', 'published'] as $status) {
                $slug = str_replace('_', '-', $policy) . '-' . $status;
                [$page, $id] = bms_stream_create_prepared($intent($slug, $status), 'Prepared body', 1, $policy);
                $row = $pdo->query('SELECT * FROM ' . bms_table('posts') . ' WHERE id=' . $id)->fetch();
                $assert($id > 0 && $row['slug'] === $page['slug'] && $row['slug'] === $slug
                    && $row['author_id'] == 1 && $row['status'] === $status
                    && trim($row['content_body']) === 'Prepared body', 'Created page/identity/author/status mismatch.');
                $terms = bms_api_stream_post_terms($id);
                $assert($terms['category']['name'] === 'Stream' && $terms['tags'][0]['name'] === 'Core tag', 'Creation lost terms.');
                $created[$policy][$status] = $id;
                $assert((bms_stream_find_published($id) !== null) === ($status === 'published'), 'Single lookup crossed status boundary.');
            }
        }
        $throws(static fn() => bms_stream_create_prepared($intent('reject-prepared-draft', 'published'), 'Must reject', 1, 'reject_prepared'), RuntimeException::class);
        [$suffix, $suffixId] = bms_stream_create_prepared($intent('reject-prepared-draft', 'published'), 'Independent', 1, 'allocate_retry');
        $assert($suffix['slug'] === 'reject-prepared-draft-2' && $suffixId !== $created['reject_prepared']['draft'], 'Remote policy adopted a cross-status identity.');
        foreach (['reject_prepared', 'allocate_retry'] as $policy) {
            $fields = array_replace($intent('', 'draft'), ['title' => 'Generated ' . $policy]);
            [$first, $firstId] = bms_stream_create_prepared($fields, 'Generated ' . $policy, 1, $policy);
            [$second, $secondId] = bms_stream_create_prepared($fields, 'Generated ' . $policy, 1, $policy);
            $assert($firstId !== $secondId && $second['slug'] === $first['slug'] . '-2', 'Generated slug allocation changed.');
            $pdo->beginTransaction();
            try {
                [, $rolledBackId] = bms_stream_create_prepared($intent('rollback-' . $policy, 'published'), 'Rollback', 1, $policy);
                $assert($pdo->inTransaction(), 'Command committed the outer transaction.');
            } finally { $pdo->rollBack(); }
            $assert(bms_stream_find_published($rolledBackId) === null, 'Command escaped rollback.');
        }
        $throws(static fn() => bms_stream_create_prepared($intent('oversize', 'draft'), str_repeat('x', 2097153), 1, 'allocate_retry'), BMS_Stream_Document_Too_Large::class);
        $mapped = $throws(static fn() => bms_api_insert_remote_stream_post($intent('oversize', 'draft'), str_repeat('x', 2097153), 1), BMS_Api_Exception::class);
        $assert($mapped->statusCode === 413 && $mapped->apiCode === 'post_too_large' && $mapped->getMessage() === 'Remote post is too large.', 'Size error wire mapping changed.');

        // Exclude Page and Trash, even with valid positive IDs.
        $pageFields = array_replace($intent('core-page', 'published'), ['content_type' => 'page']);
        $pageId = bms_insert_database_content(bms_parse_markdown_string(bms_build_markdown_document($pageFields, 'Page')), 'pages', 'core-page.md', 1);
        $assert(bms_stream_find_published($pageId) === null, 'Published Page leaked into Stream query.');
        $trashId = $created['reject_prepared']['draft'];
        $pdo->exec("UPDATE " . bms_table('posts') . " SET status='trash' WHERE id=" . $trashId);
        $assert(bms_stream_find_published($trashId) === null, 'Trash leaked into Stream query.');
        $expected = [$created['reject_prepared']['published'], $created['allocate_retry']['published'], $suffixId];
        $pdo->exec("UPDATE " . bms_table('posts') . " SET created_at='2026-10-04 12:00:00', updated_at='2026-10-04 12:00:00', published_at='2026-10-04 12:00:00' WHERE status='published' AND post_type='stream'");
        foreach (['id', 'created_at', 'updated_at', 'published_at'] as $column) {
            foreach (['asc', 'desc'] as $direction) {
                $query = bms_stream_query_published(['orderby' => $column, 'order' => $direction]);
                $assert(array_map('intval', array_column($query['rows'], 'id')) === ($direction === 'asc' ? $expected : array_reverse($expected)), 'Published sort or ID tie-break changed.');
            }
        }
        $paged = bms_stream_query_published(['page' => 2, 'per_page' => 2]);
        $assert(array_map('intval', array_column($paged['rows'], 'id')) === [$suffixId]
            && $paged['pagination'] === ['page' => 2, 'per_page' => 2, 'returned' => 1, 'total' => 3, 'total_pages' => 2], 'Pagination changed.');
        $assert(bms_stream_query_published(['modified_after' => '2026-10-04 12:00:00'])['rows'] === [], 'modified_after lost strict greater-than semantics.');
        $assert(count(bms_stream_query_published(['modified_after' => '2026-10-04 11:59:59'])['rows']) === 3, 'modified_after excluded newer rows.');
        $throws(static fn() => bms_stream_query_published(['page' => 3, 'per_page' => 2]), BMS_Stream_Page_Out_Of_Range::class);
        $throws(static fn() => bms_stream_query_published(['orderby' => 'id; DELETE FROM posts']), InvalidArgumentException::class);
        $throws(static fn() => bms_stream_find_published(0), InvalidArgumentException::class);
        $_GET = ['page' => '3', 'per_page' => '2'];
        $mapped = $throws(static fn() => bms_api_read_stream_posts(), BMS_Api_Exception::class);
        $assert($mapped->apiCode === 'page_out_of_range' && $mapped->statusCode === 400, 'Out-of-range API mapping changed.');
        $_GET = [];
        $wire = bms_api_read_stream_posts();
        $assert($wire['pagination'] === bms_stream_query_published()['pagination'] && array_column($wire['posts'], 'id') === $expected, 'Adapter projection lost query identities or metadata.');

        $assert($wire['posts'][0]['metadata']['location'] === [
            'mode' => 'city', 'primary' => 'Public city', 'secondary' => 'Public region', 'category' => 'other',
        ] && !str_contains(json_encode($wire), 'Private exact venue')
            && !str_contains(json_encode($wire), 'Private area')
            && !str_contains(json_encode($wire), 'location_place_id'), 'Published projection exposed private location details.');
        bms_core_published_query_http($assert, $expected[0], $trashId);
        bms_core_media_query_regression($assert, $throws);
        // Rename only the disposable fixture table to distinguish unavailable from empty.
        $table = bms_table('posts');
        $pdo->exec('RENAME TABLE ' . $table . ' TO ' . $table . '_unavailable');
        try {
            $throws(static fn() => bms_stream_query_published(), PDOException::class);
            $throws(static fn() => bms_stream_find_published(1), PDOException::class);
            $throws(static fn() => bms_api_read_stream_posts(), PDOException::class);
        } finally { $pdo->exec('RENAME TABLE ' . $table . '_unavailable TO ' . $table); }
    } finally { $_GET = $oldGet; $_POST = $oldPost; }
}

function bms_core_media_query_regression(callable $assert, callable $throws): void
{
    $pdo = bms_db();
    $assert(!$pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES), 'Strict media regression requires native prepares.');
    $assert(bms_media_query() === [] && bms_media_list() === [], 'Empty media query failed.');
    $insert = $pdo->prepare('INSERT INTO ' . bms_table('media') . " (filename, original_filename, public_path, mime_type, file_size, alt_text, caption, uploaded_by, created_at, updated_at, trashed_at) VALUES (?, ?, ?, 'image/png', 4, ?, ?, 1, '2026-10-04 12:00:00', ?, ?)");
    $ids = [];
    for ($n = 0; $n < 505; $n++) {
        $insert->execute(['core-' . $n . '.png', 'original-' . $n, 'media/core-' . $n . '.png', 'alt-' . $n, 'caption-' . $n,
            $n === 503 ? '2026-10-04 13:00:00' : '2026-10-04 12:00:00', $n >= 502 ? '2026-10-04 12:00:00' : null]);
        $ids[] = (int)$pdo->lastInsertId();
    }
    $snapshot = $pdo->query('SELECT * FROM ' . bms_table('media') . ' ORDER BY id')->fetchAll();
    foreach ([0 => 1, 100 => 100, 160 => 160, 200 => 200, 500 => 500, 900 => 500] as $limit => $count) {
        $strict = bms_media_query($limit);
        $assert(count($strict) === $count && $strict === bms_media_list($limit)
            && array_map('intval', array_column($strict, 'id')) === array_slice(array_reverse(array_slice($ids, 0, 502)), 0, $count), 'Media limit/order/wrapper parity failed.');
    }
    $assert(array_map('intval', array_column(bms_media_query(100, '', 'trash'), 'id')) === [$ids[503], $ids[504], $ids[502]], 'Trash tie ordering changed.');
    $assert(array_map('intval', array_column(bms_media_query(3, '', 'all'), 'id')) === [$ids[504], $ids[503], $ids[502]], 'All-media order changed.');
    foreach (['core-501.png', 'original-501', 'alt-501', 'caption-501'] as $search) {
        $strict = bms_media_query(100, ' ' . $search . ' ');
        $assert(count($strict) === 1 && (int)$strict[0]['id'] === $ids[501] && $strict === bms_media_list(100, $search), 'Media search identity changed.');
    }
    $assert(bms_media_query(100, 'core-50%.png') === bms_media_list(100, 'core-50%.png')
        && count(bms_media_query(100, 'core-50%.png')) === 3, 'Strict percent wildcard changed.');
    $assert(count(bms_media_query(100, 'core-5_.png')) === 10, 'Strict underscore wildcard changed.');
    $assert(bms_media_query(100, '', ' TRASH ') === bms_media_query(100, '', 'trash'), 'Strict status trimming changed.');
    $assert(bms_media_query(100, '', 'invalid') === bms_media_list(100, '', 'active'), 'Media status normalization changed.');
    $assert($snapshot === $pdo->query('SELECT * FROM ' . bms_table('media') . ' ORDER BY id')->fetchAll(), 'Media query wrote data.');
    $table = bms_table('media');
    $pdo->exec('RENAME TABLE ' . $table . ' TO ' . $table . '_unavailable');
    try {
        $throws(static fn() => bms_media_query(), PDOException::class);
        $assert(bms_media_list() === [], 'Media compatibility fallback changed.');
    } finally { $pdo->exec('RENAME TABLE ' . $table . '_unavailable TO ' . $table); }
    $assert(bms_media_query(100, 'core-501.png') === bms_media_list(100, 'core-501.png')
        && count(bms_media_query(100, 'core-501.png')) === 1, 'Strict query did not recover.');
    $assert($snapshot === $pdo->query('SELECT * FROM ' . $table . ' ORDER BY id')->fetchAll(), 'Media failure/recovery wrote data.');
}

/** Verify the unchanged HTTP envelope and sanitized failure boundary. */
function bms_core_published_query_http(callable $assert, int $publishedId, int $privateId): void
{
    $root = (string)$GLOBALS['bms_api_smoke_temp_root'];
    $token = bms_api_create_token('Core read parity', ['stream:read'], null, 1);
    $port = random_int(47000, 47999);
    $log = $root . '/core-query-http.log';
    $proc = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-S', '127.0.0.1:' . $port, '-t', $root],
        [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $root, array_merge($_ENV, getenv()));
    $assert(is_resource($proc), 'Core read HTTP server did not start.');
    fclose($pipes[0]);
    $url = 'http://127.0.0.1:' . $port . '/api/v1/stream/posts.php';
    $request = static fn(array $query = [], string $method = 'GET'): array => bms_api_smoke_http_request(
        $url . '?' . http_build_query($query), $method, ['Authorization: Bearer ' . $token['plain_token']]);
    $oldGet = $_GET;
    try {
        $ready = false;
        for ($i = 0; $i < 50; $i++) {
            try { $request(); $ready = true; break; }
            catch (Throwable $e) { usleep(100000); }
        }
        $assert($ready, 'Core read HTTP server unavailable.');
        foreach ([[], ['include_html' => '1'], ['page' => '2', 'per_page' => '2'],
            ['per_page' => '999'], ['per_page' => '0'], ['id' => (string)$publishedId],
            ['modified_after' => '2099-01-01T00:00:00Z']] as $query) {
            $_GET = $query;
            $expected = bms_api_read_stream_posts();
            unset($expected['single']);
            $response = $request($query);
            $decoded = json_decode($response['body'], true);
            $assert($response['status'] === 200 && $decoded === ['ok' => true] + $expected, 'Read HTTP result shape/projection differs from adapter.');
            $assert(str_starts_with($response['headers']['content-type'] ?? '', 'application/json'), 'Read content type changed.');
            if (isset($expected['pagination'])) {
                $assert((int)$response['headers']['x-bonumark-total'] === $expected['pagination']['total']
                    && (int)$response['headers']['x-bonumark-total-pages'] === $expected['pagination']['total_pages'], 'Read pagination headers changed.');
            }
        }
        $head = $request([], 'HEAD');
        $assert($head['status'] === 200 && $head['body'] === '' && (int)$head['headers']['x-bonumark-total'] === 3, 'HEAD read changed.');
        foreach ([[['id' => (string)$privateId], 404, 'stream_post_not_found'],
            [['page' => '99'], 400, 'page_out_of_range'], [['status' => 'draft'], 422, 'invalid_status']] as [$query, $status, $code]) {
            $response = $request($query);
            $assert($response['status'] === $status && (json_decode($response['body'], true)['error']['code'] ?? '') === $code, 'Read error mapping changed: ' . $code);
        }
        $table = bms_table('posts');
        bms_db()->exec('RENAME TABLE ' . $table . ' TO ' . $table . '_unavailable');
        try {
            $response = $request();
            $assert($response['status'] === 500 && json_decode($response['body'], true) === [
                'ok' => false, 'error' => ['code' => 'server_error', 'message' => 'The API request could not be completed.'],
            ], 'Database failure became success or leaked internal details.');
        } finally { bms_db()->exec('RENAME TABLE ' . $table . '_unavailable TO ' . $table); }
        $assert($request()['status'] === 200, 'Read endpoint failed to recover.');
    } finally {
        $_GET = $oldGet;
        proc_terminate($proc);
        proc_close($proc);
        @unlink($log);
    }
}

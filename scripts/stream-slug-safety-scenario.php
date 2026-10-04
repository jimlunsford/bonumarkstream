<?php
/** Deterministic real-database creation, transaction, HTTP and scheduler regression. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

function bms_api_smoke_stream_slug_safety(): void
{
    $pdo = bms_db();
    $assert = static function (bool $ok, string $message): void {
        if (!$ok) { throw new RuntimeException($message); }
    };
    $row = static function (int $id) use ($pdo): array {
        $stmt = $pdo->prepare('SELECT * FROM ' . bms_table('posts') . ' WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: [];
    };
    $state = static function (int $id) use ($pdo, $row): array {
        $result = ['post' => $row($id)];
        foreach (['post_terms', 'activitypub_local_objects', 'activitypub_publication_events'] as $table) {
            $s = $pdo->prepare('SELECT * FROM ' . bms_table($table) . ' WHERE post_id = ?');
            $s->execute([$id]);
            $result[$table] = $s->fetchAll();
        }
        return $result;
    };
    $section = static fn(string $s): string => $s === 'draft' ? 'drafts' : $s;
    $intent = static fn(string $slug, string $status): array => [
        'title' => 'Collision ' . $slug, 'slug' => $slug, 'status' => $status,
        'content_type' => 'stream', 'date' => '2026-10-04', 'category' => 'Stream',
        'tags' => ['Collision'], 'scheduled_at' => '2099-01-01 12:00:00',
        'stream_created_at' => '2026-10-04 12:00:00',
    ];
    $page = static fn(array $fields, string $body): array => bms_parse_markdown_string(bms_build_markdown_document($fields, $body));
    $conflict = static function (callable $work) use ($assert): void {
        try { $work(); } catch (BMS_Content_Slug_Conflict $e) { return; }
        $assert(false, 'Expected a safe slug conflict.');
    };
    $data = bms_api_create_token('Creation identity regressions', ['stream:draft', 'stream:publish', 'media:upload'], null, 1);
    $token = $data['token'];
    $tokenId = (int)$token['id'];
    foreach ([['draft', 'draft'], ['scheduled', 'scheduled'], ['published', 'published'],
        ['draft', 'scheduled'], ['draft', 'published'], ['scheduled', 'published']] as [$aStatus, $bStatus]) {
        $slug = 'race-' . $aStatus . '-' . $bStatus;
        $fieldsA = $intent(bms_stream_unique_slug($slug), $aStatus);
        $fieldsB = $intent(bms_stream_unique_slug($slug), $bStatus);
        $a = $page($fieldsA, 'A must remain unchanged.');
        $b = $page($fieldsB, 'B is a separate logical post.');
        $assert($a['slug'] === $b['slug'], 'The fixture did not prepare the same free slug.');
        $ownerA = null; $ownerB = null;
        $hashA = hash('sha256', $slug . '-a'); $hashB = hash('sha256', $slug . '-b');
        bms_api_idempotency_begin($tokenId, $slug . '-a', $hashA, $ownerA);
        bms_api_idempotency_begin($tokenId, $slug . '-b', $hashB, $ownerB);
        $assert($ownerA > 0 && $ownerB > 0 && $ownerA !== $ownerB, 'Independent reservations collapsed.');
        $idA = bms_insert_database_content($a, $section($aStatus), $slug . '.md', 1);
        $before = $state($idA);
        $conflict(static fn() => bms_insert_database_content($b, $section($bStatus), $slug . '.md', 1));
        $assert($state($idA) === $before, 'Losing prepared create changed A or its terms/federation.');
        [$savedB, $idB] = bms_api_insert_remote_stream_post($fieldsB, $b['body'], 1);
        $assert($idB !== $idA && $savedB['slug'] === $slug . '-2', 'Retry did not create its own suffixed identity.');
        $assert($state($idA) === $before, 'B changed A after successful suffixing.');
        $stored = $row($idB);
        $front = json_decode($stored['content_front_matter'], true);
        $assert($stored['slug'] === $savedB['slug'] && $front['slug'] === $savedB['slug']
            && $stored['markdown_path'] === 'content/' . $section($bStatus) . '/' . $savedB['slug'] . '.md'
            && $stored['author_id'] == 1 && $stored['content_body'] === $b['body'], 'Slug representation or author/body disagreed.');
        if ($bStatus === 'scheduled') {
            $assert($stored['scheduled_at'] === '2099-01-01 12:00:00', 'Scheduled timestamp changed.');
        }
        if ($bStatus === 'published') {
            $events = $state($idB)['activitypub_publication_events'];
            $payload = json_decode($events[0]['payload_json'] ?? '{}', true);
            $assert(count($events) === 1 && $payload['type'] === 'Create'
                && $payload['object']['id'] === bms_activitypub_generation_object_url($idB, 1)
                && $payload['object']['url'] === bms_site_url(bms_stream_relative_directory_for_post($savedB) . '/'), 'B did not own its independent ActivityPub Create/permalink.');
        }
        foreach ([['a', $hashA, $ownerA, $idA], ['b', $hashB, $ownerB, $idB]] as [$suffix, $hash, $owner, $id]) {
            $response = ['ok' => true, 'post_id' => $id];
            bms_api_idempotency_store($tokenId, $slug . '-' . $suffix, $hash, $response, 201, $owner);
            $observer = null;
            $assert(bms_api_idempotency_begin($tokenId, $slug . '-' . $suffix, $hash, $observer) === ['status' => 201, 'payload' => $response]
                && $observer === null, 'Retry reservation lost its own outcome.');
        }
        foreach (['id', 'post_id'] as $identity) {
            try { bms_insert_database_content($b + [$identity => $idA], $section($bStatus), $slug . '.md', 1); }
            catch (InvalidArgumentException $e) { continue; }
            throw new RuntimeException('INSERT accepted an existing identity.');
        }
        // Existing mutation may never suffix itself or take another live/alias slug.
        $other = bms_database_row_to_content_page($stored);
        $other['slug'] = $slug;
        $otherBefore = $state($idB);
        $conflict(static fn() => bms_upsert_database_content($other, $section($bStatus), $slug . '.md', 1));
        $assert($state($idB) === $otherBefore && $state($idA) === $before, 'Rejected rename partially changed a post.');
    }

    // This is the old race boundary: both no-ID pages were prepared before A.
    // Synchronization still intentionally updates; a creator must never call it.
    foreach (['draft', 'scheduled', 'published'] as $status) {
        $legacySlug = 'legacy-sync-' . $status;
        $fields = $intent($legacySlug, $status);
        $a = $page($fields, 'Old sync content'); $b = $page($fields, 'Resynchronized content');
        $id = bms_upsert_database_content($a, $section($status), $legacySlug . '.md', 1);
        $assert(bms_upsert_database_content($b, $section($status), $legacySlug . '.md', 1) === $id
            && trim($row($id)['content_body']) === 'Resynchronized content', 'Legacy race/synchronization boundary changed.');
        if ($status === 'published') {
            $types = array_map(static fn(array $event): string => json_decode($event['payload_json'], true)['type'], $state($id)['activitypub_publication_events']);
            $assert($types === ['Create', 'Update'], 'Legacy published overwrite reproduction did not record Update.');
        }
    }

    // Classify actual driver 1062 on the exact posts key, and no other integrity error.
    bms_with_stream_slug_lock(static function () use ($fields, $page, $assert): void {
        try { bms_insert_database_content_record(bms_database_content_record_from_page($page($fields, 'duplicate'), 'published', 'legacy-sync-published.md', 1)); }
        catch (BMS_Content_Slug_Conflict $e) { return; }
        $assert(false, 'Actual posts INSERT duplicate was not classified.');
    });
    foreach ([['23000', 1452, 'foreign key'], ['23000', 1062, "Duplicate entry for key 'PRIMARY'"],
        ['HY000', 1205, 'lock timeout'], ['23000', 1062, "Duplicate entry for key 'type_slug'"]] as $errorInfo) {
        $e = new PDOException('private diagnostic'); $e->errorInfo = $errorInfo;
        $assert(!bms_is_posts_slug_duplicate($e), 'Unrelated database error classified as slug collision.');
    }
    // Existing aliases reserve their slug; returning to one's own alias is valid.
    $published = bms_api_create_remote_stream_post(['content' => 'Alias owner', 'slug' => 'alias-owner', 'confirm_publish' => true], $token, 'published');
    $renamed = bms_database_row_to_content_page($row($published['post_id']));
    $renamed['slug'] = 'alias-owner-renamed';
    bms_upsert_database_content($renamed, 'published', 'alias-owner-renamed.md', 1);
    $aliasNew = bms_api_create_remote_stream_post(['content' => 'New alias intent', 'slug' => 'alias-owner'], $token, 'draft');
    $assert($aliasNew['slug'] === 'alias-owner-2', 'Create stole a reserved permalink alias.');
    $renamed['slug'] = 'alias-owner';
    bms_upsert_database_content($renamed, 'published', 'alias-owner.md', 1);

    // A pre-existing corrupt pair is a fixture, not data automatically repaired by this patch.
    $winner = bms_api_create_remote_stream_post(['content' => 'Published winner', 'slug' => 'scheduler-winner', 'confirm_publish' => true], $token, 'published');
    $blocked = bms_api_create_remote_stream_post(['content' => 'Conflicting scheduled post', 'slug' => 'scheduler-blocked', 'scheduled_at' => '2099-01-01T12:00'], $token, 'scheduled');
    $healthy = bms_api_create_remote_stream_post(['content' => 'Independent due post', 'slug' => 'scheduler-healthy', 'scheduled_at' => '2099-01-01T12:00'], $token, 'scheduled');
    $pdo->exec("UPDATE " . bms_table('posts') . " SET slug='scheduler-winner', scheduled_at='2020-01-01 00:00:00' WHERE id=" . $blocked['post_id']);
    $pdo->exec("UPDATE " . bms_table('posts') . " SET scheduled_at='2020-01-02 00:00:00' WHERE id=" . $healthy['post_id']);
    $winnerBefore = $state($winner['post_id']); $blockedBefore = $state($blocked['post_id']);
    $blockedPage = bms_database_row_to_content_page($row($blocked['post_id']));
    $conflict(static fn() => bms_schedule_post_page($blockedPage, 'scheduled', 'scheduler-winner.md', 1, '2099-02-01 00:00:00'));
    $conflict(static fn() => bms_upsert_database_content($blockedPage, 'drafts', 'scheduler-winner.md', 1));
    $outcomes = [];
    $assert(bms_publish_due_scheduled_posts(50, $outcomes) === 1
        && $outcomes[$blocked['post_id']] === 'slug_conflict' && $outcomes[$healthy['post_id']] === 'published', 'Scheduler did not isolate the conflicting post.');
    $assert($state($winner['post_id']) === $winnerBefore && $state($blocked['post_id']) === $blockedBefore, 'Scheduler conflict changed winner or blocked post.');
    $assert(count($state($healthy['post_id'])['activitypub_publication_events']) === 1, 'Healthy scheduled post did not publish once.');
    $diagnostics = bms_run_registered_scheduled_tasks('public_traffic', ['scheduled_post_limit' => 50]);
    $assert(!$diagnostics['scheduled_posts']['ok'] && $diagnostics['scheduled_posts']['status'] === 'partial_failure'
        && str_contains($diagnostics['scheduled_posts']['message'], '1 failed'), 'Scheduler diagnostics hid the conflict.');
    // An unrelated per-row database failure also cannot abort the next due post.
    $bad = bms_api_create_remote_stream_post(['content' => 'SQL failure', 'slug' => 'scheduler-sql-failure', 'scheduled_at' => '2099-01-01T12:00'], $token, 'scheduled');
    $next = bms_api_create_remote_stream_post(['content' => 'After SQL failure', 'slug' => 'scheduler-after-failure', 'scheduled_at' => '2099-01-01T12:00'], $token, 'scheduled');
    $pdo->exec("UPDATE " . bms_table('posts') . " SET scheduled_at='2020-01-03 00:00:00' WHERE id IN (" . $bad['post_id'] . ',' . $next['post_id'] . ')');
    $badBefore = $state($bad['post_id']);
    $pdo->exec('ALTER TABLE ' . bms_table('posts') . " ADD CONSTRAINT slug_scheduler_failure CHECK (slug <> 'scheduler-sql-failure' OR status <> 'published')");
    try {
        $assert(bms_publish_due_scheduled_posts(50, $outcomes) === 1 && $outcomes[$bad['post_id']] === 'failed'
            && $outcomes[$next['post_id']] === 'published' && $state($bad['post_id']) === $badBefore,
            'A per-post SQL failure aborted another post or partially committed.');
    } finally { $pdo->exec('ALTER TABLE ' . bms_table('posts') . ' DROP CONSTRAINT slug_scheduler_failure'); }
    $pdo->exec("UPDATE " . bms_table('posts') . " SET scheduled_at='2099-01-01 12:00:00' WHERE id=" . $bad['post_id']);

    // Remove only the deliberately injected duplicate; further tests require a valid namespace.
    $pdo->exec("UPDATE " . bms_table('posts') . " SET slug='scheduler-blocked', scheduled_at='2099-01-01 12:00:00' WHERE id=" . $blocked['post_id']);

    // An existing restore must preserve its Trash state and history on a cross-status conflict.
    $restore = bms_api_create_remote_stream_post(['content' => 'Restore fixture', 'slug' => 'restore-safety'], $token, 'draft');
    bms_delete_content_file('draft', $restore['filename']);
    $trashId = (int)$pdo->query('SELECT id FROM ' . bms_table('trash') . ' WHERE post_id=' . $restore['post_id'])->fetchColumn();
    $competitor = bms_api_create_remote_stream_post(['content' => 'Restore competitor', 'slug' => 'restore-competitor', 'confirm_publish' => true], $token, 'published');
    $pdo->exec("UPDATE " . bms_table('posts') . " SET slug='restore-safety' WHERE id=" . $competitor['post_id']);
    $restoreBefore = $state($restore['post_id']);
    $conflict(static fn() => bms_restore_trash_item($trashId));
    $assert($state($restore['post_id']) === $restoreBefore && bms_get_trash_item($trashId) !== null, 'Conflicting restore partially committed.');
    $pdo->exec("UPDATE " . bms_table('posts') . " SET slug='restore-competitor' WHERE id=" . $competitor['post_id']);

    bms_slug_safety_retry_budget($assert, $intent, $token);
    bms_slug_safety_transactions($assert, $token);
    bms_slug_safety_http($assert, $row, $state, $data);
}

function bms_slug_safety_transactions(callable $assert, array $token): void
{
    $pdo = bms_db();
    $db = bms_db_config();
    $second = new PDO('mysql:host=' . $db['host'] . ';dbname=' . $db['name'] . ';charset=utf8mb4', $db['user'], $db['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $second->exec('SET SESSION innodb_lock_wait_timeout=1');
    $pdo->beginTransaction();
    try {
        $created = bms_api_create_remote_stream_post(['content' => 'Must roll back', 'slug' => 'outer-rollback', 'confirm_publish' => true], $token, 'published');
        $assert($pdo->inTransaction(), 'Nested creation committed the outer transaction.');
        $second->beginTransaction();
        try {
            $second->query("SELECT setting_key FROM " . bms_table('settings') . " WHERE setting_key='site_name' FOR UPDATE");
            throw new RuntimeException('Slug lock was released before the caller committed.');
        } catch (PDOException $e) {
            $assert((int)($e->errorInfo[1] ?? 0) === 1205, 'Unexpected lock proof failure.');
        } finally { $second->rollBack(); }
    } finally { $pdo->rollBack(); }
    foreach (['posts' => 'id', 'post_terms' => 'post_id', 'activitypub_local_objects' => 'post_id', 'activitypub_publication_events' => 'post_id'] as $table => $column) {
        $assert((int)$pdo->query('SELECT COUNT(*) FROM ' . bms_table($table) . ' WHERE ' . $column . '=' . $created['post_id'])->fetchColumn() === 0, 'Outer rollback left content/publication state.');
    }
    $second->beginTransaction();
    $second->query("SELECT setting_key FROM " . bms_table('settings') . " WHERE setting_key='site_name' FOR UPDATE");
    $second->rollBack();

    // Failure to acquire the sentinel must fail closed, without an INSERT.
    $pdo->beginTransaction();
    $pdo->exec("DELETE FROM " . bms_table('settings') . " WHERE setting_key='site_name'");
    try {
        try {
            bms_api_create_remote_stream_post(['content' => 'Unavailable lock', 'slug' => 'missing-lock'], $token, 'draft');
            throw new LogicException('Missing sentinel was accepted.');
        } catch (RuntimeException $e) {
            $assert($e->getMessage() === 'Stream slug coordination is unavailable.', 'Missing lock did not fail closed.');
        }
    } finally { $pdo->rollBack(); }

    // Child holds an older REPEATABLE READ snapshot BEFORE A commits. Pipe barriers
    // model the ordering deterministically; no race sleeps or privileged process views.
    $root = (string)$GLOBALS['bms_api_smoke_temp_root'];
    $worker = $root . '/slug-snapshot-worker.php';
    file_put_contents($worker, <<<'WORKER'
<?php
require __DIR__ . '/_bonumark_stream/app/api.php';
$pdo = bms_db();
$pdo->beginTransaction();
$pdo->query('SELECT COUNT(*) FROM ' . bms_table('posts'))->fetchColumn();
echo "READY\n"; fflush(STDOUT);
$input = json_decode((string)fgets(STDIN), true);
try {
    [$page, $id] = bms_api_insert_remote_stream_post($input['intent'], 'Snapshot B', 1);
    if (!$pdo->inTransaction()) { throw new RuntimeException('Outer snapshot transaction was lost.'); }
    $pdo->commit();
    echo json_encode(['id' => $id, 'slug' => $page['slug']]) . "\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    throw $e;
}
WORKER);
    $proc = proc_open([PHP_BINARY, $worker], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, array_merge($_ENV, getenv()));
    $assert(is_resource($proc), 'Snapshot worker failed to start.');
    stream_set_timeout($pipes[1], 15);
    try {
        $assert(trim((string)fgets($pipes[1])) === 'READY', 'Snapshot worker did not reach its barrier.');
        $intent = ['title' => 'Snapshot race', 'slug' => 'snapshot-race', 'status' => 'scheduled', 'date' => '2026-10-04',
            'category' => 'Snapshot category', 'tags' => ['Snapshot tag'], 'scheduled_at' => '2099-01-01 12:00:00'];
        [$a, $idA] = bms_api_insert_remote_stream_post(array_replace($intent, ['status' => 'draft']), 'Snapshot A', 1);
        fwrite($pipes[0], json_encode(['intent' => $intent]) . "\n"); fflush($pipes[0]); fclose($pipes[0]);
        $b = json_decode((string)fgets($pipes[1]), true);
        fclose($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $exit = proc_close($proc); $proc = null;
        $assert($exit === 0 && ($b['id'] ?? $idA) !== $idA && ($b['slug'] ?? '') === 'snapshot-race-2', 'Old snapshot bypassed current slug/term reads: ' . $error);
    } finally {
        if (is_resource($proc)) { proc_terminate($proc); proc_close($proc); }
        @unlink($worker);
    }
}

function bms_slug_safety_http(callable $assert, callable $row, callable $state, array $tokenData): void
{
    $root = (string)$GLOBALS['bms_api_smoke_temp_root'];
    bms_api_smoke_set_setting('remote_posting_rate_limit_per_minute', '600');
    $port = random_int(46100, 46999);
    $log = $root . '/slug-http.log';
    $proc = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-S', '127.0.0.1:' . $port, '-t', $root],
        [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $root, array_merge($_ENV, getenv()));
    $assert(is_resource($proc), 'Slug HTTP server did not start.'); fclose($pipes[0]);
    $url = 'http://127.0.0.1:' . $port . '/api/v1/stream/posts.php';
    $request = static function (array $payload, string $key) use ($url, $tokenData): array {
        return bms_api_smoke_http_request($url, 'POST', ['Authorization: Bearer ' . $tokenData['plain_token'],
            'Content-Type: application/json', 'Idempotency-Key: ' . $key], (string)json_encode($payload));
    };
    $columnAdded = false;
    try {
        $ready = false;
        for ($i = 0; $i < 50; $i++) {
            try { bms_api_smoke_http_request('http://127.0.0.1:' . $port . '/api/v1/status.php'); $ready = true; break; }
            catch (Throwable $e) { usleep(100000); } // Startup only, not race synchronization.
        }
        $assert($ready, 'Slug HTTP server was unavailable.');
        foreach (['draft', 'scheduled', 'published'] as $status) {
            foreach ([true, false] as $explicit) {
                $base = 'http-' . $status . '-' . ($explicit ? 'explicit' : 'generated');
                $payload = ['title' => $base, 'content' => 'First HTTP content', 'status' => $status,
                    'scheduled_at' => '2099-01-01T12:00', 'confirm_publish' => true];
                if ($explicit) { $payload['slug'] = $base; }
                $first = $request($payload, $base . '-a');
                $a = json_decode($first['body'], true)['post'] ?? [];
                $assert($first['status'] === 201 && !empty($a['post_id']), 'First HTTP creation failed: ' . $first['body']);
                $before = $state($a['post_id']);
                $payloadB = array_replace($payload, ['content' => 'Second HTTP content']);
                $second = $request($payloadB, $base . '-b');
                $b = json_decode($second['body'], true)['post'] ?? [];
                $assert($second['status'] === 201 && $b['post_id'] !== $a['post_id'] && $b['slug'] === $a['slug'] . '-2', 'Independent HTTP creation adopted identity.');
                $assert($state($a['post_id']) === $before, 'Second HTTP request modified first post.');
                foreach ([[$payload, $base . '-a', $first, $a], [$payloadB, $base . '-b', $second, $b]] as [$body, $key, $response, $post]) {
                    $replay = $request($body, $key);
                    $assert($replay['status'] === 201 && $replay['body'] === $response['body'], 'HTTP replay did not return its own exact outcome.');
                    $stored = $row($post['post_id']);
                    $front = json_decode($stored['content_front_matter'], true);
                    $section = $status === 'draft' ? 'drafts' : $status;
                    $assert($stored['slug'] === $post['slug'] && $front['slug'] === $post['slug']
                        && $post['filename'] === $post['slug'] . '.md'
                        && $stored['markdown_path'] === 'content/' . $section . '/' . $post['filename']
                        && str_contains($post['edit_url'], 'file=' . urlencode($post['filename'])), 'HTTP stored/returned slug metadata differed.');
                    $parsed = bms_parse_markdown_string(bms_database_content_raw(bms_database_row_to_content_page($stored)));
                    $assert($parsed['slug'] === $post['slug'], 'Portable Markdown retained a lost slug.');
                    if ($status === 'published') {
                        $events = $state($post['post_id'])['activitypub_publication_events'];
                        $activity = json_decode($events[0]['payload_json'], true);
                        $assert(count($events) === 1 && $activity['type'] === 'Create' && $activity['object']['url'] === $post['public_url']
                            && $stored['html_path'] === trim(bms_stream_relative_directory_for_post(bms_database_row_to_content_page($stored)), '/') . '/index.html', 'HTTP public/federation identity mismatch.');
                    }
                }
            }
        }
        // A suffixed creation retains exactly one upload and replay adds none.
        $image = imagecreatetruecolor(4, 4); ob_start(); imagepng($image); $png = (string)ob_get_clean(); imagedestroy($image);
        $mediaBefore = (int)bms_db()->query('SELECT COUNT(*) FROM ' . bms_table('media'))->fetchColumn();
        $mediaPayload = ['content' => 'Media collision', 'slug' => 'http-draft-explicit', 'media_upload' => [
            'filename' => 'slug.png', 'content_base64' => base64_encode($png), 'alt_text' => 'Collision fixture']];
        $mediaResponse = $request($mediaPayload, 'slug-media');
        $mediaPost = json_decode($mediaResponse['body'], true)['post'] ?? [];
        $assert($mediaResponse['status'] === 201 && $mediaPost['slug'] === 'http-draft-explicit-3', 'Media create collision failed.');
        $assert($request($mediaPayload, 'slug-media')['body'] === $mediaResponse['body']
            && (int)bms_db()->query('SELECT COUNT(*) FROM ' . bms_table('media'))->fetchColumn() === $mediaBefore + 1, 'Suffix/replay duplicated media.');

        // Unrelated INSERT failure stays HTTP 500/server_error, and releases only its owner.
        $owner = null; $hash = hash('sha256', 'held request');
        bms_api_idempotency_begin((int)$tokenData['token']['id'], 'other-held-owner', $hash, $owner);
        bms_db()->exec('ALTER TABLE ' . bms_table('posts') . ' ADD COLUMN slug_test_required INT NOT NULL'); $columnAdded = true;
        $failed = $request(['content' => 'Must fail safely', 'slug' => 'infrastructure-error'], 'failed-insert');
        $json = json_decode($failed['body'], true);
        $assert($failed['status'] === 500 && ($json['error']['code'] ?? '') === 'server_error'
            && ($json['error']['message'] ?? '') === 'The API request could not be completed.'
            && !str_contains($failed['body'], 'slug_test_required') && !str_contains($failed['body'], 'SQLSTATE'), 'Persistence diagnostic leaked or was retried as a slug.');
        $assert((int)bms_db()->query('SELECT COUNT(*) FROM ' . bms_table('api_idempotency_keys') . " WHERE idempotency_key='failed-insert'")->fetchColumn() === 0
            && (int)bms_db()->query('SELECT COUNT(*) FROM ' . bms_table('api_idempotency_keys') . ' WHERE id=' . $owner)->fetchColumn() === 1, 'Failure cleanup affected the wrong reservation.');
        bms_db()->exec('ALTER TABLE ' . bms_table('posts') . ' DROP COLUMN slug_test_required'); $columnAdded = false;
        $postsBefore = (int)bms_db()->query('SELECT COUNT(*) FROM ' . bms_table('posts'))->fetchColumn();
        bms_db()->exec('RENAME TABLE ' . bms_table('post_terms') . ' TO ' . bms_table('post_terms') . '_failure');
        try {
            $failedTerms = $request(['content' => 'Terms must roll back', 'slug' => 'terms-failure'], 'failed-terms');
            $termsJson = json_decode($failedTerms['body'], true);
            $assert($failedTerms['status'] === 500 && ($termsJson['error']['code'] ?? '') === 'server_error'
                && (int)bms_db()->query('SELECT COUNT(*) FROM ' . bms_table('posts'))->fetchColumn() === $postsBefore,
                'Post-INSERT terms failure left a row or became a suffix retry.');
        } finally { bms_db()->exec('RENAME TABLE ' . bms_table('post_terms') . '_failure TO ' . bms_table('post_terms')); }
        bms_api_idempotency_release((int)$tokenData['token']['id'], 'other-held-owner', $hash, $owner);
    } finally {
        if ($columnAdded) { bms_db()->exec('ALTER TABLE ' . bms_table('posts') . ' DROP COLUMN slug_test_required'); }
        proc_terminate($proc); proc_close($proc); @unlink($log);
    }
}

/** Exercise the unchanged API retry loop with deterministic injected collisions.
 * Each injected collision is backed by a real duplicate INSERT, not SQLSTATE guessing.
 * Only the disposable namespaced copy substitutes the primitive; no runtime hook exists.
 */
function bms_slug_safety_retry_budget(callable $assert, callable $intent, array $token): void
{
    $root = (string)$GLOBALS['bms_api_smoke_temp_root'];
    $reflection = new ReflectionFunction('bms_api_insert_remote_stream_post');
    $source = implode('', array_slice(file($reflection->getFileName()), $reflection->getStartLine() - 1,
        $reflection->getEndLine() - $reflection->getStartLine() + 1));
    $fixture = $root . '/slug-retry-budget.php';
    file_put_contents($fixture, <<<'FIXTURE'
<?php
namespace BmsSlugRetryRegression;
use \BMS_Api_Exception;
use \BMS_Content_Slug_Conflict;
function bms_insert_database_content(array $page, string $section, string $filename, ?int $authorId): int
{
    $GLOBALS['slug_retry_calls']++;
    if ($GLOBALS['slug_retry_losses']-- > 0) {
        // Model the uncoordinated writer winning immediately before our INSERT.
        \bms_insert_database_content($page, $section, $filename, $authorId);
        return \bms_insert_database_content_record(\bms_database_content_record_from_page($page, $section, $filename, $authorId));
    }
    return \bms_insert_database_content($page, $section, $filename, $authorId);
}
FIXTURE
        . "\n" . $source);
    require $fixture;
    $GLOBALS['slug_retry_calls'] = 0; $GLOBALS['slug_retry_losses'] = 2;
    [$saved, $id] = \BmsSlugRetryRegression\bms_api_insert_remote_stream_post($intent('forced-retry', 'draft'), 'Retry body', 1);
    $assert($GLOBALS['slug_retry_calls'] === 3 && $id > 0 && $saved['slug'] === 'forced-retry-3', 'Bounded collision retry did not rebuild the final slug.');
    $key = 'retry-exhaustion'; $hash = hash('sha256', $key); $owner = null;
    bms_api_idempotency_begin((int)$token['id'], $key, $hash, $owner);
    $before = (int)bms_db()->query('SELECT COUNT(*) FROM ' . bms_table('posts'))->fetchColumn();
    $GLOBALS['slug_retry_calls'] = 0; $GLOBALS['slug_retry_losses'] = 10;
    try {
        \BmsSlugRetryRegression\bms_api_insert_remote_stream_post($intent('exhaustion', 'published'), 'Exhaustion body', 1);
        throw new RuntimeException('Retries did not terminate.');
    } catch (BMS_Api_Exception $e) {
        $assert($e->apiCode === 'slug_conflict' && $e->statusCode === 409 && $GLOBALS['slug_retry_calls'] === 5, 'Exhaustion did not produce a bounded stable conflict.');
        // Same cleanup routine and owned handle as the endpoint catch path.
        bms_api_idempotency_release((int)$token['id'], $key, $hash, $owner);
    }
    $assert((int)bms_db()->query('SELECT COUNT(*) FROM ' . bms_table('posts'))->fetchColumn() === $before
        && (int)bms_db()->query('SELECT COUNT(*) FROM ' . bms_table('api_idempotency_keys') . ' WHERE id=' . $owner)->fetchColumn() === 0,
        'Exhaustion left partial creation or its unfinished reservation.');
    $assert((int)bms_db()->query('SELECT COUNT(*) FROM ' . bms_table('activitypub_publication_events') . " WHERE payload_json LIKE '%exhaustion%'")->fetchColumn() === 0,
        'Exhaustion left rolled-back publication work.');
    @unlink($fixture);
}

<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}
function bms_api_smoke_publishing_safety(): void
{
    $root = (string)$GLOBALS['bms_api_smoke_temp_root'];
    $_SESSION['bms_logged_in'] = true;
    $_SESSION['bms_user_id'] = 1;
    $bodies = [
        'Morning 🌅 notes from the workbench with café 日本語 and a longer sentence that exercises generated metadata.',
        str_repeat('é', 68) . '🌅' . str_repeat('日本語 👨‍👩‍👧‍👦 ', 25),
        str_repeat('a', 158) . '🌅 café',
    ];
    foreach ($bodies as $index => $body) {
        $fields = bms_stream_prepare_metadata_fields(['status' => 'published', 'slug' => 'unicode-' . $index], $body);
        $page = bms_parse_markdown_string(bms_build_markdown_document($fields, $body));
        foreach (['title', 'description', 'seo_title'] as $key) {
            if (preg_match('//u', (string)$page[$key]) !== 1 || $page[$key] !== $fields[$key]) {
                throw new RuntimeException('Metadata was corrupted during generated-field serialization: ' . $key);
            }
        }
        $postId = bms_upsert_database_content($page, 'published', 'unicode-' . $index . '.md', 1);
        $stored = bms_db()->query('SELECT * FROM ' . bms_table('posts') . ' WHERE id = ' . $postId)->fetch();
        if (trim($stored['content_body']) !== trim($body) || preg_match('//u', $stored['title'] . $stored['description']) !== 1) {
            throw new RuntimeException('Valid Unicode did not survive the complete database publication path.');
        }
    }

    $insert = bms_db()->prepare('INSERT INTO ' . bms_table('media') . " (filename, original_filename, public_path, mime_type, file_size, alt_text, caption, uploaded_by, created_at, updated_at) VALUES (?, ?, ?, 'image/png', 4, '', '', 1, NOW(), NOW())");
    $media = [];
    for ($n = 1; $n <= 4; $n++) {
        $path = 'media/safety-image-' . $n . '.png';
        $insert->execute([basename($path), basename($path), $path]);
        $media[] = bms_media_find((int)bms_db()->lastInsertId());
        bms_write_file(bms_public_path($path), 'test');
    }
    for ($count = 1; $count <= 4; $count++) {
        $fields = bms_stream_prepare_metadata_fields([
            'status' => 'published', 'slug' => 'gallery-' . $count,
            'featured_media' => $media[0]['public_path'],
            'media_gallery' => array_column(array_slice($media, 0, $count), 'public_path'),
        ], 'Gallery reference case ' . $count . '.');
        $page = bms_parse_markdown_string(bms_build_markdown_document($fields, 'Gallery reference case ' . $count . '.'));
        bms_upsert_database_content($page, 'published', 'gallery-' . $count . '.md', 1);
        foreach (array_slice($media, 0, $count) as $item) {
            $references = bms_media_usage_references($item);
            if (!array_filter($references, static fn(array $r): bool => str_contains($r['path'], '/gallery-' . $count . '/'))) {
                throw new RuntimeException('A published gallery image was absent from media usage.');
            }
        }
    }
    $maxAlt = str_repeat('🌅', 255);
    bms_media_update((int)$media[0]['id'], $maxAlt, 'Caption retained.');
    if (bms_media_find((int)$media[0]['id'])['alt_text'] !== $maxAlt) {
        throw new RuntimeException('The supported 255-character Unicode alt text did not save.');
    }
    try {
        bms_media_update((int)$media[0]['id'], str_repeat('a', 270), 'Rejected caption.');
        throw new RuntimeException('Over-limit alt text was accepted.');
    } catch (InvalidArgumentException $e) {
        if (!str_contains($e->getMessage(), '255')) { throw $e; }
    }
    if (bms_media_find((int)$media[0]['id'])['caption'] !== 'Caption retained.') {
        throw new RuntimeException('Rejected alt input partially changed media metadata.');
    }
    bms_api_smoke_expect_api_exception('alt_text_invalid', static function (): void {
        bms_api_alt_text(['alt_text' => str_repeat('🌅', 256)], ['alt_text']);
    });
    if (bms_api_alt_text(['alt_text' => $maxAlt], ['alt_text']) !== $maxAlt
        || preg_match('//u', bms_api_string_field(['description' => str_repeat('🌅', 80)], ['description'], 70)) !== 1) {
        throw new RuntimeException('API metadata truncated Unicode by bytes.');
    }
    bms_media_trash((int)$media[3]['id']);
    if (!is_file(bms_public_path($media[3]['public_path'])) || bms_media_usage_references($media[3]) === []) {
        throw new RuntimeException('Media trash broke an existing gallery file or hid its usage.');
    }
    bms_media_restore((int)$media[3]['id']);

    $uncertainKey = bms_composer_request_key();
    bms_composer_begin_request($uncertainKey);
    try {
        bms_composer_begin_request($uncertainKey);
        throw new RuntimeException('An uncertain submission allowed a blind duplicate retry.');
    } catch (InvalidArgumentException $e) {
        if (!str_contains($e->getMessage(), 'could not be confirmed')) { throw $e; }
    }

    // Actual HTTP handlers: private fixture sessions, isolated DB and temporary source copy.
    if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
    $sessionPath = $root . '/_bonumark_stream/tmp/test-sessions';
    mkdir($sessionPath, 0700, true);
    session_save_path($sessionPath);
    session_id('safety' . bin2hex(random_bytes(12)));
    bms_start_secure_session();
    $_SESSION['bms_logged_in'] = true;
    $_SESSION['bms_user_id'] = 1;
    $csrf = bms_csrf_token();
    $key = bms_composer_request_key();
    $cookie = session_name() . '=' . session_id();
    session_write_close();
    $port = random_int(44100, 44999);
    $log = $root . '/publishing-safety-server.log';
    $process = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $sessionPath, '-S', '127.0.0.1:' . $port, '-t', $root],
        [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $root, array_merge($_ENV, getenv()));
    if (!is_resource($process)) { throw new RuntimeException('Could not start publishing safety HTTP fixture.'); }
    fclose($pipes[0]);
    $base = 'http://127.0.0.1:' . $port;
    try {
        for ($attempt = 0; $attempt < 50; $attempt++) {
            try { bms_api_smoke_http_request($base . '/admin/stream-composer.php', 'GET', ['Cookie: ' . $cookie]); break; }
            catch (Throwable $e) { if ($attempt === 49) { throw $e; } usleep(100000); }
        }
        $payload = ['csrf_token' => $csrf, 'composer_request_key' => $key, 'return_to' => '/',
            'stream_body' => 'Recover this 🌅 composer body.', 'stream_slug' => 'recovered-composer',
            'stream_submit_action' => 'schedule', 'stream_scheduled_at' => 'not-a-date',
            'stream_title' => 'Retained internal title'];
        $submit = static function (array $data) use ($base, $cookie): array {
            return bms_api_smoke_http_request($base . '/admin/quick-post.php', 'POST',
                ['Cookie: ' . $cookie, 'Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
                http_build_query($data));
        };
        $before = (int)bms_db()->query('SELECT COUNT(*) FROM ' . bms_table('posts'))->fetchColumn();
        $failed = $submit($payload);
        if ($failed['status'] !== 422 || json_decode($failed['body'], true)['ok'] !== false) {
            throw new RuntimeException('Invalid schedule did not return a recoverable failure: ' . $failed['body']);
        }
        $recovered = bms_api_smoke_http_request($base . '/admin/stream-composer.php?return_to=%2F', 'GET', ['Cookie: ' . $cookie]);
        foreach ([$payload['stream_body'], $payload['stream_title'], $payload['stream_slug']] as $value) {
            if (!str_contains($recovered['body'], $value)) { throw new RuntimeException('Native fallback lost a composer field.'); }
        }
        $payload['stream_submit_action'] = 'draft';
        $payload['stream_title'] = str_repeat('x', 181);
        if ($submit($payload)['status'] !== 422) { throw new RuntimeException('Over-limit title bypassed validation.'); }
        $payload['stream_title'] = 'Corrected title';
        $saved = $submit($payload);
        $replay = $submit($payload); // Simulate a lost successful response, followed by retry.
        $after = (int)bms_db()->query('SELECT COUNT(*) FROM ' . bms_table('posts'))->fetchColumn();
        if ($saved['status'] !== 200 || $replay['status'] !== 200 || $after !== $before + 1) {
            throw new RuntimeException('Corrected composer retry failed or created duplicate content: ' . $saved['body'] . ' / ' . $replay['body']);
        }
        $recoveryCleared = bms_api_smoke_http_request($base . '/admin/stream-composer.php?return_to=%2F', 'GET', ['Cookie: ' . $cookie]);
        if (str_contains($recoveryCleared['body'], $payload['stream_body'])) {
            throw new RuntimeException('Successful save left the failed draft in the composer.');
        }

        $admin = bms_api_smoke_http_request($base . '/admin/activitypub.php', 'GET', ['Cookie: ' . $cookie]);
        if ($admin['status'] !== 200 || !str_contains($admin['body'], 'Federated profile') || !str_contains($admin['body'], 'ap-diagnostics')) {
            throw new RuntimeException('The authenticated ActivityPub Admin workflow did not render.');
        }
        $privateView = bms_api_smoke_http_request($base . '/admin/_activitypub-view.php', 'GET');
        if ($privateView['status'] !== 403) { throw new RuntimeException('Direct access to the ActivityPub view was not blocked.'); }

        $badAlt = str_repeat('z', 270);
        $edit = bms_api_smoke_http_request($base . '/admin/media-edit.php?id=' . $media[0]['id'], 'POST',
            ['Cookie: ' . $cookie, 'Content-Type: application/x-www-form-urlencoded'],
            http_build_query(['csrf_token' => $csrf, 'id' => $media[0]['id'], 'alt_text' => $badAlt, 'caption' => 'Keep this attempted caption']));
        if ($edit['status'] !== 422 || !str_contains($edit['body'], $badAlt)
            || !str_contains($edit['body'], 'Keep this attempted caption') || !str_contains($edit['body'], '255 characters')) {
            throw new RuntimeException('Media Edit did not retain rejected input with an actionable error.');
        }
    } finally {
        proc_terminate($process);
        proc_close($process);
    }
}

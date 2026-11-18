<?php
/**
 * Actual export archive acceptance and failure checks against a disposable install.
 * Uses real authenticated HTTP downloads, the core renderer and synthetic records.
 * --archive-only runs the same content assertions against an unchanged baseline.
 * BMS_EXPORT_TEST_SOURCE optionally selects that baseline; never use an installed tree.
 * BMS_EXPORT_TEST_REPORT writes manifests/assertions only, never archive or SQL bytes.
 *
 * Uses BMS_DB_HOST, BMS_DB_NAME, BMS_DB_USER, BMS_DB_PASS and requires
 * BMS_DB_DANGER_RESET=1. Only random bms_export_ci_* tables are created and
 * removed. Application files, sessions and the loopback HTTP server are temporary.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}
if ((string)getenv('BMS_DB_DANGER_RESET') !== '1') {
    fwrite(STDERR, "Refusing to run without BMS_DB_DANGER_RESET=1.\n");
    exit(1);
}
foreach (['BMS_DB_HOST', 'BMS_DB_NAME', 'BMS_DB_USER'] as $required) {
    if ((string)getenv($required) === '') {
        throw new RuntimeException($required . ' is required.');
    }
}

$source = (string)getenv('BMS_EXPORT_TEST_SOURCE') ?: dirname(__DIR__);
$archiveOnly = in_array('--archive-only', $argv, true);
$failures = [];
$report = ['assertions' => [], 'archives' => []];
$temporary = sys_get_temp_dir() . '/bms-export-test-' . bin2hex(random_bytes(6));
$prefix = 'bms_export_ci_' . bin2hex(random_bytes(5)) . '_';
$process = null;
$pdo = null;
$checks = 0;

function bms_export_test_expect(bool $condition, string $label): void
{
    $GLOBALS['report']['assertions'][] = ['label' => $label, 'passed' => $condition];
    if (!$condition) {
        $GLOBALS['failures'][] = $label;
        fwrite(STDOUT, 'FAIL ' . $label . "\n");
        return;
    }
    $GLOBALS['checks']++;
    fwrite(STDOUT, 'PASS ' . $label . "\n");
}

function bms_export_test_request(string $base, string $path, array &$cookies, ?array $form = null): array
{
    $headers = ['User-Agent: Bonumark Export Archive Regression', 'Connection: close'];
    if ($cookies !== []) {
        $headers[] = 'Cookie: ' . implode('; ', array_map(static fn(string $key, string $value): string => $key . '=' . $value, array_keys($cookies), $cookies));
    }
    $options = ['method' => $form === null ? 'GET' : 'POST', 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 10];
    if ($form !== null) {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $options['content'] = http_build_query($form);
    }
    $options['header'] = implode("\r\n", $headers);
    $body = file_get_contents($base . $path, false, stream_context_create(['http' => $options]));
    if ($body === false || !isset($http_response_header[0])) {
        throw new RuntimeException('HTTP request failed: ' . $path);
    }
    preg_match('/\s(\d{3})\s/', $http_response_header[0], $status);
    $location = '';
    foreach ($http_response_header as $header) {
        if (preg_match('/^Set-Cookie:\s*([^=;]+)=([^;]*)/i', $header, $match)) {
            $cookies[$match[1]] = $match[2];
        }
        if (stripos($header, 'Location:') === 0) {
            $location = trim(substr($header, 9));
        }
    }
    return ['status' => (int)($status[1] ?? 0), 'body' => $body, 'location' => $location, 'headers' => $http_response_header];
}

function bms_export_test_token(string $html): string
{
    if (!preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $match)) {
        throw new RuntimeException('Missing CSRF token in rendered form.');
    }
    return html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
}

function bms_export_test_remove_tree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($path);
}

function bms_export_test_upload(string $base, array &$cookies, string $token, string $bytes): array
{
    $boundary = 'bms-export-' . bin2hex(random_bytes(12));
    $body = '';
    foreach (['csrf_token' => $token, 'import_action' => 'preview'] as $key => $value) {
        $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"$key\"\r\n\r\n$value\r\n";
    }
    $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"import_file\"; filename=\"synthetic-markdown.zip\"\r\nContent-Type: application/zip\r\n\r\n$bytes\r\n--$boundary--\r\n";
    $headers = ['Content-Type: multipart/form-data; boundary=' . $boundary, 'Connection: close'];
    $headers[] = 'Cookie: ' . implode('; ', array_map(static fn($key, $value) => "$key=$value", array_keys($cookies), $cookies));
    $result = file_get_contents($base . '/admin/import.php', false, stream_context_create(['http' => ['method' => 'POST', 'content' => $body, 'header' => implode("\r\n", $headers), 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 20]]));
    preg_match('/\s(\d{3})\s/', $http_response_header[0], $status);
    return ['status' => (int)$status[1], 'body' => (string)$result];
}


try {
    mkdir($temporary, 0700);
    $iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), static function (SplFileInfo $file) use ($source): bool {
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($source) + 1));
        // Never copy an installed checkout's credentials, backups, uploads or
        // generated exports into the disposable HTTP document root.
        return !$file->isLink()
            && !in_array($relative, [
                '.git', '_bonumark_stream/config.php', '_bonumark_stream/installed.lock',
                '_bonumark_stream/data', '_bonumark_stream/tmp', '_bonumark_stream/backups',
                'media', 'index.html', 'stream', 'feed.xml', 'sitemap.xml', 'sitemap.xsl', 'robots.txt',
            ], true)
            && preg_match('/\.(?:log|sql|sqlite|zip|tar|tar\.gz|tgz)$/i', $relative) !== 1;
    }), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($iterator as $file) {
        $target = $temporary . substr($file->getPathname(), strlen($source));
        $file->isDir() ? mkdir($target, 0700, true) : copy($file->getPathname(), $target);
    }
    mkdir($temporary . '/sessions', 0700);
    ini_set('session.save_path', $temporary . '/sessions');
    mkdir($temporary . '/media', 0700);
    $listener = stream_socket_server('tcp://127.0.0.1:0', $error, $errorMessage);
    if ($listener === false) {
        throw new RuntimeException('Cannot reserve a loopback port: ' . $errorMessage);
    }
    $address = stream_socket_get_name($listener, false);
    fclose($listener);
    $base = 'http://' . $address;
    $config = [
        'site_name' => 'Export Fixture', 'site_tagline' => 'Saved tagline',
        'site_admin_email' => 'saved@example.test', 'timezone' => 'America/Indiana/Indianapolis',
        'base_url' => $base, 'base_path' => '', 'security_salt' => bin2hex(random_bytes(24)),
        'scheduled_tasks_public_traffic_enabled' => '0', 'scheduled_tasks_heartbeat_enabled' => '0',
        'activitypub_enabled' => '0', 'comments_enabled' => '0', 'pwa_enabled' => '0', 'mail_transport' => 'disabled',
        'database' => ['host' => (string)getenv('BMS_DB_HOST'), 'name' => (string)getenv('BMS_DB_NAME'),
            'user' => (string)getenv('BMS_DB_USER'), 'password' => (string)getenv('BMS_DB_PASS'), 'charset' => 'utf8mb4', 'prefix' => $prefix],
    ];
    file_put_contents($temporary . '/_bonumark_stream/config.php', "<?php\nreturn " . var_export($config, true) . ";\n");
    touch($temporary . '/_bonumark_stream/installed.lock');
    require $temporary . '/_bonumark_stream/app/database.php';
    $pdo = bms_db();
    bms_database_require_supported($pdo);
    bms_install_schema($pdo, $prefix);
    $insertSetting = $pdo->prepare("INSERT INTO `{$prefix}settings` (setting_key, setting_value, updated_at) VALUES (?, ?, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
    foreach ($config as $key => $value) {
        if (is_string($value)) {
            $insertSetting->execute([$key, $value]);
        }
    }
    $insertUser = $pdo->prepare("INSERT INTO `{$prefix}users` (username,display_name,email,email_verified_at,password_hash,role,status,created_at,updated_at) VALUES (?,?,?,UTC_TIMESTAMP(),?,?,'active',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    foreach (['owner' => 'admin', 'commenter' => 'commenter'] as $username => $role) {
        $insertUser->execute([$username, ucfirst($username), $username . '@example.test', password_hash('disposable-password', PASSWORD_DEFAULT), $role]);
    }
    $migrationCount = (int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}migrations`")->fetchColumn();
    $process = proc_open([PHP_BINARY, '-d', 'opcache.enable=0', '-d', 'display_errors=1', '-d', 'session.save_path=' . $temporary . '/sessions', '-S', $address, '-t', $temporary], [0 => ['pipe', 'r'], 1 => ['file', $temporary . '/http.log', 'a'], 2 => ['file', $temporary . '/http.log', 'a']], $pipes, $temporary);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start the isolated PHP HTTP server.');
    }
    fclose($pipes[0]);
    $ready = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $socket = @stream_socket_client('tcp://' . $address, $error, $errorMessage, 0.1);
        if ($socket !== false) {
            fclose($socket);
            $ready = true;
            break;
        }
        usleep(100000);
    }
    bms_export_test_expect($ready, 'Isolated loopback server starts');
    fwrite(STDOUT, 'Runtime: PHP ' . PHP_VERSION . ' / ' . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . "\n");

    $guest = [];
    $sessions = [];
    $sessionTokens = [];
    foreach (['owner', 'commenter'] as $username) {
        $cookies = [];
        $login = bms_export_test_request($base, '/admin/login.php', $cookies);
        $sessionTokens[$username] = bms_export_test_token($login['body']);
        $login = bms_export_test_request($base, '/admin/login.php', $cookies, ['csrf_token' => $sessionTokens[$username], 'username' => $username, 'password' => 'disposable-password']);
        bms_export_test_expect($login['status'] === 302, ucfirst($username) . ' fixture authenticates through real login');
        $sessions[$username] = $cookies;
    }


    require_once $temporary . '/_bonumark_stream/app/pages.php';
    $owner =& $sessions['owner'];
    $ownerId = (int)$pdo->query("SELECT id FROM `{$prefix}users` WHERE username='owner'")->fetchColumn();

    $report['runtime'] = ['php' => PHP_VERSION, 'database' => $pdo->getAttribute(PDO::ATTR_SERVER_VERSION)];
    $fixtures = [
        ['public-post', 'Published Stream Title', 'Published stream body with **faithful words**.', 'published', 'stream'],
        ['public-page', 'Published Page Title', 'Published page body with **faithful words**.', 'published', 'page'],
        ['private-draft', 'PRIVATE DRAFT TITLE', 'PRIVATE DRAFT BODY', 'draft', 'stream'],
        ['future-post', 'PRIVATE SCHEDULED TITLE', 'PRIVATE SCHEDULED BODY', 'scheduled', 'stream'],
        ['private-page', 'PRIVATE PAGE TITLE', 'PRIVATE PAGE BODY', 'draft', 'page'],
    ];
    foreach ($fixtures as [$slug, $title, $body, $status, $type]) {
        $fields = ['title' => $title, 'seo_title' => $title, 'slug' => $slug, 'status' => $status,
            'content_type' => $type, 'date' => '2026-01-02', 'featured_media' => $slug === 'public-post' ? 'media/synthetic.png' : ''];
        if ($status === 'scheduled') { $fields['scheduled_at'] = '2099-01-02 12:00:00'; }
        $page = bms_parse_markdown_string(bms_build_markdown_document($fields, $body));
        $section = $type === 'page' ? 'pages/' . ($status === 'draft' ? 'drafts' : 'published') : ($status === 'draft' ? 'drafts' : $status);
        bms_upsert_database_content($page, $section, $slug . '.md', $ownerId);
    }
    // Generated, non-owner media and a stored file version, both intentionally tiny.
    $media = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aWQAAAABJRU5ErkJggg==');
    file_put_contents($temporary . '/media/synthetic.png', $media);
    $mediaInsert = $pdo->prepare("INSERT INTO `{$prefix}media` (filename, original_filename, public_path, mime_type, file_size, alt_text, caption, uploaded_by, created_at, updated_at) VALUES ('synthetic.png', 'synthetic.png', 'media/synthetic.png', 'image/png', ?, 'Synthetic pixel', '', ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    $mediaInsert->execute([strlen($media), $ownerId]);
    bms_export_test_expect((int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}media`")->fetchColumn() === 1, 'Synthetic attachment has a database Media record');
    bms_write_file(bms_content_path('versions/public-post/synthetic.md'), "Synthetic stored version.\n");
    // A pre-existing unrelated output must survive this export's cleanup.
    bms_write_file(bms_static_site_export_root('retained') . '/sentinel.txt', 'retained fixture');

    $exportPath = $temporary . '/admin/export.php';
    $originalExport = (string)file_get_contents($exportPath);
    $rendererPath = $temporary . '/_bonumark_stream/app/renderer.php';
    $originalRenderer = (string)file_get_contents($rendererPath);
    $form = bms_export_test_request($base, '/admin/export.php', $owner);
    $token = bms_export_test_token($form['body']);
    $post = static function (string $kind) use ($base, &$owner, $token): array {
        return bms_export_test_request($base, '/admin/export.php', $owner, ['csrf_token' => $token, 'export_kind' => $kind]);
    };
    $clean = static function (string $label) use ($temporary): void {
        bms_export_test_expect((glob($temporary . '/_bonumark_stream/tmp/exports/*') ?: []) === [], $label . ': temporary ZIP output removed');
        $dirs = array_map('basename', glob($temporary . '/_bonumark_stream/tmp/static-site-exports/*') ?: []);
        bms_export_test_expect($dirs === ['retained'], $label . ': generated directory removed, unrelated output retained');
    };
    $inspect = static function (array $response, string $kind, string $label) use ($temporary, $token, &$owner, $media, $clean): array {
        $headers = implode("\n", $response['headers']);
        bms_export_test_expect($response['status'] === 200, $label . ': HTTP 200');
        bms_export_test_expect(str_contains($headers, 'Content-Type: application/zip'), $label . ': ZIP MIME');
        bms_export_test_expect(preg_match('/Content-Disposition: attachment; filename="bonumark-stream-export-' . $kind . '-\d{8}-\d{6}\.zip"/', $headers) === 1, $label . ': download filename contract');
        bms_export_test_expect(preg_match('/Content-Length: (\d+)/i', $headers, $length) === 1 && (int)$length[1] === strlen($response['body']), $label . ': exact download length');
        bms_export_test_expect(str_contains(strtolower($headers), 'no-store'), $label . ': private no-store response');
        $path = $temporary . '/received.zip';
        file_put_contents($path, $response['body']);
        $archive = new ZipArchive();
        $opened = $archive->open($path, ZipArchive::CHECKCONS) === true;
        bms_export_test_expect($opened, $label . ': downloaded bytes open with ZIP consistency check');
        $entries = [];
        $manifest = [];
        if ($opened) {
            for ($i = 0; $i < $archive->numFiles; $i++) {
                $name = $archive->getNameIndex($i);
                $bytes = $archive->getFromIndex($i);
                bms_export_test_expect(is_string($bytes), $label . ': readable entry ' . $name);
                $entries[$name] = (string)$bytes;
                $manifest[] = ['path' => $name, 'bytes' => strlen((string)$bytes), 'sha256' => hash('sha256', (string)$bytes)];
            }
            $archive->close();
        }
        unlink($path);
        usort($manifest, static fn($a, $b) => strcmp($a['path'], $b['path']));
        $GLOBALS['report']['archives'][$label] = ['status' => $response['status'], 'entry_count' => count($manifest), 'entries' => $manifest];
        bms_export_test_expect(str_contains($entries['EXPORT.txt'] ?? '', "Type: {$kind}\nVersion: " . bms_version()), $label . ': export metadata retained');
        $allowed = match ($kind) {
            'static' => ['static/', 'EXPORT.txt'],
            'full' => ['static/', 'markdown/', 'content/versions/', 'media/', 'database/', 'EXPORT.txt'],
            'markdown' => ['markdown/', 'content/versions/', 'EXPORT.txt'],
            'media' => ['media/', 'EXPORT.txt'],
            'database' => ['database/', 'EXPORT.txt'],
        };
        $unexpected = array_filter(array_keys($entries), static function ($name) use ($allowed): bool {
            foreach ($allowed as $prefix) { if (str_starts_with($name, $prefix)) { return false; } }
            return true;
        });
        bms_export_test_expect($unexpected === [], $label . ': existing archive category layout only');
        if ($kind === 'static' || $kind === 'full') {
            foreach (['index.html', 'stream/index.html', 'stream/public-post/index.html', 'pages/public-page/index.html', 'feed.xml', 'stream/feed.xml', 'sitemap.xml', 'sitemap.xsl', 'robots.txt', 'assets/style.css', 'assets/stream.js', 'assets/themes/default/assets/css/theme.css', 'media/synthetic.png'] as $required) {
                bms_export_test_expect(isset($entries['static/' . $required]) && $entries['static/' . $required] !== '', $label . ': includes ' . $required);
            }
            bms_export_test_expect(str_contains($entries['static/index.html'] ?? '', 'Published stream body with <strong>faithful words</strong>.'), $label . ': homepage faithful body');
            bms_export_test_expect(str_contains($entries['static/stream/public-post/index.html'] ?? '', 'Published Stream Title') && str_contains($entries['static/stream/public-post/index.html'] ?? '', 'Published stream body with <strong>faithful words</strong>.'), $label . ': Stream title and body');
            bms_export_test_expect(str_contains($entries['static/pages/public-page/index.html'] ?? '', 'Published Page Title') && str_contains($entries['static/pages/public-page/index.html'] ?? '', 'Published page body with <strong>faithful words</strong>.'), $label . ': Page title and body');
            bms_export_test_expect(($entries['static/media/synthetic.png'] ?? '') === $media, $label . ': static media byte fidelity');
            bms_export_test_expect(str_contains($entries['static/index.html'] ?? '', 'href="/stream/public-post/"') && str_contains($entries['static/index.html'] ?? '', '/media/synthetic.png'), $label . ': existing root-relative public links');
            foreach (['static/feed.xml', 'static/stream/feed.xml', 'static/sitemap.xml'] as $xml) {
                bms_export_test_expect(isset($entries[$xml]) && @simplexml_load_string($entries[$xml]) !== false, $label . ': valid ' . $xml);
                bms_export_test_expect(str_contains($entries[$xml] ?? '', '/stream/public-post/'), $label . ': public URL in ' . $xml);
            }
            $html = implode("\n", array_filter($entries, static fn($key) => str_ends_with($key, '.html'), ARRAY_FILTER_USE_KEY));
            foreach (['PRIVATE DRAFT', 'PRIVATE SCHEDULED', 'PRIVATE PAGE', 'PRIVATE SESSION NOTICE', 'name="csrf_token"', 'data-stream-quick-edit-form', 'data-stream-quick-edit-open', 'data-stream-trash-form', 'Edit Page', 'Open full editor', 'stream-composer-mount', 'owner@example.test', $token] as $private) {
                bms_export_test_expect(!str_contains($html, $private), $label . ': HTML excludes ' . (strlen($private) > 50 ? 'session CSRF token' : $private));
            }
            foreach ($owner as $cookie) { bms_export_test_expect(!str_contains($html, $cookie), $label . ': HTML excludes session cookie'); }
            bms_export_test_expect(!isset($entries['static/stream/private-draft/index.html']) && !isset($entries['static/stream/future-post/index.html']) && !isset($entries['static/pages/private-page/index.html']), $label . ': unpublished paths excluded');
            $publicText = implode("\n", array_filter($entries, static fn($key) => str_starts_with($key, 'static/') && preg_match('/\.(html|xml|txt)$/', $key), ARRAY_FILTER_USE_KEY));
            bms_export_test_expect(!str_contains($publicText, 'PRIVATE DRAFT') && !str_contains($publicText, 'PRIVATE SCHEDULED') && !str_contains($publicText, 'PRIVATE PAGE'), $label . ': feeds/discovery also exclude unpublished content');
        }
        if ($kind === 'markdown' || $kind === 'full') {
            $manifestData = json_decode($entries['markdown/manifest.json'] ?? '', true);
            bms_export_test_expect(($manifestData['source'] ?? '') === 'database-first-content-records' && count($manifestData['items'] ?? []) === 5, $label . ': all database-first Markdown items');
            foreach (['posts/published/public-post.md', 'posts/drafts/private-draft.md', 'posts/scheduled/future-post.md', 'pages/published/public-page.md', 'pages/drafts/private-page.md'] as $required) {
                bms_export_test_expect(isset($entries['markdown/' . $required]), $label . ': Markdown path ' . $required);
            }
            bms_export_test_expect(str_contains($entries['markdown/posts/drafts/private-draft.md'] ?? '', 'PRIVATE DRAFT BODY'), $label . ': private Markdown retained');
            bms_export_test_expect(($entries['content/versions/public-post/synthetic.md'] ?? '') === "Synthetic stored version.\n", $label . ': stored content version retained');
            bms_export_test_expect(isset($entries['markdown/README.txt']), $label . ': Markdown README retained');
        }
        if ($kind === 'media' || $kind === 'full') { bms_export_test_expect(($entries['media/synthetic.png'] ?? '') === $media, $label . ': media byte fidelity'); }
        if ($kind === 'database' || $kind === 'full') {
            $sql = $entries['database/bonumark.sql'] ?? '';
            bms_export_test_expect(str_contains($sql, 'INSERT INTO') && str_contains($sql, 'password_hash') && str_contains($sql, 'owner@example.test') && str_contains($sql, 'PRIVATE DRAFT BODY'), $label . ': private SQL categories retained');
            bms_export_test_expect(str_contains($sql, 'Warning: this private backup may contain'), $label . ': SQL confidentiality warning retained');
        }
        $clean($label);
        return $entries;
    };

    // Seed a private flash through the session file, never a public test endpoint.
    $seedNotice = static function () use ($temporary, &$owner): void {
        $sessionId = $owner[bms_session_cookie_name()] ?? '';
        if (!preg_match('/^[A-Za-z0-9,-]+$/', $sessionId)) { throw new RuntimeException('Missing synthetic session.'); }
        $script = $temporary . '/seed-notice.php';
        file_put_contents($script, '<?php session_save_path(' . var_export($temporary . '/sessions', true) . '); session_id(' . var_export($sessionId, true) . '); session_start(); $_SESSION["flash"][] = ["type"=>"error","message"=>"PRIVATE SESSION NOTICE"]; session_write_close();');
        $child = proc_open([PHP_BINARY, $script], [0 => ['pipe', 'r'], 1 => ['file', $temporary . '/seed.log', 'a'], 2 => ['file', $temporary . '/seed.log', 'a']], $pipes);
        fclose($pipes[0]);
        if (proc_close($child) !== 0) { throw new RuntimeException('Could not seed private flash.'); }
        unlink($script);
    };
    foreach (['markdown', 'media', 'database', 'static', 'full'] as $kind) {
        $seedNotice();
        $response = $post($kind);
        if ($kind === 'markdown') { $roundTripBytes = $response['body']; }
        $inspect($response, $kind, $kind);
    }
    // Requests rejected before work must not allocate archive files.
    foreach (['markdown', 'media', 'database', 'static', 'full'] as $kind) {
        foreach ([null, 'invalid'] as $invalid) {
            $fields = ['export_kind' => $kind];
            if ($invalid !== null) { $fields['csrf_token'] = $invalid; }
            $response = bms_export_test_request($base, '/admin/export.php', $owner, $fields);
            bms_export_test_expect($response['status'] === 403, $kind . ': missing/invalid CSRF denied');
        }
        $response = bms_export_test_request($base, '/admin/export.php', $sessions['commenter'], ['csrf_token' => $sessionTokens['commenter'], 'export_kind' => $kind]);
        bms_export_test_expect($response['status'] === 403, $kind . ': authenticated commenter denied');
        $response = bms_export_test_request($base, '/admin/export.php', $guest, ['csrf_token' => $token, 'export_kind' => $kind]);
        bms_export_test_expect($response['status'] === 302 && str_ends_with($response['location'], '/admin/login.php'), $kind . ': guest redirected to login');
        $clean($kind . ' authorization');
    }

    if (!$archiveOnly) {
        $reject = static function (string $kind, string $label) use ($post, $base, &$owner, $clean): void {
            $response = $post($kind);
            $headers = strtolower(implode("\n", $response['headers']));
            bms_export_test_expect($response['status'] === 302 && str_ends_with($response['location'], '/admin/export.php'), $label . ': explicit failure redirect');
            bms_export_test_expect(!str_contains($headers, 'application/zip') && !str_contains($headers, 'content-disposition:') && !str_contains($headers, 'content-length:'), $label . ': no download headers');
            bms_export_test_expect($response['body'] === '', $label . ': no partial ZIP or diagnostics delivered');
            $form = bms_export_test_request($base, '/admin/export.php', $owner);
            bms_export_test_expect(str_contains($form['body'], 'Export failed. No download was created.'), $label . ': error shown to owner');
            $clean($label);
        };
        // All fault doubles are installed in the temporary application only.
        $faultClass = <<<'PHP'

class BmsExportFaultZip extends ZipArchive
{
    public function open(string $filename, int $flags = 0): bool|int
    {
        if (trim((string)@file_get_contents(__DIR__ . '/../fault.txt')) === 'open') { return ZipArchive::ER_OPEN; }
        return parent::open($filename, $flags);
    }
    public function close(): bool
    {
        $roots = glob(bms_static_site_export_root() . '/export-*') ?: [];
        $index = $roots !== [] ? $roots[0] . '/index.html' : '';
        $state = ['index_exists_before_close' => is_file($index)];
        $fault = trim((string)@file_get_contents(__DIR__ . '/../fault.txt'));
        if ($fault === 'missing-file' && is_file($index)) { unlink($index); }
        $result = $fault === 'close' ? false : parent::close();
        $state['close_succeeded'] = $result;
        $state['index_exists_after_close'] = is_file($index);
        file_put_contents(__DIR__ . '/../close-trace.json', json_encode($state));
        return $result;
    }
}
PHP;
        $instrumented = str_replace('$zip = new ZipArchive();', '$zip = new BmsExportFaultZip();', $originalExport, $replaced);
        if ($replaced !== 1) { throw new RuntimeException('Cannot instrument exact export constructor.'); }
        $instrumented = str_replace("bms_require_capability('view_system');", "bms_require_capability('view_system');\n" . $faultClass, $instrumented);
        foreach (['static', 'full'] as $kind) {
            rename($rendererPath, $rendererPath . '.held');
            try {
                $reject($kind, $kind . ' renderer unavailable');
                bms_export_test_expect($post('media')['status'] === 200, 'Missing renderer does not block Media export');
            } finally { rename($rendererPath . '.held', $rendererPath); }
            // Block the core renderer's Stream directory with a real file. Its
            // normal write helper must throw after creating partial output.
            $broken = str_replace('        bms_delete_directory($targetRoot);', "        bms_delete_directory(\$targetRoot);\n        bms_write_file(\$targetRoot . '/stream', 'blocked directory');", $originalRenderer, $replaced);
            if ($replaced !== 1) { throw new RuntimeException('Cannot instrument exact renderer directory preparation.'); }
            file_put_contents($rendererPath, $broken);
            try { $reject($kind, $kind . ' generation throws after partial output'); } finally { file_put_contents($rendererPath, $originalRenderer); }
            file_put_contents($rendererPath, str_replace("function bms_generate_static_site_export(?string \$targetRoot = null): int\n{", "function bms_generate_static_site_export(?string \$targetRoot = null): int\n{\n    return 0;", $originalRenderer));
            try { $reject($kind, $kind . ' generation returns without homepage'); } finally { file_put_contents($rendererPath, $originalRenderer); }
            file_put_contents($exportPath, $instrumented);
            foreach (['open', 'missing-file', 'close'] as $fault) {
                file_put_contents($temporary . '/fault.txt', $fault);
                $reject($kind, $kind . ' ZIP ' . $fault);
                if ($fault !== 'open') {
                    $trace = json_decode((string)file_get_contents($temporary . '/close-trace.json'), true);
                    bms_export_test_expect(($trace['index_exists_before_close'] ?? false) === true, $kind . ' ' . $fault . ': generated input survived until close');
                    bms_export_test_expect(($trace['close_succeeded'] ?? true) === false, $kind . ' ' . $fault . ': finalization failure actually observed');
                }
            }
            unlink($temporary . '/fault.txt');
            $inspect($post($kind), $kind, $kind . ' recovery after failure');
            $trace = json_decode((string)file_get_contents($temporary . '/close-trace.json'), true);
            bms_export_test_expect($trace === ['index_exists_before_close' => true, 'close_succeeded' => true, 'index_exists_after_close' => true], $kind . ': successful close completes before input cleanup');
            file_put_contents($exportPath, $originalExport);
        }
        // Empty Stream still produces the index, Page, feeds and discovery files.
        $pdo->exec("UPDATE `{$prefix}posts` SET status='draft' WHERE slug='public-post'");
        foreach (['static', 'full'] as $kind) {
            $response = $post($kind);
            file_put_contents($temporary . '/empty.zip', $response['body']);
            $archive = new ZipArchive();
            $opened = $archive->open($temporary . '/empty.zip', ZipArchive::CHECKCONS) === true;
            bms_export_test_expect($response['status'] === 200 && $opened, $kind . ': empty Stream archive succeeds');
            if ($opened) {
                foreach (['static/index.html', 'static/stream/index.html', 'static/pages/public-page/index.html', 'static/feed.xml', 'static/sitemap.xml'] as $entry) {
                    bms_export_test_expect($archive->locateName($entry) !== false, $kind . ': empty Stream includes ' . $entry);
                }
                bms_export_test_expect($archive->locateName('static/stream/public-post/index.html') === false && str_contains((string)$archive->getFromName('static/index.html'), 'No stream posts'), $kind . ': no fabricated Stream content');
                $archive->close();
            }
            unlink($temporary . '/empty.zip');
            $clean($kind . ' empty Stream');
        }
        $reject('../invalid', 'Invalid export type');
        // Restore the fixture for dynamic and import/export regression checks.
        $pdo->exec("UPDATE `{$prefix}posts` SET status='published' WHERE slug='public-post'");
        $seedNotice();
        $dynamic = bms_export_test_request($base, '/index.php', $owner);
        bms_export_test_expect($dynamic['status'] === 200 && str_contains($dynamic['body'], 'data-stream-quick-edit-form') && str_contains($dynamic['body'], 'PRIVATE SESSION NOTICE'), 'Normal authenticated dynamic rendering retains editing and private notices');
        $dynamic = bms_export_test_request($base, '/index.php?__bonumark_route=page&slug=public-page', $owner);
        bms_export_test_expect($dynamic['status'] === 200 && str_contains($dynamic['body'], 'Edit Page'), 'Normal authenticated dynamic Page retains editing');
        foreach (['tools', 'system-check', 'scheduled-tasks', 'export', 'import', 'import-markdown', 'upgrade', 'help'] as $route) {
            $response = bms_export_test_request($base, '/admin/' . $route . '.php', $owner);
            bms_export_test_expect($response['status'] === 200, 'System route remains available: ' . $route);
            $response = bms_export_test_request($base, '/admin/' . $route . '.php', $sessions['commenter']);
            bms_export_test_expect($response['status'] === 403, 'System route remains owner-only: ' . $route);
        }
        $before = (int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}posts`")->fetchColumn();
        $preview = bms_export_test_upload($base, $owner, $token, $roundTripBytes);
        bms_export_test_expect($preview['status'] === 200 && preg_match('/name="preview_token"\s+value="([^"]+)"/', $preview['body'], $match) === 1, 'Actual Markdown export can be uploaded to native importer preview');
        bms_export_test_expect((int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}posts`")->fetchColumn() === $before, 'Import preview does not commit content');
        if (empty($match[1])) { throw new RuntimeException('Missing import preview token.'); }
        $previewToken = $match[1];
        $fields = ['import_action' => 'import', 'preview_token' => $previewToken, 'target_status' => 'draft', 'duplicate_policy' => 'rename', 'media_policy' => 'remote', 'preserve_dates' => '1', 'range_start' => '1', 'batch_size' => '0'];
        $denied = bms_export_test_request($base, '/admin/import.php', $owner, ['csrf_token' => 'invalid'] + $fields);
        bms_export_test_expect($denied['status'] === 403 && (int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}posts`")->fetchColumn() === $before, 'Import confirmation still requires valid CSRF');
        $imported = bms_export_test_request($base, '/admin/import.php', $owner, ['csrf_token' => $token] + $fields);
        $count = (int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}posts`")->fetchColumn();
        bms_export_test_expect($imported['status'] === 302 && $count > $before, 'Explicit confirmation imports exported Markdown through existing importer');
        bms_export_test_expect((int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}posts` WHERE content_body LIKE '%faithful words%' AND status='draft'")->fetchColumn() > 0, 'Markdown round trip retains content in database records');
        bms_export_test_expect((glob($temporary . '/_bonumark_stream/import-staging/previews/*') ?: []) === [], 'Confirmed import cleans private preview files');
    }
    bms_export_test_expect((int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}migrations`")->fetchColumn() === $migrationCount, 'Export adds no migrations');
    $report['completed'] = true;
} finally {
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
    if ($pdo instanceof PDO) {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
            if (str_starts_with((string)$table, $prefix)) {
                $pdo->exec('DROP TABLE `' . str_replace('`', '``', (string)$table) . '`');
            }
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }
    bms_export_test_remove_tree($temporary);
    bms_export_test_expect(!is_dir($temporary), 'Disposable installation removed');
    if ($pdo instanceof PDO) {
        $remaining = array_filter($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN), static fn($table) => str_starts_with($table, $prefix));
        bms_export_test_expect($remaining === [], 'Only task tables removed, none remain');
    }
    $report['passed'] = $checks;
    $report['failed'] = $failures;
    $reportPath = (string)getenv('BMS_EXPORT_TEST_REPORT');
    if ($reportPath !== '') { file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); }
}

fwrite(STDOUT, 'Export archive regression: ' . $checks . ' passed, ' . count($failures) . " failed.\n");
exit($failures === [] ? 0 : 1);

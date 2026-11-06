<?php
/**
 * Admin media HTTP contract regression test against a disposable install.
 *
 * Uses BMS_DB_HOST, BMS_DB_NAME, BMS_DB_USER, BMS_DB_PASS and requires
 * BMS_DB_DANGER_RESET=1. Only random bms_media_ci_* tables are created and
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

$source = dirname(__DIR__);
$temporary = sys_get_temp_dir() . '/bms-media-test-' . bin2hex(random_bytes(6));
$prefix = 'bms_media_ci_' . bin2hex(random_bytes(5)) . '_';
$process = null;
$pdo = null;
$checks = 0;

function bms_media_test_expect(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
    $GLOBALS['checks']++;
    fwrite(STDOUT, 'PASS ' . $label . "\n");
}

function bms_media_test_request(string $base, string $path, array &$cookies, ?array $form = null, ?array $upload = null): array
{
    $headers = ['User-Agent: Bonumark Media Regression', 'Connection: close'];
    if ($cookies !== []) {
        $headers[] = 'Cookie: ' . implode('; ', array_map(static fn(string $key, string $value): string => $key . '=' . $value, array_keys($cookies), $cookies));
    }
    $options = ['method' => $form === null ? 'GET' : 'POST', 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 10];
    if ($form !== null) {
        if ($upload !== null) {
            $boundary = 'bms-media-test-' . bin2hex(random_bytes(8));
            $headers[] = 'Content-Type: multipart/form-data; boundary=' . $boundary;
            $body = '';
            foreach ($form as $key => $value) {
                $body .= '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"" . $key . "\"\r\n\r\n" . $value . "\r\n";
            }
            $body .= '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"media_file\"; filename=\"" . $upload['name'] . "\"\r\nContent-Type: " . $upload['type'] . "\r\n\r\n" . $upload['bytes'] . "\r\n--" . $boundary . "--\r\n";
            $options['content'] = $body;
        } else {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            $options['content'] = http_build_query($form);
        }
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
    return ['status' => (int)($status[1] ?? 0), 'body' => $body, 'location' => $location];
}

function bms_media_test_token(string $html): string
{
    if (!preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $match)) {
        throw new RuntimeException('Missing CSRF token in rendered form.');
    }
    return html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
}

/** Return only the selected media landmark, never similarly named page media. */
function bms_media_test_landmark(string $html, string $class): string
{
    preg_match_all('/<nav\b([^>]*)>(.*?)<\/nav>/s', $html, $matches, PREG_SET_ORDER);
    foreach ($matches as $match) {
        $attributes = bms_media_test_attributes($match[1]);
        if (in_array($class, explode(' ', $attributes['class'] ?? ''), true)) {
            return $match[2];
        }
    }
    throw new RuntimeException('Missing media landmark: ' . $class);
}

function bms_media_test_attributes(string $markup): array
{
    preg_match_all('/([a-zA-Z-]+)="([^"]*)"/', $markup, $matches, PREG_SET_ORDER);
    $attributes = [];
    foreach ($matches as $match) {
        $attributes[$match[1]] = html_entity_decode($match[2], ENT_QUOTES, 'UTF-8');
    }
    return $attributes;
}

function bms_media_test_links(string $html, string $class = ''): array
{
    preg_match_all('/<a\b([^>]*)>(.*?)<\/a>/s', $html, $matches, PREG_SET_ORDER);
    $links = [];
    foreach ($matches as $match) {
        $attributes = bms_media_test_attributes($match[1]);
        if ($class !== '' && !in_array($class, explode(' ', $attributes['class'] ?? ''), true)) {
            continue;
        }
        $attributes['label'] = trim(html_entity_decode(strip_tags($match[2]), ENT_QUOTES, 'UTF-8'));
        $links[] = $attributes;
    }
    return $links;
}

function bms_media_test_ids(string $html): array
{
    preg_match_all('/<input\b[^>]*name="media_ids\[\]"[^>]*value="([^"]+)"/', $html, $matches);
    return array_map('intval', $matches[1]);
}

function bms_media_test_row(PDO $pdo, string $prefix, int $id): array
{
    $statement = $pdo->prepare("SELECT * FROM `{$prefix}media` WHERE id=?");
    $statement->execute([$id]);
    return $statement->fetch() ?: [];
}

function bms_media_test_remove_tree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($path);
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
    mkdir($temporary . '/media', 0700);
    $listener = stream_socket_server('tcp://127.0.0.1:0', $error, $errorMessage);
    if ($listener === false) {
        throw new RuntimeException('Cannot reserve a loopback port: ' . $errorMessage);
    }
    $address = stream_socket_get_name($listener, false);
    fclose($listener);
    $base = 'http://' . $address;
    $config = [
        'site_name' => 'Media Fixture', 'site_tagline' => 'Saved tagline',
        'site_admin_email' => 'saved@example.test', 'timezone' => 'America/Indiana/Indianapolis',
        'stream_published_at_utc_cutover' => '2025-01-01 00:00:00',
        'base_url' => $base, 'base_path' => '', 'security_salt' => bin2hex(random_bytes(24)),
        'scheduled_tasks_public_traffic_enabled' => '0', 'scheduled_tasks_heartbeat_enabled' => '0',
        'media_upload_limit_mb' => '1', 'activitypub_enabled' => '0', 'pwa_enabled' => '0', 'mail_transport' => 'disabled',
        'database' => ['host' => (string)getenv('BMS_DB_HOST'), 'name' => (string)getenv('BMS_DB_NAME'),
            'user' => (string)getenv('BMS_DB_USER'), 'password' => (string)getenv('BMS_DB_PASS'), 'charset' => 'utf8mb4', 'prefix' => $prefix],
    ];
    file_put_contents($temporary . '/_bonumark_stream/config.php', "<?php\nreturn " . var_export($config, true) . ";\n");
    touch($temporary . '/_bonumark_stream/installed.lock');
    require $temporary . '/_bonumark_stream/app/database.php';
    require $temporary . '/_bonumark_stream/app/media.php';
    $pdo = bms_db();
    bms_media_test_expect(!$pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES), 'Media search uses native PDO prepared statements');
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
    $process = proc_open([PHP_BINARY, '-d', 'upload_max_filesize=4M', '-d', 'post_max_size=5M', '-d', 'session.save_path=' . $temporary . '/sessions', '-S', $address, '-t', $temporary], [0 => ['pipe', 'r'], 1 => ['file', $temporary . '/http.log', 'a'], 2 => ['file', $temporary . '/http.log', 'a']], $pipes, $temporary);
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
    bms_media_test_expect($ready, 'Isolated loopback server starts');
    fwrite(STDOUT, 'Runtime: PHP ' . PHP_VERSION . ' / ' . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . "\n");

    $guest = [];
    $sessions = [];
    foreach (['owner', 'commenter'] as $username) {
        $cookies = [];
        $login = bms_media_test_request($base, '/admin/login.php', $cookies);
        $login = bms_media_test_request($base, '/admin/login.php', $cookies, ['csrf_token' => bms_media_test_token($login['body']), 'username' => $username, 'password' => 'disposable-password']);
        bms_media_test_expect($login['status'] === 302, ucfirst($username) . ' fixture authenticates through real login');
        $sessions[$username] = $cookies;
    }


    $owner =& $sessions['owner'];
    $empty = bms_media_test_request($base, '/admin/media.php', $owner);
    bms_media_test_expect($empty['status'] === 200 && str_contains($empty['body'], 'No media yet'), 'Empty library renders a useful empty state');
    $emptyTrash = bms_media_test_request($base, '/admin/media.php?status=trash', $owner);
    bms_media_test_expect($emptyTrash['status'] === 200 && str_contains(strtolower($emptyTrash['body']), 'trash is empty'), 'Empty Trash renders its distinct empty state');
    $uploadPage = bms_media_test_request($base, '/admin/media-upload.php', $owner);
    $token = bms_media_test_token($uploadPage['body']);
    bms_media_test_expect(str_contains($uploadPage['body'], 'Maximum size: 1 MB') && str_contains($uploadPage['body'], bms_allowed_media_accept_attribute()), 'Upload destination preserves accepted types and configured size limit');

    $ownerId = (int)$pdo->query("SELECT id FROM `{$prefix}users` WHERE username='owner'")->fetchColumn();
    $commenterId = (int)$pdo->query("SELECT id FROM `{$prefix}users` WHERE username='commenter'")->fetchColumn();
    $longName = str_repeat('Long-original-file-name-', 8) . 'final-marker.png';
    $longCaption = str_repeat('Caption with <markup> & quoted "metadata". ', 10);
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jP1sAAAAASUVORK5CYII=');
    $seed = $pdo->prepare("INSERT INTO `{$prefix}media` (filename,original_filename,public_path,mime_type,file_size,width,height,alt_text,caption,uploaded_by,privacy_status,privacy_note,image_variants_json,trashed_at,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $fixtures = [
        ['clean', 'amber-photo.png', 'image/png', 'Amber accessibility marker', 'First caption', 'cleaned', '', null],
        ['warning', $longName, 'image/png', 'Cobalt alt marker', $longCaption, 'unconfirmed', 'Privacy warning marker: removal could not be confirmed.', null],
        ['document', 'quartz-notes.txt', 'text/plain', 'Document description', 'Document caption', 'not_applicable', '', null],
        ['legacy', 'legacy-photo.png', 'image/png', '', '', 'legacy_unchecked', '', null],
        ['unknown', 'unknown-photo.png', 'image/png', '', '', 'unknown', '', null],
        ['trash', 'trashed-photo.png', 'image/png', 'Trashed image', 'Trashed caption', 'cleaned', '', '2026-01-10 12:00:00'],
    ];
    $ids = [];
    foreach ($fixtures as $n => [$key, $name, $mime, $alt, $caption, $privacy, $note, $trashDate]) {
        $filename = 'bms-' . str_pad((string)($n + 1), 24, '0', STR_PAD_LEFT) . ($mime === 'text/plain' ? '.txt' : '.png');
        $relative = 'media/' . $filename;
        file_put_contents($temporary . '/' . $relative, $mime === 'text/plain' ? "Synthetic document.\n" : $png);
        $seed->execute([$filename, $name, $relative, $mime, filesize($temporary . '/' . $relative), $mime === 'text/plain' ? null : 1, $mime === 'text/plain' ? null : 1, $alt, $caption, $key === 'document' ? $commenterId : $ownerId, $privacy, $note, null, $trashDate, sprintf('2026-01-%02d 12:00:00', $n + 1), sprintf('2026-01-%02d 12:00:00', $n + 1)]);
        $ids[$key] = (int)$pdo->lastInsertId();
    }
    $active = bms_media_test_request($base, '/admin/media.php', $owner);
    $trash = bms_media_test_request($base, '/admin/media.php?status=trash', $owner);
    bms_media_test_expect(bms_media_test_ids($active['body']) === array_reverse(array_values(array_diff_key($ids, ['trash' => true]))), 'Active view retains created-date ordering and excludes Trash');
    bms_media_test_expect(bms_media_test_ids($trash['body']) === [$ids['trash']], 'Trash view contains only trashed records');
    bms_media_test_expect(in_array($ids['document'], bms_media_test_ids($active['body']), true), 'Sole publisher retains management of media uploaded by any account');
    $views = bms_media_test_links(bms_media_test_landmark($active['body'], 'status-tabs'));
    bms_media_test_expect(array_map(static fn(array $link): string => preg_replace('/\s+/', ' ', $link['label']), $views) === ['Active 5', 'Trash 1'], 'Active and Trash counts reflect all records in each status');
    bms_media_test_expect(($views[0]['aria-current'] ?? '') === 'page' && !isset($views[1]['aria-current']), 'Current view is exposed semantically');
    bms_media_test_expect(preg_match_all('/<h1\b/', $active['body']) === 1 && preg_match('/<h1[^>]*>Media<\/h1>/', $active['body']) === 1, 'Library has one clear Media heading');
    bms_media_test_expect(str_contains($active['body'], '<label for="media_search">Search media</label>') && str_contains($active['body'], '<label for="bulk_action">Bulk action</label>'), 'Search and bulk actions have visible native labels');
    bms_media_test_expect(str_contains($active['body'], htmlspecialchars($longName, ENT_QUOTES, 'UTF-8')) && str_contains($active['body'], htmlspecialchars($longCaption, ENT_QUOTES, 'UTF-8')), 'Long original filenames and metadata are preserved and escaped');
    bms_media_test_expect(!str_contains($active['body'], '<markup>'), 'Caption markup cannot become HTML');
    bms_media_test_expect(str_contains($active['body'], 'media-library-file-badge') && str_contains($active['body'], 'Text'), 'Non-image file has an identifiable type representation');
    foreach (['Metadata removed', 'Metadata warning', 'Filename randomized', 'Legacy file', 'Not checked'] as $label) {
        bms_media_test_expect(str_contains($active['body'], $label), 'Privacy presentation retains ' . $label);
    }
    foreach (['Privacy warning marker', 'before media privacy status was tracked', 'Metadata status was not confirmed'] as $note) {
        bms_media_test_expect(str_contains($active['body'], $note), 'Details data exposes privacy explanation: ' . $note);
    }
    foreach (['data-media-detail-alt', 'data-media-detail-caption', 'data-media-detail-markdown', 'data-media-detail-copy', 'data-media-detail-edit', 'data-media-detail-view', 'data-media-details-close'] as $hook) {
        bms_media_test_expect(str_contains($active['body'], $hook), 'Details retains supported control ' . $hook);
    }
    $details = array_filter(bms_media_test_links($active['body']), static fn(array $link): bool => str_contains($link['class'] ?? '', 'media-library-details-button'));
    bms_media_test_expect(count($details) === 5 && count(array_filter($details, static fn(array $link): bool => str_starts_with($link['href'], '/admin/media-edit.php?id='))) === 5, 'Details offers a native full-editor fallback without JavaScript');
    bms_media_test_expect(!str_contains($active['body'], 'value="delete_permanently"') && str_contains($trash['body'], 'value="restore"') && str_contains($trash['body'], 'value="delete_permanently"'), 'Irreversible deletion is available only in Trash controls');
    bms_media_test_expect(str_contains($trash['body'], 'data-confirm=') && str_contains(strtolower($trash['body']), 'cannot be undone'), 'Trash retains explicit permanent-deletion warning and confirmation hook');
    bms_media_test_expect(str_contains($trash['body'], 'data-media-markdown=""'), 'Trashed items do not offer insertion Markdown in library details');

    foreach (['media-upload.php', 'media-regenerate.php', 'media-edit.php?id=' . $ids['clean']] as $route) {
        $response = bms_media_test_request($base, '/admin/' . $route, $owner);
        bms_media_test_expect($response['status'] === 200, 'Existing ' . $route . ' destination remains operational');
    }
    $invalid = bms_media_test_request($base, '/admin/media.php?status=invalid', $owner);
    bms_media_test_expect(bms_media_test_ids($invalid['body']) === bms_media_test_ids($active['body']), 'Invalid status retains existing Active default');
    $all = bms_media_test_request($base, '/admin/media.php?status=all', $owner);
    bms_media_test_expect(count(bms_media_test_ids($all['body'])) === 6, 'Existing all-status retrieval remains supported');

    // Positive searches must return real records. An empty result from a
    // swallowed query error must never count as a successful matching search.
    $beforeSearch = $pdo->query("SELECT * FROM `{$prefix}media` ORDER BY id")->fetchAll();
    foreach (['AMBER' => $ids['clean'], 'cobalt' => $ids['warning'], 'quartz' => $ids['document'], 'First caption' => $ids['clean'], 'bms-' . str_pad('3', 24, '0', STR_PAD_LEFT) . '.txt' => $ids['document']] as $query => $id) {
        $response = bms_media_test_request($base, '/admin/media.php?s=' . rawurlencode(' ' . $query . ' '), $owner);
        bms_media_test_expect($response['status'] === 200 && str_contains($response['body'], 'id="media_search"') && str_contains($response['body'], 'aria-label="Media views"') && str_contains($response['body'], 'value="' . htmlspecialchars($query, ENT_QUOTES, 'UTF-8') . '"'), 'Matching search returns a valid rendered form with trimmed query: ' . $query);
        bms_media_test_expect(bms_media_test_ids($response['body']) === [$id] && !str_contains($response['body'], 'No matching media'), 'Trimmed case-insensitive search returns expected match for ' . $query);
    }
    foreach (['original filename' => 'TRASHED-PHOTO.PNG', 'filename' => 'bms-' . str_pad('6', 24, '0', STR_PAD_LEFT) . '.png', 'alt text' => 'Trashed image', 'caption' => 'Trashed caption'] as $field => $query) {
        $response = bms_media_test_request($base, '/admin/media.php?status=trash&s=' . rawurlencode(' ' . $query . ' '), $owner);
        bms_media_test_expect($response['status'] === 200 && bms_media_test_ids($response['body']) === [$ids['trash']] && !str_contains($response['body'], 'No matching media'), 'Trash search matches ' . $field . ' with native prepares');
        $response = bms_media_test_request($base, '/admin/media.php?status=active&s=' . rawurlencode($query), $owner);
        bms_media_test_expect($response['status'] === 200 && bms_media_test_ids($response['body']) === [] && str_contains($response['body'], 'No matching media'), 'Active search excludes trashed ' . $field . ' match');
    }
    foreach (['AMBER', 'cobalt', 'quartz', 'First caption', 'bms-' . str_pad('3', 24, '0', STR_PAD_LEFT) . '.txt'] as $query) {
        $response = bms_media_test_request($base, '/admin/media.php?status=trash&s=' . rawurlencode($query), $owner);
        bms_media_test_expect($response['status'] === 200 && bms_media_test_ids($response['body']) === [] && str_contains($response['body'], 'No matching media'), 'Trash search excludes active match for ' . $query);
    }
    foreach (['active', 'trash'] as $status) {
        foreach (['missing-fixture', "' OR 1=1 --"] as $query) {
            $noMatch = bms_media_test_request($base, '/admin/media.php?status=' . $status . '&s=' . rawurlencode($query), $owner);
            bms_media_test_expect($noMatch['status'] === 200 && bms_media_test_ids($noMatch['body']) === [] && str_contains($noMatch['body'], 'No matching media'), ucfirst($status) . ' nonmatching search returns the distinct no-match state: ' . $query);
        }
    }
    foreach ([['active', 'a%ber', [$ids['clean']]], ['active', 'co_alt', [$ids['warning']]], ['trash', 'trashed%caption', [$ids['trash']]], ['all', 'photo.png', [$ids['trash'], $ids['unknown'], $ids['legacy'], $ids['clean']]]] as [$status, $query, $expectedIds]) {
        $response = bms_media_test_request($base, '/admin/media.php?status=' . $status . '&s=' . rawurlencode($query), $owner);
        bms_media_test_expect($response['status'] === 200 && bms_media_test_ids($response['body']) === $expectedIds, 'Search preserves LIKE wildcards, status and date ordering: ' . $status . ' / ' . $query);
    }
    bms_media_test_expect($beforeSearch === $pdo->query("SELECT * FROM `{$prefix}media` ORDER BY id")->fetchAll(), 'Searches preserve every media record');
    $clear = array_filter(bms_media_test_links($noMatch['body']), static fn(array $link): bool => $link['label'] === 'Clear search');
    bms_media_test_expect($clear !== [] && count(array_filter($clear, static fn(array $link): bool => $link['href'] === '/admin/media.php?status=trash')) === count($clear), 'Clear search retains the current Trash view');

    $snapshot = $pdo->query("SELECT * FROM `{$prefix}media` ORDER BY id")->fetchAll();
    foreach ([['', [$ids['clean']], 'Choose a bulk media action.'], ['trash', [], 'Select at least one media item first.'], ['invalid', [$ids['clean']], 'Unsupported bulk media action.']] as [$action, $selected, $message]) {
        $response = bms_media_test_request($base, '/admin/media.php', $owner, ['csrf_token' => $token, 'bulk_action' => $action, 'media_ids' => $selected]);
        $feedback = bms_media_test_request($base, '/admin/media.php', $owner);
        bms_media_test_expect($response['status'] === 302 && str_contains($feedback['body'], $message), 'Invalid/empty bulk request reports ' . $message);
    }
    bms_media_test_expect($snapshot === $pdo->query("SELECT * FROM `{$prefix}media` ORDER BY id")->fetchAll(), 'Invalid bulk requests preserve every media record');
    $response = bms_media_test_request($base, '/admin/media.php', $owner, ['csrf_token' => $token, 'bulk_action' => 'delete_permanently', 'media_ids' => [$ids['clean']]]);
    $feedback = bms_media_test_request($base, '/admin/media.php', $owner);
    bms_media_test_expect($response['status'] === 302 && str_contains($feedback['body'], 'Move this media item to trash before permanent deletion.') && bms_media_test_row($pdo, $prefix, $ids['clean']) !== [], 'Forged permanent-delete request cannot delete an active item');

    // Retention uses actual original and recorded/generated files, never owner data.
    $original = $temporary . '/' . bms_media_test_row($pdo, $prefix, $ids['clean'])['public_path'];
    mkdir($temporary . '/media/_generated', 0700, true);
    $recordedPath = 'media/_generated/work29-recorded.png';
    $generatedPath = bms_media_generated_relative_path(bms_media_test_row($pdo, $prefix, $ids['clean'])['public_path'], 320);
    if (!is_dir(dirname($temporary . '/' . $generatedPath))) {
        mkdir(dirname($temporary . '/' . $generatedPath), 0700, true);
    }
    file_put_contents($temporary . '/' . $recordedPath, $png);
    file_put_contents($temporary . '/' . $generatedPath, $png);
    $pdo->prepare("UPDATE `{$prefix}media` SET image_variants_json=? WHERE id=?")->execute([json_encode(['small' => ['path' => $recordedPath]]), $ids['clean']]);
    $originalHash = hash_file('sha256', $original);
    $response = bms_media_test_request($base, '/admin/media.php', $owner, ['csrf_token' => $token, 'bulk_action' => 'trash', 'media_ids' => [$ids['clean'], $ids['clean'], 999999], 'status' => 'active', 's' => 'preserved search']);
    bms_media_test_expect($response['status'] === 302 && $response['location'] === '/admin/media.php?status=active&s=preserved%20search', 'Bulk redirect preserves search and view state');
    $feedback = bms_media_test_request($base, '/admin/media.php', $owner);
    bms_media_test_expect(str_contains($feedback['body'], '1 media item(s) moved to trash.') && str_contains($feedback['body'], '1 media item(s) could not be processed.'), 'Partial failure reports successful and failed counts; duplicate IDs are deduplicated');
    bms_media_test_expect(bms_media_test_row($pdo, $prefix, $ids['clean'])['trashed_at'] !== null && hash_file('sha256', $original) === $originalHash && is_file($temporary . '/' . $recordedPath) && is_file($temporary . '/' . $generatedPath), 'Trash retains the original and every generated derivative');
    $picker = bms_media_test_request($base, '/admin/media-picker.php', $owner);
    $pickerData = json_decode($picker['body'], true);
    bms_media_test_expect($picker['status'] === 200 && is_array($pickerData), 'Existing composer media picker remains operational');
    $pickerIds = array_column($pickerData['items'] ?? [], 'id');
    bms_media_test_expect(!in_array($ids['clean'], array_map('intval', $pickerIds), true), 'Trashed media is excluded from composer picker');
    $response = bms_media_test_request($base, '/admin/media.php?status=trash', $owner, ['csrf_token' => $token, 'bulk_action' => 'restore', 'media_ids' => [$ids['clean']]]);
    bms_media_test_expect($response['status'] === 302 && bms_media_test_row($pdo, $prefix, $ids['clean'])['trashed_at'] === null && hash_file('sha256', $original) === $originalHash, 'Restore returns the selected original without file changes');

    $editorPath = '/admin/media-edit.php?id=' . $ids['clean'];
    $response = bms_media_test_request($base, $editorPath, $owner, ['csrf_token' => $token, 'action' => 'save', 'alt_text' => 'Saved alt text', 'caption' => 'Saved caption']);
    $saved = bms_media_test_row($pdo, $prefix, $ids['clean']);
    bms_media_test_expect($response['status'] === 302 && $saved['alt_text'] === 'Saved alt text' && $saved['caption'] === 'Saved caption', 'Existing full editor persists alt text and caption');
    $response = bms_media_test_request($base, $editorPath, $owner, ['csrf_token' => $token, 'action' => 'save', 'alt_text' => str_repeat('x', 256), 'caption' => 'Rejected caption']);
    bms_media_test_expect($response['status'] === 422 && str_contains($response['body'], '255 characters or fewer') && bms_media_test_row($pdo, $prefix, $ids['clean'])['caption'] === 'Saved caption', 'Invalid metadata preserves entered form feedback and stored values');
    $response = bms_media_test_request($base, $editorPath, $owner, ['csrf_token' => $token, 'action' => 'trash']);
    bms_media_test_expect($response['status'] === 302 && is_file($original), 'Editor Trash preserves the original');
    $response = bms_media_test_request($base, $editorPath, $owner, ['csrf_token' => $token, 'action' => 'save', 'alt_text' => 'Forbidden edit', 'caption' => 'Forbidden edit']);
    bms_media_test_expect($response['status'] === 422 && bms_media_test_row($pdo, $prefix, $ids['clean'])['alt_text'] === 'Saved alt text', 'Trashed item cannot be edited before restoration');
    $response = bms_media_test_request($base, $editorPath, $owner, ['csrf_token' => $token, 'action' => 'restore']);
    bms_media_test_expect($response['status'] === 302 && bms_media_test_row($pdo, $prefix, $ids['clean'])['trashed_at'] === null, 'Editor Restore retains original record identity');
    bms_media_test_request($base, '/admin/media.php', $owner, ['csrf_token' => $token, 'bulk_action' => 'trash', 'media_ids' => [$ids['clean']]]);
    $response = bms_media_test_request($base, '/admin/media.php?status=trash', $owner, ['csrf_token' => $token, 'bulk_action' => 'delete_permanently', 'media_ids' => [$ids['clean']]]);
    // The HTTP server removes files in a different PHP process.
    clearstatcache();
    bms_media_test_expect($response['status'] === 302 && bms_media_test_row($pdo, $prefix, $ids['clean']) === [] && !is_file($original) && !is_file($temporary . '/' . $recordedPath) && !is_file($temporary . '/' . $generatedPath), 'Permanent deletion removes only selected trashed record, original and derivatives');
    bms_media_test_expect(bms_media_test_row($pdo, $prefix, $ids['warning'])['trashed_at'] === null, 'Unselected media survives every bulk action');

    // Real multipart requests verify the existing upload endpoint and privacy path.
    $response = bms_media_test_request($base, '/admin/media-upload.php', $owner, ['csrf_token' => $token, 'alt_text' => 'Uploaded description', 'caption' => 'Uploaded caption'], ['name' => 'private-original-name.txt', 'type' => 'text/plain', 'bytes' => "Synthetic upload.\n"]);
    bms_media_test_expect($response['status'] === 302 && str_contains($response['location'], 'media-edit.php?id='), 'Valid non-image multipart upload reaches its item editor');
    $uploaded = $pdo->query("SELECT * FROM `{$prefix}media` ORDER BY id DESC LIMIT 1")->fetch();
    bms_media_test_expect(preg_match('/^bms-[a-f0-9]{24}\.txt$/', $uploaded['filename']) === 1 && $uploaded['original_filename'] === 'private-original-name.txt' && !str_contains($uploaded['public_path'], 'private-original-name'), 'Upload randomizes public filename while retaining original name privately');
    bms_media_test_expect($uploaded['privacy_status'] === 'not_applicable' && $uploaded['alt_text'] === 'Uploaded description' && $uploaded['caption'] === 'Uploaded caption', 'Non-image upload retains metadata and reports its privacy status accurately');
    foreach ([['rejected.php', 'text/plain', '<?php echo 1;', 'Unsupported media type'], ['invalid.png', 'image/png', 'not an image', 'valid image'], ['too-large.txt', 'text/plain', str_repeat('x', 1048577), 'too large']] as [$name, $type, $bytes, $message]) {
        $beforeCount = (int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}media`")->fetchColumn();
        $response = bms_media_test_request($base, '/admin/media-upload.php', $owner, ['csrf_token' => $token], ['name' => $name, 'type' => $type, 'bytes' => $bytes]);
        bms_media_test_expect($response['status'] === 422 && str_contains($response['body'], $message) && (int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}media`")->fetchColumn() === $beforeCount, 'Upload validation rejects ' . $name . ' without a record');
    }
    if (function_exists('imagecreatetruecolor') && function_exists('imagejpeg')) {
        $image = imagecreatetruecolor(1800, 900);
        imagefill($image, 0, 0, imagecolorallocate($image, 40, 100, 120));
        ob_start();
        imagejpeg($image, null, 88);
        $jpeg = ob_get_clean();
        imagedestroy($image);
        // Add a JPEG comment marker. Re-encoding should remove this marker.
        $comment = 'Synthetic-private-metadata-marker';
        $jpeg = substr($jpeg, 0, 2) . "\xff\xfe" . pack('n', strlen($comment) + 2) . $comment . substr($jpeg, 2);
        $response = bms_media_test_request($base, '/admin/media-upload.php', $owner, ['csrf_token' => $token, 'alt_text' => 'Wide synthetic image', 'caption' => 'Image caption'], ['name' => 'synthetic-camera-photo.jpg', 'type' => 'image/jpeg', 'bytes' => $jpeg]);
        bms_media_test_expect($response['status'] === 302, 'Valid image multipart upload completes');
        $imageRow = $pdo->query("SELECT * FROM `{$prefix}media` ORDER BY id DESC LIMIT 1")->fetch();
        $imageFile = $temporary . '/' . $imageRow['public_path'];
        bms_media_test_expect($imageRow['privacy_status'] === 'cleaned' && !str_contains(file_get_contents($imageFile), $comment), 'Image upload re-encodes and removes synthetic metadata marker');
        $variants = json_decode($imageRow['image_variants_json'] ?? '', true) ?: [];
        bms_media_test_expect(count($variants) > 0 && is_file($imageFile), 'Image upload preserves original and records generated derivatives');
        foreach ($variants as $variant) {
            bms_media_test_expect(is_file($temporary . '/' . $variant['path']), 'Recorded image derivative exists: ' . basename($variant['path']));
        }
        $imageHash = hash_file('sha256', $imageFile);
        $response = bms_media_test_request($base, '/admin/media-edit.php?id=' . $imageRow['id'], $owner, ['csrf_token' => $token, 'action' => 'regenerate_variants']);
        bms_media_test_expect($response['status'] === 302 && hash_file('sha256', $imageFile) === $imageHash, 'Individual variant refresh preserves the original file');
        $response = bms_media_test_request($base, '/admin/media-regenerate.php', $owner, ['csrf_token' => $token, 'mode' => 'all', 'after_id' => (int)$imageRow['id'] - 1]);
        bms_media_test_expect($response['status'] === 200 && str_contains($response['body'], 'Image optimization batch complete') && hash_file('sha256', $imageFile) === $imageHash, 'Existing batch optimization preserves original files');
    } else {
        fwrite(STDOUT, "CAPABILITY SKIP JPEG re-encoding and generated-variant checks: GD JPEG unavailable.\n");
    }

    $beforeSecurity = $pdo->query("SELECT * FROM `{$prefix}media` ORDER BY id")->fetchAll();
    foreach ([['/admin/media.php', ['bulk_action' => 'trash', 'media_ids' => [$ids['warning']]]], ['/admin/media-edit.php?id=' . $ids['warning'], ['action' => 'save', 'alt_text' => 'Rejected']], ['/admin/media-regenerate.php', ['mode' => 'all']], ['/admin/media-upload.php', []]] as [$path, $form]) {
        foreach (['invalid', ''] as $badToken) {
            $response = bms_media_test_request($base, $path, $owner, ['csrf_token' => $badToken] + $form);
            bms_media_test_expect($response['status'] === 403, $path . ' rejects ' . ($badToken === '' ? 'missing' : 'invalid') . ' CSRF token');
        }
        $response = bms_media_test_request($base, $path, $sessions['commenter'], ['csrf_token' => $token] + $form);
        bms_media_test_expect($response['status'] === 403, $path . ' denies commenter mutation');
    }
    foreach (['/admin/media.php', '/admin/media.php?status=active&s=cobalt', '/admin/media.php?status=trash&s=Trashed%20caption', '/admin/media-upload.php', '/admin/media-regenerate.php', '/admin/media-edit.php?id=' . $ids['warning'], '/admin/media-picker.php'] as $path) {
        $response = bms_media_test_request($base, $path, $guest);
        bms_media_test_expect($response['status'] === 302 && str_ends_with($response['location'], '/admin/login.php'), $path . ' requires authenticated access');
        $response = bms_media_test_request($base, $path, $sessions['commenter']);
        bms_media_test_expect($response['status'] === 403, $path . ' denies commenter access');
    }
    bms_media_test_expect($beforeSecurity === $pdo->query("SELECT * FROM `{$prefix}media` ORDER BY id")->fetchAll(), 'Rejected requests preserve all media records');
    $response = bms_media_test_request($base, '/admin/media-edit.php?id=999999', $owner);
    bms_media_test_expect($response['status'] === 404, 'Unknown media editor ID returns 404');
    $public = bms_media_test_request($base, '/', $guest);
    bms_media_test_expect($public['status'] === 200 && !str_contains($public['body'], 'assets/admin-media-library.css') && !str_contains($public['body'], 'assets/admin-shell.css'), 'Public frontend does not load Media or Admin presentation assets');

    // The library intentionally retains the existing 200-record retrieval cap.
    // Normalize earlier upload dates so this ordering check is clock-independent.
    $pdo->exec("UPDATE `{$prefix}media` SET created_at='2026-01-01 12:00:00'");
    for ($n = 1; $n <= 205; $n++) {
        $seed->execute(['limit-' . $n . '.txt', 'Limit fixture ' . $n . '.txt', 'media/limit-' . $n . '.txt', 'text/plain', 1, null, null, '', '', $ownerId, 'not_applicable', '', null, null, '2026-02-01 12:00:00', '2026-02-01 12:00:00']);
    }
    $response = bms_media_test_request($base, '/admin/media.php', $owner);
    $shown = bms_media_test_ids($response['body']);
    $latestId = (int)$pdo->lastInsertId();
    bms_media_test_expect(count($shown) === 200 && $shown[0] === $latestId && $shown[199] === $latestId - 199, 'Library preserves the 200-item cap and newest-ID ordering for equal dates');
    $counts = bms_media_test_links(bms_media_test_landmark($response['body'], 'status-tabs'));
    $activeTotal = (int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}media` WHERE trashed_at IS NULL")->fetchColumn();
    bms_media_test_expect(str_contains($counts[0]['label'], (string)$activeTotal) && $activeTotal > 200, 'Active view count reflects all matching status records beyond displayed cap');
    foreach (['active', 'trash'] as $status) {
        if ($status === 'trash') {
            $pdo->exec("UPDATE `{$prefix}media` SET trashed_at='2026-03-01 12:00:00' WHERE filename LIKE 'limit-%'");
        }
        $response = bms_media_test_request($base, '/admin/media.php?status=' . $status . '&s=Limit%20fixture', $owner);
        bms_media_test_expect($response['status'] === 200 && bms_media_test_ids($response['body']) === range($latestId, $latestId - 199), ucfirst($status) . ' matching search preserves the 200-item cap and newest-ID ordering for equal dates');
    }
    bms_media_test_expect((int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}migrations`")->fetchColumn() === $migrationCount, 'Media workflows introduce no migrations');
    fwrite(STDOUT, 'Admin Media runtime regression passed: ' . $checks . " checks.\n");

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
    bms_media_test_remove_tree($temporary);
}

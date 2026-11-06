<?php
/**
 * Admin content HTTP contract regression test against a disposable install.
 *
 * Uses BMS_DB_HOST, BMS_DB_NAME, BMS_DB_USER, BMS_DB_PASS and requires
 * BMS_DB_DANGER_RESET=1. Only random bms_content_ci_* tables are created and
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
$temporary = sys_get_temp_dir() . '/bms-content-test-' . bin2hex(random_bytes(6));
$prefix = 'bms_content_ci_' . bin2hex(random_bytes(5)) . '_';
$process = null;
$pdo = null;
$checks = 0;

function bms_content_test_expect(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
    $GLOBALS['checks']++;
    fwrite(STDOUT, 'PASS ' . $label . "\n");
}

function bms_content_test_request(string $base, string $path, array &$cookies, ?array $form = null): array
{
    $headers = ['User-Agent: Bonumark Content Regression', 'Connection: close'];
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
    return ['status' => (int)($status[1] ?? 0), 'body' => $body, 'location' => $location];
}

function bms_content_test_token(string $html): string
{
    if (!preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $match)) {
        throw new RuntimeException('Missing CSRF token in rendered form.');
    }
    return html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
}

/** Return only the selected content landmark, never similarly named page content. */
function bms_content_test_landmark(string $html, string $class): string
{
    preg_match_all('/<nav\b([^>]*)>(.*?)<\/nav>/s', $html, $matches, PREG_SET_ORDER);
    foreach ($matches as $match) {
        $attributes = bms_content_test_attributes($match[1]);
        if (in_array($class, explode(' ', $attributes['class'] ?? ''), true)) {
            return $match[2];
        }
    }
    throw new RuntimeException('Missing content landmark: ' . $class);
}

function bms_content_test_attributes(string $markup): array
{
    preg_match_all('/([a-zA-Z-]+)="([^"]*)"/', $markup, $matches, PREG_SET_ORDER);
    $attributes = [];
    foreach ($matches as $match) {
        $attributes[$match[1]] = html_entity_decode($match[2], ENT_QUOTES, 'UTF-8');
    }
    return $attributes;
}

function bms_content_test_links(string $html, string $class = ''): array
{
    preg_match_all('/<a\b([^>]*)>(.*?)<\/a>/s', $html, $matches, PREG_SET_ORDER);
    $links = [];
    foreach ($matches as $match) {
        $attributes = bms_content_test_attributes($match[1]);
        if ($class !== '' && !in_array($class, explode(' ', $attributes['class'] ?? ''), true)) {
            continue;
        }
        $attributes['label'] = trim(html_entity_decode(strip_tags($match[2]), ENT_QUOTES, 'UTF-8'));
        $links[] = $attributes;
    }
    return $links;
}

/** Read submitted record identities rather than matching preview text or menus. */
function bms_content_test_record_slugs(string $html): array
{
    preg_match_all('/<input\b[^>]*name="selected\[\]"[^>]*value="([^"]+)"/', $html, $matches);
    $slugs = [];
    foreach ($matches[1] as $value) {
        $parts = explode('|', html_entity_decode($value, ENT_QUOTES, 'UTF-8'), 2);
        if (($parts[0] ?? '') !== 'trash') {
            $slugs[] = pathinfo($parts[1] ?? '', PATHINFO_FILENAME);
        }
    }
    return $slugs;
}

function bms_content_test_status(PDO $pdo, string $prefix, string $slug): string
{
    $statement = $pdo->prepare("SELECT status FROM `{$prefix}posts` WHERE slug=?");
    $statement->execute([$slug]);
    return (string)$statement->fetchColumn();
}

function bms_content_test_remove_tree(string $path): void
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
        'site_name' => 'Content Fixture', 'site_tagline' => 'Saved tagline',
        'site_admin_email' => 'saved@example.test', 'timezone' => 'America/Indiana/Indianapolis',
        'stream_published_at_utc_cutover' => '2025-01-01 00:00:00',
        'base_url' => $base, 'base_path' => '', 'security_salt' => bin2hex(random_bytes(24)),
        'scheduled_tasks_public_traffic_enabled' => '0', 'scheduled_tasks_heartbeat_enabled' => '0',
        'activitypub_enabled' => '0', 'pwa_enabled' => '0', 'mail_transport' => 'disabled',
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
    $process = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $temporary . '/sessions', '-S', $address, '-t', $temporary], [0 => ['pipe', 'r'], 1 => ['file', $temporary . '/http.log', 'a'], 2 => ['file', $temporary . '/http.log', 'a']], $pipes, $temporary);
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
    bms_content_test_expect($ready, 'Isolated loopback server starts');
    fwrite(STDOUT, 'Runtime: PHP ' . PHP_VERSION . ' / ' . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . "\n");

    $guest = [];
    $sessions = [];
    foreach (['owner', 'commenter'] as $username) {
        $cookies = [];
        $login = bms_content_test_request($base, '/admin/login.php', $cookies);
        $login = bms_content_test_request($base, '/admin/login.php', $cookies, ['csrf_token' => bms_content_test_token($login['body']), 'username' => $username, 'password' => 'disposable-password']);
        bms_content_test_expect($login['status'] === 302, ucfirst($username) . ' fixture authenticates through real login');
        $sessions[$username] = $cookies;
    }


    $owner =& $sessions['owner'];
    $empty = bms_content_test_request($base, '/admin/content.php', $owner);
    bms_content_test_expect($empty['status'] === 200 && str_contains($empty['body'], 'No posts in this view.'), 'Empty Content screen renders a useful empty state');
    bms_content_test_expect((bool)array_filter(bms_content_test_links($empty['body']), static fn(array $link): bool => str_contains($link['href'], '?compose=1#stream-composer')), 'Empty state retains access to frontend creation');
    $token = bms_content_test_token($empty['body']);

    // Seed only this random-prefix disposable install. Fixed dates make sort and
    // timezone assertions independent of the date on which CI runs.
    $ownerId = (int)$pdo->query("SELECT id FROM `{$prefix}users` WHERE username='owner'")->fetchColumn();
    $fixtures = [
        ['alpha', 'drafts', 'Amber title marker', 'Description A', 'Older draft body.', '2026-01-01 08:00:00'],
        ['bravo', 'drafts', 'Second draft', 'Quartz description marker', 'Second draft body.', '2026-01-02 08:00:00'],
        ['charlie', 'scheduled', 'Scheduled fixture', 'Description C', 'Cobalt body marker.', '2026-01-03 08:00:00'],
        ['delta', 'published', 'Published fixture', 'Description D', 'Published body.', '2026-01-04 13:00:00'],
        ['echo-slugmarker', 'published', 'Pinned fixture', 'Description E', 'Pinned body.', '2026-01-05 13:00:00'],
        ['trash-fixture', 'drafts', 'Discarded draft', 'Description T', 'Trashed body.', '2026-01-06 08:00:00'],
        ['page-fixture', 'pages/drafts', 'Page must stay separate', 'Description P', 'Page body.', '2026-01-07 08:00:00'],
    ];
    $scheduledUtc = (new DateTimeImmutable('+3 days', new DateTimeZone('UTC')))->setTime(18, 30)->format('Y-m-d H:i:s');
    foreach ($fixtures as [$slug, $section, $title, $description, $body, $date]) {
        bms_upsert_database_content([
            'slug' => $slug, 'title' => $title, 'description' => $description,
            'body' => $body, 'date' => substr($date, 0, 10), 'stream_created_at' => $date,
            'scheduled_at' => $section === 'scheduled' ? $scheduledUtc : '',
        ], $section, $slug . '.md', $ownerId);
        $pdo->prepare("UPDATE `{$prefix}posts` SET created_at=?, published_at=CASE WHEN status='published' THEN ? ELSE NULL END WHERE slug=?")->execute([$date, $date, $slug]);
    }
    $pdo->exec("UPDATE `{$prefix}posts` SET is_pinned=1,pinned_at='2026-01-05 14:00:00' WHERE slug='echo-slugmarker'");
    $trashed = bms_content_test_request($base, '/admin/delete.php', $owner, ['csrf_token' => $token, 'type' => 'draft', 'file' => 'trash-fixture.md']);
    bms_content_test_expect($trashed['status'] === 302, 'Record-specific Trash action accepts a valid draft');
    $trashId = (int)$pdo->query("SELECT id FROM `{$prefix}trash` WHERE slug='trash-fixture'")->fetchColumn();
    bms_content_test_expect($trashId > 0, 'Trash action retains a restorable record');

    $list = bms_content_test_request($base, '/admin/content.php', $owner);
    $filters = bms_content_test_links(bms_content_test_landmark($list['body'], 'content-filter'));
    $filterText = array_map(static fn(array $link): string => preg_replace('/\s+/', ' ', $link['label']), $filters);
    bms_content_test_expect($filterText === ['All 5', 'Drafts 2', 'Scheduled 1', 'Published 2', 'Pinned 1', 'Trash 1'], 'All six counts retain pinned posts within Published and All, excluding Pages and Trash');
    $context = bms_content_test_links(bms_content_test_landmark($list['body'], 'admin-context-nav'));
    foreach (['content.php', 'pages.php', 'places.php', 'analytics.php'] as $route) {
        bms_content_test_expect(in_array('/admin/' . $route, array_map(static fn(array $link): string => (string)parse_url($link['href'], PHP_URL_PATH), $context), true), 'Content retains contextual access to ' . $route);
    }
    bms_content_test_expect(str_contains($list['body'], 'id="bulk-content-form"') && str_contains($list['body'], 'form="bulk-content-form"'), 'Record checkboxes remain associated with the native bulk form');
    bms_content_test_expect(str_contains($list['body'], 'name="q"') && str_contains($list['body'], 'name="sort"'), 'Search and sort are server-rendered controls');

    $expected = [
        'all' => ['charlie', 'echo-slugmarker', 'delta', 'bravo', 'alpha'],
        'draft' => ['bravo', 'alpha'], 'scheduled' => ['charlie'],
        'published' => ['echo-slugmarker', 'delta'], 'pinned' => ['echo-slugmarker'],
    ];
    $pages = [];
    foreach ($expected as $status => $slugs) {
        $response = bms_content_test_request($base, '/admin/content.php?status=' . $status, $owner);
        bms_content_test_expect($response['status'] === 200 && bms_content_test_record_slugs($response['body']) === $slugs, ucfirst($status) . ' filter returns the expected records in preserved date order');
        $pages[$status] = $response['body'];
    }
    $trashPage = bms_content_test_request($base, '/admin/content.php?status=trash', $owner);
    bms_content_test_expect(str_contains($trashPage['body'], 'value="trash|' . $trashId . '"') && !str_contains($trashPage['body'], 'file=alpha.md'), 'Trash filter exposes only the trash record');
    bms_content_test_expect(str_contains($trashPage['body'], 'name="empty_trash"') && str_contains($trashPage['body'], 'value="restore"') && str_contains($trashPage['body'], 'value="delete_permanent"'), 'Trash retains empty, bulk restore and permanent-delete controls');
    bms_content_test_expect(str_contains($pages['published'], 'Jan 4, 2026 8:00 AM'), 'Published UTC timestamp is displayed in the saved Indianapolis timezone');
    bms_content_test_expect(str_contains($pages['scheduled'], 'Scheduled') && str_contains($pages['scheduled'], (new DateTimeImmutable($scheduledUtc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('America/Indiana/Indianapolis'))->format('M j, Y g:i A')), 'Scheduled record displays its status and correct site-local publication time');

    foreach (['AMBER' => 'alpha', 'quartz' => 'bravo', 'cobalt' => 'charlie', 'slugmarker' => 'echo-slugmarker'] as $query => $slug) {
        $response = bms_content_test_request($base, '/admin/content.php?q=' . rawurlencode(' ' . $query . ' '), $owner);
        bms_content_test_expect(bms_content_test_record_slugs($response['body']) === [$slug], 'Case-insensitive trimmed search includes ' . $query . ' from title, description, body or slug');
    }
    $response = bms_content_test_request($base, '/admin/content.php?status=draft&q=cobalt', $owner);
    bms_content_test_expect(bms_content_test_record_slugs($response['body']) === [] && str_contains($response['body'], 'No '), 'No-match state respects both search and status');
    $response = bms_content_test_request($base, '/admin/content.php?status=draft&sort=date_asc', $owner);
    bms_content_test_expect(bms_content_test_record_slugs($response['body']) === ['alpha', 'bravo'], 'Oldest-first sort reverses the same filtered records');
    $response = bms_content_test_request($base, '/admin/content.php?status=unexpected&sort=unexpected', $owner);
    bms_content_test_expect(bms_content_test_record_slugs($response['body']) === $expected['all'], 'Unknown status and sort retain safe existing defaults');

    foreach (['draft' => 'alpha', 'scheduled' => 'charlie', 'published' => 'delta'] as $status => $slug) {
        $links = bms_content_test_links($pages[$status]);
        foreach (['edit.php', 'quick-edit.php', 'preview.php'] as $endpoint) {
            $wanted = '/admin/' . $endpoint . '?type=' . $status . '&file=' . $slug . '.md';
            bms_content_test_expect((bool)array_filter($links, static fn(array $link): bool => $link['href'] === $wanted), $status . ' retains its record-specific ' . $endpoint . ' destination');
            $response = bms_content_test_request($base, $wanted, $owner);
            bms_content_test_expect($response['status'] === 200, $status . ' ' . $endpoint . ' remains operational');
        }
        bms_content_test_expect((bool)array_filter($links, static fn(array $link): bool => $link['href'] === '/admin/revisions.php?slug=' . $slug), $status . ' retains its record-specific revision history');
    }
    $view = array_filter(bms_content_test_links($pages['published']), static fn(array $link): bool => $link['href'] === '/stream/delta/' && ($link['target'] ?? '') === '_blank' && str_contains($link['rel'] ?? '', 'noopener'));
    bms_content_test_expect($view !== [], 'Published record retains a safe View post link to its public URL');
    foreach (['pin.php', 'unpublish.php', 'delete.php'] as $endpoint) {
        bms_content_test_expect(str_contains($pages['published'], 'action="/admin/' . $endpoint . '"'), 'Published record retains native ' . $endpoint . ' action');
    }
    bms_content_test_expect(str_contains($pages['draft'], 'action="/admin/publish.php"') && str_contains($pages['scheduled'], 'action="/admin/publish.php"'), 'Draft and Scheduled retain record-specific Publish');

    // HTTP only: all following forms operate without JavaScript. Confirm the
    // submitted checkbox values affect exactly the selected records.
    $unchanged = bms_content_test_request($base, '/admin/content.php?status=draft', $owner, ['csrf_token' => $token, 'bulk_action' => '', 'selected' => ['draft|alpha.md']]);
    bms_content_test_expect($unchanged['status'] === 302 && bms_content_test_status($pdo, $prefix, 'alpha') === 'draft', 'Missing bulk action does not mutate selected content');
    $none = bms_content_test_request($base, '/admin/content.php?status=draft', $owner, ['csrf_token' => $token, 'bulk_action' => 'trash']);
    bms_content_test_expect($none['status'] === 302 && bms_content_test_status($pdo, $prefix, 'alpha') === 'draft', 'Empty bulk selection does not mutate content');
    $partial = bms_content_test_request($base, '/admin/content.php?status=draft', $owner, ['csrf_token' => $token, 'bulk_action' => 'publish', 'selected' => ['draft|bravo.md', 'draft|missing-fixture.md']]);
    $feedback = bms_content_test_request($base, '/admin/content.php?status=draft', $owner);
    bms_content_test_expect($partial['status'] === 302 && bms_content_test_status($pdo, $prefix, 'bravo') === 'published' && bms_content_test_status($pdo, $prefix, 'alpha') === 'draft', 'Bulk publish affects a selected valid draft and preserves unselected content');
    bms_content_test_expect(str_contains($feedback['body'], '1 post published.') && str_contains($feedback['body'], '1 selected item could not be changed.'), 'Partial-failure feedback preserves successful count and failed-item count');
    $response = bms_content_test_request($base, '/admin/content.php?status=published', $owner, ['csrf_token' => $token, 'bulk_action' => 'unpublish', 'selected' => ['published|bravo.md']]);
    bms_content_test_expect($response['status'] === 302 && bms_content_test_status($pdo, $prefix, 'bravo') === 'draft', 'Bulk unpublish returns the selected record to Drafts');

    foreach (['pin', 'unpin'] as $action) {
        $response = bms_content_test_request($base, '/admin/pin.php', $owner, ['csrf_token' => $token, 'file' => 'delta.md', 'action' => $action, 'return_to' => '/admin/content.php?status=published']);
        $pinned = (int)$pdo->query("SELECT is_pinned FROM `{$prefix}posts` WHERE slug='delta'")->fetchColumn();
        bms_content_test_expect($response['status'] === 302 && $pinned === ($action === 'pin' ? 1 : 0), 'Record-specific ' . $action . ' preserves its existing mutation');
        if ($action === 'pin') {
            $pinnedPage = bms_content_test_request($base, '/admin/content.php?status=pinned&sort=date_asc', $owner);
            bms_content_test_expect(bms_content_test_record_slugs($pinnedPage['body']) === ['delta', 'echo-slugmarker'], 'Pinned view retains newest-pin order regardless of date sort query');
        }
    }
    $response = bms_content_test_request($base, '/admin/content.php?status=draft', $owner, ['csrf_token' => $token, 'bulk_action' => 'trash', 'selected' => ['draft|bravo.md']]);
    $bravoTrashId = (int)$pdo->query("SELECT id FROM `{$prefix}trash` WHERE slug='bravo'")->fetchColumn();
    bms_content_test_expect($response['status'] === 302 && $bravoTrashId > 0 && bms_content_test_status($pdo, $prefix, 'bravo') === 'trash', 'Bulk Trash preserves the selected post identity');
    $response = bms_content_test_request($base, '/admin/content.php?status=trash', $owner, ['csrf_token' => $token, 'bulk_action' => 'restore', 'selected' => ['trash|' . $bravoTrashId]]);
    bms_content_test_expect($response['status'] === 302 && bms_content_test_status($pdo, $prefix, 'bravo') === 'draft', 'Bulk restore returns the original draft status');
    $response = bms_content_test_request($base, '/admin/restore.php', $owner, ['csrf_token' => $token, 'trash_id' => $trashId]);
    bms_content_test_expect($response['status'] === 302 && bms_content_test_status($pdo, $prefix, 'trash-fixture') === 'draft', 'Record-specific Restore returns its original draft status');
    bms_content_test_request($base, '/admin/delete.php', $owner, ['csrf_token' => $token, 'type' => 'draft', 'file' => 'trash-fixture.md']);
    $trashId = (int)$pdo->query("SELECT id FROM `{$prefix}trash` WHERE slug='trash-fixture'")->fetchColumn();
    $response = bms_content_test_request($base, '/admin/content.php?status=trash', $owner, ['csrf_token' => $token, 'bulk_action' => 'delete_permanent', 'selected' => ['trash|' . $trashId]]);
    bms_content_test_expect($response['status'] === 302 && (int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}trash` WHERE id={$trashId}")->fetchColumn() === 0, 'Bulk permanent deletion removes only the selected disposable Trash item');

    $editorPath = '/admin/edit.php?type=draft&file=alpha.md';
    $response = bms_content_test_request($base, $editorPath, $owner, ['csrf_token' => $token, 'type' => 'draft', 'file' => 'alpha.md', 'stream_title' => 'Amber title marker', 'stream_slug' => 'alpha', 'body_markdown' => 'Saved backend draft body.', 'stream_submit_action' => 'save']);
    bms_content_test_expect($response['status'] === 302 && trim((string)$pdo->query("SELECT content_body FROM `{$prefix}posts` WHERE slug='alpha'")->fetchColumn()) === 'Saved backend draft body.', 'Full editor saves a draft reached from the Content list');
    $response = bms_content_test_request($base, '/admin/autosave.php', $owner, ['csrf_token' => $token, 'key' => 'content-fixture-backup', 'title' => 'Draft backup', 'slug' => 'alpha', 'section' => 'drafts', 'filename' => 'alpha.md', 'markdown' => 'Unsaved recovery body.']);
    bms_content_test_expect($response['status'] === 200 && (json_decode($response['body'], true)['ok'] ?? false), 'Editor autosave accepts the same guarded draft identity');
    $response = bms_content_test_request($base, '/admin/autosave.php?key=content-fixture-backup', $owner);
    bms_content_test_expect((json_decode($response['body'], true)['autosave']['markdown'] ?? '') === 'Unsaved recovery body.', 'Editor autosave can recover its saved body');
    $response = bms_content_test_request($base, '/admin/publish.php', $owner, ['csrf_token' => $token, 'file' => 'alpha.md', 'return' => 'content']);
    bms_content_test_expect($response['status'] === 302 && bms_content_test_status($pdo, $prefix, 'alpha') === 'published', 'Record-specific Publish retains full-editor draft content');
    $response = bms_content_test_request($base, '/admin/quick-edit.php?type=published&file=alpha.md', $owner, ['csrf_token' => $token, 'type' => 'published', 'file' => 'alpha.md', 'stream_title' => 'Quick edited fixture', 'stream_slug' => 'alpha', 'stream_status' => 'published', 'stream_date' => '2026-01-01', 'stream_description' => 'Quick edited description']);
    bms_content_test_expect($response['status'] === 302 && $pdo->query("SELECT title FROM `{$prefix}posts` WHERE slug='alpha'")->fetchColumn() === 'Quick edited fixture', 'Quick Edit persists metadata through its existing endpoint');
    $response = bms_content_test_request($base, '/admin/revisions.php?slug=alpha', $owner);
    bms_content_test_expect($response['status'] === 200 && (int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}revisions` WHERE slug='alpha'")->fetchColumn() > 0, 'Published Quick Edit retains a reachable revision history');

    $localSchedule = (new DateTimeImmutable('+5 days', new DateTimeZone('America/Indiana/Indianapolis')))->setTime(10, 45);
    $response = bms_content_test_request($base, '/admin/quick-edit.php?type=draft&file=bravo.md', $owner, ['csrf_token' => $token, 'type' => 'draft', 'file' => 'bravo.md', 'stream_title' => 'Scheduled through Quick Edit', 'stream_slug' => 'bravo', 'stream_status' => 'scheduled', 'stream_date' => '2026-01-02', 'stream_scheduled_at' => $localSchedule->format('Y-m-d\TH:i')]);
    $storedSchedule = $pdo->query("SELECT scheduled_at FROM `{$prefix}posts` WHERE slug='bravo'")->fetchColumn();
    bms_content_test_expect($response['status'] === 302 && bms_content_test_status($pdo, $prefix, 'bravo') === 'scheduled' && $storedSchedule === $localSchedule->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'), 'Quick Edit scheduling converts site-local time to the exact UTC storage time');
    $response = bms_content_test_request($base, '/admin/quick-edit.php?type=scheduled&file=bravo.md', $owner);
    bms_content_test_expect(str_contains($response['body'], 'value="' . $localSchedule->format('Y-m-d\TH:i') . '"') && str_contains($response['body'], 'America/Indiana/Indianapolis'), 'Scheduled editor round-trips local time and identifies its timezone');

    $beforeSecurity = $pdo->query("SELECT id,status,title,content_body,is_pinned,scheduled_at FROM `{$prefix}posts` ORDER BY id")->fetchAll();
    $guarded = [
        ['/admin/content.php', ['bulk_action' => 'trash', 'selected' => ['published|alpha.md']]],
        ['/admin/pin.php', ['file' => 'alpha.md', 'action' => 'pin']],
        ['/admin/delete.php', ['type' => 'published', 'file' => 'alpha.md']],
        ['/admin/publish.php', ['file' => 'bravo.md']],
        ['/admin/unpublish.php', ['file' => 'alpha.md']],
        ['/admin/restore.php', ['trash_id' => 999999]],
        ['/admin/delete-permanent.php', ['empty_trash' => 1]],
        ['/admin/quick-edit.php?type=published&file=alpha.md', ['stream_title' => 'Rejected mutation']],
        ['/admin/edit.php?type=published&file=alpha.md', ['body_markdown' => 'Rejected mutation']],
        ['/admin/autosave.php', ['key' => 'content-fixture-backup', 'markdown' => 'Rejected mutation']],
    ];
    foreach ($guarded as [$path, $form]) {
        $response = bms_content_test_request($base, $path, $owner, ['csrf_token' => 'invalid'] + $form);
        bms_content_test_expect($response['status'] === 403, $path . ' rejects an invalid CSRF token');
        $response = bms_content_test_request($base, $path, $sessions['commenter'], ['csrf_token' => $token] + $form);
        bms_content_test_expect($response['status'] === 403, $path . ' rejects a commenter mutation');
    }
    foreach (['/admin/content.php', '/admin/analytics.php', '/admin/edit.php?type=published&file=alpha.md', '/admin/quick-edit.php?type=scheduled&file=bravo.md'] as $path) {
        $response = bms_content_test_request($base, $path, $guest);
        bms_content_test_expect($response['status'] === 302 && str_ends_with($response['location'], '/admin/login.php'), $path . ' redirects an anonymous visitor to login');
        $response = bms_content_test_request($base, $path, $sessions['commenter']);
        bms_content_test_expect($response['status'] === 403, $path . ' denies commenter access');
    }
    bms_content_test_expect($beforeSecurity === $pdo->query("SELECT id,status,title,content_body,is_pinned,scheduled_at FROM `{$prefix}posts` ORDER BY id")->fetchAll(), 'Rejected requests preserve content and publication state');
    $public = bms_content_test_request($base, '/', $guest);
    bms_content_test_expect($public['status'] === 200 && !str_contains($public['body'], 'assets/admin-content-list.css') && !str_contains($public['body'], 'assets/admin-shell.css'), 'Public frontend does not load Content or Admin shell styles');

    // Pagination is a presentation-only slice of the complete filtered/sorted set.
    for ($n = 1; $n <= 31; $n++) {
        $slug = sprintf('paged-%02d', $n);
        bms_upsert_database_content(['slug' => $slug, 'title' => 'Paged fixture ' . $n, 'body' => 'Pagination marker.', 'date' => '2026-02-01', 'stream_created_at' => sprintf('2026-02-01 %02d:%02d:00', intdiv($n, 60), $n % 60)], 'drafts', $slug . '.md', $ownerId);
    }
    $first = bms_content_test_request($base, '/admin/content.php?status=draft&q=Pagination&sort=date_asc', $owner);
    $second = bms_content_test_request($base, '/admin/content.php?status=draft&q=Pagination&sort=date_asc&page=2', $owner);
    $firstSlugs = bms_content_test_record_slugs($first['body']);
    $secondSlugs = bms_content_test_record_slugs($second['body']);
    bms_content_test_expect(count($firstSlugs) === 25 && count($secondSlugs) === 6 && array_merge($firstSlugs, $secondSlugs) === array_map(static fn(int $n): string => sprintf('paged-%02d', $n), range(1, 31)), 'Pagination shows 25 records then 6 without omission, duplication or sort changes');
    $nextLinks = array_filter(bms_content_test_links($first['body']), static function (array $link): bool {
        parse_str((string)parse_url($link['href'], PHP_URL_QUERY), $query);
        return ($query['page'] ?? '') === '2' && ($query['status'] ?? '') === 'draft' && ($query['q'] ?? '') === 'Pagination' && ($query['sort'] ?? '') === 'date_asc';
    });
    bms_content_test_expect($nextLinks !== [], 'Pagination links retain search, status and sort');
    foreach (['0' => $firstSlugs, '99999' => $secondSlugs] as $page => $slugs) {
        $response = bms_content_test_request($base, '/admin/content.php?status=draft&q=Pagination&sort=date_asc&page=' . $page, $owner);
        bms_content_test_expect(bms_content_test_record_slugs($response['body']) === $slugs, 'Out-of-range page ' . $page . ' clamps to an existing result page');
    }
    bms_content_test_expect((int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}migrations`")->fetchColumn() === $migrationCount, 'Content workflows introduce no migrations');
    fwrite(STDOUT, 'Admin Content runtime regression passed: ' . $checks . " checks.\n");

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
    bms_content_test_remove_tree($temporary);
}

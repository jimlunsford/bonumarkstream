<?php
/**
 * Pages management and editor HTTP regression against a disposable install.
 * Verifies rendered form contracts and database results. The dedicated Page
 * lifecycle suite separately covers identity preservation and failure rollback.
 *
 * Uses BMS_DB_HOST, BMS_DB_NAME, BMS_DB_USER, BMS_DB_PASS and requires
 * BMS_DB_DANGER_RESET=1. Only random bms_pages_ui_ci_* tables are created and
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
$temporary = sys_get_temp_dir() . '/bms-pages-ui-test-' . bin2hex(random_bytes(6));
$prefix = 'bms_pages_ui_ci_' . bin2hex(random_bytes(5)) . '_';
$process = null;
$pdo = null;
$checks = 0;

function bms_pages_test_expect(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
    $GLOBALS['checks']++;
    fwrite(STDOUT, 'PASS ' . $label . "\n");
}

function bms_pages_test_request(string $base, string $path, array &$cookies, ?array $form = null): array
{
    $headers = ['User-Agent: Bonumark Pages Interface Regression', 'Connection: close'];
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

function bms_pages_test_token(string $html): string
{
    if (!preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $match)) {
        throw new RuntimeException('Missing CSRF token in rendered form.');
    }
    return html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
}

function bms_pages_test_remove_tree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($path);
}


function bms_pages_test_attributes(string $markup): array
{
    preg_match_all('/([a-zA-Z0-9_-]+)="([^"]*)"/', $markup, $matches, PREG_SET_ORDER);
    $attributes = [];
    foreach ($matches as $match) {
        $attributes[$match[1]] = html_entity_decode($match[2], ENT_QUOTES, 'UTF-8');
    }
    return $attributes;
}

function bms_pages_test_text(string $html): string
{
    return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8')) ?? '');
}

function bms_pages_test_links(string $html): array
{
    preg_match_all('/<a\b([^>]*)>(.*?)<\/a>/s', $html, $matches, PREG_SET_ORDER);
    return array_map(static fn(array $match): array => bms_pages_test_attributes($match[1]) + ['label' => bms_pages_test_text($match[2])], $matches);
}

function bms_pages_test_filters(string $html): array
{
    preg_match_all('/<nav\b([^>]*)>(.*?)<\/nav>/s', $html, $matches, PREG_SET_ORDER);
    foreach ($matches as $match) {
        $attributes = bms_pages_test_attributes($match[1]);
        if (($attributes['aria-label'] ?? '') === 'Page status filters') {
            return bms_pages_test_links($match[2]);
        }
    }
    throw new RuntimeException('Missing Page status filters landmark.');
}

function bms_pages_test_records(string $html): array
{
    preg_match_all('/<article\b([^>]*)>(.*?)<\/article>/s', $html, $matches, PREG_SET_ORDER);
    $records = [];
    foreach ($matches as $match) {
        $attributes = bms_pages_test_attributes($match[1]);
        if (in_array('page-content-record', explode(' ', $attributes['class'] ?? ''), true)) {
            preg_match('/<h2\b[^>]*>(.*?)<\/h2>/s', $match[2], $title);
            $records[bms_pages_test_text($title[1] ?? '')] = $match[2];
        }
    }
    return $records;
}

function bms_pages_test_has_link(string $html, string $href, ?string $label = null): bool
{
    return (bool)array_filter(bms_pages_test_links($html), static fn(array $link): bool => ($link['href'] ?? '') === $href && ($label === null || $link['label'] === $label));
}

function bms_pages_test_h1(string $html): array
{
    preg_match_all('/<h1\b[^>]*>(.*?)<\/h1>/s', $html, $matches);
    return array_map('bms_pages_test_text', $matches[1]);
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
        'site_name' => 'Pages Interface Fixture', 'site_tagline' => 'Saved tagline',
        'site_admin_email' => 'saved@example.test', 'timezone' => 'America/Indiana/Indianapolis',
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
    bms_pages_test_expect($ready, 'Isolated loopback server starts');
    fwrite(STDOUT, 'Runtime: PHP ' . PHP_VERSION . ' / ' . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . "\n");

    $guest = [];
    $sessions = [];
    $sessionTokens = [];
    foreach (['owner', 'commenter'] as $username) {
        $cookies = [];
        $login = bms_pages_test_request($base, '/admin/login.php', $cookies);
        $sessionTokens[$username] = bms_pages_test_token($login['body']);
        $login = bms_pages_test_request($base, '/admin/login.php', $cookies, ['csrf_token' => $sessionTokens[$username], 'username' => $username, 'password' => 'disposable-password']);
        bms_pages_test_expect($login['status'] === 302, ucfirst($username) . ' fixture authenticates through real login');
        $sessions[$username] = $cookies;
    }


    require_once $temporary . '/_bonumark_stream/app/pages.php';
    $owner =& $sessions['owner'];
    $ownerId = (int)$pdo->query("SELECT id FROM `{$prefix}users` WHERE username='owner'")->fetchColumn();
    $row = static function (string $slug, string $type = 'page') use ($pdo, $prefix): ?array {
        $query = $pdo->prepare("SELECT * FROM `{$prefix}posts` WHERE slug=? AND post_type=?");
        $query->execute([$slug, $type]);
        return $query->fetch(PDO::FETCH_ASSOC) ?: null;
    };
    $snapshot = static function () use ($pdo, $prefix): array {
        $data = [];
        foreach (['posts', 'post_terms', 'revisions', 'trash'] as $table) {
            $rows = array_map('serialize', $pdo->query("SELECT * FROM `{$prefix}{$table}`")->fetchAll(PDO::FETCH_ASSOC));
            sort($rows);
            $data[$table] = $rows;
        }
        return $data;
    };
    $new = bms_pages_test_request($base, '/admin/page-new.php', $owner);
    $token = bms_pages_test_token($new['body']);
    foreach (['all', 'draft', 'published', 'trash'] as $status) {
        $response = bms_pages_test_request($base, '/admin/pages.php?status=' . $status, $owner);
        bms_pages_test_expect($response['status'] === 200 && bms_pages_test_h1($response['body']) === ['Pages'], 'Empty ' . $status . ' view keeps one Pages heading');
        bms_pages_test_expect(bms_pages_test_records($response['body']) === [] && str_contains($response['body'], 'content-list-empty-state'), 'Empty ' . $status . ' view explains its empty state');
        bms_pages_test_expect(bms_pages_test_has_link($response['body'], '/admin/page-new.php', 'New Page'), 'Empty ' . $status . ' view retains New Page');
    }

    // Deliberately different title/date order, text fields, and content kinds.
    $fixtures = [
        ['alpha', 'pages/drafts', 'Amber title marker', 'Description A', 'Draft body A.', '2026-01-05'],
        ['bravo', 'pages/drafts', 'beta draft', 'Quartz description marker', 'Draft body B.', '2026-01-04'],
        ['charlie', 'pages/published', 'Gamma public', '', 'Cobalt body marker with **bold** and markup.', '2026-01-03'],
        ['delta-slugmarker', 'pages/published', 'Delta public', 'Description D', 'Public body D.', '2026-01-02'],
        ['long-page', 'pages/published', 'Zebra <script>alert("title")</script> & ' . trim(str_repeat('Long ', 20)), 'Literal 100%_search marker', 'Safe body.', '2026-01-01'],
        ['trash-old', 'pages/drafts', 'Old discarded draft', 'Deleted draft description', 'Deleted draft body.', '2026-01-06'],
        ['trash-new', 'pages/published', 'New discarded public', 'Deleted public description', 'Deleted public body.', '2026-01-07'],
        ['stream-draft', 'drafts', 'Separate Stream draft', 'Stream only', 'Stream draft body.', '2026-01-08'],
        ['stream-public', 'published', 'Separate Stream public', 'Stream only', 'Stream public body.', '2026-01-09'],
        ['stream-trash', 'drafts', 'Separate Stream trash', 'Stream only', 'Stream trash body.', '2026-01-10'],
    ];
    foreach ($fixtures as [$slug, $section, $title, $description, $bodyText, $date]) {
        bms_upsert_database_content(['slug' => $slug, 'title' => $title, 'description' => $description,
            'body' => $bodyText, 'date' => $date, 'seo_title' => 'Search ' . $slug,
            'robots' => 'noindex,follow'], $section, $slug . '.md', $ownerId);
    }
    $trashIds = [];
    foreach (['trash-old' => 'draft', 'trash-new' => 'published'] as $slug => $status) {
        $original = $row($slug);
        $response = bms_pages_test_request($base, '/admin/page-delete.php', $owner, ['csrf_token' => $token, 'type' => $status, 'file' => $slug . '.md']);
        $query = $pdo->prepare("SELECT * FROM `{$prefix}trash` WHERE slug=? AND post_type='page'");
        $query->execute([$slug]);
        $saved = $query->fetch(PDO::FETCH_ASSOC);
        bms_pages_test_expect($response['status'] === 302 && $saved !== false && $row($slug) === null, 'Native Trash form moves ' . $status . ' Page into database Trash');
        bms_pages_test_expect($saved['content_body'] === $original['content_body'] && (int)$saved['original_author_id'] === $ownerId, 'Trash preserves ' . $status . ' Page body and author');
        $trashIds[$slug] = (int)$saved['id'];
        $pdo->prepare("UPDATE `{$prefix}trash` SET deleted_at=? WHERE id=?")->execute([$slug === 'trash-old' ? '2026-02-01 10:00:00' : '2026-02-02 10:00:00', $saved['id']]);
    }
    bms_pages_test_request($base, '/admin/delete.php', $owner, ['csrf_token' => $token, 'type' => 'draft', 'file' => 'stream-trash.md']);
    $streamTrash = $pdo->query("SELECT * FROM `{$prefix}trash` WHERE slug='stream-trash'")->fetch(PDO::FETCH_ASSOC);
    bms_pages_test_expect($streamTrash !== false, 'Separate Stream Trash control fixture exists');
    $streamBefore = [$row('stream-draft', 'stream'), $row('stream-public', 'stream')];
    $beforeRead = $snapshot();

    $expectedTitles = [
        'all' => ['Amber title marker', 'beta draft', 'Delta public', 'Gamma public', $fixtures[4][2]],
        'draft' => ['Amber title marker', 'beta draft'],
        'published' => ['Delta public', 'Gamma public', $fixtures[4][2]],
        'trash' => ['New discarded public', 'Old discarded draft'],
    ];
    $views = [];
    foreach ($expectedTitles as $status => $titles) {
        $response = bms_pages_test_request($base, '/admin/pages.php?status=' . $status, $owner);
        $views[$status] = $response['body'];
        bms_pages_test_expect($response['status'] === 200 && bms_pages_test_h1($response['body']) === ['Pages'], ucfirst($status) . ' filter retains one stable Pages heading');
        bms_pages_test_expect(array_keys(bms_pages_test_records($response['body'])) === $titles, ucfirst($status) . ' filter preserves membership and title/deletion ordering');
        $filters = bms_pages_test_filters($response['body']);
        bms_pages_test_expect(array_column($filters, 'label') === ['All 5', 'Drafts 2', 'Published 3', 'Trash 2'], ucfirst($status) . ' counts exclude Stream records and remain unfiltered');
        $active = array_values(array_filter($filters, static fn(array $link): bool => isset($link['aria-current'])));
        $wanted = '/admin/pages.php' . ($status === 'all' ? '' : '?status=' . $status);
        bms_pages_test_expect(count($active) === 1 && $active[0]['href'] === $wanted, ucfirst($status) . ' selection has accessible current-filter semantics');
        bms_pages_test_expect(preg_match('/<label\b([^>]*)for="pages_q"[^>]*>Search pages<\/label>/', $response['body'], $label) === 1 && !str_contains($label[1], 'sr-only'), ucfirst($status) . ' search has a visible explicit label');
        bms_pages_test_expect(!str_contains($response['body'], 'name="selected[]"') && !str_contains($response['body'], 'name="sort"'), ucfirst($status) . ' view does not introduce bulk actions or new sort controls');
        if ($status !== 'all') {
            bms_pages_test_expect(str_contains($response['body'], 'name="status" value="' . $status . '"'), ucfirst($status) . ' native search retains its status');
        }
    }
    bms_pages_test_expect(str_contains($views['trash'], 'Newest deleted first') && str_contains($views['all'], 'Sorted by title'), 'Ordering summaries describe the existing sort behavior');
    bms_pages_test_expect(!str_contains($views['all'], '<script>alert("title")</script>') && str_contains($views['all'], '&lt;script&gt;'), 'Long Page title is escaped in rendered records and actions');
    $gamma = bms_pages_test_records($views['published'])['Gamma public'];
    bms_pages_test_expect(str_contains($gamma, 'Cobalt body marker with bold and markup.') && !str_contains($gamma, '<b>markup</b>'), 'Missing description falls back to a plain-text body excerpt');
    bms_pages_test_expect(str_contains($views['draft'], 'URL when published') && str_contains($views['published'], 'Public URL'), 'Draft and published URL labels communicate visibility');
    bms_pages_test_expect(!str_contains($views['all'], '>Database<'), 'Management records omit storage implementation labels');

    foreach (['AMBER' => ['Amber title marker'], 'quartz' => ['beta draft'], 'cobalt' => ['Gamma public'], 'slugmarker' => ['Delta public'], '100%_search' => [$fixtures[4][2]], '%_missing' => []] as $query => $titles) {
        $response = bms_pages_test_request($base, '/admin/pages.php?q=' . rawurlencode(' ' . $query . ' '), $owner);
        bms_pages_test_expect(array_keys(bms_pages_test_records($response['body'])) === $titles, 'Trimmed case-insensitive literal search preserves ' . $query . ' semantics');
        bms_pages_test_expect(array_column(bms_pages_test_filters($response['body']), 'label') === ['All 5', 'Drafts 2', 'Published 3', 'Trash 2'], 'Search ' . $query . ' does not alter total filter counts');
    }
    $response = bms_pages_test_request($base, '/admin/pages.php?status=trash&q=trash-old.md', $owner);
    bms_pages_test_expect(array_keys(bms_pages_test_records($response['body'])) === ['Old discarded draft'], 'Trash search includes the original filename');
    foreach (['draft', 'published', 'trash'] as $status) {
        $response = bms_pages_test_request($base, '/admin/pages.php?status=' . $status . '&q=no-match', $owner);
        bms_pages_test_expect(bms_pages_test_records($response['body']) === [] && str_contains($response['body'], 'No matching pages'), $status . ' no-match state is distinct from an empty collection');
        bms_pages_test_expect(bms_pages_test_has_link($response['body'], '/admin/pages.php?status=' . $status, 'Clear search'), $status . ' no-match clear-search action preserves the filter');
    }
    $response = bms_pages_test_request($base, '/admin/pages.php?status=unexpected', $owner);
    bms_pages_test_expect(array_keys(bms_pages_test_records($response['body'])) === $expectedTitles['all'], 'Unknown status falls back to All');
    foreach (['draft' => ['alpha', 'Amber title marker'], 'published' => ['charlie', 'Gamma public']] as $status => [$slug, $title]) {
        $record = bms_pages_test_records($views[$status])[$title];
        $editUrl = '/admin/page-edit.php?type=' . $status . '&file=' . $slug . '.md';
        $previewUrl = '/admin/preview.php?type=page-' . $status . '&file=' . $slug . '.md';
        bms_pages_test_expect(bms_pages_test_has_link($record, $editUrl, 'Edit'), $status . ' record exposes Edit as a named action');
        $beforeMenu = explode('<details', $record, 2)[0];
        bms_pages_test_expect(bms_pages_test_has_link($beforeMenu, $editUrl, 'Edit'), $status . ' Edit is visible outside the Actions disclosure');
        bms_pages_test_expect(preg_match('/<summary\b[^>]*aria-label="[^"]*' . preg_quote($title, '/') . '[^"]*"/', $record) === 1, $status . ' Actions has a record-specific accessible name');
        bms_pages_test_expect(bms_pages_test_has_link($record, $previewUrl, 'Preview'), $status . ' preview keeps its existing route');
        $preview = bms_pages_test_request($base, $previewUrl, $owner);
        bms_pages_test_expect($preview['status'] === 200 && str_contains($preview['body'], $status === 'draft' ? 'Draft body A.' : 'Cobalt body marker'), $status . ' saved preview renders Page content');
        foreach ([$status === 'published' ? 'page-unpublish.php' : 'page-publish.php', 'page-delete.php'] as $action) {
            bms_pages_test_expect(str_contains($record, 'action="/admin/' . $action . '"') && str_contains($record, 'name="file" value="' . $slug . '.md"'), $status . ' record retains native ' . $action . ' form identity');
        }
    }
    bms_pages_test_expect(bms_pages_test_has_link($gamma, '/pages/charlie/', 'View page'), 'Published record links to the existing public Page URL');
    bms_pages_test_expect(str_contains($views['trash'], 'name="empty_page_trash"') && str_contains($views['trash'], 'action="/admin/page-restore.php"') && str_contains($views['trash'], 'action="/admin/page-delete-permanent.php"'), 'Trash retains separate empty, restore and permanent-delete forms');
    bms_pages_test_expect(str_contains(strtolower($views['trash']), 'cannot be undone'), 'Permanent deletion has a visible irreversible consequence');
    bms_pages_test_expect($beforeRead === $snapshot(), 'Filtering, searching, list rendering and saved previews do not mutate database content');

    $editorPaths = ['New' => '/admin/page-new.php', 'Draft' => '/admin/page-edit.php?type=draft&file=alpha.md', 'Published' => '/admin/page-edit.php?type=published&file=charlie.md'];
    foreach ($editorPaths as $kind => $path) {
        $response = bms_pages_test_request($base, $path, $owner);
        $html = $response['body'];
        bms_pages_test_expect($response['status'] === 200 && bms_pages_test_h1($html) === [$kind === 'New' ? 'New Page' : 'Edit Page'], $kind . ' editor has one clear heading');
        bms_pages_test_expect(bms_pages_test_has_link($html, '/admin/pages.php', 'Back to Pages'), $kind . ' editor offers Back to Pages');
        bms_pages_test_expect(str_contains($html, 'id="stream-editor-form"') && str_contains($html, 'name="content_kind" value="page"'), $kind . ' editor retains shared form and Page identity');
        foreach (['page_title', 'body_markdown', 'page_slug', 'page_date', 'page_description', 'page_seo_title', 'page_robots'] as $field) {
            bms_pages_test_expect(str_contains($html, 'name="' . $field . '"') && str_contains($html, 'id="' . $field . '"'), $kind . ' editor retains ' . $field);
        }
        foreach (['data-bonumark-editor', 'data-editor-mode="visual"', 'data-editor-mode="markdown"', 'data-editor-mode="preview"', 'data-open-media-picker', 'data-editor-screen-controls-shell', 'data-editor-mobile-action-bar', 'data-editor-card="page-settings"', 'data-editor-card="page-url"'] as $hook) {
            bms_pages_test_expect(str_contains($html, $hook), $kind . ' editor retains ' . $hook);
        }
        bms_pages_test_expect(str_contains($html, 'form="stream-editor-form"') && str_contains($html, 'formaction="/admin/preview-current.php"'), $kind . ' editor retains associated mobile/save/current-preview controls');
        bms_pages_test_expect(!str_contains($html, 'Editing database content record:') && !str_contains($html, 'data-editor-card="stream-post"') && !str_contains($html, 'name="stream_scheduled_at"'), $kind . ' Page surface excludes storage prose and Stream-only settings');
        bms_pages_test_expect(str_contains($html, 'value="index,follow"') && str_contains($html, 'value="noindex,follow"'), $kind . ' editor retains all indexing choices');
        if ($kind === 'Published') {
            bms_pages_test_expect(str_contains($html, 'data-page-confirm-slug-change') && str_contains($html, 'name="confirm_slug_change"'), 'Published editor retains URL-change acknowledgement');
            bms_pages_test_expect(str_contains($html, 'form="unpublish-post-action-form"') && str_contains($html, 'action="/admin/page-unpublish.php"'), 'Published editor retains separate unpublish action');
        } else {
            bms_pages_test_expect(str_contains($html, '>Save Draft</button>') && str_contains($html, '>Publish Page</button>'), $kind . ' editor distinguishes draft save from publication');
        }
        if ($kind !== 'New') {
            bms_pages_test_expect(str_contains($html, 'name="type" value="' . strtolower($kind) . '"') && str_contains($html, 'form="trash-post-action-form"'), $kind . ' editor retains existing identity and separate Trash action');
        }
    }
    $stream = bms_pages_test_request($base, '/admin/edit.php?type=draft&file=stream-draft.md', $owner);
    bms_pages_test_expect($stream['status'] === 200 && str_contains($stream['body'], 'data-bonumark-editor') && str_contains($stream['body'], 'name="stream_title"') && !str_contains($stream['body'], 'name="page_title"'), 'Shared Stream editor remains operational with Stream fields');
    bms_pages_test_expect(str_contains($stream['body'], 'Post Now') && str_contains($stream['body'], 'data-editor-card="stream-post"'), 'Shared Stream editor retains its publishing and settings labels');
    $missing = bms_pages_test_request($base, '/admin/page-edit.php?type=draft&file=missing.md', $owner);
    bms_pages_test_expect($missing['status'] === 404 && str_contains($missing['body'], 'Page not found'), 'Missing Page retains its existing useful 404 response');

    // Submit the same field names supplied by the redesigned forms. Detailed
    // identity/rollback permutations remain in page-lifecycle-runtime-test.php.
    $form = ['content_kind' => 'page', 'page_title' => 'Created through Page interface', 'page_slug' => 'ui-created', 'body_markdown' => "# Interface body\n\nSaved content.", 'page_date' => '2026-03-01', 'page_description' => 'Interface description.', 'page_seo_title' => 'Interface search title', 'page_robots' => 'noindex,follow'];
    $response = bms_pages_test_request($base, '/admin/page-new.php', $owner, ['csrf_token' => $token, 'stream_submit_action' => 'draft'] + $form);
    $created = $row('ui-created');
    bms_pages_test_expect($response['status'] === 302 && $created !== null && $created['status'] === 'draft', 'Redesigned New Page native draft action persists a Page');
    $editPath = '/admin/page-edit.php?type=draft&file=ui-created.md';
    $form['body_markdown'] = 'Saved and published through Page editor.';
    $response = bms_pages_test_request($base, $editPath, $owner, ['csrf_token' => $token, 'stream_submit_action' => 'publish', 'type' => 'draft', 'file' => 'ui-created.md'] + $form);
    $saved = $row('ui-created');
    bms_pages_test_expect($response['status'] === 302 && $saved['status'] === 'published' && $saved['id'] === $created['id'] && trim($saved['content_body']) === $form['body_markdown'], 'Redesigned editor publishes saved content through the corrected original identity');
    $metadata = json_decode($saved['content_front_matter'], true, 512, JSON_THROW_ON_ERROR);
    bms_pages_test_expect($metadata['description'] === $form['page_description'] && $metadata['seo_title'] === $form['page_seo_title'] && $metadata['robots'] === $form['page_robots'] && (int)$saved['author_id'] === $ownerId, 'Page interface submission preserves author and SEO settings');
    $editPath = '/admin/page-edit.php?type=published&file=ui-created.md';
    foreach (['page_title' => 'Page title is required.', 'body_markdown' => 'Page body cannot be empty.'] as $invalid => $notice) {
        $response = bms_pages_test_request($base, $editPath, $owner, ['csrf_token' => $token, $invalid => ''] + $form);
        $feedback = bms_pages_test_request($base, $editPath, $owner);
        bms_pages_test_expect($response['status'] === 302 && str_contains($feedback['body'], $notice) && $row('ui-created') === $saved, 'Invalid ' . $invalid . ' displays the existing notice and preserves the saved Page');
    }
    $response = bms_pages_test_request($base, $editPath, $owner, ['csrf_token' => $token, 'page_slug' => 'ui-renamed'] + $form);
    $feedback = bms_pages_test_request($base, $editPath, $owner);
    bms_pages_test_expect($response['status'] === 302 && str_contains($feedback['body'], 'Confirm the live URL change') && $row('ui-created') === $saved, 'Published URL change without acknowledgement remains rejected with an actionable notice');
    $response = bms_pages_test_request($base, $editPath, $owner, ['csrf_token' => $token, 'page_slug' => 'ui-renamed', 'confirm_slug_change' => '1'] + $form);
    bms_pages_test_expect($response['status'] === 302 && $row('ui-renamed')['id'] === $created['id'] && $row('ui-created') === null, 'Acknowledged published URL change retains the corrected Page identity');

    // Normal restores only, with disposable records. A failure aborts the suite
    // immediately. Restore's existing delete-before-sync risk is not modified.
    foreach (['trash-old' => 'draft', 'trash-new' => 'published'] as $slug => $status) {
        $response = bms_pages_test_request($base, '/admin/page-restore.php', $owner, ['csrf_token' => $token, 'trash_id' => $trashIds[$slug]]);
        $restored = $row($slug);
        bms_pages_test_expect($response['status'] === 302 && str_contains($response['location'], 'status=' . $status) && $restored !== null && $restored['status'] === $status, 'Disposable normal restore returns ' . $status . ' Page to its original state');
        bms_pages_test_expect(trim($restored['content_body']) === ($status === 'draft' ? 'Deleted draft body.' : 'Deleted public body.') && (int)$restored['author_id'] === $ownerId, 'Normal ' . $status . ' restore retains content and author');
        bms_pages_test_request($base, '/admin/page-delete.php', $owner, ['csrf_token' => $token, 'type' => $status, 'file' => $slug . '.md']);
        $trashIds[$slug] = (int)$pdo->query("SELECT id FROM `{$prefix}trash` WHERE slug=" . $pdo->quote($slug))->fetchColumn();
    }
    $response = bms_pages_test_request($base, '/admin/page-delete-permanent.php', $owner, ['csrf_token' => $token, 'trash_id' => $trashIds['trash-old']]);
    bms_pages_test_expect($response['status'] === 302 && (int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}trash` WHERE id=" . $trashIds['trash-old'])->fetchColumn() === 0 && (int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}trash` WHERE id=" . $trashIds['trash-new'])->fetchColumn() === 1, 'Single permanent deletion affects only its selected Page Trash item');
    $response = bms_pages_test_request($base, '/admin/page-delete-permanent.php', $owner, ['csrf_token' => $token, 'empty_page_trash' => '1']);
    bms_pages_test_expect($response['status'] === 302 && (int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}trash` WHERE post_type='page'")->fetchColumn() === 0, 'Empty Page Trash removes remaining Page Trash only');
    bms_pages_test_expect($pdo->query("SELECT * FROM `{$prefix}trash` WHERE slug='stream-trash'")->fetch(PDO::FETCH_ASSOC) === $streamTrash && [$row('stream-draft', 'stream'), $row('stream-public', 'stream')] === $streamBefore, 'Page editing and Trash operations leave Stream records and Stream Trash unchanged');

    $beforeSecurity = $snapshot();
    $guarded = [
        ['/admin/page-new.php', $form],
        ['/admin/page-edit.php?type=draft&file=alpha.md', $form],
        ['/admin/page-publish.php', ['file' => 'alpha.md']],
        ['/admin/page-unpublish.php', ['file' => 'charlie.md']],
        ['/admin/page-delete.php', ['type' => 'draft', 'file' => 'alpha.md']],
        ['/admin/page-restore.php', ['trash_id' => 999999]],
        ['/admin/page-delete-permanent.php', ['empty_page_trash' => '1']],
    ];
    foreach ($guarded as [$path, $fields]) {
        $response = bms_pages_test_request($base, $path, $owner, ['csrf_token' => 'invalid'] + $fields);
        bms_pages_test_expect($response['status'] === 403, $path . ' rejects invalid CSRF');
        $response = bms_pages_test_request($base, $path, $sessions['commenter'], ['csrf_token' => $sessionTokens['commenter']] + $fields);
        bms_pages_test_expect($response['status'] === 403 && str_contains($response['body'], 'You do not have permission to access this area.'), $path . ' rejects commenter mutation with a valid session CSRF token');
        $response = bms_pages_test_request($base, $path, $guest, ['csrf_token' => $token] + $fields);
        bms_pages_test_expect($response['status'] === 302 && str_ends_with($response['location'], '/admin/login.php'), $path . ' requires authentication before mutation');
    }
    foreach (['/admin/pages.php', '/admin/page-new.php', '/admin/page-edit.php?type=draft&file=alpha.md'] as $path) {
        $response = bms_pages_test_request($base, $path, $guest);
        bms_pages_test_expect($response['status'] === 302 && str_ends_with($response['location'], '/admin/login.php'), $path . ' redirects anonymous readers to login');
        $response = bms_pages_test_request($base, $path, $sessions['commenter']);
        bms_pages_test_expect($response['status'] === 403, $path . ' denies commenter access');
    }
    bms_pages_test_expect($beforeSecurity === $snapshot(), 'Rejected Page requests leave database records and relationships unchanged');
    bms_pages_test_expect((int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}migrations`")->fetchColumn() === $migrationCount, 'Pages interface introduces no migrations');
    fwrite(STDOUT, 'Admin Pages runtime regression passed: ' . $checks . " checks.\n");

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
    bms_pages_test_remove_tree($temporary);
}

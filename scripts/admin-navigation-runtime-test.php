<?php
/**
 * Admin navigation HTTP contract regression test against a disposable install.
 *
 * Uses BMS_DB_HOST, BMS_DB_NAME, BMS_DB_USER, BMS_DB_PASS and requires
 * BMS_DB_DANGER_RESET=1. Only random bms_navigation_ci_* tables are created and
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
$temporary = sys_get_temp_dir() . '/bms-navigation-test-' . bin2hex(random_bytes(6));
$prefix = 'bms_navigation_ci_' . bin2hex(random_bytes(5)) . '_';
$process = null;
$pdo = null;
$checks = 0;

function bms_navigation_test_expect(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
    $GLOBALS['checks']++;
    fwrite(STDOUT, 'PASS ' . $label . "\n");
}

function bms_navigation_test_request(string $base, string $path, array &$cookies, ?array $form = null): array
{
    $headers = ['User-Agent: Bonumark Navigation Regression', 'Connection: close'];
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

function bms_navigation_test_token(string $html): string
{
    if (!preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $match)) {
        throw new RuntimeException('Missing CSRF token in rendered form.');
    }
    return html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
}

/** Return only the selected navigation landmark, never similarly named page content. */
function bms_navigation_test_landmark(string $html, string $class): string
{
    preg_match_all('/<nav\b([^>]*)>(.*?)<\/nav>/s', $html, $matches, PREG_SET_ORDER);
    foreach ($matches as $match) {
        $attributes = bms_navigation_test_attributes($match[1]);
        if (in_array($class, explode(' ', $attributes['class'] ?? ''), true)) {
            return $match[2];
        }
    }
    throw new RuntimeException('Missing navigation landmark: ' . $class);
}

function bms_navigation_test_attributes(string $markup): array
{
    preg_match_all('/([a-zA-Z-]+)="([^"]*)"/', $markup, $matches, PREG_SET_ORDER);
    $attributes = [];
    foreach ($matches as $match) {
        $attributes[$match[1]] = html_entity_decode($match[2], ENT_QUOTES, 'UTF-8');
    }
    return $attributes;
}

function bms_navigation_test_links(string $html, string $class = ''): array
{
    preg_match_all('/<a\b([^>]*)>(.*?)<\/a>/s', $html, $matches, PREG_SET_ORDER);
    $links = [];
    foreach ($matches as $match) {
        $attributes = bms_navigation_test_attributes($match[1]);
        if ($class !== '' && !in_array($class, explode(' ', $attributes['class'] ?? ''), true)) {
            continue;
        }
        $attributes['label'] = trim(html_entity_decode(strip_tags($match[2]), ENT_QUOTES, 'UTF-8'));
        $links[] = $attributes;
    }
    return $links;
}

function bms_navigation_test_active(string $html, string $label, string $current): void
{
    $nav = bms_navigation_test_landmark($html, 'admin-sidebar-nav');
    $links = bms_navigation_test_links($nav);
    bms_navigation_test_expect(array_column($links, 'label') === ['Dashboard', 'Content', 'Media', 'Comments', 'Settings', 'System'], 'Six primary destinations retain their exact order');
    $active = array_values(array_filter($links, static fn(array $link): bool => isset($link['aria-current'])));
    bms_navigation_test_expect(count($active) === 1 && $active[0]['label'] === $label && $active[0]['aria-current'] === $current, $label . ' has the correct ' . $current . ' current state');
    bms_navigation_test_expect(!str_contains($nav, '<details') && !str_contains($nav, 'aria-expanded'), 'Primary navigation is flat native links without disclosure states');
}

function bms_navigation_test_remove_tree(string $path): void
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
        'site_name' => 'Navigation Fixture', 'site_tagline' => 'Saved tagline',
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
    bms_navigation_test_expect($ready, 'Isolated loopback server starts');
    fwrite(STDOUT, 'Runtime: PHP ' . PHP_VERSION . ' / ' . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . "\n");

    $guest = [];
    $sessions = [];
    foreach (['owner', 'commenter'] as $username) {
        $cookies = [];
        $login = bms_navigation_test_request($base, '/admin/login.php', $cookies);
        $login = bms_navigation_test_request($base, '/admin/login.php', $cookies, ['csrf_token' => bms_navigation_test_token($login['body']), 'username' => $username, 'password' => 'disposable-password']);
        bms_navigation_test_expect($login['status'] === 302, ucfirst($username) . ' fixture authenticates through real login');
        $sessions[$username] = $cookies;
    }

    // Expected destinations are deliberately independent of the navigation model.
    $settings = [
        'settings.php', 'settings-writing.php', 'settings-reading.php', 'site-identity.php',
        'theme.php', 'theme-settings.php', 'navigation.php', 'security.php',
        'registration.php', 'mail.php', 'activitypub.php', 'remote-posting.php',
    ];
    $routes = [
        'index.php' => ['Dashboard', 'page'], 'content.php' => ['Content', 'page'],
        'media.php' => ['Media', 'page'], 'comments.php' => ['Comments', 'page'],
        'settings-index.php' => ['Settings', 'page'], 'tools.php' => ['System', 'page'],
        'pages.php' => ['Content', 'location'], 'page-new.php' => ['Content', 'location'],
        'places.php' => ['Content', 'location'], 'place-edit.php' => ['Content', 'location'],
        'analytics.php' => ['Content', 'location'], 'revisions.php' => ['Content', 'location'],
        'media-upload.php' => ['Media', 'location'], 'media-regenerate.php' => ['Media', 'location'],
        'users.php' => ['Comments', 'location'], 'user-new.php' => ['Comments', 'location'],
        'user-edit.php?id=2' => ['Comments', 'location'],
        'theme-details.php?slug=default' => ['Settings', 'location'], 'theme-install.php' => ['Settings', 'location'],
        'system-check.php' => ['System', 'location'], 'scheduled-tasks.php' => ['System', 'location'],
        'export.php' => ['System', 'location'], 'import.php' => ['System', 'location'],
        'import-markdown.php' => ['System', 'location'], 'upgrade.php' => ['System', 'location'],
        'help.php' => ['System', 'location'],
    ];
    foreach ($settings as $route) {
        $routes[$route] = ['Settings', 'location'];
    }
    $pages = [];
    foreach ($routes as $route => [$area, $current]) {
        $path = '/admin/' . $route;
        $response = bms_navigation_test_request($base, $path, $guest);
        bms_navigation_test_expect($response['status'] === 302 && str_ends_with($response['location'], '/admin/login.php'), $route . ' redirects an unauthenticated visitor to login');
        $response = bms_navigation_test_request($base, $path, $sessions['commenter']);
        bms_navigation_test_expect($response['status'] === 403 && str_contains($response['body'], 'You do not have permission'), $route . ' rejects a reduced-permission commenter');
        $response = bms_navigation_test_request($base, $path, $sessions['owner']);
        bms_navigation_test_expect($response['status'] === 200, $route . ' renders through its existing direct URL for the owner');
        bms_navigation_test_active($response['body'], $area, $current);
        $pages[$route] = $response['body'];
    }
    // The shell supplies responsive variants of one global View site utility.
    // Visibility is a browser/CSS contract; these HTTP checks preserve all variants
    // while rejecting duplicate homepage-view actions in individual page content.
    $globalViewClasses = ['admin-topbar-site', 'admin-mobile-site', 'admin-sidebar-view-site'];
    foreach (['index.php', 'comments.php', 'activitypub.php', 'pages.php', 'theme.php',
        'theme-details.php?slug=default', 'theme-settings.php', 'site-identity.php',
        'navigation.php', 'settings-reading.php'] as $route) {
        foreach ($globalViewClasses as $class) {
            $links = bms_navigation_test_links($pages[$route], $class);
            bms_navigation_test_expect(count($links) === 1 && $links[0]['href'] === '/'
                && ($links[0]['target'] ?? '') === '_blank'
                && in_array('noopener', explode(' ', $links[0]['rel'] ?? ''), true)
                && str_contains($links[0]['label'], 'opens in a new tab'), $route . ' preserves the ' . $class . ' global utility');
        }
        $duplicates = array_filter(bms_navigation_test_links($pages[$route]), static function (array $link) use ($globalViewClasses): bool {
            return ($link['href'] ?? '') === '/'
                && preg_match('/^View (?:site|stream)(?:$|\s)/i', $link['label']) === 1
                && !array_intersect($globalViewClasses, explode(' ', $link['class'] ?? ''));
        });
        bms_navigation_test_expect($duplicates === [], $route . ' has no page-specific homepage View Site or View Stream action');
    }
    bms_navigation_test_expect((bool)array_filter(bms_navigation_test_links($pages['settings-writing.php']), static fn(array $link): bool => $link['label'] === 'Stream Composer' && $link['href'] === '/'), 'Writing retains its distinct Stream Composer publishing action');
    $welcome = bms_navigation_test_request($base, '/admin/welcome.php', $sessions['owner']);
    bms_navigation_test_expect($welcome['status'] === 200 && (bool)array_filter(bms_navigation_test_links($welcome['body']), static fn(array $link): bool => str_contains($link['label'], 'Post from the stream') && $link['href'] === '/'), 'Welcome retains its distinct Post from the stream publishing action');
    $index = $pages['settings-index.php'];
    $indexLinks = bms_navigation_test_links($index, 'settings-index-link');
    bms_navigation_test_expect(array_map(static fn(array $link): string => basename((string)parse_url($link['href'], PHP_URL_PATH)), $indexLinks) === $settings, 'Settings index exposes all twelve existing routes in approved order');
    foreach (['Site', 'Access', 'Connections'] as $heading) {
        bms_navigation_test_expect((bool)preg_match('/<h2\b[^>]*>\s*' . $heading . '\s*<\/h2>/', $index), 'Settings index has the ' . $heading . ' heading');
    }
    foreach ($settings as $route) {
        $back = bms_navigation_test_links($pages[$route], 'admin-back-link');
        bms_navigation_test_expect(count($back) === 1 && str_ends_with($back[0]['href'], '/admin/settings-index.php') && str_contains($back[0]['label'], 'Back to Settings'), $route . ' provides a native Back to Settings path');
    }
    bms_navigation_test_expect(str_contains($pages['settings.php'], 'admin-settings-local-nav'), 'General Settings retains a compact local settings landmark');
    bms_navigation_test_expect(!str_contains($pages['activitypub.php'], 'admin-settings-local-nav'), 'ActivityPub retains space for its own section navigation');
    foreach (['comments.php', 'users.php', 'user-new.php', 'user-edit.php?id=2'] as $route) {
        $context = bms_navigation_test_links(bms_navigation_test_landmark($pages[$route], 'admin-context-nav'));
        bms_navigation_test_expect(in_array('Comments', array_column($context, 'label'), true) && in_array('Commenter accounts', array_column($context, 'label'), true), $route . ' exposes Comments and Commenter accounts together');
    }
    $contentLinks = bms_navigation_test_links(bms_navigation_test_landmark($pages['content.php'], 'admin-context-nav'));
    foreach (['content.php', 'pages.php', 'places.php', 'analytics.php'] as $route) {
        bms_navigation_test_expect(in_array('/admin/' . $route, array_map(static fn(array $link): string => (string)parse_url($link['href'], PHP_URL_PATH), $contentLinks), true), 'Content provides direct contextual access to ' . $route);
    }
    bms_navigation_test_expect((bool)array_filter($contentLinks, static fn(array $link): bool => str_contains($link['href'], '?compose=1#stream-composer')), 'Frontend composer remains a secondary Content destination');
    bms_navigation_test_expect(!(bool)array_filter(bms_navigation_test_links($pages['content.php'], 'primary-button'), static fn(array $link): bool => str_contains($link['href'], '?compose=1')), 'Content does not promote the frontend composer to a primary Admin action');
    foreach (['system-check.php', 'scheduled-tasks.php', 'export.php', 'import.php', 'upgrade.php', 'help.php'] as $route) {
        bms_navigation_test_expect(str_contains($pages['tools.php'], '/admin/' . $route), 'System landing preserves ' . $route);
    }
    bms_navigation_test_expect(str_contains($pages['help.php'], '/admin/welcome.php'), 'Help preserves discoverable onboarding access');
    $primary = bms_navigation_test_landmark($index, 'admin-sidebar-nav');
    foreach (['_navigation.php', 'autosave.php', 'scheduled-runner.php', 'media-picker.php', 'quick-post.php', 'stream-like.php', 'places-save.php'] as $support) {
        bms_navigation_test_expect(!str_contains($primary, $support) && !str_contains(implode('', array_column($indexLinks, 'href')), $support), $support . ' is not exposed as a destination');
    }
    bms_navigation_test_expect(str_contains($index, 'href="#admin-main-content"') && str_contains($index, 'id="admin-main-content"'), 'Skip navigation target remains intact');
    preg_match('/<form\b([^>]*class="admin-sidebar-logout"[^>]*)>(.*?)<\/form>/s', $index, $logout);
    $logoutAttributes = bms_navigation_test_attributes($logout[1] ?? '');
    bms_navigation_test_expect(($logoutAttributes['method'] ?? '') === 'post' && str_ends_with($logoutAttributes['action'] ?? '', '/admin/logout.php') && str_contains($logout[2] ?? '', 'name="csrf_token"'), 'Sign out remains a CSRF-protected POST utility');

    $redirect = bms_navigation_test_request($base, '/admin/appearance.php', $sessions['owner']);
    bms_navigation_test_expect($redirect['status'] === 302 && str_ends_with($redirect['location'], '/admin/theme.php'), 'Legacy appearance bookmark retains its existing redirect');
    $redirect = bms_navigation_test_request($base, '/admin/new.php', $sessions['owner']);
    bms_navigation_test_expect($redirect['status'] === 302 && str_ends_with($redirect['location'], '/?compose=1#stream-composer'), 'Legacy new-post bookmark retains the frontend composer redirect');

    // Exercise the existing frontend creation path, then open and save its backend editor.
    $public = bms_navigation_test_request($base, '/?compose=1', $sessions['owner']);
    bms_navigation_test_expect($public['status'] === 200 && !str_contains($public['body'], 'assets/admin-shell.css') && !str_contains($public['body'], 'settings-index-link'), 'Authenticated frontend composer remains isolated from Admin navigation/styles');
    preg_match('/name="composer_request_key"\s+value="([^"]+)"/', $public['body'], $requestKey);
    bms_navigation_test_expect(isset($requestKey[1]), 'Frontend composer supplies its existing request guard');
    $draft = bms_navigation_test_request($base, '/admin/quick-post.php', $sessions['owner'], [
        'csrf_token' => bms_navigation_test_token($public['body']), 'composer_request_key' => html_entity_decode($requestKey[1], ENT_QUOTES, 'UTF-8'),
        'stream_body' => 'Navigation fixture draft body.', 'stream_title' => 'Navigation fixture draft',
        'stream_slug' => 'navigation-fixture-draft', 'stream_submit_action' => 'continue', 'return_to' => $base . '/',
    ]);
    bms_navigation_test_expect($draft['status'] === 302 && str_contains($draft['location'], '/admin/edit.php?type=draft&file='), 'Frontend draft creation retains the continue-in-backend path');
    $editorPath = '/admin/edit.php?type=draft&file=navigation-fixture-draft.md';
    $editor = bms_navigation_test_request($base, $editorPath, $sessions['owner']);
    bms_navigation_test_expect($editor['status'] === 200 && str_contains($editor['body'], 'Navigation fixture draft body.'), 'Backend editor loads the saved draft through its direct URL');
    bms_navigation_test_active($editor['body'], 'Content', 'location');
    $saved = bms_navigation_test_request($base, $editorPath, $sessions['owner'], [
        'csrf_token' => bms_navigation_test_token($editor['body']), 'type' => 'draft', 'file' => 'navigation-fixture-draft.md',
        'stream_title' => 'Navigation fixture draft', 'stream_slug' => 'navigation-fixture-draft',
        'body_markdown' => 'Backend saved body.', 'stream_submit_action' => 'save',
    ]);
    $body = $pdo->query("SELECT content_body FROM `{$prefix}posts` WHERE slug='navigation-fixture-draft' AND status='draft'")->fetchColumn();
    bms_navigation_test_expect($saved['status'] === 302 && trim((string)$body) === 'Backend saved body.', 'Backend editor preserves real draft save behavior');
    $revisions = bms_navigation_test_request($base, '/admin/revisions.php?slug=navigation-fixture-draft', $sessions['owner']);
    bms_navigation_test_expect($revisions['status'] === 200 && str_contains($revisions['body'], 'navigation-fixture-draft'), 'Backend revision history remains reachable');
    bms_navigation_test_active($revisions['body'], 'Content', 'location');
    $page = bms_navigation_test_request($base, '/admin/page-new.php', $sessions['owner'], [
        'csrf_token' => bms_navigation_test_token($pages['page-new.php']), 'page_title' => 'Navigation fixture page',
        'page_slug' => 'navigation-fixture-page', 'body_markdown' => 'Page fixture body.', 'page_submit_action' => 'draft',
    ]);
    bms_navigation_test_expect($page['status'] === 302 && str_contains($page['location'], '/admin/page-edit.php?type=draft&file='), 'Backend Page creation retains its existing form endpoint and redirect');
    $pageEditor = bms_navigation_test_request($base, '/admin/page-edit.php?type=draft&file=navigation-fixture-page.md', $sessions['owner']);
    bms_navigation_test_expect($pageEditor['status'] === 200 && str_contains($pageEditor['body'], 'Page fixture body.'), 'Backend Page editor remains functional');
    bms_navigation_test_active($pageEditor['body'], 'Content', 'location');
    // Contextual public-object links must survive a cleanup of generic site links.
    $publishedPost = bms_navigation_test_request($base, '/admin/publish.php', $sessions['owner'], [
        'csrf_token' => bms_navigation_test_token($editor['body']), 'file' => 'navigation-fixture-draft.md',
    ]);
    bms_navigation_test_expect($publishedPost['status'] === 302 && str_contains($publishedPost['location'], '/admin/edit.php?type=published'), 'Disposable post publishes for contextual View Post verification');
    // Seed the existing disposable Page as published. This tests link rendering,
    // not the separately owned Page draft-to-published transition workflow.
    $publishedPage = $pdo->prepare("UPDATE `{$prefix}posts` SET status='published', markdown_path='content/pages/published/navigation-fixture-page.md', html_path='pages/navigation-fixture-page/index.html', published_at=UTC_TIMESTAMP() WHERE slug='navigation-fixture-page' AND post_type='page' AND status='draft'");
    $publishedPage->execute();
    bms_navigation_test_expect($publishedPage->rowCount() === 1, 'Published Page fixture is ready for contextual View Page verification');
    foreach ([
        ['/admin/quick-edit.php?type=published&file=navigation-fixture-draft.md', 'View Post', '/stream/navigation-fixture-draft/'],
        ['/admin/content.php?status=published', 'View post', '/stream/navigation-fixture-draft/'],
        ['/admin/page-edit.php?type=published&file=navigation-fixture-page.md', 'View Page', '/pages/navigation-fixture-page/'],
        ['/admin/pages.php?status=published', 'View page', '/pages/navigation-fixture-page/'],
    ] as [$route, $label, $href]) {
        $response = bms_navigation_test_request($base, $route, $sessions['owner']);
        $links = array_filter(bms_navigation_test_links($response['body']), static fn(array $link): bool => strcasecmp($link['label'], $label) === 0 && $link['href'] === $href && ($link['target'] ?? '') === '_blank' && in_array('noopener', explode(' ', $link['rel'] ?? ''), true));
        bms_navigation_test_expect($response['status'] === 200 && $links !== [], $route . ' preserves its contextual ' . $label . ' link to the published object');
    }
    bms_navigation_test_expect(str_contains($pages['user-edit.php?id=2'], 'name="confirm_username"') && str_contains($pages['user-edit.php?id=2'], 'data-confirm="Delete this account?'), 'Commenter account deletion retains its explicit confirmation controls');
    bms_navigation_test_expect((int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}migrations`")->fetchColumn() === $migrationCount, 'Navigation and publishing requests introduce no migrations');

    $invalidLogout = bms_navigation_test_request($base, '/admin/logout.php', $sessions['owner'], ['csrf_token' => 'invalid']);
    bms_navigation_test_expect($invalidLogout['status'] === 403, 'Sign out rejects an invalid CSRF token');
    $stillAuthenticated = bms_navigation_test_request($base, '/admin/settings-index.php', $sessions['owner']);
    bms_navigation_test_expect($stillAuthenticated['status'] === 200, 'Rejected sign out preserves the authenticated session');
    $validLogout = bms_navigation_test_request($base, '/admin/logout.php', $sessions['owner'], ['csrf_token' => bms_navigation_test_token($stillAuthenticated['body'])]);
    bms_navigation_test_expect($validLogout['status'] === 302 && str_ends_with($validLogout['location'], '/admin/login.php'), 'Valid sign out returns to login');
    $afterLogout = bms_navigation_test_request($base, '/admin/settings-index.php', $sessions['owner']);
    bms_navigation_test_expect($afterLogout['status'] === 302, 'Sign out ends access to the new Settings index');
    fwrite(STDOUT, 'Admin navigation runtime regression passed: ' . $checks . " checks.\n");
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
    bms_navigation_test_remove_tree($temporary);
}

<?php
/**
 * Admin Comments and Commenter accounts HTTP contract regression test against a disposable install.
 *
 * Uses BMS_DB_HOST, BMS_DB_NAME, BMS_DB_USER, BMS_DB_PASS and requires
 * BMS_DB_DANGER_RESET=1. Only random bms_comments_ci_* tables are created and
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
$temporary = sys_get_temp_dir() . '/bms-comments-test-' . bin2hex(random_bytes(6));
$prefix = 'bms_comments_ci_' . bin2hex(random_bytes(5)) . '_';
$process = null;
$pdo = null;
$checks = 0;

function bms_comments_test_expect(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
    $GLOBALS['checks']++;
    fwrite(STDOUT, 'PASS ' . $label . "\n");
}

function bms_comments_test_request(string $base, string $path, array &$cookies, ?array $form = null): array
{
    $headers = ['User-Agent: Bonumark Comments Regression', 'Connection: close'];
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

function bms_comments_test_token(string $html): string
{
    if (!preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $match)) {
        throw new RuntimeException('Missing CSRF token in rendered form.');
    }
    return html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
}

/** Return only the selected content landmark, never similarly named page content. */
function bms_comments_test_landmark(string $html, string $class): string
{
    preg_match_all('/<nav\b([^>]*)>(.*?)<\/nav>/s', $html, $matches, PREG_SET_ORDER);
    foreach ($matches as $match) {
        $attributes = bms_comments_test_attributes($match[1]);
        if (in_array($class, explode(' ', $attributes['class'] ?? ''), true)) {
            return $match[2];
        }
    }
    throw new RuntimeException('Missing content landmark: ' . $class);
}

function bms_comments_test_attributes(string $markup): array
{
    preg_match_all('/([a-zA-Z-]+)="([^"]*)"/', $markup, $matches, PREG_SET_ORDER);
    $attributes = [];
    foreach ($matches as $match) {
        $attributes[$match[1]] = html_entity_decode($match[2], ENT_QUOTES, 'UTF-8');
    }
    return $attributes;
}

function bms_comments_test_links(string $html, string $class = ''): array
{
    preg_match_all('/<a\b([^>]*)>(.*?)<\/a>/s', $html, $matches, PREG_SET_ORDER);
    $links = [];
    foreach ($matches as $match) {
        $attributes = bms_comments_test_attributes($match[1]);
        if ($class !== '' && !in_array($class, explode(' ', $attributes['class'] ?? ''), true)) {
            continue;
        }
        $attributes['label'] = trim(html_entity_decode(strip_tags($match[2]), ENT_QUOTES, 'UTF-8'));
        $links[] = $attributes;
    }
    return $links;
}

function bms_comments_test_ids(string $html): array
{
    preg_match_all('/<input\b[^>]*name="selected\[\]"[^>]*value="([^"]+)"/', $html, $matches);
    return array_map('intval', $matches[1]);
}

function bms_comments_test_row(PDO $pdo, string $prefix, string $table, int $id): array
{
    if (!in_array($table, ['comments', 'users', 'posts'], true)) {
        throw new RuntimeException('Unexpected fixture table.');
    }
    $statement = $pdo->prepare("SELECT * FROM `{$prefix}{$table}` WHERE id=?");
    $statement->execute([$id]);
    return $statement->fetch() ?: [];
}

function bms_comments_test_account_ids(string $html): array
{
    preg_match_all('/<article\b[^>]*class="account-record"[^>]*>(.*?)<\/article>/s', $html, $records);
    $ids = [];
    foreach ($records[1] as $record) {
        if (!preg_match('/href="[^"]*user-edit\.php\?id=(\d+)"/', $record, $match)) {
            throw new RuntimeException('Account record has no management destination.');
        }
        $ids[] = (int)$match[1];
    }
    return $ids;
}

function bms_comments_test_has_error(string $html): bool
{
    return preg_match('/class="[^"]*flash[^"]*error[^"]*"/', $html) === 1;
}

function bms_comments_test_remove_tree(string $path): void
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
        'site_name' => 'Comments Fixture', 'site_tagline' => 'Saved tagline',
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
    bms_comments_test_expect(!$pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES), 'Matching searches run with native PDO prepared statements');
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
    bms_comments_test_expect($ready, 'Isolated loopback server starts');
    fwrite(STDOUT, 'Runtime: PHP ' . PHP_VERSION . ' / ' . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . "\n");

    $guest = [];
    $sessions = [];
    foreach (['owner', 'commenter'] as $username) {
        $cookies = [];
        $login = bms_comments_test_request($base, '/admin/login.php', $cookies);
        $login = bms_comments_test_request($base, '/admin/login.php', $cookies, ['csrf_token' => bms_comments_test_token($login['body']), 'username' => $username, 'password' => 'disposable-password']);
        bms_comments_test_expect($login['status'] === 302, ucfirst($username) . ' fixture authenticates through real login');
        $sessions[$username] = $cookies;
    }


    $owner =& $sessions['owner'];
    $ownerId = (int)$pdo->query("SELECT id FROM `{$prefix}users` WHERE username='owner'")->fetchColumn();
    $commenterId = (int)$pdo->query("SELECT id FROM `{$prefix}users` WHERE username='commenter'")->fetchColumn();
    $empty = bms_comments_test_request($base, '/admin/comments.php', $owner);
    $token = bms_comments_test_token($empty['body']);
    foreach (['approved', 'pending', 'trash'] as $status) {
        $response = bms_comments_test_request($base, '/admin/comments.php?status=' . $status, $owner);
        bms_comments_test_expect($response['status'] === 200 && bms_comments_test_ids($response['body']) === [] && str_contains(strtolower($response['body']), 'no ' . $status . ' comments'), ucfirst($status) . ' empty state is accurate');
    }

    // Distinct searchable fields make a missing OR condition observable. All
    // data is synthetic, inserted only into this run's random table prefix.
    $userSeed = $pdo->prepare("INSERT INTO `{$prefix}users` (username,display_name,email,email_verified_at,password_hash,role,status,created_at,updated_at,verification_token_hash,verification_token_expires_at) VALUES (?,?,?,?,?,'commenter',?,'2026-01-01 12:00:00','2026-01-01 12:00:00',?,?)");
    $userIds = ['owner' => $ownerId, 'commenter' => $commenterId];
    foreach ([
        ['cedarhandle', 'Birch Display', 'active', true],
        ['approval', 'Approval Fixture', 'pending', true],
        ['verification', 'Verification Fixture', 'pending', false],
        ['inactive', 'Inactive Fixture', 'inactive', true],
    ] as [$username, $displayName, $status, $verified]) {
        $userSeed->execute([$username, $displayName, $username . '@example.test', $verified ? '2026-01-01 12:00:00' : null, password_hash('disposable-password', PASSWORD_DEFAULT), $status, $verified ? null : hash('sha256', 'fixture-token'), $verified ? null : '2099-01-01 00:00:00']);
        $userIds[$username] = (int)$pdo->lastInsertId();
    }
    $posts = [];
    foreach ([['elm-slug', 'Dahlia title'], ['other-post', 'Other title']] as [$slug, $title]) {
        bms_upsert_database_content(['slug' => $slug, 'title' => $title, 'description' => '', 'body' => 'Synthetic published Stream body.', 'date' => '2026-01-01', 'stream_created_at' => '2026-01-01 12:00:00'], 'published', $slug . '.md', $ownerId);
        $posts[$slug] = (int)$pdo->query("SELECT id FROM `{$prefix}posts` WHERE slug=" . $pdo->quote($slug))->fetchColumn();
    }
    $seed = $pdo->prepare("INSERT INTO `{$prefix}comments` (post_slug,post_id,user_id,body,status,created_at,updated_at,approved_at) VALUES (?,?,?,?,?,?,?,?)");
    $ids = [];
    $longBody = '<script>window.work30Injected=true</script><img src=x onerror=alert(1)> & "quoted" ' . str_repeat('Long comment without truncation. ', 120) . str_repeat('x', 600) . ' final-body-marker';
    foreach ([
        ['approved', 'elm-slug', $userIds['cedarhandle'], 'Amber body marker.', 'approved', '2026-01-01 12:00:00'],
        ['newer', 'other-post', $commenterId, 'Newer approved reply.', 'approved', '2026-01-02 12:00:00'],
        ['pending', 'elm-slug', $userIds['cedarhandle'], 'Pending amber reply.', 'pending', '2026-01-03 12:00:00'],
        ['trash', 'elm-slug', $userIds['cedarhandle'], 'Trashed amber reply.', 'trash', '2026-01-04 12:00:00'],
        ['long', 'other-post', $commenterId, $longBody, 'pending', '2026-01-05 12:00:00'],
    ] as [$key, $slug, $author, $body, $status, $date]) {
        $seed->execute([$slug, $posts[$slug], $author, $body, $status, $date, $date, $status === 'approved' ? $date : null]);
        $ids[$key] = (int)$pdo->lastInsertId();
    }
    $commentsBefore = $pdo->query("SELECT * FROM `{$prefix}comments` ORDER BY id")->fetchAll();
    $usersBefore = $pdo->query("SELECT * FROM `{$prefix}users` ORDER BY id")->fetchAll();
    foreach (['approved' => [$ids['newer'], $ids['approved']], 'pending' => [$ids['long'], $ids['pending']], 'trash' => [$ids['trash']]] as $status => $expected) {
        $response = bms_comments_test_request($base, '/admin/comments.php?status=' . $status, $owner);
        bms_comments_test_expect($response['status'] === 200 && bms_comments_test_ids($response['body']) === $expected, ucfirst($status) . ' view preserves status isolation and newest-first ordering');
        $filters = bms_comments_test_links(bms_comments_test_landmark($response['body'], 'comments-content-filter'));
        $labels = array_map(static fn(array $link): string => preg_replace('/\s+/', ' ', $link['label']), $filters);
        bms_comments_test_expect($labels === ['Approved 2', 'Pending 2', 'Trash 1'], ucfirst($status) . ' view shows exact global status counts');
        bms_comments_test_expect(count(array_filter($filters, static fn(array $link): bool => ($link['aria-current'] ?? '') === 'page')) === 1, ucfirst($status) . ' view exposes the current status to assistive technology');
        $context = bms_comments_test_links(bms_comments_test_landmark($response['body'], 'admin-context-nav'));
        foreach (['/admin/comments.php', '/admin/users.php'] as $destination) {
            bms_comments_test_expect(in_array($destination, array_column($context, 'href'), true), 'Comments contextual navigation retains ' . $destination);
        }
    }
    $response = bms_comments_test_request($base, '/admin/comments.php?status=invalid', $owner);
    bms_comments_test_expect(bms_comments_test_ids($response['body']) === [$ids['long'], $ids['pending']], 'Unknown comment status retains pending normalization');
    $pending = bms_comments_test_request($base, '/admin/comments.php?status=pending', $owner);
    bms_comments_test_expect(str_contains($pending['body'], htmlspecialchars($longBody, ENT_QUOTES, 'UTF-8')) && !str_contains($pending['body'], '<script>window.work30Injected'), 'Comment markup is escaped and long text remains complete');
    bms_comments_test_expect(str_contains($pending['body'], 'form="comment-bulk-form"') && str_contains($pending['body'], 'name="selected[]"'), 'Record selection remains associated with the native bulk form');
    bms_comments_test_expect(preg_match('/<label[^>]*for="comments_q"[^>]*>Search comments<\/label>/', $pending['body']) === 1, 'Comment search has an explicit label');
    $links = bms_comments_test_links($pending['body']);
    foreach (['/stream/elm-slug/', '/admin/user-edit.php?id=' . $userIds['cedarhandle']] as $destination) {
        bms_comments_test_expect(in_array($destination, array_column($links, 'href'), true), 'Record retains correct destination ' . $destination);
    }

    // No known-defect bypass: positive matches must return these exact IDs under
    // native prepares. Reusing a placeholder or removing one OR must fail here.
    foreach (['approved', 'pending', 'trash'] as $status) {
        $expectedId = $ids[$status];
        foreach (['Amber', 'Birch', 'cedarhandle', 'Dahlia', 'elm-slug', '  aMbEr  ', 'A%ber'] as $query) {
            $response = bms_comments_test_request($base, '/admin/comments.php?status=' . $status . '&q=' . rawurlencode($query), $owner);
            bms_comments_test_expect($response['status'] === 200 && bms_comments_test_ids($response['body']) === [$expectedId], ucfirst($status) . ' matching search: ' . $query);
        }
        foreach (['no-such-fixture', "' OR 1=1 --"] as $query) {
            $response = bms_comments_test_request($base, '/admin/comments.php?status=' . $status . '&q=' . rawurlencode($query), $owner);
            bms_comments_test_expect($response['status'] === 200 && bms_comments_test_ids($response['body']) === [] && str_contains($response['body'], 'No matching comments.'), ucfirst($status) . ' no-match search is safe: ' . $query);
        }
    }
    $searched = bms_comments_test_request($base, '/admin/comments.php?status=trash&q=Amber', $owner);
    $filters = bms_comments_test_links(bms_comments_test_landmark($searched['body'], 'comments-content-filter'));
    bms_comments_test_expect(count(array_filter($filters, static fn(array $link): bool => str_contains($link['href'], 'q=Amber'))) === 3, 'Status links preserve the active query');
    bms_comments_test_expect($commentsBefore === $pdo->query("SELECT * FROM `{$prefix}comments` ORDER BY id")->fetchAll(), 'Comment listing and searches preserve every record');

    // Account filtering preserves the single Admin among participation accounts.
    $expectedUsers = ['all' => [$userIds['approval'], $userIds['cedarhandle'], $commenterId, $userIds['inactive'], $ownerId, $userIds['verification']], 'active' => [$userIds['cedarhandle'], $commenterId, $ownerId], 'pending' => [$userIds['approval'], $userIds['verification']], 'inactive' => [$userIds['inactive']]];
    foreach ($expectedUsers as $status => $expected) {
        $response = bms_comments_test_request($base, '/admin/users.php?status=' . $status, $owner);
        bms_comments_test_expect($response['status'] === 200 && bms_comments_test_account_ids($response['body']) === $expected, 'Account filter and name ordering: ' . $status);
        $filters = bms_comments_test_links(bms_comments_test_landmark($response['body'], 'accounts-content-filter'));
        $labels = array_map(static fn(array $link): string => preg_replace('/\s+/', ' ', $link['label']), $filters);
        bms_comments_test_expect($labels === ['All 6', 'Active 3', 'Pending 2', 'Inactive 1'], 'Account filter counts: ' . $status);
    }
    $accounts = bms_comments_test_request($base, '/admin/users.php', $owner);
    bms_comments_test_expect(str_contains(strtolower($accounts['body']), 'email verification') && str_contains(strtolower($accounts['body']), 'admin approval') && str_contains(strtolower($accounts['body']), 'not verified'), 'Accounts distinguish pending verification, pending approval and verification status');
    $links = bms_comments_test_links($accounts['body']);
    foreach (['/admin/user-new.php', '/admin/user-edit.php?id=' . $commenterId, '/profile/commenter'] as $destination) {
        bms_comments_test_expect(in_array($destination, array_column($links, 'href'), true), 'Accounts retain destination ' . $destination);
    }
    bms_comments_test_expect($usersBefore === $pdo->query("SELECT * FROM `{$prefix}users` ORDER BY id")->fetchAll(), 'Account views do not verify emails or activate pending accounts');

    foreach ([['pending', 'approve', 'approved'], ['pending', 'pending', 'pending'], ['pending', 'trash', 'trash'], ['pending', 'approve', 'approved'], ['trash', 'pending', 'pending'], ['trash', 'trash', 'trash']] as [$key, $action, $expectedStatus]) {
        $response = bms_comments_test_request($base, '/admin/comments.php?status=pending&q=Amber', $owner, ['csrf_token' => $token, 'moderation_action' => $action, 'comment_id' => $ids[$key]]);
        $record = bms_comments_test_row($pdo, $prefix, 'comments', $ids[$key]);
        bms_comments_test_expect($response['status'] === 302 && $response['location'] === '/admin/comments.php?status=pending&q=Amber' && $record['status'] === $expectedStatus, 'Individual ' . $action . ' preserves requested view/query and changes selected ' . $key);
    }
    $approvedAt = bms_comments_test_row($pdo, $prefix, 'comments', $ids['pending'])['approved_at'];
    bms_comments_test_expect($approvedAt !== null, 'Approval records its timestamp');
    foreach (['bulk_approve' => 'approved', 'bulk_pending' => 'pending', 'bulk_trash' => 'trash'] as $action => $status) {
        $response = bms_comments_test_request($base, '/admin/comments.php', $owner, ['csrf_token' => $token, 'moderation_action' => $action, 'selected' => [$ids['pending'], $ids['trash'], $ids['pending']]]);
        bms_comments_test_expect($response['status'] === 302 && bms_comments_test_row($pdo, $prefix, 'comments', $ids['pending'])['status'] === $status && bms_comments_test_row($pdo, $prefix, 'comments', $ids['trash'])['status'] === $status, $action . ' processes selected IDs and deduplicates duplicates');
    }
    bms_comments_test_expect(bms_comments_test_row($pdo, $prefix, 'comments', $ids['pending'])['approved_at'] === $approvedAt, 'Status cycling preserves the original approval timestamp');
    $response = bms_comments_test_request($base, '/admin/comments.php', $owner, ['csrf_token' => $token, 'moderation_action' => 'bulk_trash', 'selected' => [$ids['pending'], $ids['trash']]]);
    $feedback = bms_comments_test_request($base, '/admin/comments.php', $owner);
    bms_comments_test_expect($response['status'] === 302 && !bms_comments_test_has_error($feedback['body']) && bms_comments_test_row($pdo, $prefix, 'comments', $ids['pending'])['status'] === 'trash' && bms_comments_test_row($pdo, $prefix, 'comments', $ids['trash'])['status'] === 'trash', 'Repeated bulk request recognizes already-satisfied targets without false failure');

    $response = bms_comments_test_request($base, '/admin/comments.php', $owner, ['csrf_token' => $token, 'moderation_action' => 'bulk_pending', 'selected' => [$ids['pending'], 999999]]);
    $feedback = bms_comments_test_request($base, '/admin/comments.php', $owner);
    bms_comments_test_expect($response['status'] === 302 && bms_comments_test_row($pdo, $prefix, 'comments', $ids['pending'])['status'] === 'pending' && bms_comments_test_has_error($feedback['body']), 'A partially completed bulk status change reports an error alongside its actual outcome');
    foreach ([['bulk_approve', []], ['bulk_invalid', [$ids['approved']]], ['invalid', []], ['approve', []]] as [$action, $selected]) {
        $before = $pdo->query("SELECT * FROM `{$prefix}comments` ORDER BY id")->fetchAll();
        $response = bms_comments_test_request($base, '/admin/comments.php', $owner, ['csrf_token' => $token, 'moderation_action' => $action, 'selected' => $selected, 'comment_id' => 999999]);
        $feedback = bms_comments_test_request($base, '/admin/comments.php', $owner);
        bms_comments_test_expect($response['status'] === 302 && bms_comments_test_has_error($feedback['body']) && $before === $pdo->query("SELECT * FROM `{$prefix}comments` ORDER BY id")->fetchAll(), 'Invalid or empty moderation request reports failure without mutation: ' . $action);
    }

    $preview = bms_comments_test_request($base, '/admin/comments.php?status=pending', $owner, ['csrf_token' => $token, 'moderation_action' => 'delete', 'comment_id' => $ids['long']]);
    bms_comments_test_expect($preview['status'] === 200 && str_contains($preview['body'], '&lt;script&gt;') && !str_contains($preview['body'], '<script>window.work30Injected') && bms_comments_test_row($pdo, $prefix, 'comments', $ids['long'])['body'] === $longBody, 'Deletion preview escapes hostile comment excerpts and preserves its target');
    $before = $pdo->query("SELECT * FROM `{$prefix}comments` ORDER BY id")->fetchAll();
    $confirmation = bms_comments_test_request($base, '/admin/comments.php?status=trash&q=Amber', $owner, ['csrf_token' => $token, 'moderation_action' => 'delete', 'comment_id' => $ids['trash']]);
    bms_comments_test_expect($confirmation['status'] === 200 && str_contains($confirmation['body'], 'name="confirm_delete" value="1"') && str_contains($confirmation['body'], 'cannot be undone') && $before === $pdo->query("SELECT * FROM `{$prefix}comments` ORDER BY id")->fetchAll(), 'Initial deletion POST renders explicit native confirmation and deletes nothing');
    bms_comments_test_expect(str_contains($confirmation['body'], 'status=trash&amp;q=Amber'), 'Deletion confirmation retains the originating filter and search');
    $response = bms_comments_test_request($base, '/admin/comments.php?status=trash', $owner, ['csrf_token' => $token, 'moderation_action' => 'delete', 'comment_id' => $ids['trash'], 'confirm_delete' => '1']);
    bms_comments_test_expect($response['status'] === 302 && bms_comments_test_row($pdo, $prefix, 'comments', $ids['trash']) === [], 'Confirmed deletion removes the selected trashed comment');
    $response = bms_comments_test_request($base, '/admin/comments.php', $owner, ['csrf_token' => $token, 'moderation_action' => 'delete', 'comment_id' => $ids['approved'], 'confirm_delete' => '1']);
    $feedback = bms_comments_test_request($base, '/admin/comments.php', $owner);
    bms_comments_test_expect($response['status'] === 302 && bms_comments_test_has_error($feedback['body']) && bms_comments_test_row($pdo, $prefix, 'comments', $ids['approved'])['status'] === 'approved', 'Forged confirmed deletion cannot remove an approved comment');
    bms_comments_test_request($base, '/admin/comments.php', $owner, ['csrf_token' => $token, 'moderation_action' => 'trash', 'comment_id' => $ids['pending']]);
    $before = $pdo->query("SELECT * FROM `{$prefix}comments` ORDER BY id")->fetchAll();
    $confirmation = bms_comments_test_request($base, '/admin/comments.php?status=trash', $owner, ['csrf_token' => $token, 'moderation_action' => 'bulk_delete', 'selected' => [$ids['pending'], $ids['approved'], 999999]]);
    bms_comments_test_expect($confirmation['status'] === 200 && str_contains($confirmation['body'], 'name="confirm_delete" value="1"') && $before === $pdo->query("SELECT * FROM `{$prefix}comments` ORDER BY id")->fetchAll(), 'Bulk deletion also requires native confirmation before mutation');
    $response = bms_comments_test_request($base, '/admin/comments.php?status=trash', $owner, ['csrf_token' => $token, 'moderation_action' => 'bulk_delete', 'selected' => [$ids['pending'], $ids['approved'], 999999], 'confirm_delete' => '1']);
    $feedback = bms_comments_test_request($base, '/admin/comments.php?status=trash', $owner);
    bms_comments_test_expect($response['status'] === 302 && bms_comments_test_row($pdo, $prefix, 'comments', $ids['pending']) === [] && bms_comments_test_row($pdo, $prefix, 'comments', $ids['approved']) !== [] && bms_comments_test_has_error($feedback['body']), 'Partial bulk deletion reports failure and preserves non-Trash targets');
    $response = bms_comments_test_request($base, '/admin/comments.php?status=trash', $owner, ['csrf_token' => $token, 'moderation_action' => 'bulk_delete', 'selected' => [$ids['approved'], 999999], 'confirm_delete' => '1']);
    $feedback = bms_comments_test_request($base, '/admin/comments.php?status=trash', $owner);
    bms_comments_test_expect($response['status'] === 302 && bms_comments_test_has_error($feedback['body']), 'Zero-completion bulk deletion is an error');

    // Preserve the existing owner override: activation verifies email and
    // clears tokens. Merely visiting a list never performs that override.
    foreach (['approval', 'verification', 'inactive'] as $key) {
        $response = bms_comments_test_request($base, '/admin/users.php?status=pending', $owner, ['csrf_token' => $token, 'action' => 'set_status', 'user_id' => $userIds[$key], 'status' => 'active', 'return_status' => 'pending', 'role' => 'admin']);
        $record = bms_comments_test_row($pdo, $prefix, 'users', $userIds[$key]);
        bms_comments_test_expect($response['status'] === 302 && $response['location'] === '/admin/users.php?status=pending' && $record['status'] === 'active' && $record['role'] === 'commenter' && $record['email_verified_at'] !== null && $record['verification_token_hash'] === null, 'Owner activation preserves verification semantics and rejects role escalation: ' . $key);
    }
    foreach (['pending', 'inactive'] as $status) {
        $response = bms_comments_test_request($base, '/admin/users.php', $owner, ['csrf_token' => $token, 'action' => 'set_status', 'user_id' => $userIds['approval'], 'status' => $status]);
        bms_comments_test_expect($response['status'] === 302 && bms_comments_test_row($pdo, $prefix, 'users', $userIds['approval'])['status'] === $status, 'Existing account status action preserves ' . $status);
        $response = bms_comments_test_request($base, '/admin/users.php', $owner, ['csrf_token' => $token, 'action' => 'set_status', 'user_id' => $ownerId, 'status' => $status]);
        $feedback = bms_comments_test_request($base, '/admin/users.php', $owner);
        bms_comments_test_expect($response['status'] === 302 && bms_comments_test_has_error($feedback['body']) && bms_comments_test_row($pdo, $prefix, 'users', $ownerId)['status'] === 'active', 'Sole Admin cannot be made ' . $status);
    }
    foreach (['approve' => 'active', 'deactivate' => 'inactive'] as $action => $status) {
        $response = bms_comments_test_request($base, '/admin/users.php', $owner, ['csrf_token' => $token, 'action' => $action, 'user_id' => $userIds['approval']]);
        bms_comments_test_expect($response['status'] === 302 && bms_comments_test_row($pdo, $prefix, 'users', $userIds['approval'])['status'] === $status, 'Legacy account action alias remains valid: ' . $action);
    }
    $response = bms_comments_test_request($base, '/admin/users.php?status=pending', $owner);
    bms_comments_test_expect($response['status'] === 200 && bms_comments_test_account_ids($response['body']) === [] && str_contains($response['body'], 'No pending accounts.'), 'Empty account status is clearly reported');
    $response = bms_comments_test_request($base, '/admin/users.php', $owner, ['csrf_token' => $token, 'action' => 'approve', 'user_id' => 999999]);
    $feedback = bms_comments_test_request($base, '/admin/users.php', $owner);
    bms_comments_test_expect($response['status'] === 302 && bms_comments_test_has_error($feedback['body']), 'Unknown account action target reports failure');

    // Existing account creation/editor endpoints keep fixed commenter roles,
    // password hashing and deletion safeguards without redesigning their forms.
    $response = bms_comments_test_request($base, '/admin/user-new.php', $owner, ['csrf_token' => $token, 'new_commenter_username' => 'created', 'new_commenter_display_name' => 'Created Fixture', 'new_commenter_email' => 'created@example.test', 'new_commenter_password' => 'temporary-cedar-river-42', 'role' => 'admin']);
    $created = $pdo->query("SELECT * FROM `{$prefix}users` WHERE username='created'")->fetch();
    bms_comments_test_expect($response['status'] === 302 && is_array($created) && $created['role'] === 'commenter' && password_verify('temporary-cedar-river-42', $created['password_hash']), 'Add Commenter creates only a commenter and stores a secure password hash');
    $createdId = (int)$created['id'];
    $editPath = '/admin/user-edit.php?id=' . $createdId;
    $response = bms_comments_test_request($base, $editPath, $owner, ['csrf_token' => $token, 'action' => 'update_account', 'username' => 'created', 'display_name' => 'Edited Fixture', 'email' => 'edited@example.test', 'status' => 'active', 'profile_visibility' => 'private', 'email_verified' => '1', 'role' => 'admin']);
    $edited = bms_comments_test_row($pdo, $prefix, 'users', $createdId);
    bms_comments_test_expect($response['status'] === 302 && $edited['display_name'] === 'Edited Fixture' && $edited['role'] === 'commenter' && $edited['profile_visibility'] === 'private' && $edited['password_hash'] === $created['password_hash'], 'Existing account edit preserves fixed role and password while saving authorized fields');
    $privateProfile = bms_comments_test_request($base, '/profile.php?username=created', $guest);
    $ownerProfile = bms_comments_test_request($base, '/profile.php?username=created', $owner);
    $publicProfile = bms_comments_test_request($base, '/profile.php?username=commenter', $guest);
    bms_comments_test_expect($privateProfile['status'] === 404 && $ownerProfile['status'] === 200 && $publicProfile['status'] === 200, 'Public profile visibility preserves private-account protection and owner access');
    $response = bms_comments_test_request($base, $editPath, $owner, ['csrf_token' => $token, 'action' => 'reset_password', 'new_password' => 'replacement-summit-river-84', 'confirm_password' => 'different']);
    bms_comments_test_expect($response['status'] === 302 && bms_comments_test_row($pdo, $prefix, 'users', $createdId)['password_hash'] === $created['password_hash'], 'Mismatched password confirmation preserves the current hash');
    $response = bms_comments_test_request($base, $editPath, $owner, ['csrf_token' => $token, 'action' => 'reset_password', 'new_password' => 'replacement-summit-river-84', 'confirm_password' => 'replacement-summit-river-84']);
    bms_comments_test_expect($response['status'] === 302 && password_verify('replacement-summit-river-84', bms_comments_test_row($pdo, $prefix, 'users', $createdId)['password_hash']), 'Valid existing password reset continues to hash the replacement');
    foreach ([['wrong', $ownerId], ['created', $createdId], ['created', 999999]] as [$confirmationName, $reassignId]) {
        $response = bms_comments_test_request($base, $editPath, $owner, ['csrf_token' => $token, 'action' => 'delete_user', 'confirm_username' => $confirmationName, 'reassign_to' => $reassignId]);
        bms_comments_test_expect($response['status'] === 302 && bms_comments_test_row($pdo, $prefix, 'users', $createdId) !== [], 'Account deletion rejects invalid confirmation or reassignment: ' . $confirmationName . '/' . $reassignId);
    }
    $seed->execute(['other-post', $posts['other-post'], $createdId, 'Reassignment fixture.', 'approved', '2026-01-01 12:00:00', '2026-01-01 12:00:00', '2026-01-01 12:00:00']);
    $reassignCommentId = (int)$pdo->lastInsertId();
    $response = bms_comments_test_request($base, $editPath, $owner, ['csrf_token' => $token, 'action' => 'delete_user', 'confirm_username' => 'created', 'reassign_to' => $ownerId]);
    bms_comments_test_expect($response['status'] === 302 && bms_comments_test_row($pdo, $prefix, 'users', $createdId) === [] && (int)bms_comments_test_row($pdo, $prefix, 'comments', $reassignCommentId)['user_id'] === $ownerId, 'Confirmed account deletion reassigns existing comments before removal');
    $response = bms_comments_test_request($base, '/admin/user-edit.php?id=' . $ownerId, $owner, ['csrf_token' => $token, 'action' => 'delete_user', 'confirm_username' => 'owner', 'reassign_to' => $commenterId]);
    bms_comments_test_expect($response['status'] === 302 && bms_comments_test_row($pdo, $prefix, 'users', $ownerId)['role'] === 'admin', 'Sole Admin deletion is rejected by existing editor safeguards');

    $securityComments = $pdo->query("SELECT * FROM `{$prefix}comments` ORDER BY id")->fetchAll();
    $securityUsers = $pdo->query("SELECT * FROM `{$prefix}users` ORDER BY id")->fetchAll();
    $protectedForms = [
        ['/admin/comments.php', ['moderation_action' => 'trash', 'comment_id' => $ids['approved']]],
        ['/admin/comments.php?status=trash', ['moderation_action' => 'bulk_delete', 'selected' => [$ids['approved']], 'confirm_delete' => '1']],
        ['/admin/users.php', ['action' => 'set_status', 'user_id' => $commenterId, 'status' => 'inactive']],
        ['/admin/user-new.php', ['new_commenter_username' => 'forbidden', 'new_commenter_password' => 'forbidden-password']],
        ['/admin/user-edit.php?id=' . $commenterId, ['action' => 'reset_password', 'new_password' => 'forbidden-password', 'confirm_password' => 'forbidden-password']],
    ];
    foreach ($protectedForms as [$path, $form]) {
        foreach ([[], ['csrf_token' => 'invalid']] as $csrfForm) {
            $response = bms_comments_test_request($base, $path, $owner, $csrfForm + $form);
            bms_comments_test_expect($response['status'] === 403, $path . ' rejects ' . ($csrfForm === [] ? 'missing' : 'invalid') . ' CSRF');
        }
        $response = bms_comments_test_request($base, $path, $sessions['commenter'], ['csrf_token' => $token] + $form);
        bms_comments_test_expect($response['status'] === 403, 'Commenter cannot mutate ' . $path);
        $response = bms_comments_test_request($base, $path, $guest, ['csrf_token' => $token] + $form);
        bms_comments_test_expect($response['status'] === 302 && str_ends_with($response['location'], '/admin/login.php'), 'Guest cannot mutate ' . $path);
    }
    foreach (['/admin/comments.php', '/admin/comments.php?status=trash', '/admin/users.php', '/admin/user-new.php', '/admin/user-edit.php?id=' . $commenterId, '/admin/content.php', '/admin/media.php', '/admin/new.php'] as $path) {
        $response = bms_comments_test_request($base, $path, $sessions['commenter']);
        bms_comments_test_expect($response['status'] === 403, 'Commenter cannot enter privileged destination ' . $path);
        $response = bms_comments_test_request($base, $path, $guest);
        bms_comments_test_expect($response['status'] === 302 && str_ends_with($response['location'], '/admin/login.php'), 'Guest must authenticate for ' . $path);
    }
    // A stale token is captured from a real session that has been logged out,
    // then presented after a fresh real login, not merely another invalid string.
    $expiredToken = $token;
    $response = bms_comments_test_request($base, '/admin/logout.php', $owner, ['csrf_token' => $token]);
    bms_comments_test_expect($response['status'] === 302, 'Owner fixture logs out before stale-token test');
    $owner = [];
    $login = bms_comments_test_request($base, '/admin/login.php', $owner);
    $login = bms_comments_test_request($base, '/admin/login.php', $owner, ['csrf_token' => bms_comments_test_token($login['body']), 'username' => 'owner', 'password' => 'disposable-password']);
    $response = bms_comments_test_request($base, '/admin/comments.php', $owner);
    $token = bms_comments_test_token($response['body']);
    bms_comments_test_expect($token !== $expiredToken && $login['status'] === 302, 'Fresh owner session has a different CSRF token');
    foreach ($protectedForms as [$path, $form]) {
        $response = bms_comments_test_request($base, $path, $owner, ['csrf_token' => $expiredToken] + $form);
        bms_comments_test_expect($response['status'] === 403, 'Expired owner session token cannot mutate ' . $path);
    }
    bms_comments_test_expect($securityComments === $pdo->query("SELECT * FROM `{$prefix}comments` ORDER BY id")->fetchAll() && $securityUsers === $pdo->query("SELECT * FROM `{$prefix}users` ORDER BY id")->fetchAll(), 'Rejected access and CSRF requests preserve all comments, roles, status and passwords');

    // Registration still requires verification. Mail remains disabled throughout
    // this test, so no verification or recovery message leaves the fixture.
    $insertSetting->execute(['registration_mode', 'open']);
    $insertSetting->execute(['registration_require_email_verification', '1']);
    $publicAccount = bms_comments_test_request($base, '/account.php', $guest);
    $response = bms_comments_test_request($base, '/account.php', $guest, ['csrf_token' => bms_comments_test_token($publicAccount['body']), 'action' => 'register', 'username' => 'publicsignup', 'display_name' => 'Public Signup', 'email' => 'public@example.test', 'password' => 'public-signup-orchid-73', 'confirm_password' => 'public-signup-orchid-73', 'role' => 'admin']);
    bms_comments_test_expect($response['status'] === 200 && (int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}users` WHERE username='publicsignup'")->fetchColumn() === 0, 'Public registration cannot bypass required verification when delivery is unavailable');
    foreach ([['pendingverify', false], ['pendingapproval', true]] as [$username, $verified]) {
        $userSeed->execute([$username, $username, $username . '@example.test', $verified ? '2026-01-01 12:00:00' : null, password_hash('disposable-password', PASSWORD_DEFAULT), 'pending', null, null]);
        $cookies = [];
        $page = bms_comments_test_request($base, '/account.php', $cookies);
        $response = bms_comments_test_request($base, '/account.php', $cookies, ['csrf_token' => bms_comments_test_token($page['body']), 'action' => 'login', 'username' => $username, 'password' => 'disposable-password']);
        $denied = bms_comments_test_request($base, '/admin/comments.php', $cookies);
        bms_comments_test_expect($response['status'] === 200 && $denied['status'] === 302 && str_contains($response['body'], $verified ? 'waiting for admin approval' : 'verify the account'), 'Public login enforces ' . ($verified ? 'pending owner approval' : 'pending email verification'));
    }

    $public = bms_comments_test_request($base, '/', $guest);
    bms_comments_test_expect($public['status'] === 200 && !str_contains($public['body'], 'assets/admin-comments.css') && !str_contains($public['body'], 'assets/admin-accounts.css') && !str_contains($public['body'], 'assets/admin-shell.css'), 'Public frontend remains separate from Admin presentation assets');
    $publicComments = bms_comments_test_request($base, '/comments.php?slug=elm-slug', $guest);
    bms_comments_test_expect($publicComments['status'] === 200 && str_contains($publicComments['body'], 'Amber body marker.') && !str_contains($publicComments['body'], 'Pending amber reply.') && !str_contains($publicComments['body'], 'Trashed amber reply.'), 'Public comments show approved local replies only');
    $commentForm = bms_comments_test_request($base, '/comments.php?slug=elm-slug', $sessions['commenter']);
    $response = bms_comments_test_request($base, '/comments.php?slug=elm-slug', $sessions['commenter'], ['csrf_token' => bms_comments_test_token($commentForm['body']), 'slug' => 'elm-slug', 'body' => 'Public submission preservation marker.']);
    $submitted = $pdo->query("SELECT * FROM `{$prefix}comments` WHERE body='Public submission preservation marker.'")->fetch();
    bms_comments_test_expect($response['status'] === 200 && is_array($submitted) && (int)$submitted['user_id'] === $commenterId && $submitted['status'] === 'approved', 'Existing public commenter submission and default approval remain operational');
    bms_comments_test_expect((int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}users` WHERE role='admin'")->fetchColumn() === 1, 'Every account workflow preserves exactly one Admin');

    // Existing list cap applies independently to each status and matching query.
    // Normalize the real public submission date for clock-independent ordering.
    $pdo->exec("UPDATE `{$prefix}comments` SET created_at='2026-01-01 12:00:00'");
    for ($n = 0; $n < 205; $n++) {
        $seed->execute(['other-post', $posts['other-post'], $commenterId, 'Limit fixture ' . $n, 'approved', '2026-04-01 12:00:00', '2026-04-01 12:00:00', '2026-04-01 12:00:00']);
    }
    $lastId = (int)$pdo->lastInsertId();
    foreach (['approved', 'pending', 'trash'] as $status) {
        $pdo->prepare("UPDATE `{$prefix}comments` SET status=? WHERE body LIKE 'Limit fixture %'")->execute([$status]);
        foreach (['', '&q=Limit%20fixture'] as $search) {
            $response = bms_comments_test_request($base, '/admin/comments.php?status=' . $status . $search, $owner);
            $shown = bms_comments_test_ids($response['body']);
            bms_comments_test_expect($response['status'] === 200 && $shown === range($lastId, $lastId - 199), ucfirst($status) . ' preserves the 200-record cap and ID order for equal dates' . ($search === '' ? '' : ' with a matching search'));
        }
        $filters = bms_comments_test_links(bms_comments_test_landmark($response['body'], 'comments-content-filter'));
        $statusLink = array_values(array_filter($filters, static fn(array $link): bool => str_contains($link['href'], 'status=' . $status)))[0];
        $total = (int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}comments` WHERE status=" . $pdo->quote($status))->fetchColumn();
        bms_comments_test_expect($total > 200 && str_contains($statusLink['label'], (string)$total), ucfirst($status) . ' count includes records beyond the retrieval cap');
    }
    bms_comments_test_expect((int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}migrations`")->fetchColumn() === $migrationCount, 'Comments and account management introduce no migrations');
    fwrite(STDOUT, 'Admin Comments management runtime regression passed: ' . $checks . " checks.\n");
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
    bms_comments_test_remove_tree($temporary);
}

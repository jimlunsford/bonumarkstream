<?php
/**
 * Admin Dashboard states and security HTTP contract regression test against a disposable install.
 *
 * Uses BMS_DB_HOST, BMS_DB_NAME, BMS_DB_USER, BMS_DB_PASS and requires
 * BMS_DB_DANGER_RESET=1. Only random bms_dashboard_ci_* tables are created and
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
$temporary = sys_get_temp_dir() . '/bms-dashboard-test-' . bin2hex(random_bytes(6));
$prefix = 'bms_dashboard_ci_' . bin2hex(random_bytes(5)) . '_';
$process = null;
$pdo = null;
$checks = 0;

function bms_dashboard_test_expect(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
    $GLOBALS['checks']++;
    fwrite(STDOUT, 'PASS ' . $label . "\n");
}

function bms_dashboard_test_request(string $base, string $path, array &$cookies, ?array $form = null): array
{
    $headers = ['User-Agent: Bonumark Dashboard Regression', 'Connection: close'];
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

function bms_dashboard_test_token(string $html): string
{
    if (!preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $match)) {
        throw new RuntimeException('Missing CSRF token in rendered form.');
    }
    return html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
}

function bms_dashboard_test_remove_tree(string $path): void
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
        'site_name' => 'Dashboard Fixture', 'site_tagline' => 'Saved tagline',
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
    session_save_path($temporary . '/sessions');
    require $temporary . '/_bonumark_stream/app/auth.php';
    $pdo = bms_db();
    bms_dashboard_test_expect(!$pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES), 'Native PDO prepared statements remain enabled');
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
    bms_dashboard_test_expect($ready, 'Isolated loopback server starts');
    fwrite(STDOUT, 'Runtime: PHP ' . PHP_VERSION . ' / ' . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . "\n");

    $guest = [];
    $sessions = [];
    foreach (['owner', 'commenter'] as $username) {
        $cookies = [];
        $login = bms_dashboard_test_request($base, '/admin/login.php', $cookies);
        $login = bms_dashboard_test_request($base, '/admin/login.php', $cookies, ['csrf_token' => bms_dashboard_test_token($login['body']), 'username' => $username, 'password' => 'disposable-password']);
        bms_dashboard_test_expect($login['status'] === 302, ucfirst($username) . ' fixture authenticates through real login');
        $sessions[$username] = $cookies;
    }


    $owner =& $sessions['owner'];
    $ownerId = (int)$pdo->query("SELECT id FROM `{$prefix}users` WHERE username='owner'")->fetchColumn();
    $commenterId = (int)$pdo->query("SELECT id FROM `{$prefix}users` WHERE username='commenter'")->fetchColumn();
    require_once $temporary . '/_bonumark_stream/app/dashboard.php';

    // Ordinary scheduler lock contention holds saved records stable. No scheduler
    // implementation, configuration or execution rule is bypassed or replaced.
    $lockDir = $temporary . '/_bonumark_stream/tmp';
    if (!is_dir($lockDir)) { mkdir($lockDir, 0700, true); }
    $schedulerLock = fopen($lockDir . '/scheduled-tasks.lock', 'c');
    bms_dashboard_test_expect($schedulerLock !== false && flock($schedulerLock, LOCK_EX | LOCK_NB), 'Fixture holds the existing scheduler lock');
    $snapshot = static function () use ($pdo, $prefix): array {
        $state = [];
        foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
            if (str_starts_with($table, $prefix)) {
                $rows = array_map('serialize', $pdo->query('SELECT * FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC));
                sort($rows);
                $state[$table] = hash('sha256', serialize($rows));
            }
        }
        ksort($state);
        return $state;
    };
    $dashboard = static function (string $label) use ($base, &$owner, $snapshot): string {
        $before = $snapshot();
        $response = bms_dashboard_test_request($base, '/admin/index.php', $owner);
        bms_dashboard_test_expect($response['status'] === 200, $label . ': Dashboard responds');
        bms_dashboard_test_expect($before === $snapshot(), $label . ': no database rows changed');
        return $response['body'];
    };
    $noQuiet = static function (string $html, string $label): void {
        bms_dashboard_test_expect(!str_contains($html, 'Nothing awaiting your review') && !str_contains($html, 'All clear'), $label . ': no false quiet or all-clear');
    };
    $html = $dashboard('All sources empty');
    foreach (['Nothing awaiting your review', 'No comments awaiting review.', 'No commenter accounts awaiting approval.', 'No scheduled content.'] as $copy) {
        bms_dashboard_test_expect(str_contains($html, $copy), 'Quiet includes ' . $copy);
    }
    foreach (['dashboard-metric-grid', 'Quick Actions', 'Publishing workspace', 'Recent Pages', 'Recent Stream Posts', 'System Status', 'Open Stream Composer'] as $removed) {
        bms_dashboard_test_expect(!str_contains($html, $removed), 'Removed obsolete Dashboard component: ' . $removed);
    }
    bms_dashboard_test_expect(substr_count($html, '<h1>') === 1 && str_contains($html, 'View site'), 'One heading and shared View site remain');
    bms_dashboard_test_expect(bms_dashboard_pending_review('comments') === ['state' => 'empty', 'count' => 0], 'Successful empty source has an explicit zero');

    $commentSeed = $pdo->prepare("INSERT INTO `{$prefix}comments` (post_slug,user_id,body,status,created_at,updated_at) VALUES ('dashboard-fixture',?,'Pending fixture','pending',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    $commentSeed->execute([$commenterId]);
    $html = $dashboard('Pending comments only');
    bms_dashboard_test_expect(str_contains($html, '1 comment awaiting review') && !str_contains($html, 'awaiting your approval') && str_contains($html, 'comments.php?status=pending'), 'Comments independently link to moderation');
    $noQuiet($html, 'Pending comments');
    $pdo->exec("UPDATE `{$prefix}comments` SET status='approved'");
    $pdo->exec("UPDATE `{$prefix}users` SET status='pending' WHERE id={$commenterId}");
    $html = $dashboard('Pending approval only');
    bms_dashboard_test_expect(str_contains($html, '1 commenter awaiting your approval') && !str_contains($html, '1 comment awaiting review') && str_contains($html, 'users.php?status=pending'), 'Verified pending commenter is owner review work');
    bms_dashboard_test_expect(!str_contains($html, 'Approval needed'), 'Dashboard does not repeat the shared approval banner');
    bms_dashboard_test_expect(bms_dashboard_pending_review('accounts') === ['state' => 'populated', 'count' => 1], 'Dashboard approval source reports the verified pending account independently');
    $otherScreen = bms_dashboard_test_request($base, '/admin/media.php', $owner);
    $sharedPending = bms_user_pending_counts();
    bms_dashboard_test_expect(str_contains($otherScreen['body'], 'Approval needed') === ($sharedPending['pending_approval'] > 0), 'Other Admin screens preserve the existing shared helper banner result');
    if ($sharedPending['pending_approval'] === 0) {
        fwrite(STDOUT, "NOTE Existing shared helper reports zero for this verified-pending fixture; Dashboard independently reports one.\n");
    }
    $noQuiet($html, 'Pending approval');
    $pdo->exec("UPDATE `{$prefix}comments` SET status='pending'");
    $html = $dashboard('Both pending categories');
    bms_dashboard_test_expect(str_contains($html, '1 comment awaiting review') && str_contains($html, '1 commenter awaiting your approval'), 'Both review rows survive independently');
    $pdo->exec("UPDATE `{$prefix}comments` SET status='trash'");
    $pdo->exec("UPDATE `{$prefix}users` SET email_verified_at=NULL WHERE id={$commenterId}");
    $html = $dashboard('Pending email verification only');
    bms_dashboard_test_expect(str_contains($html, 'Nothing awaiting your review') && !str_contains($html, 'awaiting your approval'), 'Pending email verification and Trash are not owner tasks');

    $seedPost = static function (string $slug, string $title, ?string $due, string $body = 'Saved fixture body') use ($pdo, $prefix, $ownerId): void {
        bms_upsert_database_content(['slug' => $slug, 'title' => $title, 'body' => $body, 'date' => '2026-01-01', 'scheduled_at' => $due ?? ''], 'scheduled', $slug . '.md', $ownerId);
    };
    $futureYear = (int)gmdate('Y') + 2;
    $winter = $futureYear . '-01-15 14:00:00';
    $summer = $futureYear . '-07-15 14:00:00';
    $seedPost('later', 'Later scheduled record', $summer);
    $html = $dashboard('One future record');
    bms_dashboard_test_expect(str_contains($html, 'Scheduled next') && str_contains($html, 'Later scheduled record') && str_contains($html, '10:00 AM') && str_contains($html, 'America/Indiana/Indianapolis (EDT)'), 'Future saved UTC time converts with summer EDT');
    $noQuiet($html, 'Future record');
    $seedPost('earlier', 'Earliest saved record', $winter);
    // More records than the older preview cap make truncated reads observable.
    for ($n = 0; $n < 25; $n++) { $seedPost('extra-' . $n, 'Later extra ' . $n, $summer); }
    $scheduled = bms_dashboard_scheduled_content();
    $html = $dashboard('Multiple future records');
    bms_dashboard_test_expect($scheduled['count'] === 27 && str_contains($html, 'Earliest saved record') && !str_contains($html, 'Later scheduled record'), 'Entire scheduled set is read and earliest timestamp wins, not insertion order');
    bms_dashboard_test_expect(str_contains($html, '9:00 AM') && str_contains($html, 'America/Indiana/Indianapolis (EST)') && str_contains($html, $futureYear . '-01-15T09:00:00-05:00'), 'Winter uses date-appropriate EST and machine-readable local offset');
    bms_dashboard_test_expect(str_contains($html, 'content.php?status=scheduled'), 'Scheduled work links to the existing content filter');
    $past = gmdate('Y-m-d H:i:s', time() - 86400);
    $seedPost('past-due', 'Past due saved record', $past);
    $html = $dashboard('Past due with future records');
    bms_dashboard_test_expect(str_contains($html, 'Scheduled content needs review') && str_contains($html, 'Past due saved record') && !str_contains($html, '>Scheduled next<') && !str_contains($html, 'scheduler failed'), 'Overdue record takes precedence without diagnosing scheduler failure');
    $noQuiet($html, 'Mixed past and future');
    $pdo->exec("DELETE FROM `{$prefix}posts` WHERE slug <> 'past-due'");
    $html = $dashboard('Past due only');
    bms_dashboard_test_expect(str_contains($html, 'Scheduled content needs review') && str_contains($html, 'Scheduled for'), 'Past-due-only set retains its saved due time');
    $noQuiet($html, 'Past due only');
    $pdo->exec("DELETE FROM `{$prefix}posts`");
    $seedPost('missing-due', 'Record missing due time', null);
    $html = $dashboard('Missing saved timestamp');
    bms_dashboard_test_expect(str_contains($html, 'Scheduled content needs review') && str_contains($html, 'missing or invalid'), 'Missing saved timestamp remains known review work');
    $noQuiet($html, 'Missing timestamp');
    $pdo->exec("DELETE FROM `{$prefix}posts`");
    $seedPost('escaped', '<script>alert(1)</script> & saved', $winter);
    $html = $dashboard('Escaped saved title');
    bms_dashboard_test_expect(str_contains($html, '&lt;script&gt;alert(1)&lt;/script&gt; &amp; saved') && !str_contains($html, '<script>alert(1)'), 'Saved title is escaped');
    $pdo->exec("UPDATE `{$prefix}posts` SET title='',content_body='Stable excerpt fixture'");
    $html = $dashboard('Untitled record');
    bms_dashboard_test_expect(str_contains($html, 'Stable excerpt fixture'), 'Untitled schedule uses the saved body excerpt');

    // Deliberate real SQL failures, never empty tables or result stubs. Rename
    // source-specific columns so authentication remains available independently.
    $failures = [
        'comments' => ['comments', 'status', 'Comments unavailable'],
        'accounts' => ['users', 'email_verified_at', 'Commenter accounts unavailable'],
        'scheduled' => ['posts', 'scheduled_at', 'Scheduled content unavailable'],
    ];
    foreach ($failures as $name => [$table, $column, $message]) {
        $pdo->exec("ALTER TABLE `{$prefix}{$table}` RENAME COLUMN `{$column}` TO `dashboard_unavailable_column`");
        try {
            $result = $name === 'scheduled' ? bms_dashboard_scheduled_content() : bms_dashboard_pending_review($name);
            bms_dashboard_test_expect($result['state'] === 'unavailable' && $result['count'] === null, $name . ': SQL exception returns unavailable with null, never zero');
            $html = $dashboard($name . ' source unavailable');
            bms_dashboard_test_expect(str_contains($html, $message), $name . ': failed source is identified');
            bms_dashboard_test_expect(!str_contains($html, 'SQLSTATE') && !str_contains($html, 'dashboard_unavailable_column'), $name . ': query details are not disclosed');
            $noQuiet($html, $name . ' source failed');
        } finally {
            $pdo->exec("ALTER TABLE `{$prefix}{$table}` RENAME COLUMN `dashboard_unavailable_column` TO `{$column}`");
        }
    }
    $pdo->exec("UPDATE `{$prefix}comments` SET status='pending'");
    $pdo->exec("UPDATE `{$prefix}users` SET email_verified_at=UTC_TIMESTAMP() WHERE id={$commenterId}");
    $pdo->exec("ALTER TABLE `{$prefix}comments` RENAME COLUMN status TO dashboard_unavailable_column");
    try {
        $html = $dashboard('Partial failure with known review work');
        bms_dashboard_test_expect(str_contains($html, 'Comments unavailable') && str_contains($html, '1 commenter awaiting your approval') && str_contains($html, 'Scheduled next'), 'Failed comments retain independently known approval and future schedule');
        $pdo->exec("ALTER TABLE `{$prefix}posts` RENAME COLUMN scheduled_at TO dashboard_unavailable_column");
        try {
            $html = $dashboard('Multiple sources unavailable');
            bms_dashboard_test_expect(str_contains($html, 'Comments unavailable') && str_contains($html, 'Scheduled content unavailable') && str_contains($html, '1 commenter awaiting your approval'), 'Multiple failures retain the remaining known task');
            $noQuiet($html, 'Multiple failed sources');
            $pdo->exec("ALTER TABLE `{$prefix}users` RENAME COLUMN email_verified_at TO dashboard_unavailable_column");
            try {
                $html = $dashboard('All included sources unavailable');
                bms_dashboard_test_expect(str_contains($html, 'Commenter accounts unavailable'), 'All failed sources remain explicit');
                $noQuiet($html, 'All failed sources');
            } finally { $pdo->exec("ALTER TABLE `{$prefix}users` RENAME COLUMN dashboard_unavailable_column TO email_verified_at"); }
        } finally { $pdo->exec("ALTER TABLE `{$prefix}posts` RENAME COLUMN dashboard_unavailable_column TO scheduled_at"); }
    } finally { $pdo->exec("ALTER TABLE `{$prefix}comments` RENAME COLUMN dashboard_unavailable_column TO status"); }

    $pdo->exec("UPDATE `{$prefix}users` SET status='active' WHERE id={$commenterId}");
    $before = $snapshot();
    foreach (['GET', 'POST'] as $method) {
        foreach (['guest', 'commenter'] as $actor) {
            $cookies = $actor === 'guest' ? [] : $sessions['commenter'];
            $response = bms_dashboard_test_request($base, '/admin/index.php', $cookies, $method === 'POST' ? ['action' => 'publish', 'id' => 1] : null);
            bms_dashboard_test_expect($response['status'] === ($actor === 'guest' ? 302 : 403) && !str_contains($response['body'], 'Scheduled next'), $actor . ' cannot read or invoke Dashboard through ' . $method);
        }
    }
    bms_dashboard_test_expect($snapshot() === $before, 'Unauthorized requests do not mutate the database');
    $html = $dashboard('Restored sources');
    bms_dashboard_test_expect(str_contains($html, '1 comment awaiting review') && !str_contains($html, 'Comments unavailable'), 'Source recovery restores real results');
    foreach (['/admin/comments.php?status=pending', '/admin/users.php?status=pending', '/admin/content.php?status=scheduled'] as $path) {
        $response = bms_dashboard_test_request($base, $path, $owner);
        bms_dashboard_test_expect($response['status'] === 200, 'Contextual destination responds: ' . $path);
    }

    // The unchanged scheduler still runs on Admin entry when the lock is free.
    $pdo->exec("DELETE FROM `{$prefix}posts`");
    $seedPost('automatic-publication', 'Automatic publication fixture', $past);
    $pdo->exec("DELETE FROM `{$prefix}settings` WHERE setting_key='scheduled_tasks_last_run_at'");
    flock($schedulerLock, LOCK_UN);
    fclose($schedulerLock);
    $schedulerLock = null;
    $response = bms_dashboard_test_request($base, '/admin/index.php', $owner);
    bms_dashboard_test_expect($response['status'] === 200 && $pdo->query("SELECT status FROM `{$prefix}posts` WHERE slug='automatic-publication'")->fetchColumn() === 'published', 'Existing header automatically publishes due content when its lock is free');
    bms_dashboard_test_expect(!str_contains($response['body'], 'Automatic publication fixture') && !str_contains($response['body'], 'Scheduled content needs review'), 'Dashboard reads after automatic publication, not a stale pre-header snapshot');
    bms_dashboard_test_expect((int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}migrations`")->fetchColumn() === $migrationCount, 'Dashboard introduces no migration');
    fwrite(STDOUT, 'Admin Dashboard runtime regression passed: ' . $checks . " checks.\n");
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
    bms_dashboard_test_remove_tree($temporary);
}

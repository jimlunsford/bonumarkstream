<?php
/**
 * Upgrade regression test for migration 0029 (accounts, sessions, limits).
 *
 * Builds a disposable install whose database is complete through 0028, seeds
 * it the way earlier versions store data (an owner, commenters, two accounts
 * sharing one address, blank addresses, signed-in sessions, remembered
 * devices, comments, and the old Sendmail setting), interrupts 0029 part way,
 * then resumes it through the owner-run migration helper. It verifies that no
 * existing value changed, that the new state is exactly as designed, and
 * that sessions and remembered devices from before the upgrade keep working
 * until a credential change.
 *
 * Uses BMS_DB_HOST, BMS_DB_NAME, BMS_DB_USER, BMS_DB_PASS and requires
 * BMS_DB_DANGER_RESET=1. Only random bms_upgrade29_ci_* tables are created
 * and removed.
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
$temporary = sys_get_temp_dir() . '/bms-upgrade29-' . bin2hex(random_bytes(6));
$prefix = 'bms_upgrade29_ci_' . bin2hex(random_bytes(4)) . '_';
$process = null;
$pdo = null;
$checks = 0;
$failures = [];
const BMS_UP_MIGRATION = '0029_accounts_sessions_and_limits';
const BMS_UP_PASSWORD = 'Upgrade-Fixture-Pass-29';

function bms_up_expect(bool $condition, string $label): void
{
    global $checks, $failures;
    $checks++;
    fwrite(STDOUT, ($condition ? 'PASS ' : 'FAIL ') . $label . "\n");
    if (!$condition) {
        $failures[] = $label;
    }
}

/** @return array{status:int,body:string,location:string} */
function bms_up_request(string $path, array &$cookies, ?array $form = null, string $agent = 'Bonumark Upgrade Regression'): array
{
    global $base;
    $headers = ['User-Agent: ' . $agent, 'Connection: close'];
    if ($cookies !== []) {
        $headers[] = 'Cookie: ' . implode('; ', array_map(static fn(string $key, string $value): string => $key . '=' . $value, array_keys($cookies), $cookies));
    }
    $options = ['method' => $form === null ? 'GET' : 'POST', 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 20];
    if ($form !== null) {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $options['content'] = http_build_query($form);
    }
    $options['header'] = implode("\r\n", $headers);
    $body = @file_get_contents($base . $path, false, stream_context_create(['http' => $options]));
    if ($body === false || !isset($http_response_header[0])) {
        throw new RuntimeException('HTTP request failed: ' . $path);
    }
    preg_match('/\s(\d{3})\s/', $http_response_header[0], $status);
    $location = '';
    foreach ($http_response_header as $header) {
        if (preg_match('/^Set-Cookie:\s*([^=;]+)=([^;]*)/i', $header, $match)) {
            if ($match[2] === '' || $match[2] === 'deleted') {
                unset($cookies[$match[1]]);
            } else {
                $cookies[$match[1]] = $match[2];
            }
        }
        if (stripos($header, 'Location:') === 0) {
            $location = trim(substr($header, 9));
        }
    }
    return ['status' => (int)($status[1] ?? 0), 'body' => $body, 'location' => $location];
}

function bms_up_token(string $html): string
{
    if (!preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $match)) {
        throw new RuntimeException('Missing CSRF token in rendered form.');
    }
    return html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
}

/** All rows of a table, keyed by id, limited to the listed columns. */
function bms_up_rows(string $table, array $columns, string $key = 'id'): array
{
    global $pdo, $prefix;
    $rows = [];
    foreach ($pdo->query('SELECT * FROM `' . $prefix . $table . '` ORDER BY `' . $key . '`')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rows[(string)$row[$key]] = array_intersect_key($row, array_flip($columns));
    }
    return $rows;
}

function bms_up_columns(string $table): array
{
    global $pdo, $prefix;
    return array_column($pdo->query('SHOW COLUMNS FROM `' . $prefix . $table . '`')->fetchAll(PDO::FETCH_ASSOC), 'Field');
}

function bms_up_index(string $table, string $name): array
{
    global $pdo, $prefix;
    $rows = $pdo->query('SHOW INDEX FROM `' . $prefix . $table . '` WHERE Key_name = ' . $pdo->quote($name))->fetchAll(PDO::FETCH_ASSOC);
    return $rows;
}

/** Write a PHP session file the way earlier versions signed accounts in. */
function bms_up_legacy_session(int $userId): string
{
    global $temporary;
    $id = bin2hex(random_bytes(16));
    $csrf = bin2hex(random_bytes(32));
    file_put_contents($temporary . '/sessions/sess_' . $id, 'bms_logged_in|b:1;bms_user_id|i:' . $userId . ';csrf_token|s:64:"' . $csrf . '";');
    return $id;
}

function bms_up_migrations_runner(string $root): array
{
    $output = [];
    $code = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/scripts/run-migrations.php') . ' --run --confirm-backup --from-version=0.8.5 2>&1', $output, $code);
    return ['code' => $code, 'output' => implode("\n", $output)];
}

function bms_up_remove_tree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        $file->isDir() && !$file->isLink() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    }
    @rmdir($path);
}

try {
    mkdir($temporary, 0700);
    $iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), static function (SplFileInfo $file) use ($source): bool {
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($source) + 1));
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
    foreach (['sessions', 'media', '_bonumark_stream/tmp', '_bonumark_stream/data', '_bonumark_stream/backups'] as $directory) {
        if (!is_dir($temporary . '/' . $directory)) {
            mkdir($temporary . '/' . $directory, 0700, true);
        }
    }

    $listener = stream_socket_server('tcp://127.0.0.1:0', $error, $errorMessage);
    if ($listener === false) {
        throw new RuntimeException('Cannot reserve a loopback port: ' . $errorMessage);
    }
    $address = stream_socket_get_name($listener, false);
    fclose($listener);
    $base = 'http://' . $address;
    $salt = bin2hex(random_bytes(24));
    $config = [
        'site_name' => 'Upgrade Fixture', 'site_tagline' => 'Disposable', 'site_admin_email' => 'owner@example.test',
        'timezone' => 'UTC', 'base_url' => $base, 'base_path' => '', 'security_salt' => $salt,
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
    fwrite(STDOUT, 'Runtime: PHP ' . PHP_VERSION . ' / ' . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . "\n");

    // A database complete through 0028: every earlier migration, recorded in
    // the ledger, exactly as the installer and upgrades leave it.
    $migrationFiles = glob($temporary . '/_bonumark_stream/migrations/*.php') ?: [];
    sort($migrationFiles);
    $pdo->exec('CREATE TABLE IF NOT EXISTS `' . $prefix . 'migrations` (migration VARCHAR(120) NOT NULL PRIMARY KEY, ran_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $ledger = $pdo->prepare('INSERT IGNORE INTO `' . $prefix . 'migrations` (migration, ran_at) VALUES (?, UTC_TIMESTAMP())');
    foreach ($migrationFiles as $file) {
        $name = basename($file, '.php');
        if ($name === BMS_UP_MIGRATION) {
            continue;
        }
        foreach (require $file as $statement) {
            bms_exec_migration_statement($pdo, (string)$statement, $prefix);
        }
        $ledger->execute([$name]);
    }
    bms_up_expect(!in_array('session_version', bms_up_columns('users'), true) && bms_pending_migration_names($pdo) === [BMS_UP_MIGRATION], 'The fixture starts complete through 0028 with only 0029 pending');

    // Seed data as earlier versions wrote it.
    $setting = $pdo->prepare('INSERT INTO `' . $prefix . 'settings` (setting_key, setting_value, updated_at) VALUES (?, ?, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
    foreach ($config as $key => $value) {
        if (is_string($value)) {
            $setting->execute([$key, $value]);
        }
    }
    $setting->execute(['mail_sendmail_path', '/opt/legacy/sendmail']);
    // An established site already has its timestamp cutover, so the runner's
    // standard cutover repair leaves settings alone.
    $setting->execute(['stream_published_at_utc_cutover', '2025-01-01 00:00:00']);
    $hash = password_hash(BMS_UP_PASSWORD, PASSWORD_DEFAULT);
    $user = $pdo->prepare('INSERT INTO `' . $prefix . "users` (username, display_name, email, email_verified_at, bio, password_hash, role, status, created_at, updated_at) VALUES (?, ?, ?, '2026-01-02 03:04:05', ?, ?, ?, ?, '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
    $accounts = [
        ['owner', 'Owner Person', 'Owner@Example.test', 'Owner bio', 'admin', 'active'],
        ['reader', 'Reader', 'reader@example.test', '', 'commenter', 'active'],
        ['twin-old', 'Twin Old', 'twin@example.test', '', 'commenter', 'active'],
        ['twin-new', 'Twin New', 'TWIN@example.test', '', 'commenter', 'active'],
        ['blank-one', 'Blank One', '', '', 'commenter', 'active'],
        ['blank-two', 'Blank Two', '', '', 'commenter', 'active'],
        ['waiting', 'Waiting', 'waiting@example.test', '', 'commenter', 'pending'],
        ['retired', 'Retired', 'retired@example.test', '', 'commenter', 'inactive'],
    ];
    $ids = [];
    foreach ($accounts as [$username, $display, $email, $bio, $role, $status]) {
        $user->execute([$username, $display, $email, $bio, $hash, $role, $status]);
        $ids[$username] = (int)$pdo->lastInsertId();
    }
    $remember = $pdo->prepare('INSERT INTO `' . $prefix . 'remember_tokens` (user_id, selector, token_hash, user_agent_hash, expires_at, created_at) VALUES (?, ?, ?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 10 DAY), UTC_TIMESTAMP())');
    $devices = [];
    foreach (['owner' => 'Owner Desktop', 'reader' => 'Reader Phone'] as $username => $agent) {
        $selector = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(32));
        $remember->execute([$ids[$username], $selector, hash('sha256', $validator), hash('sha256', $agent . '|' . $salt)]);
        $devices[$username] = ['cookie' => $selector . ':' . $validator, 'agent' => $agent];
    }
    $pdo->prepare('INSERT INTO `' . $prefix . "posts` (author_id,title,slug,status,post_type,description,content_body,category,category_slug,date_published,created_at,updated_at,published_at) VALUES (?, 'Upgrade post', 'upgrade-post', 'published', 'stream', 'Upgrade post', 'Body.', 'Stream', 'stream', '2026-01-03', '2026-01-03 00:00:00', '2026-01-03 00:00:00', '2026-01-03 00:00:00')")->execute([$ids['owner']]);
    $postId = (int)$pdo->lastInsertId();
    $comment = $pdo->prepare('INSERT INTO `' . $prefix . "comments` (post_slug, post_id, user_id, body, status, ip_hash, user_agent_hash, created_at, updated_at, approved_at) VALUES ('upgrade-post', ?, ?, ?, 'approved', ?, ?, '2026-01-04 00:00:00', '2026-01-04 00:00:00', '2026-01-04 00:00:00')");
    foreach (['reader', 'twin-old', 'twin-new'] as $username) {
        $comment->execute([$postId, $ids[$username], 'Comment by ' . $username, hash('sha256', 'ip'), hash('sha256', 'ua')]);
    }
    $pdo->prepare('INSERT INTO `' . $prefix . "login_attempts` (username, ip_hash, success, attempted_at) VALUES ('owner', ?, 0, '2026-01-05 00:00:00')")->execute([hash('sha256', 'old')]);

    // Signed-in sessions from before the upgrade carry no credential version.
    $sessions = [
        'owner-a' => bms_up_legacy_session($ids['owner']),
        'owner-b' => bms_up_legacy_session($ids['owner']),
        'reader' => bms_up_legacy_session($ids['reader']),
    ];

    $userColumns = bms_up_columns('users');
    $before = [
        'users' => bms_up_rows('users', $userColumns),
        'comments' => bms_up_rows('comments', bms_up_columns('comments')),
        'remember_tokens' => bms_up_rows('remember_tokens', bms_up_columns('remember_tokens')),
        'posts' => bms_up_rows('posts', bms_up_columns('posts')),
        'settings' => bms_up_rows('settings', ['setting_key', 'setting_value'], 'setting_key'),
    ];

    // Interrupt 0029 after its columns exist, before the unique key, with a
    // repeated claim written in between, as an interrupted upgrade could
    // leave it. Then resume through the owner-run helper.
    $statements = require $temporary . '/_bonumark_stream/migrations/' . BMS_UP_MIGRATION . '.php';
    $uniqueAt = null;
    foreach ($statements as $index => $statement) {
        if (str_contains($statement, 'ADD UNIQUE KEY `email_normalized`')) {
            $uniqueAt = $index;
            break;
        }
        if (!str_starts_with(ltrim($statement), 'UPDATE')) {
            bms_exec_migration_statement($pdo, $statement, $prefix);
        }
    }
    bms_up_expect($uniqueAt !== null && in_array('email_normalized', bms_up_columns('users'), true) && bms_up_index('users', 'email_normalized') === [], 'The interrupted attempt stopped after adding columns and before the unique key');
    $pdo->prepare('UPDATE `' . $prefix . "users` SET email_normalized = 'collision@example.test' WHERE username IN ('reader', 'blank-one')")->execute();

    $run = bms_up_migrations_runner($temporary);
    bms_up_expect($run['code'] === 0 && str_contains($run['output'], BMS_UP_MIGRATION), 'The owner-run migration helper resumes and completes 0029');
    if ($run['code'] !== 0) {
        fwrite(STDOUT, $run['output'] . "\n");
    }
    bms_up_expect(bms_pending_migration_names($pdo) === [], 'No migration is pending afterwards');
    $again = bms_up_migrations_runner($temporary);
    bms_up_expect($again['code'] === 0 && str_contains($again['output'], 'Pending migrations: 0'), 'Running the helper again finds nothing to do');

    // Every value that existed before is unchanged. Only the Sendmail row is gone.
    $after = [
        'users' => bms_up_rows('users', $userColumns),
        'comments' => bms_up_rows('comments', array_keys(reset($before['comments']) ?: [])),
        'remember_tokens' => bms_up_rows('remember_tokens', array_keys(reset($before['remember_tokens']) ?: [])),
        'posts' => bms_up_rows('posts', array_keys(reset($before['posts']) ?: [])),
        'settings' => bms_up_rows('settings', ['setting_key', 'setting_value'], 'setting_key'),
    ];
    foreach (['users', 'comments', 'remember_tokens', 'posts'] as $table) {
        bms_up_expect($after[$table] === $before[$table], 'Every existing ' . $table . ' value is unchanged (' . count($before[$table]) . ' rows)');
    }
    $expectedSettings = $before['settings'];
    unset($expectedSettings['mail_sendmail_path']);
    $afterSettings = $after['settings'];
    $hold = $afterSettings['comments_hold_first_comment']['setting_value'] ?? null;
    unset($afterSettings['comments_hold_first_comment']);
    bms_up_expect($afterSettings === $expectedSettings && !isset($after['settings']['mail_sendmail_path']), 'Settings are unchanged except the removed Sendmail row');
    bms_up_expect($hold === '0', 'Holding first comments for review starts off');

    // New state is exactly as designed.
    $state = [];
    foreach ($pdo->query('SELECT username, session_version, email_normalized, pending_email, pending_email_token_hash, pending_email_expires_at FROM `' . $prefix . 'users`')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $state[$row['username']] = $row;
    }
    bms_up_expect(array_unique(array_map('intval', array_column($state, 'session_version'))) === [1], 'Every account starts on credential generation 1');
    $claims = array_map(static fn(array $row): ?string => $row['email_normalized'], $state);
    bms_up_expect($claims === [
        'owner' => 'owner@example.test', 'reader' => 'reader@example.test',
        'twin-old' => null, 'twin-new' => null, 'blank-one' => null, 'blank-two' => null,
        'waiting' => 'waiting@example.test', 'retired' => 'retired@example.test',
    ], 'Unique addresses are claimed in lowercase; the shared pair and blank addresses stay unclaimed; the repeated claim left by the interrupted attempt is cleared');
    bms_up_expect(count(array_filter($state, static fn(array $row): bool => $row['pending_email'] !== null || $row['pending_email_token_hash'] !== null || $row['pending_email_expires_at'] !== null)) === 0, 'No email change is pending');
    $unique = bms_up_index('users', 'email_normalized');
    bms_up_expect($unique !== [] && (int)$unique[0]['Non_unique'] === 0, 'The normalized email claim is uniquely indexed');
    foreach ([['login_attempts', 'ip_attempted'], ['login_attempts', 'username_ip_attempted'], ['comments', 'user_created'], ['comments', 'ip_created'], ['users', 'pending_email_token_hash']] as [$table, $index]) {
        bms_up_expect(bms_up_index($table, $index) !== [], 'Index ' . $table . '.' . $index . ' exists');
    }

    // The upgraded site, through HTTP.
    $process = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $temporary . '/sessions', '-S', $address, '-t', $temporary], [0 => ['pipe', 'r'], 1 => ['file', $temporary . '/http.log', 'a'], 2 => ['file', $temporary . '/http.log', 'a']], $pipes, $temporary, array_merge(getenv(), ['PHP_CLI_SERVER_WORKERS' => '4']));
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start the isolated PHP HTTP server.');
    }
    fclose($pipes[0]);
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $socket = @stream_socket_client('tcp://' . $address, $error, $errorMessage, 0.1);
        if ($socket !== false) {
            fclose($socket);
            break;
        }
        usleep(100000);
    }

    $ownerA = ['bms_session_root' => $sessions['owner-a']];
    $ownerB = ['bms_session_root' => $sessions['owner-b']];
    $reader = ['bms_session_root' => $sessions['reader']];
    bms_up_expect(bms_up_request('/admin/index.php', $ownerA)['status'] === 200 && bms_up_request('/admin/index.php', $ownerB)['status'] === 200, 'Owner sessions signed in before the upgrade stay signed in');
    $readerPage = bms_up_request('/account.php', $reader);
    bms_up_expect(str_contains($readerPage['body'], 'Your account'), 'A commenter session signed in before the upgrade stays signed in');
    foreach ($devices as $username => $device) {
        $jar = ['bms_remember' => $device['cookie']];
        $page = $username === 'owner' ? bms_up_request('/admin/index.php', $jar, null, $device['agent']) : bms_up_request('/account.php', $jar, null, $device['agent']);
        bms_up_expect($username === 'owner' ? $page['status'] === 200 : str_contains($page['body'], 'Your account'), 'A device remembered before the upgrade still signs in: ' . $username);
    }

    $check = bms_up_request('/admin/system-check.php', $ownerA);
    bms_up_expect(str_contains($check['body'], 'Account email addresses') && str_contains($check['body'], 'twin-old, twin-new'), 'System Check lists the two accounts that share an address for the owner to resolve');

    // The first credential change after the upgrade signs the other
    // pre-upgrade session out, and the owner can resolve the shared pair.
    $page = bms_up_request('/admin/user.php', $ownerA);
    bms_up_request('/admin/user.php', $ownerA, ['csrf_token' => bms_up_token($page['body']), 'action' => 'password', 'current_password' => BMS_UP_PASSWORD, 'new_password' => 'Upgraded-Owner-Pass-30', 'confirm_password' => 'Upgraded-Owner-Pass-30']);
    bms_up_expect(bms_up_request('/admin/index.php', $ownerB)['status'] === 302, 'After a password change, the other pre-upgrade session is signed out');
    bms_up_expect(bms_up_request('/admin/index.php', $ownerA)['status'] === 200, 'The session that changed it stays signed in');
    $ownerDevice = ['bms_remember' => $devices['owner']['cookie']];
    bms_up_expect(bms_up_request('/admin/index.php', $ownerDevice, null, $devices['owner']['agent'])['status'] === 302, 'The remembered owner device is signed out by the password change');

    $page = bms_up_request('/admin/user-edit.php?id=' . $ids['twin-new'], $ownerA);
    bms_up_request('/admin/user-edit.php?id=' . $ids['twin-new'], $ownerA, ['csrf_token' => bms_up_token($page['body']), 'action' => 'update_account', 'user_id' => $ids['twin-new'], 'username' => 'twin-new', 'display_name' => 'Twin New', 'email' => 'twin-new@example.test', 'status' => 'active', 'profile_visibility' => 'public', 'email_verified' => '1']);
    $resolved = [];
    foreach ($pdo->query('SELECT username, email, email_normalized FROM `' . $prefix . "users` WHERE username IN ('twin-old', 'twin-new') ORDER BY username")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $resolved[$row['username']] = [$row['email'], $row['email_normalized']];
    }
    bms_up_expect($resolved === ['twin-new' => ['twin-new@example.test', 'twin-new@example.test'], 'twin-old' => ['twin@example.test', 'twin@example.test']], 'Changing one address of the pair claims both addresses');
    $check = bms_up_request('/admin/system-check.php', $ownerA);
    bms_up_expect(str_contains($check['body'], 'Each account email address belongs to one account.'), 'System Check then reports no shared address');

    fwrite(STDOUT, "\nMigration 0029 upgrade test: " . ($checks - count($failures)) . ' passed, ' . count($failures) . " failed.\n");
} finally {
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
    if ($failures !== [] && is_file($temporary . '/http.log')) {
        fwrite(STDERR, "HTTP server log (tail):\n" . substr((string)file_get_contents($temporary . '/http.log'), -3000) . "\n");
    }
    if ($pdo instanceof PDO) {
        $tables = $pdo->query("SHOW TABLES LIKE '" . str_replace('_', '\\_', $prefix) . "%'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '', (string)$table) . '`');
        }
    }
    bms_up_remove_tree($temporary);
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAILED ' . $failure . "\n");
    }
    exit(1);
}

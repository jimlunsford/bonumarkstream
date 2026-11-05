<?php
/**
 * General Settings HTTP contract regression test against a disposable install.
 *
 * Uses BMS_DB_HOST, BMS_DB_NAME, BMS_DB_USER, BMS_DB_PASS and requires
 * BMS_DB_DANGER_RESET=1. Only random bms_settings_ci_* tables are created and
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
$temporary = sys_get_temp_dir() . '/bms-settings-test-' . bin2hex(random_bytes(6));
$prefix = 'bms_settings_ci_' . bin2hex(random_bytes(5)) . '_';
$process = null;
$pdo = null;
$checks = 0;

function bms_settings_test_expect(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
    $GLOBALS['checks']++;
    fwrite(STDOUT, 'PASS ' . $label . "\n");
}

function bms_settings_test_request(string $base, string $path, array &$cookies, ?array $form = null): array
{
    $headers = ['User-Agent: Bonumark Settings Regression', 'Connection: close'];
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

function bms_settings_test_token(string $html): string
{
    if (!preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $match)) {
        throw new RuntimeException('Missing CSRF token in rendered form.');
    }
    return html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
}

function bms_settings_test_values(PDO $pdo, string $prefix): array
{
    return $pdo->query("SELECT setting_key, setting_value FROM `{$prefix}settings` WHERE setting_key IN ('site_name','site_tagline','site_admin_email','timezone','base_url','base_path') ORDER BY setting_key")->fetchAll(PDO::FETCH_KEY_PAIR);
}

function bms_settings_test_remove_tree(string $path): void
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
        'site_name' => 'Settings Fixture', 'site_tagline' => 'Saved tagline',
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
    bms_settings_test_expect($ready, 'Isolated loopback server starts');
    fwrite(STDOUT, 'Runtime: PHP ' . PHP_VERSION . ' / ' . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . "\n");

    $guest = [];
    $original = bms_settings_test_values($pdo, $prefix);
    $response = bms_settings_test_request($base, '/admin/settings.php', $guest, ['site_name' => 'Unauthorized']);
    bms_settings_test_expect($response['status'] === 302 && str_ends_with($response['location'], '/admin/login.php'), 'Unauthenticated settings POST redirects to login');
    bms_settings_test_expect(bms_settings_test_values($pdo, $prefix) === $original, 'Unauthenticated POST leaves settings unchanged');

    $sessions = [];
    $loginTokens = [];
    foreach (['owner', 'commenter'] as $username) {
        $cookies = [];
        $login = bms_settings_test_request($base, '/admin/login.php', $cookies);
        $loginTokens[$username] = bms_settings_test_token($login['body']);
        $login = bms_settings_test_request($base, '/admin/login.php', $cookies, ['csrf_token' => $loginTokens[$username], 'username' => $username, 'password' => 'disposable-password']);
        bms_settings_test_expect($login['status'] === 302, ucfirst($username) . ' fixture authenticates through real login');
        $sessions[$username] = $cookies;
    }
    $response = bms_settings_test_request($base, '/admin/settings.php', $sessions['commenter'], ['csrf_token' => $loginTokens['commenter'], 'site_name' => 'Unauthorized']);
    bms_settings_test_expect($response['status'] === 403 && str_contains($response['body'], 'You do not have permission') && bms_settings_test_values($pdo, $prefix) === $original, 'Authenticated commenter with a valid CSRF token cannot modify General Settings');

    $response = bms_settings_test_request($base, '/admin/settings.php', $sessions['owner']);
    bms_settings_test_expect($response['status'] === 200, 'General Settings loads for owner');
    foreach (['site_name' => ['160', 'Settings Fixture'], 'site_tagline' => ['500', 'Saved tagline'], 'site_admin_email' => ['190', 'saved@example.test']] as $field => [$max, $value]) {
        preg_match('/<input\b[^>]*name="' . $field . '"[^>]*>/', $response['body'], $input);
        bms_settings_test_expect(isset($input[0]) && str_contains($input[0], 'maxlength="' . $max . '"') && str_contains($input[0], 'value="' . $value . '"'), $field . ' preserves saved value and maxlength');
        bms_settings_test_expect(str_contains($response['body'], 'for="' . $field . '"'), $field . ' has an explicit label');
    }
    bms_settings_test_expect((bool)preg_match('/<option\s+value="America\/Indiana\/Indianapolis"[^>]*selected/', $response['body']), 'Timezone selection retains its actual IANA identifier');
    bms_settings_test_expect(str_contains($response['body'], 'for="timezone"') && str_contains($response['body'], 'site-identity.php'), 'Timezone label and Site Identity destination are present');
    $token = bms_settings_test_token($response['body']);
    $form = ['csrf_token' => $token, 'site_name' => '  Saved & Updated  ', 'site_tagline' => '<script>alert(1)</script><a href="https://example.test/safe" onclick="evil()">Safe</a><a href="javascript:evil()">Bad</a>', 'site_admin_email' => '  new@example.test  ', 'timezone' => 'Pacific/Auckland', 'base_url' => 'https://unexpected.example', 'base_path' => '/unexpected'];
    foreach (['missing' => '', 'invalid' => 'wrong-token'] as $kind => $badToken) {
        $response = bms_settings_test_request($base, '/admin/settings.php', $sessions['owner'], array_replace($form, ['csrf_token' => $badToken]));
        bms_settings_test_expect($response['status'] === 403 && bms_settings_test_values($pdo, $prefix) === $original, ucfirst($kind) . ' CSRF token cannot save');
    }
    $response = bms_settings_test_request($base, '/admin/settings.php', $sessions['owner'], $form);
    bms_settings_test_expect($response['status'] === 302 && str_ends_with($response['location'], '/admin/settings.php'), 'Valid submission redirects to General Settings');
    $saved = bms_settings_test_values($pdo, $prefix);
    bms_settings_test_expect($saved['site_name'] === 'Saved & Updated' && $saved['site_admin_email'] === 'new@example.test' && $saved['timezone'] === 'Pacific/Auckland', 'Valid values are trimmed and persisted with timezone semantics');
    bms_settings_test_expect(!str_contains($saved['site_tagline'], '<script') && !str_contains($saved['site_tagline'], 'onclick') && !str_contains($saved['site_tagline'], 'javascript:') && str_contains($saved['site_tagline'], 'href="https://example.test/safe"'), 'Tagline sanitizer removes unsafe markup and retains safe links');
    bms_settings_test_expect($saved['base_url'] === $base && $saved['base_path'] === '', 'Installation URL and base path remain read-only');
    $response = bms_settings_test_request($base, '/admin/settings.php', $sessions['owner']);
    bms_settings_test_expect(str_contains($response['body'], 'General settings saved.') && str_contains($response['body'], 'value="Saved &amp; Updated"'), 'Successful redirect shows accurate success and escaped saved name');

    $invalid = array_replace($form, ['site_name' => 'Unsaved Name', 'site_admin_email' => 'not-an-email', 'timezone' => 'Asia/Tokyo']);
    $response = bms_settings_test_request($base, '/admin/settings.php', $sessions['owner'], $invalid);
    bms_settings_test_expect($response['status'] === 302 && bms_settings_test_values($pdo, $prefix) === $saved, 'Invalid email redirects without changing any setting');
    $response = bms_settings_test_request($base, '/admin/settings.php', $sessions['owner']);
    bms_settings_test_expect(str_contains($response['body'], 'Enter a valid admin email') && !str_contains($response['body'], 'General settings saved.') && !str_contains($response['body'], 'value="Unsaved Name"') && str_contains($response['body'], 'value="Saved &amp; Updated"'), 'Invalid submission retains established saved-value behavior without false success');

    $response = bms_settings_test_request($base, '/admin/settings.php', $sessions['owner'], array_replace($form, ['site_name' => ' ', 'site_admin_email' => '', 'timezone' => 'Not/A_Timezone']));
    $fallbacks = bms_settings_test_values($pdo, $prefix);
    bms_settings_test_expect($response['status'] === 302 && $fallbacks['site_name'] === 'Bonumark Stream' && $fallbacks['site_admin_email'] === '' && $fallbacks['timezone'] === 'UTC', 'Blank name/email and invalid timezone preserve existing fallbacks');
    bms_settings_test_expect((int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}migrations`")->fetchColumn() === $migrationCount, 'Settings requests introduce no migrations');
    $public = bms_settings_test_request($base, '/', $guest);
    bms_settings_test_expect($public['status'] === 200 && !str_contains($public['body'], 'assets/admin-shell.css') && !str_contains($public['body'], 'assets/admin-settings.css'), 'Public homepage renders without Admin foundation assets');
    fwrite(STDOUT, 'General Settings runtime regression passed: ' . $checks . " checks.\n");
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
    bms_settings_test_remove_tree($temporary);
}

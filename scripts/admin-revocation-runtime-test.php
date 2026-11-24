<?php
/**
 * Revocation browser regression against a disposable install. Requires playwright-core and Chrome.
 * --negative-control verifies the two original failures against unmodified source.
 *
 * Uses BMS_DB_HOST, BMS_DB_NAME, BMS_DB_USER, BMS_DB_PASS and requires
 * BMS_DB_DANGER_RESET=1. Only random bms_revoke_ci_* tables are created and
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
$temporary = sys_get_temp_dir() . '/bms-revocation-test-' . bin2hex(random_bytes(6));
$prefix = 'bms_revoke_ci_' . bin2hex(random_bytes(5)) . '_';
$process = null;
$pdo = null;
$checks = 0;

function bms_revocation_test_expect(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
    $GLOBALS['checks']++;
    fwrite(STDOUT, 'PASS ' . $label . "\n");
}

function bms_revocation_test_remove_tree(string $path): void
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
    bms_revocation_test_expect($ready, 'Isolated loopback server starts');
    fwrite(STDOUT, 'Runtime: PHP ' . PHP_VERSION . ' / ' . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . "\n");

    $negative = in_array('--negative-control', $argv, true);
    require $temporary . '/_bonumark_stream/app/api.php';
    require $temporary . '/_bonumark_stream/app/registration.php';
    $_SESSION['bms_logged_in'] = true;
    $_SESSION['bms_user_id'] = 1;
    $insertSetting->execute(['remote_posting_enabled', '1']);
    $insertSetting->execute(['remote_posting_rate_limit_per_minute', '600']);
    $fixtures = [];
    foreach (['unrelated', 'security', 'expired', 'exhausted', 'revoked', 'negative', 'js-1440', 'js-390', 'js-320', 'nojs-1440', 'nojs-390', 'nojs-320', 'escaped'] as $name) {
        $label = $name === 'escaped' ? '<img src=x onerror=alert(1)> & "quoted"' : 'Synthetic ' . $name;
        $token = bms_api_create_token($label, ['status:read'], $name === 'expired' ? '2000-01-01 00:00:00' : '2099-01-01 00:00:00', 1);
        $invite = bms_registration_create_invite($label, 2);
        $fixtures[$name] = ['label'=>$label, 'token_id'=>(int)$token['token']['id'], 'token'=>$token['plain_token'], 'invite_id'=>(int)$invite['id'], 'code'=>$invite['code']];
        if ($name === 'expired') { $pdo->exec("UPDATE `{$prefix}registration_invites` SET expires_at='2000-01-01 00:00:00' WHERE id=" . (int)$invite['id']); }
        if ($name === 'exhausted') { $pdo->exec("UPDATE `{$prefix}registration_invites` SET used_count=2 WHERE id=" . (int)$invite['id']); }
        if ($name === 'revoked') { bms_api_revoke_token((int)$token['token']['id']); bms_registration_revoke_invite((int)$invite['id']); }
    }
    $mediaSeed = $pdo->prepare("INSERT INTO `{$prefix}media` (filename,original_filename,public_path,mime_type,file_size,uploaded_by,privacy_status,trashed_at,created_at,updated_at) VALUES (?,?,?,'text/plain',10,1,'not_applicable',?,NOW(),NOW())");
    foreach (['active'=>null, 'trash'=>'2026-01-01 00:00:00'] as $name=>$trashedAt) {
        $filename = 'synthetic-' . $name . '.txt';
        file_put_contents($temporary . '/media/' . $filename, 'Synthetic.');
        $mediaSeed->execute([$filename,$filename,'media/'.$filename,$trashedAt]);
    }
    file_put_contents($temporary . '/fixtures.json', json_encode($fixtures));
    // CLI-only state inspection; never expose credentials or a test HTTP endpoint.
    copy(__DIR__ . '/fixtures/revocation-state.php', $temporary . '/revocation-state.php');
    $report = (string)getenv('BMS_REVOCATION_BROWSER_REPORT') ?: $temporary . '/browser-report';
    putenv('BMS_PHP_BINARY=' . PHP_BINARY);
    $browser = proc_open(['node', __DIR__ . '/admin-revocation-browser-test.cjs', $temporary, $base, $report, $negative ? 'negative' : 'corrected'], [0=>['pipe','r'],1=>STDOUT,2=>STDERR], $browserPipes);
    if (!is_resource($browser)) { throw new RuntimeException('Cannot start revocation browser checks.'); }
    fclose($browserPipes[0]);
    bms_revocation_test_expect(proc_close($browser) === 0, 'Real-browser acceptance passes');
    bms_revocation_test_expect((int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}activitypub_deliveries`")->fetchColumn() === 0, 'No federation deliveries created');
    bms_revocation_test_expect((int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}migrations`")->fetchColumn() === $migrationCount, 'Migrations unchanged');
    fwrite(STDOUT, 'Revocation runtime regression passed: ' . $checks . " checks.\n");
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
    bms_revocation_test_remove_tree($temporary);
}

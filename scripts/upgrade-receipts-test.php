<?php
/** Real database and disposable-filesystem upgrade lifecycle tests. Never targets a site. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

if (($argv[1] ?? '') === '--child') {
    $site = $argv[2];
    require $site . '/_bonumark_stream/app/database.php';
    require $site . '/_bonumark_stream/app/upgrader.php';
    require $site . '/_bonumark_stream/app/scheduler.php';
    require_once $site . '/_bonumark_stream/app/upgrade-receipts.php';
    bms_ensure_runtime_directories();
    $options = json_decode($argv[4], true, 512, JSON_THROW_ON_ERROR);
    if ($argv[3] === 'route') {
        define('BMS_STATELESS_PROTOCOL_REQUEST', true);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['SCRIPT_NAME'] = '/admin/upgrade.php';
        $_SESSION = empty($options['user']) ? [] : ['bms_logged_in' => true, 'bms_user_id' => $options['user']];
        $_GET = empty($options['list']) ? ['receipt' => $options['id'], 'format' => $options['format'] ?? 'json'] : [];
        http_response_code(200);
        register_shutdown_function(static function (): void { echo "\nHTTP_STATUS:" . http_response_code(); });
        require $site . '/admin/upgrade.php';
        exit;
    }
    $result = null;
    $error = null;
    try {
        if ($argv[3] === 'inspect') {
            $result = bms_upgrade_inspect_package($argv[5]);
        } elseif ($argv[3] === 'admin_precheck') {
            $result = bms_upgrade_precheck_package($argv[5], 'release.zip');
        } else {
            $result = bms_upgrade_install($argv[5], $options);
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
    $checks = bms_security_status();
    $id = $GLOBALS['bms_upgrade_receipt_id'] ?? null;
    $row = $id && bms_receipt_schema_available() ? bms_receipt_read($id) : null;
    echo json_encode(['result' => $result, 'error' => $error, 'receipt' => $row, 'recovery' => bms_upgrade_recovery_state(), 'version' => bms_version(), 'system_checks' => $checks], JSON_THROW_ON_ERROR);
    exit;
}

if (getenv('BMS_DB_DANGER_RESET') !== '1') {
    fwrite(STDERR, "Set BMS_DB_DANGER_RESET=1 for isolated bms_receipt_test_* tables.\n"); exit(2);
}
foreach (['BMS_DB_HOST', 'BMS_DB_NAME', 'BMS_DB_USER'] as $key) {
    if (!getenv($key)) { throw new RuntimeException('Missing ' . $key); }
}
if (!class_exists('ZipArchive')) { throw new RuntimeException('ZipArchive required.'); }
$source = dirname(__DIR__);
require $source . '/_bonumark_stream/app/database.php';
$pdo = new PDO('mysql:host=' . getenv('BMS_DB_HOST') . ';dbname=' . getenv('BMS_DB_NAME') . ';charset=utf8mb4', getenv('BMS_DB_USER'), (string)getenv('BMS_DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec("SET time_zone = '+00:00'");
$base = sys_get_temp_dir() . '/bonumark-receipts-test-' . bin2hex(random_bytes(6));
mkdir($base, 0700);
$prefixes = [];
$assertions = 0;

function receipt_assert(bool $value, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$value) { throw new RuntimeException($message); }
}

function receipt_copy(string $source, string $destination): void
{
    if (!is_dir($destination)) { mkdir($destination, 0700, true); }
    foreach (new DirectoryIterator($source) as $entry) {
        if ($entry->isDot() || $entry->getFilename() === '.git' || $entry->getFilename() === 'AGENTS.md') { continue; }
        $to = $destination . '/' . $entry->getFilename();
        if ($entry->isDir()) { receipt_copy($entry->getPathname(), $to); }
        elseif ($entry->isFile()) { copy($entry->getPathname(), $to); }
    }
}

function receipt_fixture(string $name, bool $bootstrap = false): array
{
    global $base, $source, $pdo, $prefixes;
    $site = $base . '/' . $name;
    receipt_copy($source, $site);
    $prefix = 'bms_receipt_test_' . bin2hex(random_bytes(5)) . '_';
    $prefixes[] = $prefix;
    bms_install_schema($pdo, $prefix);
    // Replay the new migration using the real idempotent migration helper.
    foreach (require $source . '/_bonumark_stream/migrations/0029_structured_upgrade_receipts.php' as $sql) { bms_exec_migration_statement($pdo, $sql, $prefix); }
    receipt_assert(bms_database_table_exists($pdo, $prefix . 'upgrade_operations'), 'fresh schema and replay');
    if ($bootstrap) {
        $pdo->exec('DROP TABLE ' . $prefix . 'upgrade_operation_events, ' . $prefix . 'upgrade_operations');
        $pdo->exec("DELETE FROM {$prefix}migrations WHERE migration = '0029_structured_upgrade_receipts'");
        unlink($site . '/_bonumark_stream/migrations/0029_structured_upgrade_receipts.php');
    }
    $config = ['database' => ['host' => getenv('BMS_DB_HOST'), 'name' => getenv('BMS_DB_NAME'), 'user' => getenv('BMS_DB_USER'), 'password' => (string)getenv('BMS_DB_PASS'), 'prefix' => $prefix], 'security_salt' => 'SYNTHETIC_SECURITY_SALT', 'mail_smtp_password' => 'SYNTHETIC_PASSWORD', 'base_url' => 'http://127.0.0.1', 'public_path' => $site];
    file_put_contents($site . '/_bonumark_stream/config.php', '<?php return ' . var_export($config, true) . ';');
    file_put_contents($site . '/_bonumark_stream/installed.lock', 'installed');
    file_put_contents($site . '/media/owner.txt', 'SYNTHETIC_PRIVATE_KEY');
    mkdir($site . '/assets/themes/owner-theme', 0700, true);
    file_put_contents($site . '/assets/themes/owner-theme/style.css', '/* owner */');
    $pdo->exec("INSERT INTO {$prefix}settings (setting_key, setting_value, updated_at) VALUES ('receipt_owner_test', 'SYNTHETIC_API_TOKEN', NOW())");
    $pdo->exec("INSERT INTO {$prefix}users (username, display_name, password_hash, role, status, created_at, updated_at) VALUES ('testowner', 'Owner', 'SYNTHETIC_LOGIN_HASH', 'admin', 'active', NOW(), NOW()), ('reader', 'Reader', 'SYNTHETIC_LOGIN_HASH', 'commenter', 'active', NOW(), NOW())");
    return [$site, $prefix];
}

function receipt_package(string $name, bool $recovery = false): string
{
    global $base, $source;
    $root = $base . '/package-' . $name;
    receipt_copy($source, $root);
    file_put_contents($root . '/VERSION', "0.8.3\n");
    file_put_contents($root . '/_bonumark_stream/VERSION', "0.8.3\n");
    $meta = json_decode(file_get_contents($root . '/_bonumark_stream/PACKAGE.json'), true);
    $meta['version'] = '0.8.3'; $meta['release_name'] = 'Disposable receipt fixture';
    file_put_contents($root . '/_bonumark_stream/PACKAGE.json', json_encode($meta));
    if ($recovery) {
        file_put_contents($root . '/_bonumark_stream/migrations/0030_receipt_test_first.php', '<?php return ["CREATE TABLE IF NOT EXISTS `{{prefix}}receipt_probe` (id INT PRIMARY KEY)"];');
        file_put_contents($root . '/_bonumark_stream/migrations/0031_receipt_test_fail.php', '<?php return ["ALTER TABLE `{{prefix}}receipt_probe` ADD COLUMN applied INT NULL", "INSERT INTO `{{prefix}}receipt_prerequisite` (id) VALUES (1)"];');
    }
    $entries = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        $path = substr($file->getPathname(), strlen($root) + 1);
        if ($path === '_bonumark_stream/RELEASE-MANIFEST.json') { continue; }
        $entries[] = ['path' => $path, 'sha256' => hash_file('sha256', $file->getPathname())];
    }
    // Disposable synthetic package only; never rewrites the repository manifest.
    file_put_contents($root . '/_bonumark_stream/RELEASE-MANIFEST.json', json_encode(['name' => 'bonumark-stream', 'version' => '0.8.3', 'generated_at' => gmdate('c'), 'files' => $entries]));
    $zipPath = $base . '/' . $name . '.zip';
    $zip = new ZipArchive(); $zip->open($zipPath, ZipArchive::CREATE);
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        $zip->addFile($file->getPathname(), 'fixture/' . substr($file->getPathname(), strlen($root) + 1));
    }
    $zip->close();
    return $zipPath;
}

function receipt_child(string $site, string $mode, string $zip, array $options = []): array
{
    $command = [PHP_BINARY, __FILE__, '--child', $site, $mode, json_encode($options), $zip];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]); $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); $code = proc_close($process);
    if ($code !== 0) { throw new RuntimeException('Fixture child failed: ' . $err . $out); }
    if ($mode === 'route') { return ['raw' => $out]; }
    $result = json_decode($out, true);
    if (!is_array($result)) { throw new RuntimeException('Fixture output is not JSON: ' . $out . $err); }
    return $result;
}

function receipt_ui_capture(string $site, string $zip, string $name, ?string $id = null): void
{
    $output = getenv('BMS_RECEIPT_UI_OUTPUT');
    if (!$output) { return; }
    if (!is_dir($output)) { mkdir($output, 0700, true); }
    $html = receipt_child($site, 'route', $zip, ['id' => $id, 'list' => $id === null, 'user' => 1, 'format' => 'html']);
    file_put_contents($output . '/' . $name . '.html', explode("\nHTTP_STATUS:", $html['raw'])[0]);
}

function receipt_events(array $result): array { return array_column($result['receipt']['events'], 'event_type'); }

try {
    echo 'Database: ' . $pdo->query('SELECT VERSION()')->fetchColumn() . "\n";
    $zip = receipt_package('normal');
    $parity = [];
    foreach (['admin_zip', 'owner_cli'] as $method) {
        [$site, $prefix] = receipt_fixture($method);
        receipt_ui_capture($site, $zip, 'empty');
        $before = hash_file('sha256', $site . '/_bonumark_stream/config.php');
        file_put_contents($site . '/assets/obsolete-receipt-test.txt', 'old');
        $plan = receipt_child($site, $method === 'admin_zip' ? 'admin_precheck' : 'inspect', $zip);
        receipt_assert($plan['error'] === null, $method . ' precheck');
        receipt_assert((int)$pdo->query("SELECT COUNT(*) FROM {$prefix}upgrade_operations")->fetchColumn() === 0, 'inspection is receipt-read-only');
        $r = receipt_child($site, 'install', $zip, ['method' => $method, 'confirm_db_backup' => true, 'expected_zip_sha256' => $plan['result']['zip_sha256']]);
        receipt_assert($r['error'] === null && $r['receipt']['status'] === 'complete', $method . ' completes: ' . json_encode($r));
        $row = $r['receipt']; $e = $row['evidence'];
        receipt_assert((bool)preg_match('/^[a-f0-9]{32}$/D', $row['operation_id']), 'opaque identifier');
        receipt_assert($row['method'] === $method && $e['from_version'] === '0.8.2' && $e['target_version'] === '0.8.3', 'method and versions');
        receipt_assert($e['package']['zip_sha256'] === hash_file('sha256', $zip) && $e['package']['manifest'] === 'verified', 'package evidence');
        receipt_assert($e['execution']['cleanup_count'] >= 1 && !is_file($site . '/assets/obsolete-receipt-test.txt'), 'actual obsolete cleanup');
        receipt_assert($e['verification']['state'] === 'pass' && $e['verification']['system_check']['state'] === 'observed', 'actual verification');
        receipt_assert($before === hash_file('sha256', $site . '/_bonumark_stream/config.php') && $e['preservation']['config_and_lock'] === 'unchanged', 'config preservation');
        receipt_assert(file_get_contents($site . '/media/owner.txt') === 'SYNTHETIC_PRIVATE_KEY' && is_file($site . '/assets/themes/owner-theme/style.css'), 'media and custom theme preservation');
        receipt_assert($pdo->query("SELECT setting_value FROM {$prefix}settings WHERE setting_key='receipt_owner_test'")->fetchColumn() === 'SYNTHETIC_API_TOKEN', 'owner database preserved');
        receipt_assert(count($e['backup']['created']) === 1 && $e['preservation']['full_data_equivalence'] === 'not_run', 'honest preservation and backup');
        $counts = ['pass' => 0, 'warning' => 0, 'fail' => 0];
        foreach ($r['system_checks'] as $check) {
            $status = $check['status'] === 'pass' ? 'pass' : (in_array($check['status'], ['fail', 'error'], true) ? 'fail' : 'warning');
            $counts[$status]++;
        }
        receipt_assert($counts === $e['verification']['system_check']['counts'], 'System Check counts match provider');
        $export = receipt_child($site, 'route', $zip, ['id' => $row['operation_id'], 'user' => 1]);
        receipt_assert(str_ends_with($export['raw'], 'HTTP_STATUS:200'), 'authorized JSON export succeeds');
        $exportRow = json_decode(explode("\nHTTP_STATUS:", $export['raw'])[0], true);
        receipt_assert($exportRow === $row, 'JSON exactly matches canonical receipt and events');
        $denied = receipt_child($site, 'route', $zip, ['id' => $row['operation_id'], 'user' => 2]);
        receipt_assert(str_ends_with($denied['raw'], 'HTTP_STATUS:403') && !str_contains($denied['raw'], $row['operation_id']), 'commenter export denied');
        $guest = receipt_child($site, 'route', $zip, ['id' => $row['operation_id']]);
        receipt_assert(!str_contains($guest['raw'], $row['operation_id']) && !str_contains($guest['raw'], 'schema_version'), 'anonymous export denied');
        $html = receipt_child($site, 'route', $zip, ['id' => $row['operation_id'], 'user' => 1, 'format' => 'html']);
        if (($uiOutput = getenv('BMS_RECEIPT_UI_OUTPUT')) !== false && $uiOutput !== '') {
            if (!is_dir($uiOutput)) { mkdir($uiOutput, 0700, true); }
            file_put_contents($uiOutput . '/' . $method . '.html', explode("\nHTTP_STATUS:", $html['raw'])[0]);
        }
        receipt_assert(str_contains($html['raw'], 'Evidence history') && str_contains($html['raw'], 'Download JSON'), 'authorized detail view');
        $encoded = json_encode($row);
        foreach (['SYNTHETIC_SECURITY_SALT', 'SYNTHETIC_PASSWORD', 'SYNTHETIC_PRIVATE_KEY', 'SYNTHETIC_API_TOKEN', (string)getenv('BMS_DB_PASS'), $site] as $secret) {
            if ($secret !== '') { receipt_assert(!str_contains($encoded, $secret), 'no secret or absolute site path'); }
        }
        $parity[] = array_keys($e);
        $blocked = receipt_child($site, 'install', $zip, ['method' => $method]);
        receipt_ui_capture($site, $zip, 'blocked', $blocked['receipt']['operation_id']);
        receipt_ui_capture($site, $zip, 'recent');
        receipt_assert($blocked['receipt']['status'] === 'blocked' && $blocked['receipt']['evidence']['execution']['changed_file_count'] === 0, 'not-newer blocked without mutation');
    }
    receipt_assert($parity[0] === $parity[1], 'Admin and CLI schema parity');

    [$site, $prefix] = receipt_fixture('rollback');
    $engine = $site . '/_bonumark_stream/app/upgrader.php';
    $original = file_get_contents($engine);
    // Fault injection is confined to the disposable runtime, with no production test hook.
    $fault = str_replace('$runtimeCacheReset = bms_upgrade_reset_php_runtime_cache();', '$runtimeCacheReset = bms_upgrade_reset_php_runtime_cache(); throw new RuntimeException("SYNTHETIC_EXCEPTION_TOKEN");', $original);
    file_put_contents($engine, $fault);
    $r = receipt_child($site, 'install', $zip, ['method' => 'admin_zip']);
    receipt_ui_capture($site, $zip, 'rollback', $r['receipt']['operation_id']);
    receipt_assert($r['receipt']['error_code'] === 'software_rolled_back' && $r['version'] === '0.8.2', 'pre-migration rollback');
    receipt_assert($r['receipt']['evidence']['migrations']['completed'] === [] && $r['recovery'] === [], 'rollback runs no migration');
    receipt_assert(!str_contains(json_encode($r['receipt']), 'SYNTHETIC_EXCEPTION_TOKEN'), 'raw exceptions excluded');

    [$site, $prefix] = receipt_fixture('rollback-failure');
    $engine = $site . '/_bonumark_stream/app/upgrader.php';
    $fault = str_replace('$rollback = bms_upgrade_restore_changed_software(', 'throw new RuntimeException("fixture rollback failure"); $rollback = bms_upgrade_restore_changed_software(', $fault);
    file_put_contents($engine, $fault);
    $r = receipt_child($site, 'install', $zip, ['method' => 'owner_cli']);
    receipt_assert($r['receipt']['error_code'] === 'rollback_failed' && $r['receipt']['evidence']['execution']['rollback'] === 'failed', 'rollback failure distinguished');

    $recoveryZip = receipt_package('recovery', true);
    [$site, $prefix] = receipt_fixture('recovery');
    $blocked = receipt_child($site, 'install', $recoveryZip, ['method' => 'owner_cli']);
    receipt_assert($blocked['receipt']['error_code'] === 'database_backup_confirmation_required' && $blocked['version'] === '0.8.2', 'backup confirmation blocks before replacement');
    $r = receipt_child($site, 'install', $recoveryZip, ['method' => 'owner_cli', 'confirm_db_backup' => true]);
    receipt_assert($r['receipt']['status'] === 'recovery_required' && $r['version'] === '0.8.3', 'migration failure keeps newer files');
    receipt_ui_capture($site, $zip, 'recovery', $r['receipt']['operation_id']);
    $id = $r['receipt']['operation_id']; $started = $r['receipt']['started_at'];
    receipt_assert($r['recovery']['operation_id'] === $id && $r['recovery']['zip_sha256'] === hash_file('sha256', $recoveryZip), 'canonical marker linkage');
    receipt_assert($r['receipt']['evidence']['migrations']['completed'] === ['0030_receipt_test_first'], 'partial completed migrations from ledger');
    $wrong = receipt_child($site, 'install', $zip, ['method' => 'owner_cli', 'confirm_db_backup' => true]);
    receipt_assert($wrong['receipt']['error_code'] === 'recovery_package_mismatch' && $wrong['recovery']['operation_id'] === $id, 'same-version different ZIP blocked');
    $pdo->exec("CREATE TABLE {$prefix}receipt_prerequisite (id INT PRIMARY KEY)");
    $resumed = receipt_child($site, 'install', $recoveryZip, ['method' => 'owner_cli', 'confirm_db_backup' => true]);
    receipt_assert($resumed['receipt']['status'] === 'complete' && $resumed['receipt']['operation_id'] === $id && $resumed['receipt']['started_at'] === $started, 'same operation resumes');
    receipt_assert(in_array('recovery_required', receipt_events($resumed), true) && in_array('recovery_resumed', receipt_events($resumed), true), 'recovery history retained');
    receipt_assert(count($resumed['receipt']['evidence']['migrations']['completed']) === 2 && count($resumed['receipt']['evidence']['backup']['created']) === 2 && $resumed['recovery'] === [], 'resume retains migration and backup evidence');

    foreach (['operation_started', 'software_replaced', 'post_verification'] as $failAt) {
        [$site, $prefix] = receipt_fixture('writer-' . $failAt);
        // A real database insert failure without MySQL SUPER or binary-log changes.
        $pdo->exec("ALTER TABLE {$prefix}upgrade_operation_events ADD CONSTRAINT {$prefix}SYNTHETIC_DB_CREDENTIAL CHECK (event_type <> '{$failAt}')");
        $r = receipt_child($site, 'install', $zip, ['method' => 'owner_cli']);
        if ($failAt === 'operation_started') {
            receipt_assert($r['error'] !== null && $r['version'] === '0.8.2', 'initial writer failure blocks mutation');
            receipt_assert((int)$pdo->query("SELECT COUNT(*) FROM {$prefix}upgrade_operations")->fetchColumn() === 0, 'operation/event initialization atomic');
        } else {
            receipt_assert($r['error'] === null && $r['version'] === '0.8.3' && $r['result']['receipt_write_warning'], 'later writer failure does not roll back');
            receipt_assert($r['receipt']['status'] === 'running' && !in_array('complete', receipt_events($r), true), 'failed evidence transaction does not manufacture completion');
        }
    }

    [$site, $prefix] = receipt_fixture('bootstrap', true);
    $r = receipt_child($site, 'install', $zip, ['method' => 'owner_cli', 'confirm_db_backup' => true]);
    receipt_assert($r['receipt']['status'] === 'complete' && $r['receipt']['evidence']['bootstrap'] === 'deferred_until_schema', 'schema bootstrap through migration');
    receipt_assert(in_array('0029_structured_upgrade_receipts', $r['receipt']['evidence']['migrations']['completed'], true), 'bootstrap migration ledger evidence');
    // Execute the actual CLI adapter, rather than merely passing its method to core.
    [$site, $prefix] = receipt_fixture('actual-cli');
    receipt_child($site, 'inspect', $zip);
    $runCli = static function (array $args) use ($site): array {
        $process = proc_open(array_merge([PHP_BINARY, $site . '/scripts/deploy-update.php', '--allow-root'], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fclose($pipes[0]); $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        return [proc_close($process), $out, $err];
    };
    [$code, $out, $err] = $runCli(['--check', $zip]);
    receipt_assert($code === 0 && (int)$pdo->query("SELECT COUNT(*) FROM {$prefix}upgrade_operations")->fetchColumn() === 0, 'actual CLI --check has no receipt');
    [$code, $out, $err] = $runCli(['--yes', $zip]);
    receipt_assert($code === 0 && str_contains($out, 'Receipt: ') && str_contains($out, 'Deployment check passed.'), 'actual CLI upgrade and deployment check: ' . $err);
    $cliRow = $pdo->query("SELECT * FROM {$prefix}upgrade_operations")->fetch();
    receipt_assert($cliRow['status'] === 'complete' && $cliRow['method'] === 'owner_cli' && str_contains($out, $cliRow['operation_id']), 'CLI reports canonical identity');

    // Actual pinned pre-receipt engine, supplied from accepted develop, not a backfill.
    [$site, $prefix] = receipt_fixture('legacy-admin', true);
    copy($source . '/scripts/fixtures/pre-receipt-upgrader.php', $site . '/_bonumark_stream/app/upgrader.php');
    $legacy = receipt_child($site, 'install', $zip, ['method' => 'admin_zip']);
    receipt_assert($legacy['error'] === null && $legacy['version'] === '0.8.3' && $legacy['receipt'] === null, 'old engine produces no fabricated receipt');
    receipt_assert((int)$pdo->query("SELECT COUNT(*) FROM {$prefix}upgrade_history WHERE status='complete'")->fetchColumn() === 1, 'legacy history retained during bootstrap');
    receipt_assert((int)$pdo->query("SELECT COUNT(*) FROM {$prefix}upgrade_operations")->fetchColumn() === 0, 'no invented bootstrap evidence');

    echo "Upgrade receipt tests passed: {$assertions} assertions.\n";
} finally {
    // Only the exact random prefixes allocated by this test are removed.
    foreach ($prefixes as $prefix) {
        foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
            if (str_starts_with($table, $prefix)) { $pdo->exec('DROP TABLE `' . $table . '`'); }
        }
    }
    bms_delete_directory($base);
}

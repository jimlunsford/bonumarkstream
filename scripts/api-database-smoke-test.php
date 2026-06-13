<?php
/**
 * Bonumark Stream optional Remote API database smoke test.
 *
 * This CLI-only test uses a real MySQL/MariaDB database, copies the current
 * package to a temporary workspace, creates a temporary Bonumark config with a
 * random bms_api_ci_* table prefix, installs the schema, seeds an admin user,
 * and checks core Remote API behavior without adding endpoints or changing
 * production data.
 *
 * Required environment variables:
 *   BMS_DB_HOST
 *   BMS_DB_NAME
 *   BMS_DB_USER
 *   BMS_DB_PASS, may be empty
 *   BMS_DB_DANGER_RESET=1
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$scenario = (string)(getenv('BMS_API_SMOKE_SCENARIO') ?: '');
if ($scenario !== '') {
    bms_api_smoke_run_child($scenario);
    exit(0);
}

if ((string)getenv('BMS_DB_DANGER_RESET') !== '1') {
    fwrite(STDERR, "Refusing to run. Set BMS_DB_DANGER_RESET=1 to confirm this test may create and drop temporary bms_api_ci_* tables.\n");
    exit(1);
}

foreach (['BMS_DB_HOST', 'BMS_DB_NAME', 'BMS_DB_USER'] as $required) {
    if ((string)getenv($required) === '') {
        fwrite(STDERR, "{$required} is required.\n");
        exit(1);
    }
}

$scenarios = [
    'disabled_api',
    'missing_token',
    'invalid_token',
    'draft_create',
    'publish_scope',
    'publish_confirmation',
    'media_scope',
    'idempotency_replay',
    'idempotency_conflict',
];

foreach ($scenarios as $name) {
    $env = array_merge($_ENV, getenv());
    $env['BMS_API_SMOKE_SCENARIO'] = $name;
    $command = [PHP_BINARY, __FILE__];
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes, dirname(__DIR__), $env);
    if (!is_resource($process)) {
        fwrite(STDERR, "Could not start API smoke scenario: {$name}\n");
        exit(1);
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0) {
        fwrite(STDERR, "Remote API database smoke scenario failed: {$name}\n");
        if ($stdout !== '') {
            fwrite(STDERR, $stdout);
        }
        if ($stderr !== '') {
            fwrite(STDERR, $stderr);
        }
        exit($status > 0 ? $status : 1);
    }
}

fwrite(STDOUT, "Remote API database smoke test passed. Scenarios: " . implode(', ', $scenarios) . "\n");

function bms_api_smoke_run_child(string $scenario): void
{
    if ((string)getenv('BMS_DB_DANGER_RESET') !== '1') {
        throw new RuntimeException('BMS_DB_DANGER_RESET=1 is required.');
    }

    $sourceRoot = dirname(__DIR__);
    $tempRoot = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'bms-api-smoke-' . strtolower(bin2hex(random_bytes(5)));
    $prefix = 'bms_api_ci_' . strtolower(bin2hex(random_bytes(4))) . '_';

    bms_api_smoke_copy_tree($sourceRoot, $tempRoot);

    $configPath = $tempRoot . '/_bonumark_stream/config.php';
    $lockPath = $tempRoot . '/_bonumark_stream/installed.lock';
    $config = [
        'site_name' => 'Bonumark API Smoke',
        'site_tagline' => 'Temporary API smoke test install.',
        'version' => trim((string)file_get_contents($tempRoot . '/VERSION')),
        'base_url' => 'https://example.test',
        'base_path' => '',
        'public_path' => '',
        'security_salt' => 'api-smoke-' . bin2hex(random_bytes(12)),
        'timezone' => 'UTC',
        'database' => [
            'host' => (string)getenv('BMS_DB_HOST'),
            'name' => (string)getenv('BMS_DB_NAME'),
            'user' => (string)getenv('BMS_DB_USER'),
            'password' => (string)(getenv('BMS_DB_PASS') === false ? '' : getenv('BMS_DB_PASS')),
            'charset' => (string)(getenv('BMS_DB_CHARSET') ?: 'utf8mb4'),
            'prefix' => $prefix,
        ],
    ];
    file_put_contents($configPath, "<?php\nreturn " . var_export($config, true) . ";\n");
    touch($lockPath);

    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['HTTP_USER_AGENT'] = 'Bonumark API Smoke Test';
    $_SERVER['REQUEST_URI'] = '/api/v1/status';
    $_SERVER['REQUEST_METHOD'] = 'POST';

    require_once $tempRoot . '/_bonumark_stream/app/api.php';

    try {
        bms_install_schema(bms_db(), $prefix);
        bms_db_insert_initial_data([
            'site_name' => 'Bonumark API Smoke',
            'site_tagline' => 'Temporary API smoke test install.',
            'timezone' => 'UTC',
            'base_url' => 'https://example.test',
            'base_path' => '',
            'public_path' => '',
        ], [
            'username' => 'admin',
            'display_name' => 'Admin',
            'email' => 'admin@example.test',
            'password' => bin2hex(random_bytes(12)),
        ]);

        bms_api_smoke_set_setting('remote_posting_enabled', $scenario === 'disabled_api' ? '0' : '1');
        bms_api_smoke_set_setting('remote_posting_direct_publish_enabled', '1');
        bms_api_smoke_set_setting('remote_posting_publish_confirmation_required', '1');
        bms_api_smoke_set_setting('remote_media_upload_enabled', '1');

        bms_api_smoke_run_scenario($scenario);
    } finally {
        bms_api_smoke_drop_temp_tables($prefix);
        bms_api_smoke_remove_tree($tempRoot);
    }
}

function bms_api_smoke_run_scenario(string $scenario): void
{
    switch ($scenario) {
        case 'disabled_api':
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer invalid';
            bms_api_smoke_expect_api_exception('remote_posting_disabled', function (): void {
                bms_api_authenticate(['status:read']);
            });
            return;

        case 'missing_token':
            unset($_SERVER['HTTP_AUTHORIZATION']);
            bms_api_smoke_expect_api_exception('missing_bearer_token', function (): void {
                bms_api_authenticate(['status:read']);
            });
            return;

        case 'invalid_token':
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer invalid-token';
            bms_api_smoke_expect_api_exception('invalid_bearer_token', function (): void {
                bms_api_authenticate(['status:read']);
            });
            return;

        case 'draft_create':
            $tokenData = bms_api_create_token('Draft token', ['status:read', 'stream:draft'], null, 1);
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . (string)$tokenData['plain_token'];
            $token = bms_api_authenticate(['stream:draft']);
            $post = bms_api_create_remote_stream_post([
                'content' => 'Remote API smoke draft.',
                'status' => 'draft',
            ], $token, 'draft');
            if (($post['status'] ?? '') !== 'draft' || (int)($post['post_id'] ?? 0) < 1) {
                throw new RuntimeException('Draft token did not create a draft post.');
            }
            return;

        case 'publish_scope':
            $tokenData = bms_api_create_token('Draft only token', ['status:read', 'stream:draft'], null, 1);
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . (string)$tokenData['plain_token'];
            bms_api_smoke_expect_api_exception('missing_scope', function (): void {
                bms_api_authenticate(['stream:draft', 'stream:publish']);
            });
            return;

        case 'publish_confirmation':
            $tokenData = bms_api_create_token('Publish token', ['status:read', 'stream:draft', 'stream:publish'], null, 1);
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . (string)$tokenData['plain_token'];
            $token = bms_api_authenticate(['stream:draft', 'stream:publish']);
            bms_api_smoke_expect_api_exception('publish_confirmation_required', function () use ($token): void {
                bms_api_create_remote_stream_post([
                    'content' => 'Remote API smoke publish without confirmation.',
                    'status' => 'published',
                ], $token, 'published');
            });
            return;

        case 'media_scope':
            $tokenData = bms_api_create_token('Draft only token', ['status:read', 'stream:draft'], null, 1);
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . (string)$tokenData['plain_token'];
            bms_api_smoke_expect_api_exception('missing_scope', function (): void {
                bms_api_authenticate(['media:upload']);
            });
            return;

        case 'idempotency_replay':
            $tokenData = bms_api_create_token('Idempotency token', ['status:read', 'stream:draft'], null, 1);
            $tokenId = (int)(($tokenData['token']['id'] ?? 0));
            if ($tokenId < 1) {
                throw new RuntimeException('Token ID was not created for idempotency test.');
            }
            $key = 'smoke-replay';
            $hash = 'hash-replay';
            if (bms_api_idempotency_begin($tokenId, $key, $hash) !== null) {
                throw new RuntimeException('New idempotency key unexpectedly returned a stored response.');
            }
            bms_api_idempotency_store($tokenId, $key, $hash, ['ok' => true, 'smoke' => 'replay'], 201);
            $stored = bms_api_idempotency_begin($tokenId, $key, $hash);
            if (!is_array($stored) || (int)($stored['status'] ?? 0) !== 201 || (($stored['payload']['smoke'] ?? '') !== 'replay')) {
                throw new RuntimeException('Idempotency replay did not return the stored response.');
            }
            return;

        case 'idempotency_conflict':
            $tokenData = bms_api_create_token('Idempotency token', ['status:read', 'stream:draft'], null, 1);
            $tokenId = (int)(($tokenData['token']['id'] ?? 0));
            if ($tokenId < 1) {
                throw new RuntimeException('Token ID was not created for idempotency conflict test.');
            }
            $key = 'smoke-conflict';
            bms_api_idempotency_begin($tokenId, $key, 'hash-one');
            bms_api_idempotency_store($tokenId, $key, 'hash-one', ['ok' => true], 201);
            bms_api_smoke_expect_api_exception('idempotency_key_conflict', function () use ($tokenId, $key): void {
                bms_api_idempotency_begin($tokenId, $key, 'hash-two');
            });
            return;
    }

    throw new RuntimeException('Unknown API smoke scenario: ' . $scenario);
}

function bms_api_smoke_expect_api_exception(string $expectedCode, callable $callback): void
{
    try {
        $callback();
    } catch (BMS_Api_Exception $e) {
        if ($e->apiCode !== $expectedCode) {
            throw new RuntimeException("Expected API error {$expectedCode}, got {$e->apiCode}.");
        }
        return;
    }
    throw new RuntimeException("Expected API error {$expectedCode}, but no API exception was thrown.");
}

function bms_api_smoke_set_setting(string $key, string $value): void
{
    $stmt = bms_db()->prepare('INSERT INTO ' . bms_table('settings') . ' (setting_key, setting_value, updated_at) VALUES (:setting_key, :setting_value, NOW()) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()');
    $stmt->execute(['setting_key' => $key, 'setting_value' => $value]);
}

function bms_api_smoke_copy_tree(string $source, string $destination): void
{
    if (!mkdir($destination, 0700, true) && !is_dir($destination)) {
        throw new RuntimeException('Could not create temporary workspace.');
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($source) + 1));
        if ($relative === '_bonumark_stream/config.php' || $relative === '_bonumark_stream/installed.lock') {
            continue;
        }
        $target = $destination . DIRECTORY_SEPARATOR . $relative;
        if ($item->isDir()) {
            if (!is_dir($target) && !mkdir($target, 0700, true) && !is_dir($target)) {
                throw new RuntimeException('Could not create temporary directory: ' . $relative);
            }
        } elseif ($item->isFile()) {
            $targetDir = dirname($target);
            if (!is_dir($targetDir) && !mkdir($targetDir, 0700, true) && !is_dir($targetDir)) {
                throw new RuntimeException('Could not create temporary directory: ' . $targetDir);
            }
            if (!copy($item->getPathname(), $target)) {
                throw new RuntimeException('Could not copy file into temporary workspace: ' . $relative);
            }
        }
    }
}

function bms_api_smoke_drop_temp_tables(string $prefix): void
{
    try {
        $stmt = bms_db()->query('SHOW TABLES LIKE ' . bms_db()->quote($prefix . '%'));
        $tables = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
        foreach ($tables as $table) {
            if (is_string($table) && str_starts_with($table, $prefix)) {
                bms_db()->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', $table) . '`');
            }
        }
    } catch (Throwable $e) {
        fwrite(STDERR, "Could not drop temporary API smoke tables: " . $e->getMessage() . "\n");
    }
}

function bms_api_smoke_remove_tree(string $path): void
{
    if ($path === '' || !is_dir($path)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }
    @rmdir($path);
}

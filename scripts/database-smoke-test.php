<?php
/**
 * Bonumark Stream database smoke test.
 *
 * This CLI-only test creates temporary tables with a random prefix, runs every
 * bundled migration against a real MySQL/MariaDB database, verifies the migration
 * ledger, and then drops only the temporary tables it created.
 *
 * Required environment variables:
 *   BMS_DB_HOST
 *   BMS_DB_NAME
 *   BMS_DB_USER
 *   BMS_DB_PASS, may be empty
 *   BMS_DB_DANGER_RESET=1
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

if ((string)getenv('BMS_DB_DANGER_RESET') !== '1') {
    fwrite(STDERR, "Refusing to run. Set BMS_DB_DANGER_RESET=1 to confirm this test may create and drop temporary bms_ci_* tables.\n");
    exit(1);
}

$host = (string)getenv('BMS_DB_HOST');
$name = (string)getenv('BMS_DB_NAME');
$user = (string)getenv('BMS_DB_USER');
$pass = getenv('BMS_DB_PASS');
$pass = $pass === false ? '' : (string)$pass;
$charset = (string)(getenv('BMS_DB_CHARSET') ?: 'utf8mb4');

if ($host === '' || $name === '' || $user === '') {
    fwrite(STDERR, "BMS_DB_HOST, BMS_DB_NAME, and BMS_DB_USER are required.\n");
    exit(1);
}

$prefix = 'bms_ci_' . strtolower(bin2hex(random_bytes(4))) . '_';
$root = dirname(__DIR__);
$migrationDir = $root . '/_bonumark_stream/migrations';
$files = glob($migrationDir . '/*.php') ?: [];
sort($files);

if (!$files) {
    fwrite(STDERR, "No migration files found.\n");
    exit(1);
}

$pdo = new PDO("mysql:host={$host};dbname={$name};charset={$charset}", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

$executed = [];
try {
    foreach ($files as $file) {
        $name = basename($file, '.php');
        $statements = require $file;
        if (!is_array($statements)) {
            throw new RuntimeException("Migration did not return an array: {$name}");
        }
        foreach ($statements as $statement) {
            if (!is_string($statement) || trim($statement) === '') {
                continue;
            }
            $sql = str_replace('{{prefix}}', $prefix, $statement);
            if (preg_match('/{{[^}]+}}/', $sql) === 1) {
                throw new RuntimeException("Migration contains an unresolved placeholder: {$name}");
            }
            $pdo->exec($sql);
        }
        $pdo->prepare("INSERT INTO `{$prefix}migrations` (`migration`, `ran_at`) VALUES (:migration, NOW()) ON DUPLICATE KEY UPDATE ran_at = ran_at")
            ->execute(['migration' => $name]);
        $executed[] = $name;
    }

    $count = (int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}migrations`")->fetchColumn();
    if ($count !== count($executed)) {
        throw new RuntimeException("Migration ledger count mismatch. Expected " . count($executed) . ", got {$count}.");
    }

    $requiredTables = ['users', 'settings', 'posts', 'migrations', 'media', 'comments', 'upgrade_history', 'api_tokens', 'api_audit_log', 'api_rate_limit_attempts', 'api_idempotency_keys'];
    foreach ($requiredTables as $table) {
        $stmt = $pdo->prepare('SHOW TABLES LIKE :table_name');
        $stmt->execute(['table_name' => $prefix . $table]);
        if (!$stmt->fetchColumn()) {
            throw new RuntimeException("Expected table was not created: {$prefix}{$table}");
        }
    }

    fwrite(STDOUT, "Database smoke test passed with prefix {$prefix}. Migrations executed: " . count($executed) . "\n");
} finally {
    $stmt = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($prefix . '%'));
    $tables = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
    foreach ($tables as $table) {
        if (is_string($table) && str_starts_with($table, $prefix)) {
            $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', $table) . '`');
        }
    }
}

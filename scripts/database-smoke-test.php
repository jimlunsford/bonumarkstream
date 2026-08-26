<?php
/**
 * Bonumark Stream database smoke test.
 *
 * This CLI-only test exercises two distinct database paths against a real
 * MySQL/MariaDB database:
 *
 * 1. Fresh install: run the current installer schema path exactly as Bonumark
 *    does today, including the current cumulative 0001 baseline and all bundled
 *    migrations through the idempotent migration helper.
 * 2. Supported upgrade: start from the historical v0.4.x public baseline and
 *    replay only the migrations that follow that baseline.
 *
 * Keeping these paths separate prevents a current cumulative fresh-install
 * schema from being mistaken for an old install and then having historical DDL
 * blindly applied on top of columns/indexes that already exist.
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
    if (!headers_sent()) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
    }
    exit('CLI only.');
}

if ((string)getenv('BMS_DB_DANGER_RESET') !== '1') {
    fwrite(STDERR, "Refusing to run. Set BMS_DB_DANGER_RESET=1 to confirm this test may create and drop temporary bms_ci_* tables.\n");
    exit(1);
}

$host = (string)getenv('BMS_DB_HOST');
$dbName = (string)getenv('BMS_DB_NAME');
$user = (string)getenv('BMS_DB_USER');
$pass = getenv('BMS_DB_PASS');
$pass = $pass === false ? '' : (string)$pass;
$charset = (string)(getenv('BMS_DB_CHARSET') ?: 'utf8mb4');

if ($host === '' || $dbName === '' || $user === '') {
    fwrite(STDERR, "BMS_DB_HOST, BMS_DB_NAME, and BMS_DB_USER are required.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/_bonumark_stream/app/database.php';

$migrationDir = $root . '/_bonumark_stream/migrations';
$migrationFiles = glob($migrationDir . '/*.php') ?: [];
sort($migrationFiles);
$migrationNames = array_values(array_map(
    static fn(string $file): string => basename($file, '.php'),
    $migrationFiles
));
if ($migrationNames === [] || $migrationNames[0] !== '0001_initial_schema') {
    fwrite(STDERR, "Expected bundled migrations beginning with 0001_initial_schema.\n");
    exit(1);
}

$pdo = new PDO("mysql:host={$host};dbname={$dbName};charset={$charset}", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

$databaseInfo = bms_database_require_supported($pdo);
fwrite(STDOUT, 'Database target: ' . (string)($databaseInfo['display'] ?? 'unknown') . ' (compatibility floor ' . (string)($databaseInfo['minimum'] ?? 'unknown') . "+)\n");

$freshPrefix = 'bms_ci_fresh_' . strtolower(bin2hex(random_bytes(4))) . '_';
$upgradePrefix = 'bms_ci_upgrade_' . strtolower(bin2hex(random_bytes(4))) . '_';

try {
    bms_install_schema($pdo, $freshPrefix);
    bms_database_smoke_verify_schema($pdo, $freshPrefix, $migrationNames, 'fresh install');
    fwrite(STDOUT, "Fresh-install schema smoke test passed with prefix {$freshPrefix}.\n");

    $fixturePath = $root . '/scripts/fixtures/v0.4.x-initial-schema.php';
    if (!is_file($fixturePath)) {
        throw new RuntimeException('Historical v0.4.x database smoke fixture is missing.');
    }
    $baselineStatements = require $fixturePath;
    if (!is_array($baselineStatements) || $baselineStatements === []) {
        throw new RuntimeException('Historical v0.4.x database smoke fixture did not return a statement list.');
    }
    foreach ($baselineStatements as $index => $statement) {
        if (!is_int($index) || !is_string($statement) || trim($statement) === '') {
            throw new RuntimeException('Historical v0.4.x database smoke fixture must contain a numeric list of SQL statement strings.');
        }
        bms_exec_migration_statement($pdo, $statement, $upgradePrefix);
    }

    $ledger = $pdo->prepare("INSERT INTO `{$upgradePrefix}migrations` (`migration`, `ran_at`) VALUES (:migration, NOW()) ON DUPLICATE KEY UPDATE ran_at = ran_at");
    $ledger->execute(['migration' => '0001_initial_schema']);

    foreach ($migrationFiles as $file) {
        $migration = basename($file, '.php');
        if ($migration === '0001_initial_schema') {
            continue;
        }
        $statements = require $file;
        if (!is_array($statements)) {
            throw new RuntimeException("Migration did not return an array: {$migration}");
        }
        foreach ($statements as $index => $statement) {
            if (!is_int($index) || !is_string($statement) || trim($statement) === '') {
                throw new RuntimeException("Migration must return a numeric list of SQL statement strings: {$migration}");
            }
            bms_exec_migration_statement($pdo, $statement, $upgradePrefix);
        }
        $ledger->execute(['migration' => $migration]);
    }

    bms_database_smoke_verify_schema($pdo, $upgradePrefix, $migrationNames, 'v0.4.x upgrade');
    fwrite(STDOUT, "Supported-upgrade schema smoke test passed with prefix {$upgradePrefix}. Migrations verified: " . count($migrationNames) . "\n");
} finally {
    bms_database_smoke_drop_tables($pdo, $freshPrefix);
    bms_database_smoke_drop_tables($pdo, $upgradePrefix);
}

/**
 * @param list<string> $expectedMigrations
 */
function bms_database_smoke_verify_schema(PDO $pdo, string $prefix, array $expectedMigrations, string $label): void
{
    $migrationRows = $pdo->query("SELECT `migration` FROM `{$prefix}migrations` ORDER BY `migration`");
    $actualMigrations = $migrationRows ? array_values($migrationRows->fetchAll(PDO::FETCH_COLUMN)) : [];
    $expected = $expectedMigrations;
    sort($expected);
    if ($actualMigrations !== $expected) {
        throw new RuntimeException("{$label} migration ledger does not match the bundled migration set.");
    }

    $requiredTables = [
        'users',
        'settings',
        'posts',
        'migrations',
        'media',
        'comments',
        'upgrade_history',
        'api_tokens',
        'api_audit_log',
        'api_rate_limit_attempts',
        'api_idempotency_keys',
        'remember_tokens',
        'scheduled_task_runs',
        'analytics_daily',
        'places',
        'user_profiles',
    ];
    foreach ($requiredTables as $table) {
        $stmt = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($prefix . $table));
        if ($stmt === false || !$stmt->fetchColumn()) {
            throw new RuntimeException("{$label} expected table was not created: {$prefix}{$table}");
        }
    }

    $requiredColumns = [
        'posts' => ['scheduled_at', 'is_pinned', 'pinned_at'],
        'media' => ['privacy_status', 'privacy_note', 'privacy_checked_at'],
        'user_profiles' => ['featured_items_json', 'profile_photos_json'],
    ];
    foreach ($requiredColumns as $table => $columns) {
        foreach ($columns as $column) {
            $columnStmt = $pdo->query("SHOW COLUMNS FROM `{$prefix}{$table}` LIKE " . $pdo->quote($column));
            if ($columnStmt === false || !$columnStmt->fetch()) {
                throw new RuntimeException("{$label} expected column was not created: {$table}.{$column}");
            }
        }
    }

    foreach ([
        'status_scheduled_at',
        'post_type_status_pinned_at',
    ] as $index) {
        $indexStmt = $pdo->query("SHOW INDEX FROM `{$prefix}posts` WHERE Key_name = " . $pdo->quote($index));
        if ($indexStmt === false || !$indexStmt->fetch()) {
            throw new RuntimeException("{$label} expected posts index was not created: {$index}");
        }
    }
}

function bms_database_smoke_drop_tables(PDO $pdo, string $prefix): void
{
    $stmt = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($prefix . '%'));
    $tables = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
    foreach ($tables as $table) {
        if (is_string($table) && str_starts_with($table, $prefix)) {
            $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', $table) . '`');
        }
    }
}

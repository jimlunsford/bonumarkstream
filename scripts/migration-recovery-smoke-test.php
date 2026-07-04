<?php
/**
 * Optional MySQL/MariaDB verification for DDL migration recovery.
 *
 * This is intentionally destructive only inside a temporary table. It requires:
 * BMS_DB_TEST_ALLOW_DESTRUCTIVE=1
 * BMS_DB_HOST, BMS_DB_NAME, BMS_DB_USER, BMS_DB_PASSWORD
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    if (!headers_sent()) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
    }
    exit('CLI only.');
}

if (getenv('BMS_DB_TEST_ALLOW_DESTRUCTIVE') !== '1') {
    fwrite(STDERR, "Set BMS_DB_TEST_ALLOW_DESTRUCTIVE=1 to run this real database test.\n");
    exit(2);
}

$host = (string)getenv('BMS_DB_HOST');
$name = (string)getenv('BMS_DB_NAME');
$user = (string)getenv('BMS_DB_USER');
$pass = (string)getenv('BMS_DB_PASSWORD');
if ($host === '' || $name === '' || $user === '') {
    fwrite(STDERR, "Set BMS_DB_HOST, BMS_DB_NAME, BMS_DB_USER, and BMS_DB_PASSWORD.\n");
    exit(2);
}

$root = dirname(__DIR__);
require_once $root . '/_bonumark_stream/app/database.php';
$pdo = new PDO("mysql:host={$host};dbname={$name};charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$pdo->exec("SET time_zone = '+00:00'");
$table = 'bms_test_migration_recovery_' . bin2hex(random_bytes(4));

try {
    $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
    $pdo->exec("CREATE TABLE `{$table}` (id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB");

    $statements = [
        "ALTER TABLE `{$table}` ADD COLUMN `first_change` INT NULL",
        "ALTER TABLE `{$table}` ADD COLUMN `first_change` INT NULL", // deliberate failure, then retry is idempotent
        "ALTER TABLE `{$table}` ADD COLUMN `second_change` INT NULL",
    ];
    $failed = false;
    try {
        foreach ($statements as $statement) {
            $pdo->exec($statement);
        }
    } catch (Throwable $e) {
        $failed = true;
    }
    if (!$failed) {
        throw new RuntimeException('Expected simulated DDL failure did not occur.');
    }

    foreach ($statements as $statement) {
        try {
            $pdo->exec($statement);
        } catch (Throwable $e) {
            if (!bms_migration_error_is_idempotent($e, $statement)) {
                throw $e;
            }
        }
    }
    $columns = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('first_change', $columns, true) || !in_array('second_change', $columns, true)) {
        throw new RuntimeException('DDL retry did not complete the temporary migration.');
    }
    echo "Migration recovery test passed.\n";
} finally {
    $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
}

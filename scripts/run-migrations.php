<?php
/**
 * Owner-run database migration helper for locked-down/manual deployments.
 *
 * This script never changes application files or elevates privileges. It runs
 * only the migration files already present in the installed Bonumark release.
 * Always back up the database before using --run.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    if (!headers_sent()) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
    }
    exit('CLI only.');
}

$root = dirname(__DIR__);
require_once $root . '/_bonumark_stream/app/database.php';

$options = getopt('', ['check', 'run', 'confirm-backup', 'from-version:']);
$check = array_key_exists('check', $options);
$run = array_key_exists('run', $options);
$confirmedBackup = array_key_exists('confirm-backup', $options);
$fromVersion = trim((string)($options['from-version'] ?? ''));
$targetVersion = trim((string)@file_get_contents($root . '/VERSION'));

if (($check && $run) || (!$check && !$run)) {
    bms_manual_migration_usage();
    exit(1);
}

if (!is_file($root . '/_bonumark_stream/config.php') || !is_file($root . '/_bonumark_stream/installed.lock')) {
    fwrite(STDERR, "This helper is for an installed Bonumark Stream site.\n");
    exit(1);
}

try {
    $pdo = bms_db();
    $databaseInfo = bms_database_require_supported($pdo);
    $pending = bms_pending_migration_names($pdo);
    $recovery = bms_upgrade_recovery_state();
} catch (Throwable $e) {
    fwrite(STDERR, 'Database migration check failed: ' . $e->getMessage() . "\n");
    exit(1);
}

$databaseLabel = (string)($databaseInfo['display'] ?? 'database server');
echo "Bonumark Stream manual migration workflow\n";
echo 'Target software version: ' . ($targetVersion !== '' ? $targetVersion : 'unknown') . "\n";
echo 'Database: ' . $databaseLabel . "\n";

if ($recovery !== []) {
    echo 'Recovery state: ' . (string)($recovery['status'] ?? 'unknown') . ' for v' . (string)($recovery['to_version'] ?? 'unknown') . "\n";
}

if ($pending === []) {
    echo "Pending migrations: 0\n";
    if ($check) {
        echo "Database migration state is current.\n";
        exit(0);
    }

    if ($recovery !== [] && (string)($recovery['to_version'] ?? '') === $targetVersion) {
        $resolvedFrom = trim((string)($recovery['from_version'] ?? $fromVersion));
        bms_record_manual_upgrade_history($resolvedFrom, $targetVersion, []);
        bms_clear_upgrade_recovery_state();
        echo "No migrations remain pending. Matching recovery state was cleared and manual upgrade history was recorded.\n";
    } else {
        echo "No migration work is required.\n";
    }
    exit(0);
}

printf("Pending migrations: %d\n", count($pending));
foreach ($pending as $migration) {
    echo '  - ' . $migration . "\n";
}

if ($check) {
    echo "A database backup and an explicit --run invocation are required before these migrations can be applied.\n";
    exit(0);
}

if (!$confirmedBackup) {
    fwrite(STDERR, "Refusing to run migrations. Back up the database, then rerun with --confirm-backup.\n");
    exit(1);
}

if ($targetVersion === '') {
    fwrite(STDERR, "Could not determine the installed target VERSION.\n");
    exit(1);
}

if ($recovery !== []) {
    $recoveryTarget = trim((string)($recovery['to_version'] ?? ''));
    if ($recoveryTarget !== '' && !hash_equals($recoveryTarget, $targetVersion)) {
        fwrite(STDERR, "Existing migration recovery state targets v{$recoveryTarget}, not the installed v{$targetVersion}. Resolve that recovery state before continuing.\n");
        exit(1);
    }
    if ($fromVersion === '') {
        $fromVersion = trim((string)($recovery['from_version'] ?? ''));
    }
}

if ($fromVersion === '') {
    fwrite(STDERR, "--from-version is required for a new manual migration run so upgrade history preserves the deployment boundary. Example: --from-version=0.6.5\n");
    exit(1);
}

if (preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][A-Za-z0-9._-]+)?$/', $fromVersion) !== 1) {
    fwrite(STDERR, "--from-version must be a Bonumark semantic version such as 0.6.5.\n");
    exit(1);
}

$startedAt = gmdate('c');
bms_write_upgrade_recovery_state([
    'status' => 'migration_in_progress',
    'phase' => 'database_migration',
    'from_version' => $fromVersion,
    'to_version' => $targetVersion,
    'backup_path' => 'external-database-backup',
    'started_at' => $startedAt,
]);

try {
    $ran = bms_run_migrations($fromVersion);
    bms_record_manual_upgrade_history($fromVersion, $targetVersion, $ran);
    bms_clear_upgrade_recovery_state();
    echo 'Migrations completed: ' . count($ran) . "\n";
    foreach ($ran as $migration) {
        echo '  - ' . $migration . "\n";
    }
    echo "Manual database migration workflow completed successfully.\n";
    exit(0);
} catch (Throwable $e) {
    try {
        bms_write_upgrade_recovery_state([
            'status' => 'recovery_required',
            'phase' => 'database_migration',
            'from_version' => $fromVersion,
            'to_version' => $targetVersion,
            'backup_path' => 'external-database-backup',
            'started_at' => $startedAt,
        ]);
    } catch (Throwable $markerError) {
        fwrite(STDERR, 'WARNING: Could not persist migration recovery state: ' . $markerError->getMessage() . "\n");
    }
    fwrite(STDERR, 'Migration run stopped: ' . $e->getMessage() . "\n");
    fwrite(STDERR, "Do not deploy older application files over a database that may contain committed DDL. Fix the cause and rerun this same target release.\n");
    exit(1);
}

function bms_manual_migration_usage(): void
{
    $script = basename(__FILE__);
    fwrite(STDERR, "Usage:\n");
    fwrite(STDERR, "  php scripts/{$script} --check\n");
    fwrite(STDERR, "  php scripts/{$script} --run --confirm-backup --from-version=X.Y.Z\n");
}

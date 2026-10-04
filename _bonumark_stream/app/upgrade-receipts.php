<?php
/** Database-backed upgrade evidence. No schema creation or recovery decisions here. */
declare(strict_types=1);

function bms_receipt_schema_available(): bool
{
    $pdo = bms_db();
    return bms_database_table_exists($pdo, bms_table('upgrade_operations'))
        && bms_database_table_exists($pdo, bms_table('upgrade_operation_events'));
}

function bms_receipt_read(string $id): ?array
{
    if (!preg_match('/^[a-f0-9]{32}$/D', $id)) {
        return null;
    }
    $pdo = bms_db();
    $ownTransaction = !$pdo->inTransaction();
    if ($ownTransaction) { $pdo->beginTransaction(); }
    try {
        $stmt = $pdo->prepare('SELECT * FROM ' . bms_table('upgrade_operations') . ' WHERE operation_id = ? LOCK IN SHARE MODE');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $row['schema_version'] = (int)$row['schema_version'];
        $row['evidence'] = json_decode($row['evidence'], true, 512, JSON_THROW_ON_ERROR);
        $stmt = bms_db()->prepare('SELECT id, observed_at, event_type, evidence FROM ' . bms_table('upgrade_operation_events') . ' WHERE operation_id = ? ORDER BY id');
        $stmt->execute([$id]);
        $row['events'] = $stmt->fetchAll();
        foreach ($row['events'] as &$event) {
            $event['id'] = (int)$event['id'];
            $event['evidence'] = json_decode($event['evidence'], true, 512, JSON_THROW_ON_ERROR);
        }
        return $row;
    } finally {
        if ($ownTransaction && $pdo->inTransaction()) { $pdo->commit(); }
    }
}

function bms_receipt_recent(): array
{
    if (!bms_receipt_schema_available()) {
        return [];
    }
    $rows = bms_db()->query('SELECT operation_id, started_at, completed_at, method, status, error_code, evidence FROM ' . bms_table('upgrade_operations') . ' ORDER BY started_at DESC, operation_id DESC LIMIT 20')->fetchAll();
    foreach ($rows as &$row) {
        $row['evidence'] = json_decode($row['evidence'], true, 512, JSON_THROW_ON_ERROR);
    }
    return $rows;
}

/** Only call with explicitly constructed evidence, never config, exceptions or plans. */
function bms_receipt_flush(array &$operation): void
{
    if ($operation['queue'] === []) {
        return;
    }
    if (!$operation['persisted'] && !bms_receipt_schema_available()) {
        if ($operation['evidence']['bootstrap'] === 'deferred_until_schema') {
            return;
        }
        throw new RuntimeException('Receipt storage is unavailable.');
    }
    $pdo = bms_db();
    if ($pdo->inTransaction()) {
        throw new RuntimeException('Receipt evidence must not commit an application transaction.');
    }
    $pdo->beginTransaction();
    try {
        $values = [
            $operation['completed_at'], $operation['status'], $operation['error_code'],
            json_encode($operation['evidence'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ];
        if (!$operation['persisted']) {
            $stmt = $pdo->prepare('INSERT INTO ' . bms_table('upgrade_operations') . ' (completed_at, status, error_code, evidence, operation_id, schema_version, started_at, method) VALUES (?, ?, ?, ?, ?, 1, ?, ?)');
            $stmt->execute(array_merge($values, [$operation['operation_id'], $operation['started_at'], $operation['method']]));
        } else {
            $stmt = $pdo->prepare('SELECT operation_id FROM ' . bms_table('upgrade_operations') . ' WHERE operation_id = ? FOR UPDATE');
            $stmt->execute([$operation['operation_id']]);
            if (!$stmt->fetchColumn()) {
                throw new RuntimeException('Receipt operation is missing.');
            }
            $stmt = $pdo->prepare('UPDATE ' . bms_table('upgrade_operations') . ' SET completed_at = ?, status = ?, error_code = ?, evidence = ? WHERE operation_id = ?');
            $stmt->execute(array_merge($values, [$operation['operation_id']]));
        }
        $stmt = $pdo->prepare('INSERT INTO ' . bms_table('upgrade_operation_events') . ' (operation_id, observed_at, event_type, evidence) VALUES (?, ?, ?, ?)');
        foreach ($operation['queue'] as $event) {
            $stmt->execute([$operation['operation_id'], $event['observed_at'], $event['event_type'], json_encode($event['evidence'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)]);
        }
        $pdo->commit();
        $operation['persisted'] = true;
        $operation['queue'] = [];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function bms_receipt_event(array &$operation, string $type, array $evidence = [], bool $required = false): void
{
    $operation['queue'][] = ['observed_at' => gmdate('Y-m-d H:i:s'), 'event_type' => $type, 'evidence' => $evidence];
    try {
        bms_receipt_flush($operation);
    } catch (Throwable $e) {
        $operation['write_warning'] = true;
        // Do not log the PDO exception: drivers can include credentials or SQL values.
        error_log('Bonumark upgrade receipt write failed: ' . $operation['operation_id']);
        if ($required) {
            throw new RuntimeException('Required upgrade receipt could not be initialized. No software replacement was authorized by the engine.');
        }
    }
}

function bms_receipt_begin(string $method): array
{
    if (!in_array($method, ['admin_zip', 'owner_cli'], true)) {
        throw new InvalidArgumentException('Unsupported upgrade method.');
    }
    $available = bms_receipt_schema_available();
    if (!$available) {
        $stmt = bms_db()->prepare('SELECT COUNT(*) FROM ' . bms_table('migrations') . ' WHERE migration = ?');
        $stmt->execute(['0029_structured_upgrade_receipts']);
        if ((int)$stmt->fetchColumn() !== 0) {
            throw new RuntimeException('Receipt schema is missing after migration 0029. Repair storage before upgrading.');
        }
    }
    $operation = [
        'operation_id' => bin2hex(random_bytes(16)), 'schema_version' => 1,
        'started_at' => gmdate('Y-m-d H:i:s'), 'completed_at' => null,
        'method' => $method, 'status' => 'running', 'error_code' => null,
        'persisted' => false, 'queue' => [], 'write_warning' => false,
        'evidence' => [
            'outcome' => ['summary' => 'Execution authorized; completion has not been recorded.', 'next_action' => bms_receipt_followup('running', null)],
            'bootstrap' => $available ? 'not_applicable' : 'deferred_until_schema',
            'from_version' => bms_version(), 'target_version' => null,
            'package' => ['identity' => 'bonumark-stream', 'release_name' => null, 'zip_sha256' => null, 'manifest' => 'not_observed', 'manifest_file_count' => null, 'managed_file_count' => null],
            'preflight' => ['state' => 'not_run'],
            'backup' => ['readiness' => 'not_observed', 'created' => [], 'external_database' => 'not_observed', 'restore_test' => 'not_run'],
            'execution' => ['phase' => 'authorized', 'changed_file_count' => null, 'cleanup_count' => null, 'rollback' => 'not_run'],
            'migrations' => ['expected' => null, 'completed' => [], 'observation' => 'not_observed'],
            'recovery' => ['state' => 'not_observed', 'resumed' => false],
            'preservation' => ['policy' => 'not_observed', 'config_and_lock' => 'not_observed', 'full_data_equivalence' => 'not_run'],
            'verification' => ['state' => 'not_run', 'system_check' => ['state' => 'not_run'], 'human_acceptance' => 'not_run'],
        ],
    ];
    bms_receipt_event($operation, 'operation_started', ['method' => $method], true);
    return $operation;
}

function bms_receipt_outcome(array &$operation, string $status, ?string $code = null): void
{
    $operation['status'] = $status;
    $operation['error_code'] = $code;
    $operation['evidence']['outcome'] = [
        'summary' => match ($status) {
            'complete' => 'Software, migrations and automatic deployment verification completed.',
            'blocked' => 'Execution was blocked before package replacement.',
            'recovery_required' => 'Execution stopped after migration began. New software was retained.',
            default => 'Execution did not meet the completion contract. Inspect phase and rollback evidence.',
        },
        'next_action' => bms_receipt_followup($status, $code),
    ];
    if ($status === 'blocked') {
        $operation['evidence']['execution']['changed_file_count'] = 0;
        $operation['evidence']['execution']['cleanup_count'] = 0;
    }
    $operation['completed_at'] = in_array($status, ['complete', 'failed', 'blocked'], true) ? gmdate('Y-m-d H:i:s') : null;
    bms_receipt_event($operation, $status, ['error_code' => $code, 'execution' => $operation['evidence']['execution'], 'recovery' => $operation['evidence']['recovery'], 'outcome' => $operation['evidence']['outcome']]);
}

function bms_receipt_followup(string $status, ?string $code): string
{
    if ($status === 'complete') {
        return 'Review the receipt, System Check and normal site behavior. Human acceptance was not automated.';
    }
    if ($status === 'recovery_required') {
        return 'Keep newer software. Inspect the recovery marker and private log; retry the exact package when the marker permits recovery.';
    }
    if ($code === 'rollback_failed') {
        return 'Restore the private software backup before retrying. Review the private server log.';
    }
    if ($status === 'running') {
        return 'Completion was not recorded. Inspect the recovery marker, migration ledger and private log before taking action.';
    }
    return 'Review the recorded blocker and System Check, correct the cause, then use the supported upgrade workflow.';
}

/** Human text is derived from canonical enums, never a captured raw exception. */
function bms_receipt_cli_report(?array $row): string
{
    if (!$row) {
        return "Receipt unavailable. Inspect legacy history and recovery state; do not assume completion.\n";
    }
    $e = $row['evidence'];
    return 'Receipt: ' . $row['operation_id'] . "\n"
        . 'Version: ' . $e['from_version'] . ' -> ' . ($e['target_version'] ?? 'unknown') . "\n"
        . 'Status: ' . $row['status'] . ' (' . ($row['error_code'] ?? 'no_error') . ")\n"
        . 'Migrations observed complete: ' . count($e['migrations']['completed']) . "\n"
        . 'Cleanup count: ' . ($e['execution']['cleanup_count'] ?? 'not_observed') . "\n"
        . 'Software backups created: ' . count($e['backup']['created']) . '; recovery: ' . $e['recovery']['state'] . "\n"
        . 'Verification: ' . $e['verification']['state'] . "\n"
        . 'Next: ' . bms_receipt_followup($row['status'], $row['error_code']) . "\n";
}

/** Fingerprints stay in process memory; no config values or hashes enter receipts. */
function bms_receipt_protected_fingerprints(string $root): array
{
    $result = [];
    foreach (['_bonumark_stream/config.php', '_bonumark_stream/installed.lock'] as $path) {
        $result[$path] = is_file($root . '/' . $path) ? hash_file('sha256', $root . '/' . $path) : null;
    }
    return $result;
}

function bms_receipt_observe_migrations(array &$operation): void
{
    try {
        $done = bms_db()->query('SELECT migration FROM ' . bms_table('migrations'))->fetchAll(PDO::FETCH_COLUMN);
        $observed = array_values(array_intersect($operation['evidence']['migrations']['expected'] ?? [], $done));
        $new = array_values(array_diff($observed, $operation['evidence']['migrations']['completed']));
        $operation['evidence']['migrations']['completed'] = array_values(array_unique(array_merge($operation['evidence']['migrations']['completed'], $observed)));
        $operation['evidence']['migrations']['observation'] = 'ledger_read';
        foreach ($new as $name) {
            bms_receipt_event($operation, 'migration_completed_observed', ['migration' => $name]);
        }
    } catch (Throwable $e) {
        $operation['evidence']['migrations']['observation'] = 'unavailable';
        error_log('Bonumark receipt migration observation unavailable: ' . $operation['operation_id']);
    }
}

function bms_receipt_verify(array &$operation, string $root, string $target): void
{
    try {
        $result = bms_deployment_check($root);
        $targetMatches = trim((string)file_get_contents($root . '/VERSION')) === $target;
        $result['checks']['target_version'] = $targetMatches ? 'pass' : 'fail';
        $operation['evidence']['verification'] = [
            'state' => $result['state'] === 'pass' && $targetMatches ? 'pass' : 'fail',
            'checks' => $result['checks'], 'context' => PHP_SAPI,
            'system_check' => ['state' => 'not_run'], 'human_acceptance' => 'not_run',
        ];
        // The same read-only provider as Admin System Check, in this process's context.
        $items = bms_security_status();
        $counts = ['pass' => 0, 'warning' => 0, 'fail' => 0];
        $checks = [];
        foreach ($items as $item) {
            $status = strtolower((string)($item['status'] ?? 'warning'));
            $status = $status === 'pass' ? 'pass' : (in_array($status, ['fail', 'error'], true) ? 'fail' : 'warning');
            $counts[$status]++;
            $checks[] = ['label' => (string)$item['label'], 'status' => $status];
        }
        $operation['evidence']['verification']['system_check'] = ['state' => 'observed', 'context' => PHP_SAPI, 'counts' => $counts, 'checks' => $checks];
    } catch (Throwable $e) {
        // Never let diagnostics roll back committed migrations or replaced code.
        $operation['evidence']['verification']['state'] = 'unavailable';
        error_log('Bonumark receipt verification unavailable: ' . $operation['operation_id']);
    }
    bms_receipt_event($operation, 'post_verification', $operation['evidence']['verification']);
}

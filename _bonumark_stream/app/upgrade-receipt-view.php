<?php
/** Included only through the authorized Admin Upgrade route. */
declare(strict_types=1);
// Defense in depth if a hosting configuration exposes this private PHP file.
if (!function_exists('bms_require_capability')) {
    http_response_code(403);
    exit('Forbidden.');
}
bms_require_login();
bms_require_capability('view_system');
$receipt = null;
try {
    $receipt = bms_receipt_schema_available() ? bms_receipt_read((string)($_GET['receipt'] ?? '')) : null;
} catch (Throwable $e) {
    bms_abort_request('Receipt storage is unavailable.', 503);
}
if (!$receipt) {
    bms_abort_request('Receipt not found. Legacy upgrades do not have structured receipts.', 404);
}
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
if (($_GET['format'] ?? '') === 'json') {
    header('Content-Type: application/json; charset=UTF-8');
    header('Content-Disposition: attachment; filename="upgrade-' . $receipt['operation_id'] . '.json"');
    echo json_encode($receipt, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    return;
}
$escape = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$render = static function (mixed $value) use (&$render, $escape): void {
    if (!is_array($value)) {
        echo $escape($value === null ? 'not observed' : (is_bool($value) ? ($value ? 'yes' : 'no') : $value));
        return;
    }
    if ($value === []) { echo 'None recorded'; return; }
    echo '<ul>';
    foreach ($value as $key => $item) {
        echo '<li>';
        if (is_string($key)) { echo '<strong>' . $escape(ucfirst(str_replace('_', ' ', $key))) . ':</strong> '; }
        $render($item);
        echo '</li>';
    }
    echo '</ul>';
};
$evidence = $receipt['evidence'];
bms_admin_header('Upgrade receipt', [
    ['label' => 'All receipts', 'href' => bms_admin_url('upgrade.php'), 'style' => 'secondary', 'class' => 'operations-receipt-action'],
    ['label' => 'Download JSON', 'href' => bms_admin_url('upgrade.php?receipt=' . $receipt['operation_id'] . '&format=json'), 'style' => 'secondary', 'class' => 'operations-receipt-action'],
]);
?>
<section class="panel operations-panel">
  <p class="eyebrow">Recorded upgrade evidence</p>
  <h2><?= $escape($evidence['from_version']) ?> → <?= $escape($evidence['target_version'] ?? 'unknown target') ?></h2>
  <p class="operations-technical-value">Receipt <?= $escape($receipt['operation_id']) ?></p>
  <dl class="operations-fact-list">
    <div><dt>Outcome</dt><dd><?= $escape($receipt['status']) ?><?= $receipt['error_code'] ? ': ' . $escape($receipt['error_code']) : '' ?></dd></div>
    <div><dt>Method</dt><dd><?= $escape($receipt['method']) ?></dd></div>
    <div><dt>Started</dt><dd><?= $escape($receipt['started_at']) ?> UTC</dd></div>
    <div><dt>Completed</dt><dd><?= $escape($receipt['completed_at'] ?? 'not recorded') ?></dd></div>
    <div><dt>Next action</dt><dd><?= $escape(bms_receipt_followup($receipt['status'], $receipt['error_code'])) ?></dd></div>
  </dl>
</section>
<?php foreach (['package' => 'Package', 'preflight' => 'Preflight', 'backup' => 'Backup', 'recovery' => 'Recovery', 'execution' => 'Software and cleanup', 'migrations' => 'Migrations', 'preservation' => 'Preservation boundary', 'verification' => 'Verification'] as $key => $title): ?>
<section class="panel operations-panel">
  <h2><?= $escape($title) ?></h2>
  <dl class="operations-fact-list">
    <?php foreach ($evidence[$key] as $field => $value): ?>
    <div><dt><?= $escape(ucfirst(str_replace('_', ' ', $field))) ?></dt><dd class="operations-technical-value">
      <?php if (is_array($value)): ?>
        <?php if ($value === []): ?>None recorded<?php else: ?>
        <details class="upgrade-details"><summary>View <?= count($value) ?> recorded item(s)</summary><?php $render($value); ?></details><?php endif; ?>
      <?php else: ?><?= $escape($value === null ? 'not observed' : (is_bool($value) ? ($value ? 'yes' : 'no') : $value)) ?><?php endif; ?>
    </dd></div>
    <?php endforeach; ?>
  </dl>
</section>
<?php endforeach; ?>
<section class="panel operations-panel">
  <h2>Evidence history</h2>
  <p class="meta">Earlier failures and recovery observations remain recorded. Bootstrap: <?= $escape($evidence['bootstrap']) ?>. Full owner-data equivalence and human acceptance were not tested by this receipt.</p>
  <ol><?php foreach ($receipt['events'] as $event): ?><li><strong><?= $escape($event['event_type']) ?></strong> · <?= $escape($event['observed_at']) ?> UTC</li><?php endforeach; ?></ol>
</section>
<?php bms_admin_footer(); ?>

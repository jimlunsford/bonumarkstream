<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/scheduler.php';
require_once __DIR__ . '/_layout.php';
bms_require_login();
bms_require_capability('view_system');

$checks = bms_security_status();
$counts = ['pass' => 0, 'warning' => 0, 'fail' => 0];
foreach ($checks as $check) {
    $status = strtolower((string)($check['status'] ?? 'warning'));
    if ($status === 'pass') { $counts['pass']++; }
    elseif (in_array($status, ['fail', 'error'], true)) { $counts['fail']++; }
    else { $counts['warning']++; }
}
$overall = $counts['fail'] > 0 ? 'Needs attention' : ($counts['warning'] > 0 ? 'Review warnings' : 'Healthy');
$overallClass = $counts['fail'] > 0 ? 'is-destructive' : ($counts['warning'] > 0 ? 'is-warning' : 'is-safe');

bms_admin_header('System Check', [
    ['label' => 'Refresh Check', 'href' => bms_admin_url('system-check.php'), 'style' => 'primary'],
    ['label' => 'Tools', 'href' => bms_admin_url('tools.php'), 'style' => 'secondary'],
]);
?>
<section class="panel operations-hero">
  <div class="operations-hero-copy"><p class="eyebrow">Diagnostics</p><h2>Check the hosting and application boundaries that protect Bonumark Stream.</h2><p class="meta">This screen reports current state. It does not change files, settings, database records, or permissions.</p></div>
  <span class="operation-risk-label <?= $overallClass ?>"><?= htmlspecialchars($overall, ENT_QUOTES, 'UTF-8') ?></span>
</section>
<section class="panel operations-summary-panel"><div class="operations-summary-grid"><div><span>Passing</span><strong><?= (int)$counts['pass'] ?></strong></div><div><span>Warnings</span><strong><?= (int)$counts['warning'] ?></strong></div><div><span>Failed</span><strong><?= (int)$counts['fail'] ?></strong></div><div><span>Check type</span><strong>Read-only diagnostics</strong></div></div></section>
<section class="panel operations-diagnostic-panel">
  <div class="operations-section-heading"><div><p class="eyebrow">Current results</p><h2>Hosting and application checks</h2><p class="meta">Failures block or endanger normal operation. Warnings identify conditions that deserve review but may be intentional.</p></div><span class="operation-risk-label is-safe">No changes made</span></div>
  <div class="operations-check-grid">
    <?php foreach ($checks as $check): $status = strtolower((string)($check['status'] ?? 'warning')); ?>
      <article class="operations-check-card is-<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>"><span class="operation-risk-label <?= $status === 'pass' ? 'is-safe' : (in_array($status, ['fail','error'], true) ? 'is-destructive' : 'is-warning') ?>"><?= htmlspecialchars(strtoupper($status), ENT_QUOTES, 'UTF-8') ?></span><h3><?= htmlspecialchars((string)($check['label'] ?? 'Check'), ENT_QUOTES, 'UTF-8') ?></h3><p><?= htmlspecialchars((string)($check['message'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p></article>
    <?php endforeach; ?>
  </div>
</section>
<section class="panel operations-panel">
  <div class="operations-section-heading"><div><p class="eyebrow">Bonumark-specific boundaries</p><h2>What these checks assume</h2><p class="meta">Use these facts when interpreting a warning or talking to a hosting provider.</p></div></div>
  <dl class="operations-fact-list"><div><dt>Content source</dt><dd>Database-first records with Markdown import and export support.</dd></div><div><dt>Public output</dt><dd>Dynamic routes first, with static HTML available as optional export tooling.</dd></div><div><dt>Media uploads</dt><dd>Validated public files under <code>/media/</code>, tracked in the database, with optional optimized variants.</dd></div><div><dt>Private storage</dt><dd><code>_bonumark_stream</code> must never be publicly reachable.</dd></div></dl>
  <div class="operations-inline-actions"><a class="button-link secondary" href="<?= htmlspecialchars(bms_admin_url('security.php'), ENT_QUOTES, 'UTF-8') ?>">Security Overview</a><a class="button-link secondary" href="<?= htmlspecialchars(bms_admin_url('upgrade.php'), ENT_QUOTES, 'UTF-8') ?>">Upgrade</a></div>
</section>
<?php bms_admin_footer(); ?>

<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/scheduler.php';
require_once __DIR__ . '/_layout.php';
bms_require_login();
bms_require_capability('view_system');

require_once __DIR__ . '/_system-ui.php';
$checks = bms_system_read_checks();
$summary = bms_system_check_summary($checks);
$counts = $summary['counts'];

bms_admin_header('System Check', [
    ['label' => 'Refresh Check', 'href' => bms_admin_url('system-check.php'), 'style' => 'primary'],
]);
?>
<div class="system-workspace">
  <p class="system-intro">Current diagnostic results. Warnings may reflect intentional hosting choices; failures remain separate.</p>
  <div class="system-status-line"><span class="operation-risk-label <?= $summary['class'] ?>"><?= htmlspecialchars($summary['label'], ENT_QUOTES, 'UTF-8') ?></span><span><?= (int)$counts['pass'] ?> passing</span><span><?= (int)$counts['warning'] ?> warning</span><span><?= (int)$counts['fail'] ?> failing</span></div>
  <?php if (empty($checks)): ?>
    <p class="system-note"><?= $checks === null ? 'The diagnostic source is unavailable. Refresh Check to try again; system health has not been established.' : 'No checks were returned. Refresh Check to try again; an empty result does not establish system health.' ?></p>
  <?php else: ?>
    <section class="system-section"><h2>Hosting and application checks</h2><div class="system-check-list">
    <?php foreach (['fail', 'warning', 'pass'] as $group): ?>
      <?php foreach ($checks as $check): $status = strtolower((string)($check['status'] ?? 'warning')); $severity = $status === 'pass' ? 'pass' : (in_array($status, ['fail', 'error'], true) ? 'fail' : 'warning'); if ($severity !== $group) { continue; } ?>
      <article class="system-check-row">
        <span class="operation-risk-label <?= $severity === 'pass' ? 'is-safe' : ($severity === 'fail' ? 'is-destructive' : 'is-warning') ?>"><?= htmlspecialchars(strtoupper($status), ENT_QUOTES, 'UTF-8') ?></span>
        <div><h3><?= htmlspecialchars((string)($check['label'] ?? 'Check'), ENT_QUOTES, 'UTF-8') ?></h3><p><?= htmlspecialchars((string)($check['message'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p></div>
      </article>
      <?php endforeach; ?>
    <?php endforeach; ?>
    </div></section>
  <?php endif; ?>
  <section class="system-section"><h2>Interpreting these checks</h2>
    <p class="meta">Checks report conditions and do not perform repairs. The normal Admin page-load scheduler remains active and can process due work.</p>
    <dl class="system-facts"><div><dt>Content</dt><dd>Database records are authoritative; Markdown supports import and export.</dd></div><div><dt>Public output</dt><dd>Dynamic routes. Export reports the available portability options.</dd></div><div><dt>Media</dt><dd>Validated public files under <code>/media/</code>, tracked in the database.</dd></div><div><dt>Private storage</dt><dd><code>_bonumark_stream</code> must remain blocked from public HTTP access.</dd></div></dl>
    <div class="operations-inline-actions"><a class="system-text-link" href="<?= htmlspecialchars(bms_admin_url('security.php'), ENT_QUOTES, 'UTF-8') ?>">Security</a><a class="system-text-link" href="<?= htmlspecialchars(bms_admin_url('upgrade.php'), ENT_QUOTES, 'UTF-8') ?>">Upgrade</a></div>
  </section>
</div>
<?php bms_admin_footer(); ?>

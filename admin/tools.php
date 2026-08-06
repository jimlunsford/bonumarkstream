<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/scheduler.php';
require_once __DIR__ . '/../_bonumark_stream/app/analytics.php';
require_once __DIR__ . '/../_bonumark_stream/app/api.php';
require_once __DIR__ . '/_layout.php';
bms_require_login();
bms_require_capability('view_system');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    bms_verify_csrf();
    $action = (string)($_POST['tool_action'] ?? '');
    if ($action === 'run_due_scheduled_posts') {
        bms_require_capability('publish_content');
        $result = function_exists('bms_run_due_tasks') ? bms_run_due_tasks('manual', true, 50) : ['ok' => false, 'message' => 'Scheduled-task runner is unavailable.'];
        bms_flash((string)($result['message'] ?? 'Scheduled tasks finished.'), !empty($result['ok']) ? 'success' : 'error');
        bms_redirect(bms_admin_url('tools.php'));
    }
}

$checks = function_exists('bms_security_status') ? bms_security_status() : [];
$checkCounts = ['pass' => 0, 'warning' => 0, 'fail' => 0];
foreach ($checks as $check) {
    $status = strtolower((string)($check['status'] ?? 'warning'));
    if ($status === 'pass') {
        $checkCounts['pass']++;
    } elseif (in_array($status, ['fail', 'error'], true)) {
        $checkCounts['fail']++;
    } else {
        $checkCounts['warning']++;
    }
}
$taskStatus = function_exists('bms_scheduled_tasks_status') ? bms_scheduled_tasks_status() : ['status' => 'unknown'];
$taskState = (string)($taskStatus['status'] ?? 'unknown');
$analyticsEnabled = function_exists('bms_analytics_enabled') && bms_analytics_enabled();
$apiEnabled = function_exists('bms_api_enabled') && bms_api_enabled();
$zipReady = class_exists('ZipArchive');

bms_admin_header('Tools', [
    ['label' => 'System Check', 'href' => bms_admin_url('system-check.php'), 'style' => 'secondary'],
]);
?>
<section class="panel operations-hero">
  <div class="operations-hero-copy">
    <p class="eyebrow">System operations</p>
    <h2>Move data, inspect health, and change the system deliberately.</h2>
    <p class="meta">These tools can import content, create private backups, replace application files, expose remote access, or remove operational data. Each workflow identifies what is routine, sensitive, diagnostic, or irreversible before the action is available.</p>
  </div>
  <span class="operation-risk-label is-sensitive">Administrator tools</span>
</section>

<section class="panel operations-summary-panel">
  <div class="operations-summary-grid">
    <div><span>Installed version</span><strong>v<?= htmlspecialchars(bms_version(), ENT_QUOTES, 'UTF-8') ?></strong></div>
    <div><span>System check</span><strong><?= (int)$checkCounts['fail'] ?> failed, <?= (int)$checkCounts['warning'] ?> warning</strong></div>
    <div><span>Scheduled runner</span><strong><?= htmlspecialchars(ucfirst($taskState), ENT_QUOTES, 'UTF-8') ?></strong></div>
    <div><span>Operational services</span><strong><?= $analyticsEnabled ? 'Analytics on' : 'Analytics off' ?> · <?= $apiEnabled ? 'API on' : 'API off' ?></strong></div>
  </div>
</section>

<div class="operations-group-list">
  <section class="panel operations-panel">
    <div class="operations-section-heading"><div><p class="eyebrow">Move and protect data</p><h2>Import, export, and ownership</h2><p class="meta">Preview incoming data before it is written. Treat database and full exports as private backups.</p></div><span class="operation-risk-label is-sensitive"><?= $zipReady ? 'ZIP ready' : 'ZIP unavailable' ?></span></div>
    <div class="operations-card-grid">
      <article class="operations-action-card"><span class="operation-risk-label is-safe">Preview first</span><h3>Import archives</h3><p>Stage Markdown, JSON, XML, Bonumark, Twitter/X, or Bluesky data, review a sample, then explicitly confirm the database write.</p><div class="operations-inline-actions"><a class="button-link secondary" href="<?= htmlspecialchars(bms_admin_url('import.php'), ENT_QUOTES, 'UTF-8') ?>">Open Import</a></div></article>
      <article class="operations-action-card is-sensitive"><span class="operation-risk-label is-sensitive">Private output</span><h3>Export and backup</h3><p>Download Markdown, a static copy, media, database records, or a full package. Database and full exports contain sensitive account and security data.</p><div class="operations-inline-actions"><a class="button-link secondary" href="<?= htmlspecialchars(bms_admin_url('export.php'), ENT_QUOTES, 'UTF-8') ?>">Open Export</a></div></article>
      <article class="operations-action-card is-sensitive"><span class="operation-risk-label is-warning">Advanced migration</span><h3>Private Markdown folders</h3><p>Read legacy Markdown files already placed in Bonumark Stream private import folders and write or refresh authoritative database records.</p><div class="operations-inline-actions"><a class="button-link secondary" href="<?= htmlspecialchars(bms_admin_url('import-markdown.php'), ENT_QUOTES, 'UTF-8') ?>">Import Markdown Folders</a></div></article>
    </div>
  </section>

  <section class="panel operations-panel operations-danger-zone">
    <div class="operations-section-heading"><div><p class="eyebrow">Change system software</p><h2>Upgrade Bonumark Stream</h2><p class="meta">An upgrade replaces application files and may run database migrations. Bonumark validates the release package and creates a backup before replacement begins.</p></div><span class="operation-risk-label is-destructive">High-risk operation</span></div>
    <div class="operations-card-grid">
      <article class="operations-action-card is-destructive"><h3>Install a release ZIP</h3><p>Upload only a Bonumark Stream release package you created or trust. Review package, backup, migration, and recovery status before running it.</p><div class="operations-inline-actions"><a class="button-link secondary danger" href="<?= htmlspecialchars(bms_admin_url('upgrade.php'), ENT_QUOTES, 'UTF-8') ?>">Open Upgrade</a></div></article>
      <article class="operations-action-card"><span class="operation-risk-label is-safe">Diagnostic</span><h3>Check readiness first</h3><p>Review PHP, database, private storage, HTTPS, media support, writable paths, and upgrade readiness before changing software.</p><div class="operations-inline-actions"><a class="button-link secondary" href="<?= htmlspecialchars(bms_admin_url('system-check.php'), ENT_QUOTES, 'UTF-8') ?>">Run System Check</a></div></article>
      <article class="operations-action-card"><span class="operation-risk-label">Reference</span><h3>Upgrade guidance</h3><p>Use Help and the packaged upgrade documentation to understand what is replaced, what is preserved, and what recovery can do.</p><div class="operations-inline-actions"><a class="button-link secondary" href="<?= htmlspecialchars(bms_admin_url('help.php'), ENT_QUOTES, 'UTF-8') ?>">Open Help</a></div></article>
    </div>
  </section>

  <section class="panel operations-panel operations-diagnostic-panel">
    <div class="operations-section-heading"><div><p class="eyebrow">Monitor and automate</p><h2>Health, scheduled work, and aggregate reporting</h2><p class="meta">These screens report current state and history. Destructive cleanup actions remain isolated inside their own danger zones.</p></div><span class="operation-risk-label is-safe">Diagnostics</span></div>
    <div class="operations-card-grid">
      <article class="operations-action-card"><h3>System Check</h3><p>Inspect hosting, private storage, media support, HTTPS, database access, writable paths, and application-specific safeguards.</p><div class="operations-inline-actions"><a class="button-link secondary" href="<?= htmlspecialchars(bms_admin_url('system-check.php'), ENT_QUOTES, 'UTF-8') ?>">Open System Check</a></div></article>
      <article class="operations-action-card"><h3>Scheduled Tasks</h3><p>Review runner health, server cron, protected web cron, fallback checks, manual execution, and recent task-run history.</p><div class="operations-inline-actions"><a class="button-link secondary" href="<?= htmlspecialchars(bms_admin_url('scheduled-tasks.php'), ENT_QUOTES, 'UTF-8') ?>">Open Scheduled Tasks</a></div></article>
      <article class="operations-action-card"><h3>Analytics</h3><p>Review optional cookieless aggregate reporting, export aggregate CSV data, or enter the isolated danger zone to clear it.</p><div class="operations-inline-actions"><a class="button-link secondary" href="<?= htmlspecialchars(bms_admin_url('analytics.php'), ENT_QUOTES, 'UTF-8') ?>">Open Analytics</a></div></article>
    </div>
  </section>

  <section class="panel operations-panel">
    <div class="operations-section-heading"><div><p class="eyebrow">Access and support</p><h2>Security, remote clients, and operational help</h2><p class="meta">Review access controls before enabling public registration, recovery mail, remembered devices, cron endpoints, or remote API clients.</p></div></div>
    <div class="operations-card-grid">
      <article class="operations-action-card"><h3>Security</h3><p>Review HTTPS, admin access, registration, recovery readiness, remembered devices, API tokens, and upgrade safeguards.</p><div class="operations-inline-actions"><a class="button-link secondary" href="<?= htmlspecialchars(bms_admin_url('security.php'), ENT_QUOTES, 'UTF-8') ?>">Open Security</a></div></article>
      <article class="operations-action-card is-sensitive"><span class="operation-risk-label is-sensitive">Scoped access</span><h3>Remote Posting</h3><p>Configure API rules, create scoped tokens, revoke access, and inspect authenticated or failed API activity.</p><div class="operations-inline-actions"><a class="button-link secondary" href="<?= htmlspecialchars(bms_admin_url('remote-posting.php'), ENT_QUOTES, 'UTF-8') ?>">Open Remote Posting</a></div></article>
      <article class="operations-action-card"><h3>Help</h3><p>Understand database-first content, exports, imports, scheduled work, media, Local Places, public discovery, and recovery boundaries.</p><div class="operations-inline-actions"><a class="button-link secondary" href="<?= htmlspecialchars(bms_admin_url('help.php'), ENT_QUOTES, 'UTF-8') ?>">Open Help</a></div></article>
    </div>
  </section>
</div>
<?php bms_admin_footer(); ?>

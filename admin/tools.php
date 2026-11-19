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

require_once __DIR__ . '/_system-ui.php';
$diagnostics = bms_system_check_summary(bms_system_read_checks());
$taskStatus = [];
try {
    $taskStatus = function_exists('bms_scheduled_tasks_status') ? bms_scheduled_tasks_status() : [];
} catch (Throwable $e) {
    $taskStatus = [];
}
$taskLabel = match ((string)($taskStatus['status'] ?? 'unknown')) {
    'healthy' => 'Healthy', 'stale' => 'Stale', default => 'Unknown / not yet checked',
};
$groups = [
    'Inspect and schedule' => [
        ['System Check', 'system-check.php', 'Inspect hosting, storage, database and application checks.'],
        ['Scheduled Tasks', 'scheduled-tasks.php', 'Runner health, configuration, history and manual execution.'],
    ],
    'Move data' => [
        ['Export', 'export.php', 'Download portable content or sensitive database and full packages.'],
        ['Import', 'import.php', 'Stage an upload, review the preview, then confirm the database import.'],
        ['Import Markdown', 'import-markdown.php', 'Advanced migration from private folders directly into database records.'],
    ],
    'Maintain software' => [
        ['Upgrade', 'upgrade.php', 'Check a trusted release ZIP before explicitly authorizing software replacement.'],
        ['Help', 'help.php', 'Product reference, maintenance and recovery guidance.'],
    ],
];
bms_admin_header('System');
?>
<div class="system-workspace system-overview">
  <p class="system-intro">Diagnostics, data movement and software maintenance.</p>
  <dl class="system-summary">
    <div><dt>Installed version</dt><dd>v<?= htmlspecialchars(bms_version(), ENT_QUOTES, 'UTF-8') ?></dd></div>
    <div><dt>System Check</dt><dd><span class="operation-risk-label <?= $diagnostics['class'] ?>"><?= htmlspecialchars($diagnostics['label'], ENT_QUOTES, 'UTF-8') ?></span></dd></div>
    <div><dt>Scheduled runner</dt><dd><?= htmlspecialchars($taskLabel, ENT_QUOTES, 'UTF-8') ?></dd></div>
  </dl>
  <?php foreach ($groups as $heading => $links): ?>
  <section class="system-section">
    <h2><?= htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') ?></h2>
    <ul class="system-destinations">
    <?php foreach ($links as [$label, $path, $description]): ?>
      <li><a href="<?= htmlspecialchars(bms_admin_url($path), ENT_QUOTES, 'UTF-8') ?>"><span><strong><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars($description, ENT_QUOTES, 'UTF-8') ?></small></span><span aria-hidden="true">›</span></a></li>
    <?php endforeach; ?>
    </ul>
  </section>
  <?php endforeach; ?>
  <section class="system-section"><h2>Related settings</h2><div class="operations-inline-actions">
    <?php foreach (['analytics.php' => 'Analytics', 'security.php' => 'Security', 'remote-posting.php' => 'Remote Posting'] as $path => $label): ?>
    <a class="system-text-link" href="<?= htmlspecialchars(bms_admin_url($path), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a>
    <?php endforeach; ?>
  </div></section>
  <p class="system-note">Before changing software, verify your external backup and recovery path. Bonumark exports are not a complete server backup. A locked application tree can be intentional; Upgrade includes owner-run and manual deployment guidance.</p>
</div>
<?php bms_admin_footer(); ?>

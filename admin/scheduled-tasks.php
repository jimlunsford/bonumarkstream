<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/scheduler.php';
require_once __DIR__ . '/_layout.php';

bms_require_login();
bms_require_capability('manage_settings');

function bms_scheduled_tasks_page_checkbox(string $name, bool $checked, string $label, string $help): string
{
    return '<label class="checkbox-label"><input type="checkbox" name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '" value="1"' . ($checked ? ' checked' : '') . '> <span>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span></label><p class="field-help">' . htmlspecialchars($help, ENT_QUOTES, 'UTF-8') . '</p>';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    bms_verify_csrf();
    $action = (string)($_POST['scheduled_tasks_action'] ?? '');

    if ($action === 'save_settings') {
        $interval = (int)($_POST['scheduled_tasks_expected_interval_minutes'] ?? 5);
        if (!in_array($interval, [1, 5, 15, 30, 60], true)) {
            $interval = 5;
        }
        bms_set_setting('scheduled_tasks_expected_interval_minutes', (string)$interval);
        bms_set_setting('scheduled_tasks_public_traffic_enabled', isset($_POST['scheduled_tasks_public_traffic_enabled']) ? '1' : '0');
        bms_set_setting('scheduled_tasks_heartbeat_enabled', isset($_POST['scheduled_tasks_heartbeat_enabled']) ? '1' : '0');
        bms_flash('Scheduled-task fallback settings saved.', 'success');
        bms_redirect(bms_admin_url('scheduled-tasks.php'));
    }

    if ($action === 'run_now') {
        $result = bms_run_due_tasks('manual', true, 50);
        bms_flash((string)($result['message'] ?? 'Scheduled tasks finished.'), !empty($result['ok']) ? 'success' : 'error');
        bms_redirect(bms_admin_url('scheduled-tasks.php'));
    }

    if ($action === 'generate_web_cron_key') {
        $key = bms_scheduled_tasks_generate_web_cron_key();
        $_SESSION['bms_scheduled_tasks_new_web_cron_key'] = $key;
        bms_flash('A new web cron key was created. Copy it now, because Bonumark Stream will not show it again.', 'success');
        bms_redirect(bms_admin_url('scheduled-tasks.php'));
    }

    if ($action === 'disable_web_cron') {
        bms_scheduled_tasks_disable_web_cron();
        unset($_SESSION['bms_scheduled_tasks_new_web_cron_key']);
        bms_flash('Web cron was disabled and its key was removed.', 'success');
        bms_redirect(bms_admin_url('scheduled-tasks.php'));
    }
}

$status = bms_scheduled_tasks_status();
$history = bms_scheduled_tasks_history(20);
$newWebCronKey = (string)($_SESSION['bms_scheduled_tasks_new_web_cron_key'] ?? '');
unset($_SESSION['bms_scheduled_tasks_new_web_cron_key']);
$expectedMinutes = (int)($status['expected_interval_minutes'] ?? 5);
$serverCronExpression = bms_scheduled_tasks_cron_expression($expectedMinutes);
$serverCronCommand = bms_scheduled_tasks_server_cron_command();
$webCronUrl = bms_scheduled_tasks_web_cron_url();
$healthClass = match ((string)($status['status'] ?? 'unknown')) {
    'healthy' => 'generated',
    'stale' => 'warning',
    default => 'draft',
};
$healthLabel = match ((string)($status['status'] ?? 'unknown')) {
    'healthy' => 'Healthy',
    'stale' => 'Needs attention',
    default => 'Not checked yet',
};
$lastRunAt = (int)($status['last_run_at'] ?? 0);
$webCronTest = '';
if ($newWebCronKey !== '') {
    $webCronTest = 'curl -fsS -H ' . escapeshellarg('X-Bonumark-Cron-Key: ' . $newWebCronKey) . ' ' . escapeshellarg($webCronUrl);
}

bms_admin_header('Scheduled Tasks', [
    ['label' => 'Run Tasks Now', 'href' => '#run-now', 'style' => 'secondary'],
]);
?>
<section class="panel page-intro-panel">
  <p class="eyebrow">System</p>
  <h2>Scheduled Tasks</h2>
  <p class="meta">Choose how Bonumark Stream checks scheduled work. Server cron is the dependable option. Public traffic and browser heartbeats remain optional fallbacks for simple shared-hosting installs.</p>
</section>

<section class="panel">
  <div class="section-header-row">
    <div>
      <h2>Task runner status <span class="static-pill <?= htmlspecialchars($healthClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($healthLabel, ENT_QUOTES, 'UTF-8') ?></span></h2>
      <p class="meta"><?= htmlspecialchars((string)($status['message'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
    </div>
  </div>
  <div class="readonly-settings-grid">
    <div><span>Last task run</span><strong><?= htmlspecialchars(bms_scheduled_tasks_format_timestamp($lastRunAt), ENT_QUOTES, 'UTF-8') ?></strong></div>
    <div><span>Last execution source</span><strong><?= htmlspecialchars(trim((string)($status['last_source'] ?? '')) !== '' ? bms_scheduled_tasks_source_label((string)$status['last_source']) : 'Not recorded yet', ENT_QUOTES, 'UTF-8') ?></strong></div>
    <div><span>Last result</span><strong><?= htmlspecialchars(trim((string)($status['last_message'] ?? '')) !== '' ? (string)$status['last_message'] : 'No completed run recorded yet.', ENT_QUOTES, 'UTF-8') ?></strong></div>
    <div><span>Expected interval</span><strong>Every <?= (int)$expectedMinutes ?> minute<?= $expectedMinutes === 1 ? '' : 's' ?></strong></div>
  </div>
  <form method="post" id="run-now" class="form-actions-row">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="scheduled_tasks_action" value="run_now">
    <button type="submit" class="button-link secondary">Run Tasks Now</button>
  </form>
</section>

<section class="panel settings-panel">
  <h2>Fallback checks</h2>
  <p class="meta">These keep scheduled posts working on active sites. They do not replace a real cron job for a quiet site or a future Legacy Trigger.</p>
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="scheduled_tasks_action" value="save_settings">
    <label for="scheduled_tasks_expected_interval_minutes">Expected task interval</label>
    <select id="scheduled_tasks_expected_interval_minutes" name="scheduled_tasks_expected_interval_minutes">
      <?php foreach ([1, 5, 15, 30, 60] as $minutes): ?>
        <option value="<?= $minutes ?>" <?= $minutes === $expectedMinutes ? 'selected' : '' ?>>Every <?= $minutes ?> minute<?= $minutes === 1 ? '' : 's' ?></option>
      <?php endforeach; ?>
    </select>
    <p class="field-help">This does not create a cron job. It tells Bonumark Stream what frequency to expect when reporting runner health and generates matching setup examples.</p>
    <?= bms_scheduled_tasks_page_checkbox('scheduled_tasks_public_traffic_enabled', !empty($status['public_traffic_enabled']), 'Allow public traffic fallback', 'Safe public GET and HEAD requests may check due tasks before rendering public output.') ?>
    <?= bms_scheduled_tasks_page_checkbox('scheduled_tasks_heartbeat_enabled', !empty($status['heartbeat_enabled']), 'Allow active browser heartbeat fallback', 'Signed-in admin and front-end composer sessions check due tasks every 30 seconds while the page remains open.') ?>
    <button type="submit">Save Fallback Settings</button>
  </form>
</section>

<section class="panel settings-panel">
  <h2>Server cron, recommended</h2>
  <p class="meta">Use this when your host gives you a cron-job panel or shell access. It runs locally on the server, does not depend on site traffic, and does not need a web cron key.</p>
  <label>Schedule</label>
  <input class="copy-field" type="text" value="<?= htmlspecialchars($serverCronExpression, ENT_QUOTES, 'UTF-8') ?>" readonly>
  <label>Command</label>
  <input class="copy-field" type="text" value="<?= htmlspecialchars($serverCronCommand, ENT_QUOTES, 'UTF-8') ?>" readonly>
  <p class="field-help">In cPanel, create a cron job using the schedule above and the command shown. Some hosts require a full PHP binary path such as <code>/usr/local/bin/php</code> instead of <code>php</code>.</p>
</section>

<section class="panel settings-panel">
  <h2>Web cron</h2>
  <p class="meta">Use this when your host or an external cron service can make a protected web request but cannot run a PHP command locally. Header authentication is preferred. A query-string key is also accepted for services that cannot send headers.</p>
  <div class="readonly-settings-grid">
    <div><span>Endpoint</span><code><?= htmlspecialchars($webCronUrl, ENT_QUOTES, 'UTF-8') ?></code></div>
    <div><span>Status</span><strong><?= !empty($status['web_cron_enabled']) ? 'Enabled' : 'Disabled' ?></strong></div>
  </div>
  <?php if ($newWebCronKey !== ''): ?>
    <div class="flash notice warning" role="status"><div class="notice-copy"><strong>Copy this key now</strong><p>For security, this is the only time Bonumark Stream will display the full web cron key.</p></div></div>
    <label>Web cron key</label>
    <input class="copy-field" type="text" value="<?= htmlspecialchars($newWebCronKey, ENT_QUOTES, 'UTF-8') ?>" readonly>
    <label>Header-authenticated test command</label>
    <input class="copy-field" type="text" value="<?= htmlspecialchars($webCronTest, ENT_QUOTES, 'UTF-8') ?>" readonly>
  <?php endif; ?>
  <div class="form-actions-row">
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="scheduled_tasks_action" value="generate_web_cron_key">
      <button type="submit" class="button-link secondary"><?= !empty($status['web_cron_enabled']) ? 'Generate New Web Cron Key' : 'Enable Web Cron and Generate Key' ?></button>
    </form>
    <?php if (!empty($status['web_cron_enabled'])): ?>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="scheduled_tasks_action" value="disable_web_cron">
        <button type="submit" class="button-link secondary danger">Disable Web Cron</button>
      </form>
    <?php endif; ?>
  </div>
</section>

<section class="panel">
  <h2>Run history</h2>
  <p class="meta">Manual, server-cron, and web-cron executions are retained here. Background traffic and browser heartbeat checks update health without filling the history with noise.</p>
  <?php if (!$history): ?>
    <p class="meta">No manual or cron task runs have been recorded yet.</p>
  <?php else: ?>
    <div class="table-wrap"><table class="admin-table scheduled-task-history-table">
      <colgroup>
        <col class="scheduled-task-history-when">
        <col class="scheduled-task-history-source">
        <col class="scheduled-task-history-result">
        <col class="scheduled-task-history-published">
        <col class="scheduled-task-history-details">
      </colgroup>
      <thead><tr><th scope="col">When</th><th scope="col">Source</th><th scope="col">Result</th><th scope="col">Published</th><th scope="col">Details</th></tr></thead><tbody>
      <?php foreach ($history as $run): ?>
        <?php $completed = bms_scheduled_tasks_history_timestamp($run); ?>
        <tr>
          <td data-label="When"><?= htmlspecialchars($completed > 0 ? bms_scheduled_tasks_format_timestamp($completed) : 'Unknown', ENT_QUOTES, 'UTF-8') ?></td>
          <td data-label="Source"><?= htmlspecialchars(bms_scheduled_tasks_source_label((string)($run['source'] ?? 'manual')), ENT_QUOTES, 'UTF-8') ?></td>
          <td data-label="Result"><?= htmlspecialchars(ucfirst((string)($run['status'] ?? 'completed')), ENT_QUOTES, 'UTF-8') ?></td>
          <td data-label="Published"><?= (int)($run['scheduled_posts_published'] ?? 0) ?></td>
          <td data-label="Details"><?= htmlspecialchars((string)($run['details'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody></table></div>
  <?php endif; ?>
</section>
<?php bms_admin_footer(); ?>

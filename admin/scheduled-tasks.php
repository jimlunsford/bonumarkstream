<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/scheduler.php';
require_once __DIR__ . '/_layout.php';
bms_require_login();
bms_require_capability('manage_settings');

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
$healthClass = match ((string)($status['status'] ?? 'unknown')) { 'healthy' => 'generated', 'stale' => 'warning', default => 'draft' };
$healthLabel = match ((string)($status['status'] ?? 'unknown')) { 'healthy' => 'Healthy', 'stale' => 'Stale', default => 'Not checked yet' };
$lastRunAt = (int)($status['last_run_at'] ?? 0);
$webCronTest = $newWebCronKey !== '' ? 'curl -fsS -H ' . escapeshellarg('X-Bonumark-Cron-Key: ' . $newWebCronKey) . ' ' . escapeshellarg($webCronUrl) : '';

bms_admin_header('Scheduled Tasks', [
    ['label' => 'Scheduled Posts', 'href' => bms_admin_url('content.php?status=scheduled'), 'style' => 'secondary'],
]);
?>
<div class="system-workspace">
<p class="system-intro">Runner activity, configuration and recent outcomes. Server cron runs independently of site visits.</p>
<section class="system-section system-runner-status">
  <div class="system-heading"><h2>Runner status</h2><span class="operation-risk-label <?= $healthClass === 'generated' ? 'is-safe' : ($healthClass === 'warning' ? 'is-warning' : '') ?>"><?= htmlspecialchars($healthLabel, ENT_QUOTES, 'UTF-8') ?></span></div>
  <p class="meta"><?= htmlspecialchars((string)($status['message'] ?? ''), ENT_QUOTES, 'UTF-8') ?> Health describes recent activity; review the result below for failures.</p>
  <dl class="system-facts">
    <div><dt>Last completed run</dt><dd><?= htmlspecialchars(bms_scheduled_tasks_format_timestamp($lastRunAt), ENT_QUOTES, 'UTF-8') ?></dd></div>
    <div><dt>Last execution source</dt><dd><?= htmlspecialchars(trim((string)($status['last_source'] ?? '')) !== '' ? bms_scheduled_tasks_source_label((string)$status['last_source']) : 'Not recorded', ENT_QUOTES, 'UTF-8') ?></dd></div>
    <div><dt>Last result</dt><dd><strong><?= htmlspecialchars((string)($status['last_status'] ?? '') ?: 'Not recorded', ENT_QUOTES, 'UTF-8') ?></strong><br><?= htmlspecialchars(trim((string)($status['last_message'] ?? '')) !== '' ? (string)$status['last_message'] : 'No completed run recorded yet.', ENT_QUOTES, 'UTF-8') ?></dd></div>
    <div><dt>Expected interval</dt><dd>Every <?= (int)$expectedMinutes ?> minute<?= $expectedMinutes === 1 ? '' : 's' ?></dd></div>
    <div><dt>Public traffic fallback</dt><dd><?= !empty($status['public_traffic_enabled']) ? 'Enabled' : 'Disabled' ?></dd></div>
    <div><dt>Browser heartbeat</dt><dd><?= !empty($status['heartbeat_enabled']) ? 'Enabled' : 'Disabled' ?></dd></div>
  </dl>
  <form method="post" id="run-now" class="system-warning">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="scheduled_tasks_action" value="run_now">
    <p id="run-now-help"><strong>Runs due work now.</strong> This can publish scheduled posts and process queued federation work. It is not a diagnostic refresh.</p>
    <button type="submit" class="button-link secondary" aria-describedby="run-now-help">Run Tasks Now</button>
  </form>
</section>
<div class="system-section system-configuration">
  <section class="system-section"><div class="settings-section-header"><div><h2>Interval and optional fallbacks</h2><p class="meta">Fallbacks help active sites but do not replace a cron job when reliable timing matters.</p></div></div><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="scheduled_tasks_action" value="save_settings"><div class="settings-field-grid"><div class="settings-field-card"><label for="scheduled_tasks_expected_interval_minutes">Expected task interval</label><select id="scheduled_tasks_expected_interval_minutes" name="scheduled_tasks_expected_interval_minutes"><?php foreach ([1, 5, 15, 30, 60] as $minutes): ?><option value="<?= $minutes ?>" <?= $minutes === $expectedMinutes ? 'selected' : '' ?>>Every <?= $minutes ?> minute<?= $minutes === 1 ? '' : 's' ?></option><?php endforeach; ?></select><p class="field-help">Used for health reporting and setup examples. It does not create a cron job.</p></div></div><div class="settings-option-list"><label class="settings-option-card"><input type="checkbox" name="scheduled_tasks_public_traffic_enabled" value="1" <?= !empty($status['public_traffic_enabled']) ? 'checked' : '' ?>><span class="settings-option-copy"><strong>Allow public traffic fallback</strong><small>Safe public GET and HEAD requests may check due tasks before public output renders.</small></span></label><label class="settings-option-card"><input type="checkbox" name="scheduled_tasks_heartbeat_enabled" value="1" <?= !empty($status['heartbeat_enabled']) ? 'checked' : '' ?>><span class="settings-option-copy"><strong>Allow active browser heartbeat fallback</strong><small>Signed-in Admin and Stream composer sessions check due tasks every 30 seconds while the page stays open.</small></span></label></div><div class="operations-form-actions"><button type="submit">Save Fallback Settings</button></div></form></section>

  <aside class="system-cron-guide"><section class="system-section"><h2>Server cron</h2><p class="meta">Use your host cron panel or shell so scheduled work does not depend on site traffic.</p><label for="server-cron-schedule">Schedule</label><input id="server-cron-schedule" class="copy-field" type="text" value="<?= htmlspecialchars($serverCronExpression, ENT_QUOTES, 'UTF-8') ?>" readonly><label for="server-cron-command">Command</label><textarea id="server-cron-command" class="copy-field" rows="3" readonly><?= htmlspecialchars($serverCronCommand, ENT_QUOTES, 'UTF-8') ?></textarea><p class="field-help">Some hosts require a full PHP binary path such as <code>/usr/local/bin/php</code>.</p></section></aside>
</div>

<section class="system-section"><div class="settings-section-header"><div><h2>Protected web cron</h2><p class="meta">Use this only when the host or an external cron service can make a web request but cannot run PHP locally. Header authentication is preferred.</p></div><span class="static-pill <?= !empty($status['web_cron_enabled']) ? 'generated' : 'draft' ?>"><?= !empty($status['web_cron_enabled']) ? 'ENABLED' : 'DISABLED' ?></span></div><dl class="settings-fact-list"><div><dt>Endpoint</dt><dd><code class="settings-technical-value"><?= htmlspecialchars($webCronUrl, ENT_QUOTES, 'UTF-8') ?></code></dd></div></dl><?php if ($newWebCronKey !== ''): ?><div class="settings-attention-panel"><p class="eyebrow">Copy this key now</p><h3>Bonumark Stream will not display the full web cron key again.</h3><label for="web-cron-key">Web cron key</label><input id="web-cron-key" class="copy-field" type="text" value="<?= htmlspecialchars($newWebCronKey, ENT_QUOTES, 'UTF-8') ?>" readonly><label for="web-cron-command">Header-authenticated test command</label><textarea id="web-cron-command" class="copy-field" rows="4" readonly><?= htmlspecialchars($webCronTest, ENT_QUOTES, 'UTF-8') ?></textarea></div><?php endif; ?><p class="system-note">Replacing or disabling the key interrupts any external scheduler using the current key. Update that scheduler after generating a replacement.</p><div class="settings-inline-actions"><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="scheduled_tasks_action" value="generate_web_cron_key"><button type="submit" class="button-link secondary"><?= !empty($status['web_cron_enabled']) ? 'Generate New Web Cron Key' : 'Enable Web Cron and Generate Key' ?></button></form><?php if (!empty($status['web_cron_enabled'])): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="scheduled_tasks_action" value="disable_web_cron"><button type="submit" class="button-link secondary danger">Disable Web Cron</button></form><?php endif; ?></div></section>

<section class="system-section"><div class="settings-record-heading"><div><h2>Recent task runs</h2><p class="meta">Manual, server-cron, and web-cron runs are retained. Background fallback checks do not fill the history with noise.</p></div><span class="static-pill draft"><?= count($history) ?> RUN<?= count($history) === 1 ? '' : 'S' ?></span></div><?php if (!$history): ?><div class="settings-empty-state"><h3>No task-run history returned.</h3><p class="meta">History may be empty or unavailable. Check the last recorded result above; configure cron or deliberately run tasks to establish activity.</p></div><?php else: ?><div class="settings-record-header settings-history-record"><span>When</span><span>Source</span><span>Result</span><span>Published</span><span>Details</span></div><div class="settings-record-list"><?php foreach ($history as $run): ?><?php $completed = bms_scheduled_tasks_history_timestamp($run); ?><article class="settings-history-record"><div class="settings-record-cell"><span class="settings-mobile-label">When</span><?= htmlspecialchars($completed > 0 ? bms_scheduled_tasks_format_timestamp($completed) : 'Unknown', ENT_QUOTES, 'UTF-8') ?></div><div class="settings-record-cell"><span class="settings-mobile-label">Source</span><?= htmlspecialchars(bms_scheduled_tasks_source_label((string)($run['source'] ?? 'manual')), ENT_QUOTES, 'UTF-8') ?></div><div class="settings-record-cell"><span class="settings-mobile-label">Result</span><span class="static-pill <?= (string)($run['status'] ?? 'completed') === 'completed' ? 'generated' : 'warning' ?>"><?= htmlspecialchars(strtoupper((string)($run['status'] ?? 'completed')), ENT_QUOTES, 'UTF-8') ?></span></div><div class="settings-record-cell"><span class="settings-mobile-label">Published</span><?= (int)($run['scheduled_posts_published'] ?? 0) ?></div><div class="settings-record-cell"><span class="settings-mobile-label">Details</span><?= htmlspecialchars((string)($run['details'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div></article><?php endforeach; ?></div><?php endif; ?></section>
</div>
<?php bms_admin_footer(); ?>

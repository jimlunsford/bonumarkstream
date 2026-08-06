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
$healthLabel = match ((string)($status['status'] ?? 'unknown')) { 'healthy' => 'Healthy', 'stale' => 'Needs attention', default => 'Not checked yet' };
$lastRunAt = (int)($status['last_run_at'] ?? 0);
$webCronTest = $newWebCronKey !== '' ? 'curl -fsS -H ' . escapeshellarg('X-Bonumark-Cron-Key: ' . $newWebCronKey) . ' ' . escapeshellarg($webCronUrl) : '';

bms_admin_header('Scheduled Tasks', [
    ['label' => 'Tools', 'href' => bms_admin_url('tools.php'), 'style' => 'secondary'],
    ['label' => 'Run Tasks Now', 'href' => '#run-now', 'style' => 'secondary'],
    ['label' => 'Scheduled Posts', 'href' => bms_admin_url('content.php?status=scheduled'), 'style' => 'secondary'],
]);
?>
<section class="panel settings-workflow-hero"><div class="settings-workflow-hero-copy"><p class="eyebrow">System operations</p><h2>Keep scheduled work moving.</h2><p class="meta">Server cron is the dependable runner. Public traffic and signed-in browser heartbeats remain optional fallbacks for shared-hosting installs.</p></div><span class="static-pill <?= htmlspecialchars($healthClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(strtoupper($healthLabel), ENT_QUOTES, 'UTF-8') ?></span></section>

<section class="panel settings-summary-panel"><div class="settings-summary-grid"><div><span>Last task run</span><strong><?= htmlspecialchars(bms_scheduled_tasks_format_timestamp($lastRunAt), ENT_QUOTES, 'UTF-8') ?></strong></div><div><span>Last source</span><strong><?= htmlspecialchars(trim((string)($status['last_source'] ?? '')) !== '' ? bms_scheduled_tasks_source_label((string)$status['last_source']) : 'Not recorded', ENT_QUOTES, 'UTF-8') ?></strong></div><div><span>Expected interval</span><strong>Every <?= (int)$expectedMinutes ?> minute<?= $expectedMinutes === 1 ? '' : 's' ?></strong></div><div><span>Web cron</span><strong><?= !empty($status['web_cron_enabled']) ? 'Enabled' : 'Disabled' ?></strong></div></div></section>

<section class="panel settings-section-panel"><div class="settings-section-header"><div><p class="eyebrow">Runner health</p><h2>Task status</h2><p class="meta"><?= htmlspecialchars((string)($status['message'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p></div></div><dl class="settings-fact-list"><div><dt>Last result</dt><dd><?= htmlspecialchars(trim((string)($status['last_message'] ?? '')) !== '' ? (string)$status['last_message'] : 'No completed run recorded yet.', ENT_QUOTES, 'UTF-8') ?></dd></div><div><dt>Public traffic fallback</dt><dd><?= !empty($status['public_traffic_enabled']) ? 'Enabled' : 'Disabled' ?></dd></div><div><dt>Browser heartbeat</dt><dd><?= !empty($status['heartbeat_enabled']) ? 'Enabled' : 'Disabled' ?></dd></div></dl><form method="post" id="run-now" class="settings-form-actions"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="scheduled_tasks_action" value="run_now"><button type="submit" class="button-link secondary">Run Tasks Now</button></form></section>

<div class="settings-workflow-grid">
  <section class="panel settings-section-panel"><div class="settings-section-header"><div><p class="eyebrow">Fallbacks</p><h2>Traffic and browser checks</h2><p class="meta">Fallbacks help active sites but do not replace a cron job when reliable timing matters.</p></div></div><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="scheduled_tasks_action" value="save_settings"><div class="settings-field-grid"><div class="settings-field-card"><label for="scheduled_tasks_expected_interval_minutes">Expected task interval</label><select id="scheduled_tasks_expected_interval_minutes" name="scheduled_tasks_expected_interval_minutes"><?php foreach ([1, 5, 15, 30, 60] as $minutes): ?><option value="<?= $minutes ?>" <?= $minutes === $expectedMinutes ? 'selected' : '' ?>>Every <?= $minutes ?> minute<?= $minutes === 1 ? '' : 's' ?></option><?php endforeach; ?></select><p class="field-help">Used for health reporting and setup examples. It does not create a cron job.</p></div></div><div class="settings-option-list"><label class="settings-option-card"><input type="checkbox" name="scheduled_tasks_public_traffic_enabled" value="1" <?= !empty($status['public_traffic_enabled']) ? 'checked' : '' ?>><span class="settings-option-copy"><strong>Allow public traffic fallback</strong><small>Safe public GET and HEAD requests may check due tasks before public output renders.</small></span></label><label class="settings-option-card"><input type="checkbox" name="scheduled_tasks_heartbeat_enabled" value="1" <?= !empty($status['heartbeat_enabled']) ? 'checked' : '' ?>><span class="settings-option-copy"><strong>Allow active browser heartbeat fallback</strong><small>Signed-in Admin and Stream composer sessions check due tasks every 30 seconds while the page stays open.</small></span></label></div><div class="settings-save-bar"><div><strong>Save fallback settings</strong><p class="meta">Runner health uses the new expected interval immediately.</p></div><button type="submit">Save Fallback Settings</button></div></form></section>

  <aside class="settings-workflow-rail is-sticky"><section class="panel settings-section-panel settings-command-card"><p class="eyebrow">Recommended</p><h2>Server cron</h2><p class="meta">Use your host cron panel or shell so scheduled work does not depend on site traffic.</p><label>Schedule</label><input class="copy-field" type="text" value="<?= htmlspecialchars($serverCronExpression, ENT_QUOTES, 'UTF-8') ?>" readonly><label>Command</label><input class="copy-field" type="text" value="<?= htmlspecialchars($serverCronCommand, ENT_QUOTES, 'UTF-8') ?>" readonly><p class="field-help">Some hosts require a full PHP binary path such as <code>/usr/local/bin/php</code>.</p></section></aside>
</div>

<section class="panel settings-section-panel"><div class="settings-section-header"><div><p class="eyebrow">Alternative runner</p><h2>Protected web cron</h2><p class="meta">Use this only when the host or an external cron service can make a web request but cannot run PHP locally. Header authentication is preferred.</p></div><span class="static-pill <?= !empty($status['web_cron_enabled']) ? 'generated' : 'draft' ?>"><?= !empty($status['web_cron_enabled']) ? 'ENABLED' : 'DISABLED' ?></span></div><dl class="settings-fact-list"><div><dt>Endpoint</dt><dd><code class="settings-technical-value"><?= htmlspecialchars($webCronUrl, ENT_QUOTES, 'UTF-8') ?></code></dd></div></dl><?php if ($newWebCronKey !== ''): ?><div class="settings-attention-panel"><p class="eyebrow">Copy this key now</p><h3>Bonumark Stream will not display the full web cron key again.</h3><label>Web cron key</label><input class="copy-field" type="text" value="<?= htmlspecialchars($newWebCronKey, ENT_QUOTES, 'UTF-8') ?>" readonly><label>Header-authenticated test command</label><input class="copy-field" type="text" value="<?= htmlspecialchars($webCronTest, ENT_QUOTES, 'UTF-8') ?>" readonly></div><?php endif; ?><div class="settings-inline-actions"><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="scheduled_tasks_action" value="generate_web_cron_key"><button type="submit" class="button-link secondary"><?= !empty($status['web_cron_enabled']) ? 'Generate New Web Cron Key' : 'Enable Web Cron and Generate Key' ?></button></form><?php if (!empty($status['web_cron_enabled'])): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="scheduled_tasks_action" value="disable_web_cron"><button type="submit" class="button-link secondary danger">Disable Web Cron</button></form><?php endif; ?></div></section>

<section class="panel settings-section-panel"><div class="settings-record-heading"><div><p class="eyebrow">History</p><h2>Recent task runs</h2><p class="meta">Manual, server-cron, and web-cron runs are retained. Background fallback checks do not fill the history with noise.</p></div><span class="static-pill draft"><?= count($history) ?> RUN<?= count($history) === 1 ? '' : 'S' ?></span></div><?php if (!$history): ?><div class="settings-empty-state"><h3>No task runs recorded yet.</h3><p class="meta">Run tasks manually or configure cron to establish runner health.</p></div><?php else: ?><div class="settings-record-header settings-history-record"><span>When</span><span>Source</span><span>Result</span><span>Published</span><span>Details</span></div><div class="settings-record-list"><?php foreach ($history as $run): ?><?php $completed = bms_scheduled_tasks_history_timestamp($run); ?><article class="settings-history-record"><div class="settings-record-cell"><span class="settings-mobile-label">When</span><?= htmlspecialchars($completed > 0 ? bms_scheduled_tasks_format_timestamp($completed) : 'Unknown', ENT_QUOTES, 'UTF-8') ?></div><div class="settings-record-cell"><span class="settings-mobile-label">Source</span><?= htmlspecialchars(bms_scheduled_tasks_source_label((string)($run['source'] ?? 'manual')), ENT_QUOTES, 'UTF-8') ?></div><div class="settings-record-cell"><span class="settings-mobile-label">Result</span><span class="static-pill <?= (string)($run['status'] ?? 'completed') === 'completed' ? 'generated' : 'warning' ?>"><?= htmlspecialchars(strtoupper((string)($run['status'] ?? 'completed')), ENT_QUOTES, 'UTF-8') ?></span></div><div class="settings-record-cell"><span class="settings-mobile-label">Published</span><?= (int)($run['scheduled_posts_published'] ?? 0) ?></div><div class="settings-record-cell"><span class="settings-mobile-label">Details</span><?= htmlspecialchars((string)($run['details'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div></article><?php endforeach; ?></div><?php endif; ?></section>
<?php bms_admin_footer(); ?>

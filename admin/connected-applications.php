<?php
define('BMS_CONNECT_AUTHORIZATION_SURFACE', true);
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/connect.php';
require_once __DIR__ . '/_layout.php';
bms_require_login();
bms_require_capability('manage_settings');
bms_connect_headers();
$ownerId = (int)bms_current_user()['id'];
$error = '';
$grants = [];
$identity = null;
$initialized = false;
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        bms_verify_csrf();
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'initialize') { bms_connect_initialize($ownerId); }
        else {
            if (in_array($action, ['revoke_all', 'recovery', 'clone'], true) && ($_POST['confirmation'] ?? '') !== 'REVOKE') { throw new BMS_Connect_Error('confirmation_required'); }
            bms_connect_manage($ownerId, $action, (string)($_POST['grant_id'] ?? ''), is_array($_POST['scopes'] ?? null) ? $_POST['scopes'] : []);
        }
        bms_flash('Connected Applications updated.', 'success');
        bms_redirect(bms_admin_url('connected-applications.php'));
    }
    $identity = bms_connect_identity(false);
    $initialized = bms_connect_setting('connect_enabled', '0') === '1';
} catch (BMS_Connect_Error $e) {
    if ($e->resultCode !== 'connect_unavailable') { $error = $e->resultCode; }
} catch (Throwable $e) { $error = 'Connected Applications is unavailable. Check that the authorized migrations have completed.'; }
try {
    $grants = bms_connect_query('SELECT grant_id, display_name, scopes, state, created_at, approved_at, expires_at, last_used_at FROM ' . bms_table('connect_grants') . ' WHERE owner_id = ? ORDER BY created_at DESC, grant_id DESC LIMIT 100', [$ownerId])->fetchAll();
} catch (Throwable $e) { $error = 'Connected Applications is unavailable. Check that the authorized migrations have completed.'; }
$h = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$date = static fn(mixed $v): string => $v ? gmdate('Y-m-d H:i:s', (int)$v) . ' UTC' : 'Never';
bms_admin_header('Connected Applications');
?>
<section class="panel settings-workflow-hero"><div class="settings-workflow-hero-copy"><h2>Control access to your site</h2><p>Review approvals, reduce permissions, and revoke access locally. These controls work when Bonumark Connect is unavailable.</p></div><span class="static-pill draft"><?= $initialized ? 'ENABLED' : 'DISABLED' ?></span></section>
<?php if ($error !== ''): ?><section class="panel settings-attention-panel" role="alert"><p><?= $h($error) ?></p></section><?php endif; ?>
<?php if (!$initialized && $error === ''): ?><section class="panel settings-section-panel"><h2>Enable connections</h2><p>Enable the optional connection service on this site. A trusted client must also be provisioned by the site operator before it can request approval.</p><form method="post"><input type="hidden" name="csrf_token" value="<?= $h(bms_csrf_token()) ?>"><button name="action" value="initialize">Enable connections</button></form></section><?php endif; ?>
<?php if ($identity): ?><section class="panel settings-section-panel"><h2>Site identity</h2><p class="settings-technical-value"><?= $h($identity['canonical_origin'] . $identity['base_path']) ?></p><p class="settings-technical-value">Site ID: <?= $h($identity['site_id']) ?></p></section><?php endif; ?>
<section class="panel settings-section-panel"><h2>Recent application approvals</h2><p class="meta">Showing up to 100 approvals. Revoked grants cannot be reactivated. Reconnect through the application for a new approval.</p>
<?php if (!$grants): ?><div class="settings-empty-state"><h3>No applications connected</h3><p>Approvals appear here after you authorize a trusted application.</p></div><?php endif; ?>
<div class="settings-record-header settings-token-record"><span>Application</span><span>Permissions</span><span>State</span><span>Use and expiration</span><span>Actions</span></div><div class="settings-record-list"><?php foreach ($grants as $grant): $state = $grant['state'] !== 'revoked' && (int)$grant['expires_at'] <= time() ? 'expired' : $grant['state']; $scopes = json_decode($grant['scopes'], true) ?: []; ?>
<article class="settings-token-record">
  <div class="settings-record-cell is-primary"><strong><?= $h($grant['display_name']) ?></strong><code class="settings-technical-value"><?= $h($grant['grant_id']) ?></code><small>Created <?= $h($date($grant['created_at'])) ?></small><small>Approved <?= $h($date($grant['approved_at'])) ?></small></div>
  <div class="settings-record-cell"><span class="settings-mobile-label">Permissions</span><?= $h(implode(', ', $scopes) ?: 'No authority') ?></div>
  <div class="settings-record-cell"><span class="settings-mobile-label">State</span><strong><?= $h($state) ?></strong></div>
  <div class="settings-record-cell"><span>Expires <?= $h($date($grant['expires_at'])) ?></span><br><small>Last use <?= $h($date($grant['last_used_at'])) ?></small></div>
  <div class="settings-record-actions"><?php if ($state !== 'revoked'): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= $h(bms_csrf_token()) ?>"><input type="hidden" name="grant_id" value="<?= $h($grant['grant_id']) ?>"><button class="button-link secondary danger" name="action" value="revoke">Revoke access</button></form><?php endif; ?>
  <?php if ($state === 'active'): ?><details><summary>Reduce permissions</summary><form method="post"><input type="hidden" name="csrf_token" value="<?= $h(bms_csrf_token()) ?>"><input type="hidden" name="grant_id" value="<?= $h($grant['grant_id']) ?>"><?php foreach ($scopes as $scope): ?><label class="checkbox-line"><input type="checkbox" name="scopes[]" value="<?= $h($scope) ?>" checked><?= $h($scope) ?></label><?php endforeach; ?><button name="action" value="reduce">Save reduced permissions</button></form></details><?php endif; ?></div>
</article><?php endforeach; ?></div></section>
<section class="panel settings-section-panel settings-danger-zone"><h2>Recovery and revoke all</h2><p>These actions invalidate Connect approvals, credentials, pending requests, and codes. Your Admin, Profile, ActivityPub identity, content, and Remote Posting tokens keep their existing identities and controls.</p>
<form method="post"><input type="hidden" name="csrf_token" value="<?= $h(bms_csrf_token()) ?>"><label for="recovery-action">Action</label><select id="recovery-action" name="action"><option value="revoke_all">Revoke all Connect applications</option><option value="recovery">Revoke restored or compromised access and disable Connect</option><option value="clone">Reset an independent clone with a new site ID and disable Connect</option></select><p class="field-help">After restoring a backup, keep Connect inaccessible until the recovery action has completed. Enable connections again only when ready for fresh owner approval. A clone reset changes only Connect site identity.</p><label for="connect-confirmation">Type REVOKE to confirm</label><input id="connect-confirmation" name="confirmation" autocomplete="off" required pattern="REVOKE"><button class="button-link secondary danger">Apply recovery action</button></form></section>
<?php bms_admin_footer(); ?>

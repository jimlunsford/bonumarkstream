<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/api.php';
require_once __DIR__ . '/_layout.php';
bms_require_login();
bms_require_capability('manage_settings');

function bms_admin_api_token_state(array $token): string
{
    $status = (string)($token['status'] ?? 'active');
    if ($status !== 'active') {
        return $status;
    }
    $expiresAt = trim((string)($token['expires_at'] ?? ''));
    $expiresTimestamp = $expiresAt !== '' ? strtotime($expiresAt) : false;
    return $expiresTimestamp !== false && $expiresTimestamp < time() ? 'expired' : 'active';
}

$newPlainToken = '';
$newTokenName = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    bms_verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'save_settings') {
            $enabled = !empty($_POST['remote_posting_enabled']) ? '1' : '0';
            $directPublish = !empty($_POST['remote_posting_direct_publish_enabled']) ? '1' : '0';
            $defaultStatus = strtolower(trim((string)($_POST['remote_posting_default_status'] ?? 'draft')));
            $defaultStatus = $defaultStatus === 'published' ? 'published' : 'draft';
            if ($directPublish !== '1') {
                $defaultStatus = 'draft';
            }
            $confirmationRequired = !empty($_POST['remote_posting_publish_confirmation_required']) ? '1' : '0';
            $rateLimit = max(5, min(600, (int)($_POST['remote_posting_rate_limit_per_minute'] ?? 60)));
            $remoteMediaUpload = !empty($_POST['remote_media_upload_enabled']) ? '1' : '0';
            bms_set_setting('remote_posting_enabled', $enabled);
            bms_set_setting('remote_posting_direct_publish_enabled', $directPublish);
            bms_set_setting('remote_posting_default_status', $defaultStatus);
            bms_set_setting('remote_posting_publish_confirmation_required', $confirmationRequired);
            bms_set_setting('remote_posting_rate_limit_per_minute', (string)$rateLimit);
            bms_set_setting('remote_media_upload_enabled', $remoteMediaUpload);
            bms_flash('Remote posting settings saved.', 'success');
            bms_redirect(bms_admin_url('remote-posting.php'));
        }
        if ($action === 'create_token') {
            $name = (string)($_POST['token_name'] ?? '');
            $scopes = is_array($_POST['scopes'] ?? null) ? $_POST['scopes'] : [];
            $expiresAt = null;
            $expiresDate = trim((string)($_POST['expires_date'] ?? ''));
            if ($expiresDate !== '') {
                $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $expiresDate . ' 23:59:59');
                if (!$date) {
                    throw new RuntimeException('Enter the expiration date in YYYY-MM-DD format or leave it blank.');
                }
                $expiresAt = $date->format('Y-m-d H:i:s');
            }
            $createdBy = (int)(bms_current_user()['id'] ?? 0);
            $result = bms_api_create_token($name, $scopes, $expiresAt, $createdBy > 0 ? $createdBy : null);
            $newPlainToken = (string)($result['plain_token'] ?? '');
            $token = is_array($result['token'] ?? null) ? $result['token'] : [];
            $newTokenName = (string)($token['token_name'] ?? $name);
            bms_flash('API token created. Copy it now because Bonumark Stream only shows it once.', 'success');
        }
        if ($action === 'revoke_token') {
            bms_api_revoke_token((int)($_POST['token_id'] ?? 0));
            bms_flash('API token revoked.', 'success');
            bms_redirect(bms_admin_url('remote-posting.php'));
        }
    } catch (Throwable $e) {
        bms_log_admin_exception('remote-posting', $e);
        bms_flash('Remote posting action failed. Please try again.', 'error');
    }
}

$enabled = (string)bms_setting_or_config('remote_posting_enabled', '0') === '1';
$directPublishEnabled = bms_api_direct_publish_enabled();
$defaultStatus = bms_api_default_status();
$publishConfirmationRequired = bms_api_publish_confirmation_required();
$remoteMediaUploadEnabled = bms_api_remote_media_upload_enabled();
$rateLimit = bms_api_rate_limit_per_minute();
$scopeDefinitions = bms_api_token_scope_definitions();
$tokens = bms_api_list_tokens();
$auditLog = bms_api_recent_audit_log(12);
$activeTokens = count(array_filter($tokens, static fn(array $token): bool => bms_admin_api_token_state($token) === 'active'));
$statusEndpoint = bms_site_url('api/v1/status');
$streamPostsEndpoint = bms_site_url('api/v1/stream/posts');
$mediaEndpoint = bms_site_url('api/v1/media');
$mediaImportEndpoint = bms_site_url('api/v1/media/import');

bms_admin_header('Remote Posting', [
    ['label' => 'Tools', 'href' => bms_admin_url('tools.php'), 'style' => 'secondary'],
    ['label' => 'API Status', 'href' => bms_url_path('api/v1/status'), 'style' => 'secondary', 'target' => true],
    ['label' => 'API Documentation', 'href' => bms_url_path('docs/API.md'), 'style' => 'secondary', 'target' => true],
]);
?>
<section class="panel settings-workflow-hero">
  <div class="settings-workflow-hero-copy"><p class="eyebrow">System operations</p><h2>Control trusted remote publishing.</h2><p class="meta">The Remote API uses scoped tokens for external tools. Keep it disabled until the client, token scopes, publishing rules, and rate limit are intentional.</p></div>
  <span class="static-pill <?= $enabled ? 'generated' : 'draft' ?>"><?= $enabled ? 'API ENABLED' : 'API DISABLED' ?></span>
</section>

<section class="panel settings-summary-panel"><div class="settings-summary-grid">
  <div><span>API state</span><strong><?= $enabled ? 'Enabled' : 'Disabled' ?></strong></div>
  <div><span>Active tokens</span><strong><?= $activeTokens ?></strong></div>
  <div><span>Direct publish</span><strong><?= $directPublishEnabled ? 'Allowed' : 'Disabled' ?></strong></div>
  <div><span>Rate limit</span><strong><?= (int)$rateLimit ?> per minute</strong></div>
</div></section>

<?php if ($enabled && $activeTokens === 0): ?><section class="panel settings-attention-panel"><p class="eyebrow">Needs attention</p><h2>The API is enabled without an active token.</h2><p class="meta">No client can authenticate until a scoped token is created. Disable the API if remote access is not currently needed.</p></section><?php endif; ?>

<?php if ($newPlainToken !== ''): ?>
<section class="panel settings-attention-panel"><p class="eyebrow">Copy this token now</p><h2><?= htmlspecialchars($newTokenName !== '' ? $newTokenName : 'New API Token', ENT_QUOTES, 'UTF-8') ?></h2><p class="meta">Bonumark Stream stores only a hash. This is the only time the full token will be displayed.</p><label for="new_api_token">API token</label><textarea id="new_api_token" class="settings-token-once" rows="3" readonly><?= htmlspecialchars($newPlainToken, ENT_QUOTES, 'UTF-8') ?></textarea></section>
<?php endif; ?>

<div class="settings-workflow-grid">
  <section class="panel settings-section-panel">
    <div class="settings-section-header"><div><p class="eyebrow">API rules</p><h2>Remote access and publishing safety</h2><p class="meta">These rules apply across every API token. Individual tokens still need the matching scopes.</p></div></div>
    <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="save_settings">
      <div class="settings-option-list">
        <label class="settings-option-card"><input type="checkbox" id="remote_posting_enabled" name="remote_posting_enabled" value="1" <?= $enabled ? 'checked' : '' ?>><span class="settings-option-copy"><strong>Enable the Remote API</strong><small>Keep disabled until a trusted client and a scoped token are ready.</small></span></label>
        <label class="settings-option-card"><input type="checkbox" id="remote_posting_direct_publish_enabled" name="remote_posting_direct_publish_enabled" value="1" <?= $directPublishEnabled ? 'checked' : '' ?>><span class="settings-option-copy"><strong>Allow direct remote publishing</strong><small>When disabled, every remote Stream Post becomes a draft even if the client requests publishing.</small></span></label>
        <label class="settings-option-card"><input type="checkbox" id="remote_posting_publish_confirmation_required" name="remote_posting_publish_confirmation_required" value="1" <?= $publishConfirmationRequired ? 'checked' : '' ?>><span class="settings-option-copy"><strong>Require explicit publish confirmation</strong><small>Recommended. Publishing clients must send <code>confirm_publish: true</code> or <code>confirmation: "publish"</code>.</small></span></label>
        <label class="settings-option-card"><input type="checkbox" id="remote_media_upload_enabled" name="remote_media_upload_enabled" value="1" <?= $remoteMediaUploadEnabled ? 'checked' : '' ?>><span class="settings-option-copy"><strong>Allow remote image uploads</strong><small>Trusted clients with <code>media:upload</code> can upload images or import public image URLs. Other media remains admin-only.</small></span></label>
      </div>
      <div class="settings-field-grid">
        <div class="settings-field-card"><label for="remote_posting_default_status">Default remote post status</label><select id="remote_posting_default_status" name="remote_posting_default_status"><option value="draft" <?= $defaultStatus === 'draft' ? 'selected' : '' ?>>Draft</option><option value="published" <?= $defaultStatus === 'published' ? 'selected' : '' ?> <?= $directPublishEnabled ? '' : 'disabled' ?>>Published</option></select><p class="field-help">Explicit published and scheduled requests still require direct publishing and the publish scope.</p></div>
        <div class="settings-field-card"><label for="remote_posting_rate_limit_per_minute">Rate limit per token per minute</label><input type="number" id="remote_posting_rate_limit_per_minute" name="remote_posting_rate_limit_per_minute" min="5" max="600" value="<?= (int)$rateLimit ?>"><p class="field-help">Protects endpoints from loops and abuse. Allowed range is 5 to 600.</p></div>
      </div>
      <div class="settings-save-bar"><div><strong>Save Remote Posting Settings</strong><p class="meta">The API uses the updated rules immediately.</p></div><button type="submit">Save Remote Posting Settings</button></div>
    </form>
  </section>

  <aside class="settings-workflow-rail is-sticky">
    <section class="panel settings-section-panel"><p class="eyebrow">Endpoints</p><h2>API routes</h2><p class="meta">Clients authenticate with a Bearer token and use only the routes allowed by their scopes.</p><div class="settings-endpoint-list"><div><span>Status</span><code class="settings-technical-value"><?= htmlspecialchars($statusEndpoint, ENT_QUOTES, 'UTF-8') ?></code></div><div><span>Stream Posts</span><code class="settings-technical-value"><?= htmlspecialchars($streamPostsEndpoint, ENT_QUOTES, 'UTF-8') ?></code></div><div><span>Media upload</span><code class="settings-technical-value"><?= htmlspecialchars($mediaEndpoint, ENT_QUOTES, 'UTF-8') ?></code></div><div><span>Media import</span><code class="settings-technical-value"><?= htmlspecialchars($mediaImportEndpoint, ENT_QUOTES, 'UTF-8') ?></code></div></div></section>
  </aside>
</div>

<section class="panel settings-section-panel">
  <div class="settings-section-header"><div><p class="eyebrow">Tokens</p><h2>Create a scoped API token</h2><p class="meta">Name the client, grant only the scopes it needs, and set an expiration when practical.</p></div></div>
  <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="create_token">
    <div class="settings-field-grid"><div class="settings-field-card"><label for="token_name">Token name</label><input type="text" id="token_name" name="token_name" maxlength="120" placeholder="Example: ChatGPT Action" required></div><div class="settings-field-card"><label for="expires_date">Expiration date, optional</label><input type="date" id="expires_date" name="expires_date"><p class="field-help">The token expires at the end of this date.</p></div></div>
    <p class="field-label"><strong>Scopes</strong></p><div class="settings-scope-grid"><?php foreach ($scopeDefinitions as $scope => $definition): ?><?php $available = !empty($definition['available']); ?><label class="settings-scope-card <?= $available ? '' : 'is-reserved' ?>"><input type="checkbox" name="scopes[]" value="<?= htmlspecialchars($scope, ENT_QUOTES, 'UTF-8') ?>" <?= $scope === 'status:read' ? 'checked' : '' ?> <?= $available ? '' : 'disabled' ?>><span><strong><?= htmlspecialchars((string)($definition['label'] ?? $scope), ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars((string)($definition['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></small></span></label><?php endforeach; ?></div>
    <div class="settings-form-actions"><button type="submit">Create API Token</button></div>
  </form>
</section>

<section class="panel settings-section-panel">
  <div class="settings-record-heading"><div><p class="eyebrow">Tokens</p><h2>Existing API tokens</h2><p class="meta">Full token values cannot be recovered. Revoke a token when a client should lose access.</p></div><span class="static-pill draft"><?= count($tokens) ?> TOKEN<?= count($tokens) === 1 ? '' : 'S' ?></span></div>
  <?php if (!$tokens): ?><div class="settings-empty-state"><h3>No API tokens yet.</h3><p class="meta">Create a scoped token when a trusted client is ready to connect.</p></div><?php else: ?>
  <div class="settings-record-header settings-token-record"><span>Token</span><span>Scopes</span><span>Status</span><span>Use and expiration</span><span>Action</span></div><div class="settings-record-list"><?php foreach ($tokens as $token): ?><?php $status = bms_admin_api_token_state($token); ?>
    <article class="settings-token-record">
      <div class="settings-record-cell is-primary"><span class="settings-mobile-label">Token</span><strong><?= htmlspecialchars((string)($token['token_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong><code><?= htmlspecialchars((string)($token['token_prefix'] ?? ''), ENT_QUOTES, 'UTF-8') ?>…<?= htmlspecialchars((string)($token['token_hint'] ?? ''), ENT_QUOTES, 'UTF-8') ?></code><small class="meta">Created <?= htmlspecialchars((string)($token['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></small></div>
      <div class="settings-record-cell"><span class="settings-mobile-label">Scopes</span><?= htmlspecialchars(bms_api_scope_labels($token['scopes'] ?? []), ENT_QUOTES, 'UTF-8') ?></div>
      <div class="settings-record-cell"><span class="settings-mobile-label">Status</span><span class="static-pill <?= $status === 'active' ? 'generated' : 'draft' ?>"><?= htmlspecialchars(strtoupper($status), ENT_QUOTES, 'UTF-8') ?></span></div>
      <div class="settings-record-cell"><span class="settings-mobile-label">Use and expiration</span><small>Last used: <?= htmlspecialchars((string)($token['last_used_at'] ?? 'Never'), ENT_QUOTES, 'UTF-8') ?></small><br><small>Expires: <?= htmlspecialchars((string)($token['expires_at'] ?? 'Never'), ENT_QUOTES, 'UTF-8') ?></small></div>
      <div class="settings-record-actions"><span class="settings-mobile-label">Action</span><?php if ($status === 'active'): ?><form method="post" data-confirm="Revoke this API token? Connected clients using it will stop working."><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="revoke_token"><input type="hidden" name="token_id" value="<?= (int)($token['id'] ?? 0) ?>"><button type="submit" class="button-link secondary danger">Revoke</button></form><?php else: ?><span class="meta">No action</span><?php endif; ?></div>
    </article><?php endforeach; ?></div><?php endif; ?>
</section>

<section class="panel settings-section-panel">
  <div class="settings-record-heading"><div><p class="eyebrow">Audit</p><h2>Recent API activity</h2><p class="meta">The latest authenticated and failed API events are retained for review.</p></div><span class="static-pill draft"><?= count($auditLog) ?> EVENT<?= count($auditLog) === 1 ? '' : 'S' ?></span></div>
  <?php if (!$auditLog): ?><div class="settings-empty-state"><h3>No API activity logged yet.</h3><p class="meta">Events appear after clients begin using the API.</p></div><?php else: ?>
  <div class="settings-record-header settings-audit-record"><span>Time</span><span>Event</span><span>Token</span><span>Status</span><span>Route</span><span>Message</span></div><div class="settings-record-list"><?php foreach ($auditLog as $event): ?>
    <article class="settings-audit-record"><div class="settings-record-cell"><span class="settings-mobile-label">Time</span><?= htmlspecialchars((string)($event['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div><div class="settings-record-cell"><span class="settings-mobile-label">Event</span><?= htmlspecialchars((string)($event['event'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div><div class="settings-record-cell"><span class="settings-mobile-label">Token</span><?= htmlspecialchars((string)($event['token_name'] ?? 'Unknown'), ENT_QUOTES, 'UTF-8') ?></div><div class="settings-record-cell"><span class="settings-mobile-label">Status</span><span class="static-pill <?= !empty($event['success']) ? 'generated' : 'warning' ?>"><?= !empty($event['success']) ? 'SUCCESS' : 'FAILED' ?> <?= (int)($event['status_code'] ?? 0) ?></span></div><div class="settings-record-cell"><span class="settings-mobile-label">Route</span><code class="settings-technical-value"><?= htmlspecialchars((string)($event['route'] ?? ''), ENT_QUOTES, 'UTF-8') ?></code></div><div class="settings-record-cell"><span class="settings-mobile-label">Message</span><?= htmlspecialchars((string)($event['message'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div></article>
  <?php endforeach; ?></div><?php endif; ?>
</section>
<?php bms_admin_footer(); ?>

<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/api.php';
require_once __DIR__ . '/_layout.php';
bms_require_login();
bms_require_capability('manage_settings');

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
$statusEndpoint = bms_site_url('api/v1/status');
$streamPostsEndpoint = bms_site_url('api/v1/stream/posts');
$mediaEndpoint = bms_site_url('api/v1/media');
$mediaImportEndpoint = bms_site_url('api/v1/media/import');

bms_admin_header('Remote Posting', [
    ['label' => 'API Status', 'href' => bms_url_path('api/v1/status'), 'style' => 'secondary', 'target' => true],
]);
?>
<section class="panel page-intro-panel">
  <p class="eyebrow">Settings</p>
  <h2>Remote Posting API</h2>
  <p class="meta">Create secure API tokens for trusted external tools. Remote posting can create drafts by default and can optionally publish directly when the Admin enables publishing controls.</p>
</section>

<?php if ($newPlainToken !== ''): ?>
<section class="panel settings-panel api-token-created-panel">
  <p class="eyebrow">Copy this token now</p>
  <h2><?= htmlspecialchars($newTokenName !== '' ? $newTokenName : 'New API Token', ENT_QUOTES, 'UTF-8') ?></h2>
  <p class="notice warning"><strong>Token shown once:</strong> Bonumark Stream stores only a hash. Copy this token before leaving the page.</p>
  <label for="new_api_token">API token</label>
  <textarea id="new_api_token" class="api-token-once" rows="3" readonly><?= htmlspecialchars($newPlainToken, ENT_QUOTES, 'UTF-8') ?></textarea>
</section>
<?php endif; ?>

<section class="panel settings-panel">
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="action" value="save_settings">

    <label class="checkbox-row" for="remote_posting_enabled">
      <input type="checkbox" id="remote_posting_enabled" name="remote_posting_enabled" value="1" <?= $enabled ? 'checked' : '' ?>>
      Enable Remote Posting API
    </label>
    <p class="field-help">Keep this disabled until you have created a token and are ready to connect a trusted client.</p>

    <label class="checkbox-row" for="remote_posting_direct_publish_enabled">
      <input type="checkbox" id="remote_posting_direct_publish_enabled" name="remote_posting_direct_publish_enabled" value="1" <?= $directPublishEnabled ? 'checked' : '' ?>>
      Allow direct remote publishing
    </label>
    <p class="field-help">When disabled, all remote stream posts are drafts even if a client requests publishing.</p>

    <label for="remote_posting_default_status">Default remote post status</label>
    <select id="remote_posting_default_status" name="remote_posting_default_status">
      <option value="draft" <?= $defaultStatus === 'draft' ? 'selected' : '' ?>>Draft</option>
      <option value="published" <?= $defaultStatus === 'published' ? 'selected' : '' ?> <?= $directPublishEnabled ? '' : 'disabled' ?>>Published</option>
    </select>
    <p class="field-help">Clients can still request draft, published, or scheduled explicitly. Published and scheduled requests require the publishing setting and a token with the publish scope.</p>

    <label class="checkbox-row" for="remote_posting_publish_confirmation_required">
      <input type="checkbox" id="remote_posting_publish_confirmation_required" name="remote_posting_publish_confirmation_required" value="1" <?= $publishConfirmationRequired ? 'checked' : '' ?>>
      Require explicit publish confirmation in API requests
    </label>
    <p class="field-help">Recommended. Publishing clients must send <code>confirm_publish: true</code> or <code>confirmation: "publish"</code>.</p>

    <label class="checkbox-row" for="remote_media_upload_enabled">
      <input type="checkbox" id="remote_media_upload_enabled" name="remote_media_upload_enabled" value="1" <?= $remoteMediaUploadEnabled ? 'checked' : '' ?>>
      Allow remote image uploads
    </label>
    <p class="field-help">When enabled, trusted clients with the <code>media:upload</code> scope can upload image files or import public image URLs through the API. Non-image media remains admin-only.</p>

    <label for="remote_posting_rate_limit_per_minute">API rate limit per token per minute</label>
    <input type="number" id="remote_posting_rate_limit_per_minute" name="remote_posting_rate_limit_per_minute" min="5" max="600" value="<?= (int)$rateLimit ?>">
    <p class="field-help">Default is 60. This protects API endpoints from accidental loops and abuse.</p>

    <div class="readonly-settings-grid">
      <div><span>Status endpoint</span><code><?= htmlspecialchars($statusEndpoint, ENT_QUOTES, 'UTF-8') ?></code></div>
      <div><span>Stream posts endpoint</span><code><?= htmlspecialchars($streamPostsEndpoint, ENT_QUOTES, 'UTF-8') ?></code></div>
      <div><span>Media endpoint</span><code><?= htmlspecialchars($mediaEndpoint, ENT_QUOTES, 'UTF-8') ?></code></div>
      <div><span>Media import endpoint</span><code><?= htmlspecialchars($mediaImportEndpoint, ENT_QUOTES, 'UTF-8') ?></code></div>
      <div><span>Current state</span><code><?= $enabled ? 'Enabled' : 'Disabled' ?></code></div>
      <div><span>Direct publish</span><code><?= $directPublishEnabled ? 'Allowed' : 'Disabled' ?></code></div>
      <div><span>Default status</span><code><?= htmlspecialchars(ucfirst($defaultStatus), ENT_QUOTES, 'UTF-8') ?></code></div>
      <div><span>Publish confirmation</span><code><?= $publishConfirmationRequired ? 'Required' : 'Not required' ?></code></div>
      <div><span>Remote image uploads</span><code><?= $remoteMediaUploadEnabled ? 'Allowed' : 'Disabled' ?></code></div>
    </div>

    <button type="submit">Save Remote Posting Settings</button>
  </form>
</section>

<section class="panel settings-panel">
  <p class="eyebrow">Tokens</p>
  <h2>Create API Token</h2>
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="action" value="create_token">

    <label for="token_name">Token name</label>
    <input type="text" id="token_name" name="token_name" maxlength="120" placeholder="Example: ChatGPT Action" required>

    <label>Scopes</label>
    <div class="scope-checkbox-grid">
      <?php foreach ($scopeDefinitions as $scope => $definition): ?>
        <?php $available = !empty($definition['available']); ?>
        <label class="scope-checkbox-card <?= $available ? '' : 'is-reserved' ?>">
          <input type="checkbox" name="scopes[]" value="<?= htmlspecialchars($scope, ENT_QUOTES, 'UTF-8') ?>" <?= $scope === 'status:read' ? 'checked' : '' ?> <?= $available ? '' : 'disabled' ?>>
          <span><strong><?= htmlspecialchars((string)($definition['label'] ?? $scope), ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars((string)($definition['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></small></span>
        </label>
      <?php endforeach; ?>
    </div>

    <label for="expires_date">Expiration date, optional</label>
    <input type="date" id="expires_date" name="expires_date">

    <button type="submit">Create API Token</button>
  </form>
</section>

<section class="panel">
  <p class="eyebrow">Tokens</p>
  <h2>Existing API Tokens</h2>
  <?php if (!$tokens): ?>
    <p class="meta">No API tokens have been created yet.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table class="admin-table compact-table">
        <thead><tr><th>Name</th><th>Hint</th><th>Scopes</th><th>Status</th><th>Created</th><th>Last Used</th><th>Expires</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($tokens as $token): ?>
          <?php $status = (string)($token['status'] ?? 'active'); ?>
          <tr>
            <td><?= htmlspecialchars((string)($token['token_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
            <td><code><?= htmlspecialchars((string)($token['token_prefix'] ?? ''), ENT_QUOTES, 'UTF-8') ?>…<?= htmlspecialchars((string)($token['token_hint'] ?? ''), ENT_QUOTES, 'UTF-8') ?></code></td>
            <td><?= htmlspecialchars(bms_api_scope_labels($token['scopes'] ?? []), ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars(ucfirst($status), ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars((string)($token['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars((string)($token['last_used_at'] ?? 'Never'), ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars((string)($token['expires_at'] ?? 'Never'), ENT_QUOTES, 'UTF-8') ?></td>
            <td>
              <?php if ($status === 'active'): ?>
                <form method="post" data-confirm="Revoke this API token? Connected clients using it will stop working.">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                  <input type="hidden" name="action" value="revoke_token">
                  <input type="hidden" name="token_id" value="<?= (int)($token['id'] ?? 0) ?>">
                  <button type="submit" class="button-link danger-link">Revoke</button>
                </form>
              <?php else: ?>
                <span class="meta">No action</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<section class="panel">
  <p class="eyebrow">Audit</p>
  <h2>Recent API Activity</h2>
  <?php if (!$auditLog): ?>
    <p class="meta">No API activity has been logged yet.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table class="admin-table compact-table">
        <thead><tr><th>Time</th><th>Event</th><th>Token</th><th>Status</th><th>Route</th><th>Message</th></tr></thead>
        <tbody>
        <?php foreach ($auditLog as $event): ?>
          <tr>
            <td><?= htmlspecialchars((string)($event['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars((string)($event['event'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars((string)($event['token_name'] ?? 'Unknown'), ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= !empty($event['success']) ? 'Success' : 'Failed' ?> <?= (int)($event['status_code'] ?? 0) ?></td>
            <td><code><?= htmlspecialchars((string)($event['route'] ?? ''), ENT_QUOTES, 'UTF-8') ?></code></td>
            <td><?= htmlspecialchars((string)($event['message'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
<?php bms_admin_footer(); ?>

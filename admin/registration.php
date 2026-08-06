<?php
require_once __DIR__ . '/../_bonumark_stream/app/registration.php';
require_once __DIR__ . '/_layout.php';
bms_require_login();
bms_require_capability('manage_settings');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    bms_verify_csrf();
    $action = (string)($_POST['action'] ?? 'save_settings');
    try {
        if ($action === 'save_settings') {
            $mode = (string)($_POST['registration_mode'] ?? 'disabled');
            if (!array_key_exists($mode, bms_registration_modes())) {
                $mode = 'disabled';
            }

            $verify = isset($_POST['registration_require_email_verification']) ? '1' : '0';
            $approval = isset($_POST['registration_require_admin_approval']) ? '1' : '0';
            $honeypot = isset($_POST['registration_honeypot_enabled']) ? '1' : '0';

            bms_set_setting('registration_mode', $mode);
            bms_set_setting('registration_default_role', 'commenter');
            bms_set_setting('registration_require_email_verification', $verify);
            bms_set_setting('registration_require_admin_approval', $approval);
            bms_set_setting('registration_honeypot_enabled', $honeypot);
            bms_set_setting('comment_registration_enabled', $mode !== 'disabled' ? '1' : '0');

            bms_flash('Commenter registration settings saved.', 'success');
            bms_redirect(bms_admin_url('registration.php'));
        }

        if ($action === 'create_invite') {
            $maxUses = (int)($_POST['max_uses'] ?? 1);
            $invite = bms_registration_create_invite(
                (string)($_POST['label'] ?? ''),
                $maxUses,
                (string)($_POST['expires_at'] ?? '')
            );
            bms_flash('Invite code created: ' . (string)($invite['code'] ?? '') . ' Copy it now. Bonumark only stores a protected hash.', 'success');
            bms_redirect(bms_admin_url('registration.php'));
        }

        if ($action === 'revoke_invite') {
            bms_registration_revoke_invite((int)($_POST['invite_id'] ?? 0));
            bms_flash('Invite code revoked.', 'success');
            bms_redirect(bms_admin_url('registration.php'));
        }
    } catch (Throwable $e) {
        bms_log_admin_exception('registration', $e);
        bms_flash('Could not update commenter registration controls. Please try again.', 'error');
        bms_redirect(bms_admin_url('registration.php'));
    }
}

$mode = bms_registration_mode();
$verify = bms_registration_require_email_verification();
$approval = bms_registration_require_admin_approval();
$honeypot = bms_registration_honeypot_enabled();
$mailReady = bms_registration_mail_ready();
$accountUrl = bms_url_path('account.php');
$invites = bms_registration_list_invites();
$pendingCounts = function_exists('bms_user_pending_counts') ? bms_user_pending_counts() : ['pending_verification' => 0, 'pending_approval' => 0];
$modeLabel = bms_registration_modes()[$mode] ?? $mode;
$registrationEnabled = $mode !== 'disabled';
$verificationSummaryLabel = $verify
    ? ($registrationEnabled ? 'Required' : 'Required when enabled')
    : ($registrationEnabled ? 'Not required' : 'Not required when enabled');
$approvalSummaryLabel = $approval
    ? ($registrationEnabled ? 'Required' : 'Required when enabled')
    : ($registrationEnabled ? 'Not required' : 'Not required when enabled');
$publicVerificationLabel = $verify
    ? ($registrationEnabled ? 'Email confirmation required' : 'Required when enabled')
    : ($registrationEnabled ? 'No email confirmation' : 'Not required when enabled');
$publicApprovalLabel = $approval
    ? ($registrationEnabled ? 'Admin approval required' : 'Required when enabled')
    : ($registrationEnabled ? 'Automatic after requirements' : 'Automatic when enabled');
$activeInviteCount = 0;
foreach ($invites as $inviteRow) {
    if ((string)($inviteRow['status'] ?? 'active') === 'active' && !bms_registration_invite_is_expired($inviteRow)) {
        $activeInviteCount++;
    }
}

bms_admin_header('Registration', [
    ['label' => 'Accounts', 'href' => bms_admin_url('users.php'), 'style' => 'secondary'],
    ['label' => 'Open Account Page', 'href' => $accountUrl, 'style' => 'secondary', 'target' => true],
]);
?>
<section class="panel page-intro-panel registration-intro-panel">
  <div class="registration-intro-copy">
    <p class="eyebrow">Account access</p>
    <h2>Control how commenter accounts are created.</h2>
    <p class="meta">Commenters can sign in, manage a profile, and participate through comments. Public registration never creates another publisher or grants access to the admin publishing tools.</p>
  </div>
  <span class="status-pill <?= $mode === 'disabled' ? 'draft' : 'published' ?> registration-mode-pill"><?= htmlspecialchars($modeLabel, ENT_QUOTES, 'UTF-8') ?></span>
</section>

<?php if ($registrationEnabled && $verify && !$mailReady): ?>
<section class="panel admin-warning-panel registration-attention-panel">
  <div>
    <p class="eyebrow">Needs attention</p>
    <h2>Mail is not ready for verification.</h2>
    <p>Registration requires email verification, but Mail is disabled or missing a From address. Configure Mail before opening registration.</p>
  </div>
  <a class="button-link secondary" href="<?= htmlspecialchars(bms_admin_url('mail.php'), ENT_QUOTES, 'UTF-8') ?>">Open Mail Settings</a>
</section>
<?php endif; ?>

<?php if ((int)($pendingCounts['pending_approval'] ?? 0) > 0 || (int)($pendingCounts['pending_verification'] ?? 0) > 0): ?>
<section class="panel registration-attention-panel">
  <div>
    <p class="eyebrow">Account queue</p>
    <h2>New commenter accounts need attention.</h2>
    <p><?= (int)($pendingCounts['pending_verification'] ?? 0) ?> waiting for email verification and <?= (int)($pendingCounts['pending_approval'] ?? 0) ?> waiting for admin approval.</p>
  </div>
  <a class="button-link secondary" href="<?= htmlspecialchars(bms_admin_url('users.php?status=pending'), ENT_QUOTES, 'UTF-8') ?>">Review Pending Accounts</a>
</section>
<?php endif; ?>

<section class="panel registration-summary-panel" aria-label="Current registration configuration">
  <div class="info-grid registration-summary-grid">
    <div class="info-card"><strong>Registration mode</strong><p><?= htmlspecialchars($modeLabel, ENT_QUOTES, 'UTF-8') ?></p></div>
    <div class="info-card"><strong>Email verification</strong><p><?= htmlspecialchars($verificationSummaryLabel, ENT_QUOTES, 'UTF-8') ?></p></div>
    <div class="info-card"><strong>Admin approval</strong><p><?= htmlspecialchars($approvalSummaryLabel, ENT_QUOTES, 'UTF-8') ?></p></div>
    <div class="info-card"><strong>Active invite codes</strong><p><?= (int)$activeInviteCount ?></p></div>
  </div>
</section>

<div class="registration-workflow-grid">
  <section class="panel registration-settings-panel">
    <div class="registration-section-heading">
      <div>
        <p class="eyebrow">Registration rules</p>
        <h2>Choose who can create an account.</h2>
      </div>
    </div>

    <form method="post" class="registration-settings-form">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="action" value="save_settings">

      <div class="registration-field-group">
        <label for="registration_mode">Commenter registration</label>
        <select id="registration_mode" name="registration_mode">
          <?php foreach (bms_registration_modes() as $key => $label): ?>
            <option value="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>" <?= $mode === $key ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
          <?php endforeach; ?>
        </select>
        <p class="field-help">Disabled closes public account creation. Open registration accepts anyone who completes the form. Invite only requires a valid invite code.</p>
      </div>

      <div class="registration-option-list">
        <label class="registration-option-card">
          <input type="checkbox" name="registration_require_email_verification" value="1" <?= $verify ? 'checked' : '' ?>>
          <span>
            <strong>Require email verification</strong>
            <small>New commenters must confirm their email before signing in. This depends on Settings &gt; Mail.</small>
          </span>
        </label>

        <label class="registration-option-card">
          <input type="checkbox" name="registration_require_admin_approval" value="1" <?= $approval ? 'checked' : '' ?>>
          <span>
            <strong>Require admin approval</strong>
            <small>New commenter accounts remain pending until the admin activates them.</small>
          </span>
        </label>

        <label class="registration-option-card">
          <input type="checkbox" name="registration_honeypot_enabled" value="1" <?= $honeypot ? 'checked' : '' ?>>
          <span>
            <strong>Use the quiet anti-spam field</strong>
            <small>Blocks simple registration bots without adding a captcha or interrupting real people.</small>
          </span>
        </label>
      </div>

      <div class="registration-form-actions">
        <button type="submit">Save Registration Settings</button>
      </div>
    </form>
  </section>

  <section class="panel registration-public-panel">
    <p class="eyebrow">Public account page</p>
    <h2>What visitors can do now</h2>
    <dl class="registration-public-summary">
      <div><dt>Account creation</dt><dd><?= htmlspecialchars($modeLabel, ENT_QUOTES, 'UTF-8') ?></dd></div>
      <div><dt>New account type</dt><dd>Commenter</dd></div>
      <div><dt>Verification</dt><dd><?= htmlspecialchars($publicVerificationLabel, ENT_QUOTES, 'UTF-8') ?></dd></div>
      <div><dt>Approval</dt><dd><?= htmlspecialchars($publicApprovalLabel, ENT_QUOTES, 'UTF-8') ?></dd></div>
    </dl>
    <a class="button-link secondary registration-public-action" href="<?= htmlspecialchars($accountUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">Open Account Page</a>
  </section>
</div>

<section class="panel registration-invites-panel">
  <div class="registration-section-heading registration-invites-heading">
    <div>
      <p class="eyebrow">Invite access</p>
      <h2>Create and manage invite codes.</h2>
      <p class="meta">Codes are displayed only when created. Bonumark stores a protected hash, a short hint, usage limits, and expiration data.</p>
    </div>
  </div>

  <form method="post" class="registration-invite-form">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="action" value="create_invite">
    <label>
      <span>Label</span>
      <input name="label" placeholder="Trusted commenters" autocomplete="off">
      <small>Use a private label that explains who the code is for.</small>
    </label>
    <label>
      <span>Usage limit</span>
      <input name="max_uses" type="number" min="0" step="1" value="1" inputmode="numeric">
      <small>Use 0 for unlimited uses.</small>
    </label>
    <label>
      <span>Expiration</span>
      <input name="expires_at" type="datetime-local">
      <small>Site timezone: <?= htmlspecialchars(bms_site_timezone_name(), ENT_QUOTES, 'UTF-8') ?>.</small>
    </label>
    <button type="submit">Create Invite Code</button>
  </form>

  <?php if (!$invites): ?>
    <div class="empty-state registration-invites-empty">
      <h3>No invite codes yet.</h3>
      <p>Create a code when registration should be limited to people you choose.</p>
    </div>
  <?php else: ?>
    <div class="registration-invite-summary">
      <span><?= count($invites) ?> invite code<?= count($invites) === 1 ? '' : 's' ?></span>
      <span><?= (int)$activeInviteCount ?> active</span>
    </div>

    <div class="registration-invite-header" aria-hidden="true">
      <span>Invite</span>
      <span>Usage</span>
      <span>Expiration</span>
      <span>Status</span>
      <span>Action</span>
    </div>

    <div class="registration-invite-list" role="list" aria-label="Registration invite codes">
      <?php foreach ($invites as $invite): ?>
        <?php
          $isExpired = bms_registration_invite_is_expired($invite);
          $status = (string)($invite['status'] ?? 'active');
          $statusLabel = $isExpired && $status === 'active' ? 'Expired' : ucfirst($status);
          $isActive = $status === 'active' && !$isExpired;
          $label = trim((string)($invite['label'] ?? '')) ?: 'Unlabeled invite';
          $maxUses = (int)($invite['max_uses'] ?? 1);
        ?>
        <article class="registration-invite-record" role="listitem">
          <div class="registration-invite-identity">
            <strong><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></strong>
            <code><?= htmlspecialchars((string)($invite['code_hint'] ?? ''), ENT_QUOTES, 'UTF-8') ?></code>
          </div>
          <div class="registration-invite-usage">
            <span class="registration-mobile-label">Usage</span>
            <span><?= (int)($invite['used_count'] ?? 0) ?> / <?= $maxUses === 0 ? 'Unlimited' : $maxUses ?></span>
          </div>
          <div class="registration-invite-expiration">
            <span class="registration-mobile-label">Expiration</span>
            <span><?= htmlspecialchars(bms_registration_format_invite_expiration((string)($invite['expires_at'] ?? '')), ENT_QUOTES, 'UTF-8') ?></span>
          </div>
          <div class="registration-invite-status">
            <span class="registration-mobile-label">Status</span>
            <span class="status-pill <?= $isActive ? 'published' : 'draft' ?>"><?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?></span>
          </div>
          <div class="registration-invite-actions">
            <?php if ($status === 'active'): ?>
              <form method="post" onsubmit="return confirm('Revoke this invite code?');">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="revoke_invite">
                <input type="hidden" name="invite_id" value="<?= (int)($invite['id'] ?? 0) ?>">
                <button type="submit" class="secondary-button danger-button">Revoke</button>
              </form>
            <?php else: ?>
              <span class="meta">Closed</span>
            <?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
<?php bms_admin_footer(); ?>

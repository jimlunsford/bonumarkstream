<?php
require_once __DIR__ . '/../_bonumark_stream/app/registration.php';
require_once __DIR__ . '/_layout.php';
mp_require_login();
mp_require_capability('manage_settings');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    mp_verify_csrf();
    $action = (string)($_POST['action'] ?? 'save_settings');
    try {
        if ($action === 'save_settings') {
            $mode = (string)($_POST['registration_mode'] ?? 'disabled');
            if (!array_key_exists($mode, mp_registration_modes())) {
                $mode = 'disabled';
            }

            $role = mp_normalize_role((string)($_POST['registration_default_role'] ?? 'commenter'));
            if (!array_key_exists($role, mp_registration_role_options())) {
                $role = 'commenter';
            }

            $verify = isset($_POST['registration_require_email_verification']) ? '1' : '0';
            $approval = isset($_POST['registration_require_admin_approval']) ? '1' : '0';
            $userRoleApproval = isset($_POST['registration_user_role_requires_approval']) ? '1' : '0';
            $honeypot = isset($_POST['registration_honeypot_enabled']) ? '1' : '0';

            mp_set_setting('registration_mode', $mode);
            mp_set_setting('registration_default_role', $role);
            mp_set_setting('registration_require_email_verification', $verify);
            mp_set_setting('registration_require_admin_approval', $approval);
            mp_set_setting('registration_user_role_requires_approval', $userRoleApproval);
            mp_set_setting('registration_honeypot_enabled', $honeypot);
            mp_set_setting('comment_registration_enabled', ($mode !== 'disabled' && $role === 'commenter') ? '1' : '0');

            mp_flash('Registration settings saved.', 'success');
            mp_redirect(mp_admin_url('registration.php'));
        }

        if ($action === 'create_invite') {
            $maxUses = (int)($_POST['max_uses'] ?? 1);
            $invite = mp_registration_create_invite(
                (string)($_POST['label'] ?? ''),
                (string)($_POST['role'] ?? ''),
                $maxUses,
                (string)($_POST['expires_at'] ?? '')
            );
            mp_flash('Invite code created: ' . (string)($invite['code'] ?? '') . ' Copy it now. Bonumark only stores a protected hash.', 'success');
            mp_redirect(mp_admin_url('registration.php'));
        }

        if ($action === 'revoke_invite') {
            mp_registration_revoke_invite((int)($_POST['invite_id'] ?? 0));
            mp_flash('Invite code revoked.', 'success');
            mp_redirect(mp_admin_url('registration.php'));
        }
    } catch (Throwable $e) {
        mp_flash('Could not update registration controls: ' . $e->getMessage(), 'error');
        mp_redirect(mp_admin_url('registration.php'));
    }
}

$mode = mp_registration_mode();
$role = mp_registration_default_role();
$verify = mp_registration_require_email_verification();
$approval = mp_registration_require_admin_approval();
$userRoleApproval = mp_registration_user_role_requires_approval();
$honeypot = mp_registration_honeypot_enabled();
$mailReady = mp_registration_mail_ready();
$accountUrl = mp_url_path('account.php');
$invites = mp_registration_list_invites();
$pendingCounts = function_exists('mp_user_pending_counts') ? mp_user_pending_counts() : ['pending_verification' => 0, 'pending_approval' => 0];

mp_admin_header('Registration Settings', []);
?>
<section class="panel page-intro-panel">
  <p class="eyebrow">Settings</p>
  <h2>Public registration</h2>
  <p class="meta">Control whether visitors can create accounts, whether registration requires an invite, whether new accounts need approval, and whether self-registered users can publish.</p>
</section>

<?php if ($verify && !$mailReady): ?>
<section class="panel admin-warning-panel">
  <h2>Mail needs attention</h2>
  <p>Registration currently requires email verification, but Mail is not ready. Configure <a href="<?= htmlspecialchars(mp_admin_url('mail.php'), ENT_QUOTES, 'UTF-8') ?>">Settings &gt; Mail</a> before opening registration.</p>
</section>
<?php endif; ?>

<?php if ((int)($pendingCounts['pending_approval'] ?? 0) > 0): ?>
<section class="panel admin-warning-panel">
  <h2>Accounts waiting for approval</h2>
  <p><?= (int)$pendingCounts['pending_approval'] ?> account<?= (int)$pendingCounts['pending_approval'] === 1 ? ' is' : 's are' ?> verified and waiting for admin approval.</p>
  <p><a class="button-link secondary" href="<?= htmlspecialchars(mp_admin_url('users.php'), ENT_QUOTES, 'UTF-8') ?>">Review pending users</a></p>
</section>
<?php endif; ?>

<section class="panel settings-panel">
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(mp_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="action" value="save_settings">

    <label for="registration_mode">Account registration</label>
    <select id="registration_mode" name="registration_mode">
      <?php foreach (mp_registration_modes() as $key => $label): ?>
        <option value="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>" <?= $mode === $key ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
      <?php endforeach; ?>
    </select>
    <p class="field-help">Disabled is safest. Open registration shows the account creation form. Invite only requires a valid invite code.</p>

    <label for="registration_default_role">Default role for public accounts</label>
    <select id="registration_default_role" name="registration_default_role">
      <?php foreach (mp_registration_role_options() as $key => $label): ?>
        <option value="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>" <?= $role === $key ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
      <?php endforeach; ?>
    </select>
    <p class="field-help">Commenter accounts can comment and manage a profile. User accounts can enter admin and create Stream Posts.</p>

    <label class="checkbox-line"><input type="checkbox" name="registration_require_email_verification" value="1" <?= $verify ? 'checked' : '' ?>> Require email verification before sign-in</label>
    <p class="field-help">Recommended. Verification uses the mail settings configured under Settings &gt; Mail.</p>

    <label class="checkbox-line"><input type="checkbox" name="registration_require_admin_approval" value="1" <?= $approval ? 'checked' : '' ?>> Require admin approval for new public accounts</label>
    <p class="field-help">New accounts stay pending after registration, or after email verification, until an admin activates them.</p>

    <label class="checkbox-line"><input type="checkbox" name="registration_user_role_requires_approval" value="1" <?= $userRoleApproval ? 'checked' : '' ?>> Require approval before self-registered User accounts can sign in</label>
    <p class="field-help">Recommended. This prevents public registration from handing admin-area posting access to a new User account without review.</p>

    <label class="checkbox-line"><input type="checkbox" name="registration_honeypot_enabled" value="1" <?= $honeypot ? 'checked' : '' ?>> Enable hidden anti-spam field on the registration form</label>
    <p class="field-help">This is a quiet spam trap. It is not a full captcha, but it blocks simple bots without bothering real users.</p>

    <button type="submit">Save Registration Settings</button>
  </form>
</section>

<section class="panel">
  <h2>Invite codes</h2>
  <p class="meta">Invite codes are shown only once when created. Bonumark stores a protected hash, a hint, limits, and expiration data.</p>
  <form method="post" class="settings-grid compact-settings-grid">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(mp_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="action" value="create_invite">
    <label>Label<input name="label" placeholder="Example: Spring contributors"></label>
    <label>Invite role
      <select name="role">
        <option value="">Use default registration role</option>
        <?php foreach (mp_registration_role_options() as $key => $label): ?>
          <option value="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Usage limit<input name="max_uses" type="number" min="0" step="1" value="1"></label>
    <p class="field-help">Use 0 for unlimited uses.</p>
    <label>Expires at<input name="expires_at" type="datetime-local"></label>
    <button type="submit">Create Invite Code</button>
  </form>

  <table class="admin-table compact-table">
    <thead><tr><th>Label</th><th>Hint</th><th>Role</th><th>Uses</th><th>Expires</th><th>Status</th><th>Action</th></tr></thead>
    <tbody>
    <?php if (!$invites): ?>
      <tr><td colspan="7"><span class="meta">No invite codes have been created yet.</span></td></tr>
    <?php endif; ?>
    <?php foreach ($invites as $invite): ?>
      <?php
        $isExpired = mp_registration_invite_is_expired($invite);
        $status = (string)($invite['status'] ?? 'active');
        $statusLabel = $isExpired && $status === 'active' ? 'Expired' : ucfirst($status);
        $roleLabel = trim((string)($invite['role'] ?? '')) !== '' ? mp_role_label((string)$invite['role']) : 'Default';
      ?>
      <tr>
        <td><?= htmlspecialchars((string)($invite['label'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
        <td><code><?= htmlspecialchars((string)($invite['code_hint'] ?? ''), ENT_QUOTES, 'UTF-8') ?></code></td>
        <td><?= htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8') ?></td>
        <td><?= (int)($invite['used_count'] ?? 0) ?> / <?= (int)($invite['max_uses'] ?? 1) === 0 ? '∞' : (int)($invite['max_uses'] ?? 1) ?></td>
        <td><?= htmlspecialchars((string)($invite['expires_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?: 'Never' ?></td>
        <td><span class="status-pill <?= $status === 'active' && !$isExpired ? 'published' : 'draft' ?>"><?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?></span></td>
        <td>
          <?php if ($status === 'active'): ?>
            <form method="post" class="inline-user-form">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(mp_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
              <input type="hidden" name="action" value="revoke_invite">
              <input type="hidden" name="invite_id" value="<?= (int)($invite['id'] ?? 0) ?>">
              <button type="submit">Revoke</button>
            </form>
          <?php else: ?>
            <span class="meta">No action</span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>

<section class="panel">
  <h2>Current public account page</h2>
  <div class="info-grid">
    <div class="info-card"><strong>Mode</strong><p><?= htmlspecialchars(mp_registration_modes()[$mode] ?? $mode, ENT_QUOTES, 'UTF-8') ?></p></div>
    <div class="info-card"><strong>New role</strong><p><?= htmlspecialchars(mp_role_label($role), ENT_QUOTES, 'UTF-8') ?></p></div>
    <div class="info-card"><strong>Email verification</strong><p><?= $verify ? 'Required' : 'Not required' ?></p></div>
    <div class="info-card"><strong>Admin approval</strong><p><?= $approval ? 'Required for all public accounts' : ($userRoleApproval ? 'Required for User role' : 'Not required') ?></p></div>
  </div>
  <p><a class="button-link secondary" href="<?= htmlspecialchars($accountUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">Open Account Page</a></p>
</section>
<?php mp_admin_footer(); ?>

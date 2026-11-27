<?php
require_once __DIR__ . '/../_bonumark_stream/app/profiles.php';
require_once __DIR__ . '/../_bonumark_stream/app/registration.php';
require_once __DIR__ . '/_layout.php';
bms_require_login();

$user = bms_current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    bms_verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'account_details') {
            $result = bms_update_current_user_account((string)($_POST['username'] ?? ''), (string)($_POST['email'] ?? ''), (string)($_POST['current_password'] ?? ''));
            $emailChange = (string)($result['email_change'] ?? 'none');
            if ($emailChange === 'pending') {
                bms_flash('Account details updated. Open the link sent to ' . (string)$result['pending_email'] . ' to confirm the new email address. Until then the account keeps its current address.', 'success');
            } elseif ($emailChange === 'removed') {
                bms_flash('Account details updated. This account no longer has an email address, so password recovery by email is unavailable.', 'warning');
            } else {
                bms_flash('Account details updated.', 'success');
            }
            bms_redirect(bms_admin_url('user.php'));
        }

        if ($action === 'password') {
            bms_update_current_user_password(
                (string)($_POST['current_password'] ?? ''),
                (string)($_POST['new_password'] ?? ''),
                (string)($_POST['confirm_password'] ?? '')
            );
            bms_flash('Password updated. Every other device is signed out; this browser stays signed in.', 'success');
            bms_redirect(bms_admin_url('user.php'));
        }

        if ($action === 'sign_out_other_devices') {
            bms_sign_out_other_devices();
            bms_flash('Every other device is signed out, including remembered devices. This browser stays signed in.', 'success');
            bms_redirect(bms_admin_url('user.php#signed-in-devices'));
        }

        bms_flash('Unknown account action.', 'error');
        bms_redirect(bms_admin_url('user.php'));
    } catch (Throwable $e) {
        bms_log_admin_exception('user', $e);
        // Validation messages tell the owner what to fix. Database and
        // internal errors stay generic.
        $reason = ($e instanceof RuntimeException && !$e instanceof PDOException) ? trim($e->getMessage()) : '';
        bms_flash($reason !== '' ? $reason : 'The requested action could not be completed. Please try again.', 'error');
        bms_redirect(bms_admin_url('user.php'));
    }
}

$pendingEmail = bms_account_pending_email($user);

$profileUrl = function_exists('bms_public_profile_url_for_user')
    ? bms_public_profile_url_for_user($user)
    : bms_url_path('profile/' . rawurlencode((string)($user['username'] ?? '')));
$profileEditUrl = bms_url_path('account.php?section=profile');

bms_admin_header('Account', [
    ['label' => 'Edit Profile', 'href' => $profileEditUrl, 'style' => 'secondary', 'target' => true],
    ['label' => 'View Profile', 'href' => $profileUrl, 'style' => 'secondary', 'target' => true],
]);
?>
<section class="panel">
  <div class="admin-avatar-profile-row">
    <div class="stream-card-avatar admin-avatar-preview"><?= bms_user_avatar_markup($user, 'admin-avatar-image', 192, 192) ?></div>
    <div>
      <h2><?= htmlspecialchars((string)($user['display_name'] ?? 'Admin'), ENT_QUOTES, 'UTF-8') ?></h2>
      <p class="meta">@<?= htmlspecialchars((string)($user['username'] ?? 'admin'), ENT_QUOTES, 'UTF-8') ?></p>
      <p>Your public identity now has its own focused Profile editor. Account credentials and security stay here.</p>
    </div>
  </div>
</section>

<section class="panel">
  <h2>Account details</h2>
  <p class="meta">These fields control login identity and recovery. Public Profile content is edited separately.</p>
  <form method="post" class="settings-form">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="action" value="account_details">

    <label for="username">Username</label>
    <input id="username" type="text" name="username" value="<?= htmlspecialchars((string)($user['username'] ?? 'admin'), ENT_QUOTES, 'UTF-8') ?>" autocomplete="username" required>
    <p class="field-help">Used for login, your public handle, and your Profile URL.</p>

    <label for="email">Email</label>
    <input id="email" type="email" name="email" value="<?= htmlspecialchars((string)($user['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" autocomplete="email">
    <p class="field-help">Optional for account recovery and notifications.</p>
    <?php if ($pendingEmail !== ''): ?>
    <p class="field-help">Waiting for confirmation: <?= htmlspecialchars($pendingEmail, ENT_QUOTES, 'UTF-8') ?>. Open the link sent to that address within 24 hours. The account keeps its current address until then.</p>
    <?php endif; ?>

    <label for="account_current_password">Current password</label>
    <input id="account_current_password" type="password" name="current_password" autocomplete="current-password">
    <p class="field-help">Needed only to change the email address. A new address takes effect after the confirmation link sent to it is opened.</p>

    <button type="submit">Save account details</button>
  </form>
</section>

<section class="panel">
  <h2>Change password</h2>
  <p class="meta">Update the password for this admin account. New passwords must be at least 12 characters and pass the stronger Bonumark Stream password policy.</p>
  <form method="post" class="settings-form">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="action" value="password">

    <label for="current_password">Current password</label>
    <input id="current_password" type="password" name="current_password" autocomplete="current-password" required>

    <label for="new_password">New password</label>
    <input id="new_password" type="password" name="new_password" autocomplete="new-password" required>

    <label for="confirm_password">Confirm new password</label>
    <input id="confirm_password" type="password" name="confirm_password" autocomplete="new-password" required>
    <p class="field-help">Changing the password signs out every other device. This browser stays signed in.</p>

    <button type="submit">Change password</button>
  </form>
</section>

<section class="panel" id="signed-in-devices">
  <h2>Signed-in devices</h2>
  <p class="meta">Sign out every other browser and app signed in to this account, including devices you chose to remember. This browser stays signed in.</p>
  <form method="post" class="settings-form">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="action" value="sign_out_other_devices">
    <button type="submit" class="secondary-button">Sign out all other devices</button>
  </form>
</section>

<section class="panel">
  <h2>Account storage</h2>
  <p>Admin and commenter accounts are stored in the Bonumark Stream database. Passwords are stored as one-way hashes using PHP password hashing.</p>
  <p class="meta">Public identity data is stored separately from authentication data so themes can present the Profile without carrying private account fields into public rendering.</p>
</section>
<?php bms_admin_footer(); ?>

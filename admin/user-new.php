<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/_layout.php';
bms_require_login();
bms_require_capability('manage_users');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    bms_verify_csrf();

    try {
        $user = bms_create_user(
            (string)($_POST['new_commenter_username'] ?? ''),
            (string)($_POST['new_commenter_display_name'] ?? ''),
            (string)($_POST['new_commenter_email'] ?? ''),
            'commenter',
            (string)($_POST['new_commenter_password'] ?? '')
        );
        bms_flash('Commenter account created. “' . ($user['display_name'] ?? 'New commenter') . '” can now sign in.', 'success');
        bms_redirect(bms_admin_url('user-edit.php?id=' . (int)($user['id'] ?? 0)));
    } catch (Throwable $e) {
        bms_log_admin_exception('user-new', $e);
        bms_flash('The commenter account could not be created. Check the account details and try again.', 'error');
    }
}

bms_admin_header('Add Commenter', [
    ['label' => 'Accounts', 'href' => bms_admin_url('users.php'), 'style' => 'secondary'],
]);
?>
<section class="panel page-intro-panel account-create-intro">
  <p class="eyebrow">Commenter account</p>
  <h2>Create a sign-in for someone who participates through comments.</h2>
  <p class="meta">Commenters can manage their profile and join conversations when comments are enabled. They cannot publish Stream Posts or enter the publishing tools.</p>
</section>

<section class="panel settings-panel account-create-panel">
  <h2>Account details</h2>
  <form method="post" class="settings-form account-create-form" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">

    <label for="new_commenter_username">Username</label>
    <input id="new_commenter_username" name="new_commenter_username" type="text" autocomplete="off" autocapitalize="none" spellcheck="false" data-1p-ignore data-lpignore="true" required>
    <p class="field-help">Use at least three characters. This becomes the commenter’s sign-in name and public handle.</p>

    <label for="new_commenter_display_name">Display name</label>
    <input id="new_commenter_display_name" name="new_commenter_display_name" type="text" autocomplete="off" data-1p-ignore data-lpignore="true" required>

    <label for="new_commenter_email">Email</label>
    <input id="new_commenter_email" name="new_commenter_email" type="email" autocomplete="off" inputmode="email" data-1p-ignore data-lpignore="true">

    <label for="new_commenter_password">Temporary password</label>
    <input id="new_commenter_password" name="new_commenter_password" type="password" autocomplete="new-password" data-1p-ignore data-lpignore="true" required>
    <p class="field-help">Share the temporary password securely and have the commenter change it after signing in.</p>

    <div class="form-actions-row account-create-actions">
      <button type="submit">Create Commenter</button>
      <a class="button secondary-button" href="<?= htmlspecialchars(bms_admin_url('users.php'), ENT_QUOTES, 'UTF-8') ?>">Cancel</a>
    </div>
  </form>
</section>
<?php bms_admin_footer(); ?>

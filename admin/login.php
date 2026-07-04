<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/appearance.php';
require_once __DIR__ . '/../_bonumark_stream/app/pwa.php';

bms_require_installed();

$rawReturnTo = (string)($_POST['return_to'] ?? $_GET['return_to'] ?? '');
$returnTo = $rawReturnTo !== '' ? bms_stream_safe_return_url($rawReturnTo) : bms_admin_url();
if (bms_is_logged_in()) {
    bms_redirect($returnTo);
}

$error = '';
$username = (string)($_POST['username'] ?? '');
$rememberAvailable = function_exists('bms_remember_login_enabled') ? bms_remember_login_enabled() : true;
$remember = $rememberAvailable && !empty($_POST['remember_me']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    bms_verify_csrf();
    if (bms_attempt_login($username, $_POST['password'] ?? '', $remember)) {
        bms_redirect($returnTo);
    }
    $error = 'That username and password did not work, or too many failed attempts were made.';
}
$styleUrl = htmlspecialchars(bms_asset_url('assets/style.css'), ENT_QUOTES, 'UTF-8');
$adminStyleUrl = htmlspecialchars(bms_asset_url('assets/admin.css'), ENT_QUOTES, 'UTF-8');
$pwaTags = function_exists('bms_pwa_meta_tags') ? bms_pwa_meta_tags() : '';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Bonumark Stream Login</title>
<?= $pwaTags ?>  <link rel="stylesheet" href="<?= $styleUrl ?>">
  <link rel="stylesheet" href="<?= $adminStyleUrl ?>">
</head>
<body>
  <main class="admin-wrap narrow">
    <h1>Bonumark Stream Login</h1>
    <?php if ($error): ?><div class="flash error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <form method="post" class="panel">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="return_to" value="<?= htmlspecialchars($returnTo, ENT_QUOTES, 'UTF-8') ?>">
      <label for="username">Username</label>
      <input id="username" type="text" name="username" value="<?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?>" autocomplete="username" required autofocus>
      <label for="password">Password</label>
      <input id="password" type="password" name="password" autocomplete="current-password" required>
      <?php if ($rememberAvailable): ?>
      <label class="checkbox-line" for="remember_me">
        <input id="remember_me" type="checkbox" name="remember_me" value="1" <?= $remember ? 'checked' : '' ?>>
        <span>Remember this device</span>
      </label>
      <p class="field-help">Keeps this browser signed in for up to <?= htmlspecialchars((string)bms_remember_login_days(), ENT_QUOTES, 'UTF-8') ?> days unless you log out, change your password, or reset the account password.</p>
      <?php endif; ?>
      <button type="submit">Log in</button>
      <p class="meta">The admin account is created during installation. There is no default password.</p>
      <p class="meta"><a href="<?= htmlspecialchars(bms_url_path('account.php?action=forgot'), ENT_QUOTES, 'UTF-8') ?>">Forgot your password?</a></p>
    </form>
  </main>
</body>
</html>

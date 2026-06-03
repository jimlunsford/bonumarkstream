<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';

bms_require_installed();

if (bms_is_logged_in()) {
    bms_redirect(bms_admin_url());
}

$error = '';
$username = (string)($_POST['username'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    bms_verify_csrf();
    if (bms_attempt_login($username, $_POST['password'] ?? '')) {
        bms_redirect(bms_admin_url());
    }
    $error = 'That username and password did not work, or too many failed attempts were made.';
}
$styleUrl = htmlspecialchars(bms_asset_url('assets/style.css'), ENT_QUOTES, 'UTF-8');
$adminStyleUrl = htmlspecialchars(bms_asset_url('assets/admin.css'), ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Bonumark Stream Login</title>
  <link rel="stylesheet" href="<?= $styleUrl ?>">
  <link rel="stylesheet" href="<?= $adminStyleUrl ?>">
</head>
<body>
  <main class="admin-wrap narrow">
    <h1>Bonumark Stream Login</h1>
    <?php if ($error): ?><div class="flash error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <form method="post" class="panel">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
      <label for="username">Username</label>
      <input id="username" type="text" name="username" value="<?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?>" autocomplete="username" required autofocus>
      <label for="password">Password</label>
      <input id="password" type="password" name="password" autocomplete="current-password" required>
      <button type="submit">Log in</button>
      <p class="meta">The admin account is created during installation. There is no default password.</p>
      <p class="meta"><a href="<?= htmlspecialchars(bms_url_path('account.php?action=forgot'), ENT_QUOTES, 'UTF-8') ?>">Forgot your password?</a></p>
    </form>
  </main>
</body>
</html>

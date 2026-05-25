<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/_layout.php';

mp_require_login();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    mp_verify_csrf();
    mp_logout();
    mp_redirect(mp_admin_url('login.php'));
}

mp_admin_header('Logout', []);
?>
<section class="panel page-intro-panel">
  <p class="eyebrow">Session</p>
  <h2>Log out of Bonumark Stream?</h2>
  <p class="meta">Use the button below to end your current admin session. Closing this page keeps you signed in.</p>
</section>

<section class="panel">
  <form method="post" action="<?= htmlspecialchars(mp_admin_url('logout.php'), ENT_QUOTES, 'UTF-8') ?>" class="stacked-actions">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(mp_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <button type="submit" class="primary-button">Logout</button>
    <a class="button-link secondary" href="<?= htmlspecialchars(mp_admin_url('index.php'), ENT_QUOTES, 'UTF-8') ?>">Stay signed in</a>
  </form>
</section>
<?php mp_admin_footer(); ?>

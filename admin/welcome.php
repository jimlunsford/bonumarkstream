<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/_layout.php';
bms_require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    bms_verify_csrf();
    bms_set_setting('welcome_dismissed', '1');
    bms_flash('Welcome panel dismissed. You can still use the dashboard quick actions anytime.', 'success');
    bms_redirect(bms_admin_url());
}

bms_admin_header('Welcome to Bonumark Stream', [
    ['label' => 'New Stream Post', 'href' => bms_admin_url('new.php'), 'style' => 'primary'],
]);
?>
<section class="panel welcome-panel big-welcome-panel">
  <p class="eyebrow">Welcome</p>
  <h2>Post fast, own the stream, export cleanly.</h2>
  <p class="meta">Bonumark Stream gives you a short-form publishing workflow with database-first content, Markdown export, XML sitemaps, RSS feeds, and optional static site export output.</p>
</section>

<section class="dashboard-stats welcome-steps" aria-label="Welcome steps">
  <a class="stat-card" href="<?= htmlspecialchars(bms_url_path(), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener"><span>1</span><strong>Post from the stream</strong></a>
  <a class="stat-card" href="<?= htmlspecialchars(bms_admin_url('media-upload.php'), ENT_QUOTES, 'UTF-8') ?>"><span>2</span><strong>Upload media</strong></a>
  <a class="stat-card" href="<?= htmlspecialchars(bms_admin_url('navigation.php'), ENT_QUOTES, 'UTF-8') ?>"><span>3</span><strong>Set navigation</strong></a>
  <a class="stat-card" href="<?= htmlspecialchars(bms_admin_url('system-check.php'), ENT_QUOTES, 'UTF-8') ?>"><span>4</span><strong>Check system status</strong></a>
</section>

<section class="panel">
  <h2>The Bonumark Stream difference</h2>
  <div class="feature-grid">
    <div><h3>Visual when you want it</h3><p>Write in a familiar editor without needing to understand Markdown first.</p></div>
    <div><h3>Markdown export</h3><p>Your live content is stored in database records, and your writing can still be exported as clean Markdown whenever you need it.</p></div>
    <div><h3>Static Site Export</h3><p>Published posts and pages can be included in optional static export packages from the Export screen without making generation part of every save.</p></div>
  </div>
</section>

<section class="panel">
  <form method="post" class="form-actions-row">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <button type="submit">Do not show welcome on dashboard</button>
    <a class="button-link secondary" href="<?= htmlspecialchars(bms_admin_url(), ENT_QUOTES, 'UTF-8') ?>">Back to Dashboard</a>
  </form>
</section>
<?php bms_admin_footer(); ?>

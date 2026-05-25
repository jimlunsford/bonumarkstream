<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/_layout.php';
mp_require_login();
mp_admin_header('Tools', [
    ['label' => 'System Check', 'href' => mp_admin_url('system-check.php'), 'style' => 'secondary'],
]);
?>
<section class="panel page-intro-panel">
  <p class="eyebrow">Tools</p>
  <h2>Maintain Bonumark Stream.</h2>
  <p class="meta">Export your content, import archives, upgrade Bonumark Stream, and check install health from one place.</p>
</section>
<section class="dashboard-actions-grid">
  <div class="action-card"><h3>Media</h3><p>Upload media, edit metadata, regenerate optimized image variants, and copy Markdown syntax.</p><a class="button-link secondary" href="<?= htmlspecialchars(mp_admin_url('media.php'), ENT_QUOTES, 'UTF-8') ?>">Media Library</a><a class="button-link secondary" href="<?= htmlspecialchars(mp_admin_url('media-regenerate.php'), ENT_QUOTES, 'UTF-8') ?>">Optimize Images</a></div>
  <div class="action-card"><h3>Export</h3><p>Export Markdown, static site output, media, database records, or a full Bonumark Stream package from one screen.</p><a class="button-link secondary" href="<?= htmlspecialchars(mp_admin_url('export.php'), ENT_QUOTES, 'UTF-8') ?>">Export</a></div>
  <div class="action-card"><h3>Import</h3><p>Import Markdown, JSON, WordPress XML, Bonumark exports, Twitter/X archives, or Bluesky archives after reviewing a confirmation preview.</p><a class="button-link secondary" href="<?= htmlspecialchars(mp_admin_url('import.php'), ENT_QUOTES, 'UTF-8') ?>">Import</a></div>
  <div class="action-card"><h3>Legacy Markdown Import</h3><p>Import old private Markdown files into database-first content records.</p><a class="button-link secondary" href="<?= htmlspecialchars(mp_admin_url('import-markdown.php'), ENT_QUOTES, 'UTF-8') ?>">Import Legacy Markdown</a></div>
  <div class="action-card"><h3>Mail</h3><p>Configure outbound mail delivery and send a test email.</p><a class="button-link secondary" href="<?= htmlspecialchars(mp_admin_url('mail.php'), ENT_QUOTES, 'UTF-8') ?>">Mail Settings</a></div>
  <div class="action-card"><h3>Upgrade</h3><p>Upload a newer Bonumark Stream release package.</p><a class="button-link secondary" href="<?= htmlspecialchars(mp_admin_url('upgrade.php'), ENT_QUOTES, 'UTF-8') ?>">Upgrade</a></div>
  <div class="action-card"><h3>System Check</h3><p>Check security, private storage, media support, upgrades, and hosting readiness.</p><a class="button-link secondary" href="<?= htmlspecialchars(mp_admin_url('system-check.php'), ENT_QUOTES, 'UTF-8') ?>">Run Check</a></div>
  <div class="action-card"><h3>Help</h3><p>Explain Bonumark Stream concepts like database-first content, Markdown export, dynamic rendering, media, and static site export.</p><a class="button-link secondary" href="<?= htmlspecialchars(mp_admin_url('help.php'), ENT_QUOTES, 'UTF-8') ?>">Open Help</a></div>
</section>
<?php mp_admin_footer(); ?>

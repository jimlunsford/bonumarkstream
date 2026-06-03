<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/_layout.php';
bms_require_login();
bms_admin_header('Help', []);
$items = [
  ['Stream Posts', 'Bonumark Stream has one visible content type: Stream Posts. They are short-form entries for your public timeline.'],
  ['Database Source', 'The editor writes to the database first. Markdown remains available as an import/export ownership format.'],
  ['Static Site Export', 'Export can generate a portable downloadable static HTML copy from database content records. The live site renders dynamically by default.'],
  ['Dynamic Rendering', 'Public pages render from database content records by default, so normal saves and settings changes are reflected immediately.'],
  ['RSS', 'Bonumark Stream generates RSS feeds so people can follow your stream without a social platform.'],
  ['XML Sitemap', 'Bonumark Stream serves a dynamic sitemap.xml and robots.txt reference so search engines can discover public posts and pages.'],
];
?>
<section class="panel page-intro-panel"><p class="eyebrow">Help</p><h2>Bonumark Stream concepts without the jargon.</h2><p class="meta">Short explanations for the parts that make Bonumark Stream different from platform-owned social posting.</p></section>
<section class="dashboard-actions-grid">
<?php foreach ($items as $item): ?>
  <div class="action-card"><h3><?= htmlspecialchars($item[0], ENT_QUOTES, 'UTF-8') ?></h3><p><?= htmlspecialchars($item[1], ENT_QUOTES, 'UTF-8') ?></p></div>
<?php endforeach; ?>
</section>
<?php bms_admin_footer(); ?>

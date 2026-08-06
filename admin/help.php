<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/_layout.php';
bms_require_login();

$groups = [
  'Publishing model' => [
    ['Stream Posts', 'Short-form entries for the public timeline. Creation begins in the unified Stream composer.'],
    ['Database-first content', 'The editor writes authoritative content records to the database. Markdown remains an ownership, import, and export format.'],
    ['Dynamic rendering', 'Public pages render from database content records by default, so normal saves and settings changes appear immediately.'],
    ['Static Site Export', 'Export can create a portable HTML copy without turning generated files into the live source of truth.'],
  ],
  'Discovery and public features' => [
    ['RSS', 'Generated feeds let people follow the stream without a social platform.'],
    ['XML Sitemap', 'Dynamic sitemap.xml and robots.txt references help search engines discover public posts and pages.'],
    ['Local Places', 'A private local directory can attach a public place, approximate area, or city label without an outside places service.'],
    ['Media', 'Uploaded files are validated, tracked, and can generate optimized variants when the host supports image processing.'],
  ],
  'Operations and recovery' => [
    ['Import', 'Uploads are staged into a private preview. Nothing is committed until an administrator confirms the import.'],
    ['Export', 'Markdown and static packages are portable. Database and full exports are private backups containing sensitive records.'],
    ['Scheduled Tasks', 'Server cron is the dependable runner. Signed-in or public-traffic checks are optional fallbacks.'],
    ['Upgrade', 'Release ZIPs are validated, backed up, and checked before software replacement and database migrations begin.'],
  ],
];

bms_admin_header('Help', [
    ['label' => 'Tools', 'href' => bms_admin_url('tools.php'), 'style' => 'secondary'],
    ['label' => 'System Check', 'href' => bms_admin_url('system-check.php'), 'style' => 'secondary'],
]);
?>
<section class="panel operations-hero"><div class="operations-hero-copy"><p class="eyebrow">Operational help</p><h2>Understand Bonumark Stream before changing it.</h2><p class="meta">These explanations describe product boundaries, not generic hosting advice. For current environment failures, use System Check.</p></div><span class="operation-risk-label">Reference</span></section>
<div class="operations-group-list">
<?php foreach ($groups as $group => $items): ?>
<section class="panel operations-panel"><div class="operations-section-heading"><div><p class="eyebrow">Help</p><h2><?= htmlspecialchars($group, ENT_QUOTES, 'UTF-8') ?></h2></div></div><div class="operations-help-grid"><?php foreach ($items as $item): ?><article class="operations-help-card"><span class="operations-category-label"><?= htmlspecialchars($group, ENT_QUOTES, 'UTF-8') ?></span><h3><?= htmlspecialchars($item[0], ENT_QUOTES, 'UTF-8') ?></h3><p><?= htmlspecialchars($item[1], ENT_QUOTES, 'UTF-8') ?></p></article><?php endforeach; ?></div></section>
<?php endforeach; ?>
<section class="panel operations-diagnostic-panel"><div class="operations-section-heading"><div><p class="eyebrow">Need a next step?</p><h2>Go to the screen that owns the problem.</h2></div></div><div class="operations-card-grid"><article class="operations-action-card"><h3>Hosting or permissions</h3><p>Use System Check for HTTPS, PHP extensions, private storage, writable paths, database access, and upgrade readiness.</p><div class="operations-inline-actions"><a class="button-link secondary" href="<?= htmlspecialchars(bms_admin_url('system-check.php'), ENT_QUOTES, 'UTF-8') ?>">System Check</a></div></article><article class="operations-action-card"><h3>Accounts or remote access</h3><p>Use Security for the overview, then open Accounts, Registration, Mail, or Remote Posting for the authoritative controls.</p><div class="operations-inline-actions"><a class="button-link secondary" href="<?= htmlspecialchars(bms_admin_url('security.php'), ENT_QUOTES, 'UTF-8') ?>">Security</a></div></article><article class="operations-action-card"><h3>Data movement</h3><p>Use Import for incoming archives and Export for ownership packages or private backups.</p><div class="operations-inline-actions"><a class="button-link secondary" href="<?= htmlspecialchars(bms_admin_url('import.php'), ENT_QUOTES, 'UTF-8') ?>">Import</a><a class="button-link secondary" href="<?= htmlspecialchars(bms_admin_url('export.php'), ENT_QUOTES, 'UTF-8') ?>">Export</a></div></article></div></section>
</div>
<?php bms_admin_footer(); ?>

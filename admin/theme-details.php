<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/renderer.php';
require_once __DIR__ . '/../_bonumark_stream/app/appearance.php';
require_once __DIR__ . '/_layout.php';
bms_require_login();
bms_require_capability('manage_appearance');

$slug = function_exists('bms_theme_slug') ? bms_theme_slug((string)($_GET['slug'] ?? $_POST['slug'] ?? 'default')) : 'default';
$packages = function_exists('bms_public_theme_packages') ? bms_public_theme_packages() : [];
$theme = $packages[$slug] ?? null;

if (!$theme) {
    bms_admin_error_page('Theme Not Found', 'That public theme is not installed.', 404, [
        ['label' => 'Themes', 'href' => bms_admin_url('theme.php'), 'style' => 'primary'],
        ['label' => 'Install Theme', 'href' => bms_admin_url('theme-install.php'), 'style' => 'secondary'],
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    bms_verify_csrf();
    $action = (string)($_POST['theme_action'] ?? '');
    try {
        if ($action === 'activate') {
            $activated = bms_activate_public_theme($slug);
            bms_flash('Theme activated: ' . (string)($activated['name'] ?? $slug) . '. Dynamic public routes use the new theme immediately; use Static Site Export only when you want a portable HTML copy.', 'success');
            bms_redirect(bms_admin_url('theme-details.php?slug=' . rawurlencode($slug)));
        }
        throw new RuntimeException('Unknown theme action.');
    } catch (Throwable $e) {
        bms_log_admin_exception('theme-details', $e);

        bms_flash('The requested action could not be completed. Please try again.', 'error');
        bms_redirect(bms_admin_url('theme-details.php?slug=' . rawurlencode($slug)));
    }
}

$activeSlug = function_exists('bms_active_public_theme_slug') ? bms_active_public_theme_slug() : 'default';
$isActive = $slug === $activeSlug;
$health = is_array($theme['health'] ?? null) ? $theme['health'] : bms_public_theme_package_health($theme);
$summary = function_exists('bms_public_theme_manager_summary') ? bms_public_theme_manager_summary($theme) : ['valid' => !empty($health['valid'])];
$assetRows = function_exists('bms_public_theme_asset_inventory') ? bms_public_theme_asset_inventory($theme) : [];
$layoutRows = function_exists('bms_public_theme_layout_inventory') ? bms_public_theme_layout_inventory($theme) : [];
$supports = function_exists('bms_public_theme_supports_list') ? bms_public_theme_supports_list($theme) : [];
$settings = is_array($theme['settings'] ?? null) ? $theme['settings'] : [];
$screenshotUrl = function_exists('bms_public_theme_screenshot_url') ? bms_public_theme_screenshot_url($theme) : '';
$deleteStatus = function_exists('bms_public_theme_delete_status') ? bms_public_theme_delete_status($slug) : ['can_delete' => false, 'errors' => []];
$canDelete = !empty($deleteStatus['can_delete']);
$canActivate = !empty($summary['valid']);
$errors = is_array($health['errors'] ?? null) ? $health['errors'] : [];
$warnings = is_array($health['warnings'] ?? null) ? $health['warnings'] : [];

bms_admin_header('Theme Details', [
    ['label' => 'Themes', 'href' => bms_admin_url('theme.php'), 'style' => 'secondary'],
    ['label' => 'View Site', 'href' => bms_url_path(), 'style' => 'secondary', 'target' => true],
]);
?>
<section class="panel appearance-hero-panel appearance-theme-detail-hero">
  <div class="appearance-hero-copy">
    <p class="eyebrow">Theme details</p>
    <h2><?= htmlspecialchars((string)($theme['name'] ?? $slug), ENT_QUOTES, 'UTF-8') ?></h2>
    <p class="meta"><?= htmlspecialchars((string)($theme['description'] ?? 'A Bonumark Stream public theme.'), ENT_QUOTES, 'UTF-8') ?></p>
  </div>
  <div class="appearance-theme-status">
    <?php if ($isActive): ?><span class="status-pill published">Active</span><?php endif; ?>
    <span class="status-pill <?= $canActivate ? 'published' : 'trash' ?>"><?= htmlspecialchars((string)($health['label'] ?? ($canActivate ? 'Ready' : 'Not ready')), ENT_QUOTES, 'UTF-8') ?></span>
    <?php if ($warnings): ?><span class="status-pill draft"><?= count($warnings) ?> warning<?= count($warnings) === 1 ? '' : 's' ?></span><?php endif; ?>
  </div>
</section>

<div class="appearance-theme-detail-layout">
  <section class="panel appearance-theme-detail-preview-panel">
    <div class="appearance-detail-preview">
      <?php if ($screenshotUrl !== ''): ?>
        <img src="<?= htmlspecialchars($screenshotUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars((string)($theme['name'] ?? $slug), ENT_QUOTES, 'UTF-8') ?> screenshot">
      <?php else: ?>
        <div class="appearance-theme-preview-empty"><span>No screenshot declared</span></div>
      <?php endif; ?>
    </div>
    <div class="appearance-theme-actions">
      <?php if (!$isActive && $canActivate): ?>
        <form method="post">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="theme_action" value="activate">
          <input type="hidden" name="slug" value="<?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?>">
          <button type="submit">Activate Theme</button>
        </form>
      <?php elseif ($isActive): ?>
        <a class="button-link secondary" href="<?= htmlspecialchars(bms_admin_url('theme-settings.php'), ENT_QUOTES, 'UTF-8') ?>">Edit Settings</a>
      <?php endif; ?>
      <?php if ($canDelete): ?>
        <a class="button-link danger-link" href="<?= htmlspecialchars(bms_admin_url('theme-delete.php?slug=' . rawurlencode($slug)), ENT_QUOTES, 'UTF-8') ?>">Delete Theme</a>
      <?php endif; ?>
    </div>
  </section>

  <aside class="panel appearance-theme-package-panel">
    <p class="eyebrow">Package</p>
    <h2>Theme information</h2>
    <dl class="appearance-detail-facts">
      <div><dt>Slug</dt><dd><code><?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?></code></dd></div>
      <div><dt>Version</dt><dd><?= htmlspecialchars((string)($theme['version'] ?? '1.0.0'), ENT_QUOTES, 'UTF-8') ?></dd></div>
      <div><dt>Author</dt><dd><?= htmlspecialchars((string)($theme['author'] ?? 'Bonumark'), ENT_QUOTES, 'UTF-8') ?></dd></div>
      <div><dt>Renderer</dt><dd><?= htmlspecialchars((string)($summary['renderer'] ?? 'Legacy Core Renderer'), ENT_QUOTES, 'UTF-8') ?></dd></div>
      <div><dt>Layout schema</dt><dd><?= !empty($summary['layout_aware']) ? htmlspecialchars((string)($summary['layout_schema'] ?? ''), ENT_QUOTES, 'UTF-8') : 'Not declared' ?></dd></div>
      <div><dt>Layouts</dt><dd><?= (int)($summary['layout_total'] ?? count($layoutRows)) ?></dd></div>
      <div><dt>Assets</dt><dd><?= (int)($summary['asset_total'] ?? count($assetRows)) ?></dd></div>
      <div><dt>Settings</dt><dd><?= (int)($summary['setting_total'] ?? count($settings)) ?></dd></div>
      <div><dt>Capabilities</dt><dd><?= count($supports) ?></dd></div>
    </dl>
    <?php if ($supports): ?>
      <div class="appearance-support-list">
        <?php foreach ($supports as $support): ?><span class="status-pill"><?= htmlspecialchars($support, ENT_QUOTES, 'UTF-8') ?></span><?php endforeach; ?>
      </div>
    <?php endif; ?>
  </aside>
</div>

<?php if (!empty($summary['layout_aware'])): ?>
  <section class="panel appearance-detail-record-panel">
    <div class="appearance-section-heading">
      <div><p class="eyebrow">Declarative layouts</p><h2>Core-validated composition files</h2></div>
      <span class="appearance-record-count"><?= count($layoutRows) ?></span>
    </div>
    <p class="meta">Layout files are private JSON composition documents. Core still owns data, component markup, behavior, permissions, forms, accessibility, and application logic.</p>
    <div class="appearance-record-list">
      <?php foreach ($layoutRows as $row): ?>
        <div class="appearance-record <?= !empty($row['exists']) ? 'is-good' : 'is-bad' ?>">
          <code><?= htmlspecialchars((string)($row['file'] ?? ''), ENT_QUOTES, 'UTF-8') ?></code>
          <span><?= htmlspecialchars((string)($row['label'] ?? $row['surface'] ?? 'Layout'), ENT_QUOTES, 'UTF-8') ?></span>
          <strong><?= !empty($row['exists']) ? 'Validated' : 'Missing' ?></strong>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
<?php endif; ?>

<?php if ($errors || $warnings): ?>
  <section class="panel appearance-attention-panel appearance-theme-health-panel">
    <div>
      <p class="eyebrow">Validation</p>
      <h2>Theme health</h2>
      <p class="meta">Resolve errors before activation. Warnings describe optional or incomplete package details.</p>
    </div>
    <div class="appearance-health-columns">
      <?php if ($errors): ?><ul class="appearance-issue-list"><?php foreach ($errors as $error): ?><li><strong>Error</strong><span><?= htmlspecialchars((string)$error, ENT_QUOTES, 'UTF-8') ?></span></li><?php endforeach; ?></ul><?php endif; ?>
      <?php if ($warnings): ?><ul class="appearance-issue-list warnings"><?php foreach ($warnings as $warning): ?><li><strong>Warning</strong><span><?= htmlspecialchars((string)$warning, ENT_QUOTES, 'UTF-8') ?></span></li><?php endforeach; ?></ul><?php endif; ?>
    </div>
  </section>
<?php endif; ?>

<div class="appearance-detail-record-grid">
  <section class="panel appearance-detail-record-panel">
    <div class="appearance-section-heading">
      <div><p class="eyebrow">Assets</p><h2>Declared public assets</h2></div>
      <span class="appearance-record-count"><?= count($assetRows) ?></span>
    </div>
    <?php if (!$assetRows): ?>
      <div class="empty-state appearance-empty-state"><h3>No declared assets.</h3><p class="meta">This theme does not list public asset files.</p></div>
    <?php else: ?>
      <div class="appearance-record-list">
        <?php foreach ($assetRows as $row): ?>
          <div class="appearance-record <?= !empty($row['exists']) ? 'is-good' : 'is-bad' ?>">
            <code><?= htmlspecialchars((string)($row['file'] ?? ''), ENT_QUOTES, 'UTF-8') ?></code>
            <span><?= htmlspecialchars((string)($row['type'] ?? 'Asset'), ENT_QUOTES, 'UTF-8') ?></span>
            <strong><?= !empty($row['exists']) ? 'Available' : 'Missing' ?></strong>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <section class="panel appearance-detail-record-panel">
    <div class="appearance-section-heading">
      <div><p class="eyebrow">Settings</p><h2>Declared theme settings</h2></div>
      <span class="appearance-record-count"><?= count($settings) ?></span>
    </div>
    <?php if (!$settings): ?>
      <div class="empty-state appearance-empty-state"><h3>No editable settings.</h3><p class="meta">This theme uses a fixed presentation.</p></div>
    <?php else: ?>
      <div class="appearance-record-list">
        <?php foreach ($settings as $key => $setting): ?>
          <div class="appearance-record is-good">
            <code><?= htmlspecialchars((string)$key, ENT_QUOTES, 'UTF-8') ?></code>
            <span><?= htmlspecialchars((string)($setting['type'] ?? 'text'), ENT_QUOTES, 'UTF-8') ?></span>
            <strong><?= htmlspecialchars((string)($setting['label'] ?? $key), ENT_QUOTES, 'UTF-8') ?></strong>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
</div>
<?php bms_admin_footer(); ?>

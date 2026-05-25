<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/renderer.php';
require_once __DIR__ . '/../_bonumark_stream/app/appearance.php';
require_once __DIR__ . '/_layout.php';
mp_require_login();
mp_require_capability('manage_appearance');

$slug = function_exists('mp_theme_slug') ? mp_theme_slug((string)($_GET['slug'] ?? $_POST['slug'] ?? 'default')) : 'default';
$packages = function_exists('mp_public_theme_packages') ? mp_public_theme_packages() : [];
$theme = $packages[$slug] ?? null;

if (!$theme) {
    mp_admin_error_page('Theme Not Found', 'That public theme is not installed.', 404, [
        ['label' => 'Themes', 'href' => mp_admin_url('theme.php'), 'style' => 'primary'],
        ['label' => 'Install Theme', 'href' => mp_admin_url('theme-install.php'), 'style' => 'secondary'],
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    mp_verify_csrf();
    $action = (string)($_POST['theme_action'] ?? '');
    try {
        if ($action === 'activate') {
            $activated = mp_activate_public_theme($slug);
            mp_flash('Theme activated: ' . (string)($activated['name'] ?? $slug) . '. Dynamic public routes use the new theme immediately; use Static Site Export only when you want a portable HTML copy.', 'success');
            mp_redirect(mp_admin_url('theme-details.php?slug=' . rawurlencode($slug)));
        }
        throw new RuntimeException('Unknown theme action.');
    } catch (Throwable $e) {
        mp_flash($e->getMessage(), 'error');
        mp_redirect(mp_admin_url('theme-details.php?slug=' . rawurlencode($slug)));
    }
}

$activeSlug = function_exists('mp_active_public_theme_slug') ? mp_active_public_theme_slug() : 'default';
$isActive = $slug === $activeSlug;
$health = is_array($theme['health'] ?? null) ? $theme['health'] : mp_public_theme_package_health($theme);
$summary = function_exists('mp_public_theme_manager_summary') ? mp_public_theme_manager_summary($theme) : ['valid' => !empty($health['valid'])];
$templateRows = function_exists('mp_public_theme_template_inventory') ? mp_public_theme_template_inventory($theme) : [];
$assetRows = function_exists('mp_public_theme_asset_inventory') ? mp_public_theme_asset_inventory($theme) : [];
$supports = function_exists('mp_public_theme_supports_list') ? mp_public_theme_supports_list($theme) : [];
$settings = is_array($theme['settings'] ?? null) ? $theme['settings'] : [];
$screenshotUrl = function_exists('mp_public_theme_screenshot_url') ? mp_public_theme_screenshot_url($theme) : '';
$deleteStatus = function_exists('mp_public_theme_delete_status') ? mp_public_theme_delete_status($slug) : ['can_delete' => false, 'errors' => []];
$canDelete = !empty($deleteStatus['can_delete']);
$canActivate = !empty($summary['valid']);
$errors = is_array($health['errors'] ?? null) ? $health['errors'] : [];
$warnings = is_array($health['warnings'] ?? null) ? $health['warnings'] : [];

mp_admin_header('Theme Details', [
    ['label' => 'Themes', 'href' => mp_admin_url('theme.php'), 'style' => 'secondary'],
    ['label' => 'Install Theme', 'href' => mp_admin_url('theme-install.php'), 'style' => 'secondary'],
    ['label' => 'Theme Settings', 'href' => mp_admin_url('theme-settings.php'), 'style' => 'secondary'],
]);
?>
<section class="panel page-intro-panel theme-details-hero">
  <p class="eyebrow">Theme details</p>
  <h2><?= htmlspecialchars((string)($theme['name'] ?? $slug), ENT_QUOTES, 'UTF-8') ?></h2>
  <p class="meta"><?= htmlspecialchars((string)($theme['description'] ?? 'A Bonumark Stream public theme.'), ENT_QUOTES, 'UTF-8') ?></p>
</section>

<section class="theme-details-layout">
  <div class="panel theme-details-preview-panel">
    <?php if ($screenshotUrl !== ''): ?>
      <img class="theme-details-screenshot" src="<?= htmlspecialchars($screenshotUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars((string)($theme['name'] ?? $slug), ENT_QUOTES, 'UTF-8') ?> screenshot">
    <?php else: ?>
      <div class="theme-preview theme-preview-empty large-theme-preview"><span>No screenshot declared</span></div>
    <?php endif; ?>
    <div class="theme-card-badges theme-details-badges">
      <?php if ($isActive): ?><span class="status-pill published">Active</span><?php endif; ?>
      <span class="status-pill <?= $canActivate ? 'published' : 'trash' ?>"><?= htmlspecialchars((string)($health['label'] ?? ($canActivate ? 'Safe to activate' : 'Not safe to activate')), ENT_QUOTES, 'UTF-8') ?></span>
      <?php if ($warnings): ?><span class="status-pill draft"><?= count($warnings) ?> warning<?= count($warnings) === 1 ? '' : 's' ?></span><?php endif; ?>
    </div>
    <div class="theme-card-actions theme-manager-actions">
      <?php if (!$isActive && $canActivate): ?>
        <form method="post" class="inline-theme-action-form">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(mp_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="theme_action" value="activate">
          <input type="hidden" name="slug" value="<?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?>">
          <button type="submit">Activate Theme</button>
        </form>
      <?php elseif ($isActive): ?>
        <a class="button-link secondary" href="<?= htmlspecialchars(mp_admin_url('theme-settings.php'), ENT_QUOTES, 'UTF-8') ?>">Edit Settings</a>
      <?php endif; ?>
      <?php if ($canDelete): ?>
        <a class="button-link danger-link" href="<?= htmlspecialchars(mp_admin_url('theme-delete.php?slug=' . rawurlencode($slug)), ENT_QUOTES, 'UTF-8') ?>">Delete Theme</a>
      <?php endif; ?>
    </div>
  </div>

  <div class="panel theme-details-meta-panel">
    <p class="eyebrow">Metadata</p>
    <h2>Package information</h2>
    <div class="theme-meta-list theme-details-meta-list">
      <span><strong>Slug</strong> <code><?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?></code></span>
      <span><strong>Version</strong> <code><?= htmlspecialchars((string)($theme['version'] ?? '1.0.0'), ENT_QUOTES, 'UTF-8') ?></code></span>
      <span><strong>Author</strong> <code><?= htmlspecialchars((string)($theme['author'] ?? 'Bonumark'), ENT_QUOTES, 'UTF-8') ?></code></span>
      <span><strong>Templates</strong> <code><?= (int)($summary['template_total'] ?? count($templateRows)) ?></code></span>
      <span><strong>Assets</strong> <code><?= (int)($summary['asset_total'] ?? count($assetRows)) ?></code></span>
      <span><strong>Settings</strong> <code><?= (int)($summary['setting_total'] ?? count($settings)) ?></code></span>
    </div>
    <?php if ($supports): ?>
      <div class="theme-support-list">
        <?php foreach ($supports as $support): ?><span class="status-pill"><?= htmlspecialchars($support, ENT_QUOTES, 'UTF-8') ?></span><?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>

<?php if ($errors || $warnings): ?>
  <section class="panel settings-panel theme-health-panel">
    <p class="eyebrow">Validation</p>
    <h2>Theme health</h2>
    <?php if ($errors): ?>
      <ul class="theme-health-list theme-health-errors">
        <?php foreach ($errors as $error): ?><li><?= htmlspecialchars((string)$error, ENT_QUOTES, 'UTF-8') ?></li><?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <?php if ($warnings): ?>
      <ul class="theme-health-list theme-health-warnings">
        <?php foreach ($warnings as $warning): ?><li><?= htmlspecialchars((string)$warning, ENT_QUOTES, 'UTF-8') ?></li><?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
<?php endif; ?>

<section class="panel settings-panel theme-details-checklist-panel">
  <p class="eyebrow">Templates</p>
  <h2>Required public templates</h2>
  <div class="theme-check-table">
    <?php foreach ($templateRows as $row): ?>
      <div class="theme-check-row <?= !empty($row['exists']) ? 'is-good' : 'is-bad' ?>">
        <code><?= htmlspecialchars((string)($row['file'] ?? ''), ENT_QUOTES, 'UTF-8') ?></code>
        <span><?= !empty($row['exists']) ? 'Present' : 'Missing' ?></span>
        <span><?= !empty($row['declared']) ? 'Declared' : 'Not declared' ?></span>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<section class="panel settings-panel theme-details-checklist-panel">
  <p class="eyebrow">Assets</p>
  <h2>Declared public assets</h2>
  <?php if (!$assetRows): ?>
    <p class="meta">This theme does not declare public assets.</p>
  <?php else: ?>
    <div class="theme-check-table">
      <?php foreach ($assetRows as $row): ?>
        <div class="theme-check-row <?= !empty($row['exists']) ? 'is-good' : 'is-bad' ?>">
          <code><?= htmlspecialchars((string)($row['file'] ?? ''), ENT_QUOTES, 'UTF-8') ?></code>
          <span><?= htmlspecialchars((string)($row['type'] ?? 'Asset'), ENT_QUOTES, 'UTF-8') ?></span>
          <span><?= !empty($row['exists']) ? 'Present' : 'Missing' ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<section class="panel settings-panel theme-details-checklist-panel">
  <p class="eyebrow">Settings</p>
  <h2>Declared theme settings</h2>
  <?php if (!$settings): ?>
    <p class="meta">This theme does not declare editable settings.</p>
  <?php else: ?>
    <div class="theme-check-table">
      <?php foreach ($settings as $key => $setting): ?>
        <div class="theme-check-row is-good">
          <code><?= htmlspecialchars((string)$key, ENT_QUOTES, 'UTF-8') ?></code>
          <span><?= htmlspecialchars((string)($setting['type'] ?? 'text'), ENT_QUOTES, 'UTF-8') ?></span>
          <span><?= htmlspecialchars((string)($setting['label'] ?? $key), ENT_QUOTES, 'UTF-8') ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
<?php mp_admin_footer(); ?>

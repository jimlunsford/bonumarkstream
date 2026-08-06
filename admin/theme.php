<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/renderer.php';
require_once __DIR__ . '/../_bonumark_stream/app/appearance.php';
require_once __DIR__ . '/_layout.php';
bms_require_login();
bms_require_capability('manage_appearance');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    bms_verify_csrf();
    $action = (string)($_POST['theme_action'] ?? '');
    $slug = function_exists('bms_theme_slug') ? bms_theme_slug((string)($_POST['slug'] ?? 'default')) : 'default';

    try {
        if ($action === 'activate') {
            if (!function_exists('bms_activate_public_theme')) {
                throw new RuntimeException('Theme activation is not available.');
            }
            $theme = bms_activate_public_theme($slug);
            bms_flash('Theme activated: ' . (string)($theme['name'] ?? $slug) . '. Dynamic public routes use the new theme immediately; use Static Site Export only when you want a portable HTML copy.', 'success');
            bms_redirect(bms_admin_url('theme.php'));
        }
        throw new RuntimeException('Unknown theme action.');
    } catch (Throwable $e) {
        bms_log_admin_exception('theme', $e);

        bms_flash('The requested action could not be completed. Please try again.', 'error');
        bms_redirect(bms_admin_url('theme.php'));
    }
}

$activePackage = function_exists('bms_active_public_theme_slug') ? bms_active_public_theme_slug() : 'default';
$packages = function_exists('bms_public_theme_packages') ? bms_public_theme_packages() : ['default' => ['slug' => 'default', 'name' => 'Midnight Ledger', 'description' => 'The default Bonumark Stream public theme.']];
$discoveryIssues = function_exists('bms_public_theme_discovery_issues') ? bms_public_theme_discovery_issues() : [];
$themeCount = count($packages);
$readyThemeCount = 0;
foreach ($packages as $themePackage) {
    $packageHealth = is_array($themePackage['health'] ?? null)
        ? $themePackage['health']
        : (function_exists('bms_public_theme_package_health') ? bms_public_theme_package_health($themePackage) : ['valid' => true]);
    if (!empty($packageHealth['valid'])) {
        $readyThemeCount++;
    }
}
$activeThemeName = (string)($packages[$activePackage]['name'] ?? $activePackage);

bms_admin_header('Themes', [
    ['label' => 'Install Theme', 'href' => bms_admin_url('theme-install.php'), 'style' => 'primary'],
    ['label' => 'View Site', 'href' => bms_url_path(), 'style' => 'secondary', 'target' => true],
]);
?>
<section class="panel appearance-hero-panel">
  <div class="appearance-hero-copy">
    <p class="eyebrow">Site design</p>
    <h2>Choose the public presentation.</h2>
    <p class="meta">Themes control the look of the public site without owning posts, pages, media, accounts, comments, routing, or publishing behavior.</p>
  </div>
  <div class="appearance-hero-actions">
    <a class="button-link secondary" href="<?= htmlspecialchars(bms_admin_url('theme-settings.php'), ENT_QUOTES, 'UTF-8') ?>">Active Theme Settings</a>
    <a class="button-link secondary" href="<?= htmlspecialchars(bms_admin_url('site-identity.php'), ENT_QUOTES, 'UTF-8') ?>">Site Identity</a>
    <a class="button-link secondary" href="<?= htmlspecialchars(bms_admin_url('navigation.php'), ENT_QUOTES, 'UTF-8') ?>">Navigation</a>
  </div>
</section>

<section class="panel appearance-summary-panel" aria-label="Theme summary">
  <div class="appearance-summary-grid">
    <div><span>Active theme</span><strong><?= htmlspecialchars($activeThemeName, ENT_QUOTES, 'UTF-8') ?></strong></div>
    <div><span>Installed themes</span><strong><?= $themeCount ?></strong></div>
    <div><span>Ready to use</span><strong><?= $readyThemeCount ?></strong></div>
    <div><span>Needs attention</span><strong><?= count($discoveryIssues) ?></strong></div>
  </div>
</section>

<?php if ($discoveryIssues): ?>
  <section class="panel appearance-attention-panel">
    <div>
      <p class="eyebrow">Needs attention</p>
      <h2>Some theme folders cannot load.</h2>
      <p class="meta">Bonumark found folders under <code>_bonumark_stream/themes/</code> that do not meet the theme package contract.</p>
    </div>
    <ul class="appearance-issue-list">
      <?php foreach ($discoveryIssues as $issue): ?>
        <li><strong><?= htmlspecialchars((string)($issue['slug'] ?? 'theme'), ENT_QUOTES, 'UTF-8') ?></strong><span><?= htmlspecialchars((string)($issue['message'] ?? 'Invalid theme.'), ENT_QUOTES, 'UTF-8') ?></span></li>
      <?php endforeach; ?>
    </ul>
  </section>
<?php endif; ?>

<section class="appearance-theme-grid" aria-label="Installed themes">
  <?php foreach ($packages as $key => $theme): ?>
    <?php
      $slug = function_exists('bms_theme_slug') ? bms_theme_slug($key) : $key;
      $screenshotUrl = function_exists('bms_public_theme_screenshot_url') ? bms_public_theme_screenshot_url($theme) : '';
      $health = is_array($theme['health'] ?? null) ? $theme['health'] : (function_exists('bms_public_theme_package_health') ? bms_public_theme_package_health($theme) : ['valid' => true, 'label' => 'Ready', 'errors' => [], 'warnings' => []]);
      $summary = function_exists('bms_public_theme_manager_summary') ? bms_public_theme_manager_summary($theme) : ['valid' => !empty($health['valid']), 'asset_total' => 0, 'asset_missing' => 0, 'setting_total' => count((array)($theme['settings'] ?? [])), 'support_total' => 0];
      $isActive = $slug === $activePackage;
      $isValidTheme = !empty($summary['valid']);
      $deleteStatus = function_exists('bms_public_theme_delete_status') ? bms_public_theme_delete_status($slug) : ['can_delete' => false];
      $canDeleteTheme = !empty($deleteStatus['can_delete']);
      $healthErrors = is_array($health['errors'] ?? null) ? $health['errors'] : [];
      $healthWarnings = is_array($health['warnings'] ?? null) ? $health['warnings'] : [];
    ?>
    <article class="panel appearance-theme-card <?= $isActive ? 'is-active' : '' ?> <?= !$isValidTheme ? 'has-errors' : '' ?>">
      <div class="appearance-theme-preview">
        <?php if ($screenshotUrl !== ''): ?>
          <img src="<?= htmlspecialchars($screenshotUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars((string)($theme['name'] ?? $slug), ENT_QUOTES, 'UTF-8') ?> screenshot">
        <?php else: ?>
          <div class="appearance-theme-preview-empty"><span>No screenshot</span></div>
        <?php endif; ?>
        <div class="appearance-theme-status">
          <?php if ($isActive): ?><span class="status-pill published">Active</span><?php endif; ?>
          <span class="status-pill <?= $isValidTheme ? 'published' : 'trash' ?>"><?= htmlspecialchars((string)($health['label'] ?? ($isValidTheme ? 'Ready' : 'Not ready')), ENT_QUOTES, 'UTF-8') ?></span>
          <?php if ($healthWarnings): ?><span class="status-pill draft"><?= count($healthWarnings) ?> warning<?= count($healthWarnings) === 1 ? '' : 's' ?></span><?php endif; ?>
        </div>
      </div>

      <div class="appearance-theme-body">
        <div class="appearance-theme-title-row">
          <div>
            <p class="eyebrow"><?= $isActive ? 'Current design' : 'Installed theme' ?></p>
            <h2><?= htmlspecialchars((string)($theme['name'] ?? $slug), ENT_QUOTES, 'UTF-8') ?></h2>
          </div>
          <code><?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?></code>
        </div>
        <p class="meta appearance-theme-description"><?= htmlspecialchars((string)($theme['description'] ?? 'A Bonumark Stream public theme.'), ENT_QUOTES, 'UTF-8') ?></p>
        <dl class="appearance-theme-facts">
          <div><dt>Version</dt><dd><?= htmlspecialchars((string)($theme['version'] ?? '1.0.0'), ENT_QUOTES, 'UTF-8') ?></dd></div>
          <div><dt>Author</dt><dd><?= htmlspecialchars((string)($theme['author'] ?? 'Bonumark'), ENT_QUOTES, 'UTF-8') ?></dd></div>
          <div><dt>Assets</dt><dd><?= (int)($summary['asset_total'] ?? 0) ?></dd></div>
          <div><dt>Settings</dt><dd><?= (int)($summary['setting_total'] ?? 0) ?></dd></div>
        </dl>
        <?php if ($healthErrors): ?>
          <ul class="appearance-issue-list compact">
            <?php foreach ($healthErrors as $error): ?><li><span><?= htmlspecialchars((string)$error, ENT_QUOTES, 'UTF-8') ?></span></li><?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>

      <footer class="appearance-theme-actions">
        <a class="button-link secondary" href="<?= htmlspecialchars(bms_admin_url('theme-details.php?slug=' . rawurlencode($slug)), ENT_QUOTES, 'UTF-8') ?>">View Details</a>
        <?php if (!$isActive && $isValidTheme): ?>
          <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="theme_action" value="activate">
            <input type="hidden" name="slug" value="<?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit">Activate</button>
          </form>
        <?php elseif ($isActive): ?>
          <a class="button-link secondary" href="<?= htmlspecialchars(bms_admin_url('theme-settings.php'), ENT_QUOTES, 'UTF-8') ?>">Edit Settings</a>
        <?php else: ?>
          <span class="field-help">Fix validation before activation.</span>
        <?php endif; ?>
        <?php if ($canDeleteTheme): ?>
          <a class="button-link danger-link" href="<?= htmlspecialchars(bms_admin_url('theme-delete.php?slug=' . rawurlencode($slug)), ENT_QUOTES, 'UTF-8') ?>">Delete</a>
        <?php endif; ?>
      </footer>
    </article>
  <?php endforeach; ?>
</section>

<?php bms_admin_footer(); ?>

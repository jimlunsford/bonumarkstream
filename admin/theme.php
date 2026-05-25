<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/renderer.php';
require_once __DIR__ . '/../_bonumark_stream/app/appearance.php';
require_once __DIR__ . '/_layout.php';
mp_require_login();
mp_require_capability('manage_appearance');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    mp_verify_csrf();
    $action = (string)($_POST['theme_action'] ?? '');
    $slug = function_exists('mp_theme_slug') ? mp_theme_slug((string)($_POST['slug'] ?? 'default')) : 'default';

    try {
        if ($action === 'activate') {
            if (!function_exists('mp_activate_public_theme')) {
                throw new RuntimeException('Theme activation is not available.');
            }
            $theme = mp_activate_public_theme($slug);
            mp_flash('Theme activated: ' . (string)($theme['name'] ?? $slug) . '. Dynamic public routes use the new theme immediately; use Static Site Export only when you want a portable HTML copy.', 'success');
            mp_redirect(mp_admin_url('theme.php'));
        }
        throw new RuntimeException('Unknown theme action.');
    } catch (Throwable $e) {
        mp_flash($e->getMessage(), 'error');
        mp_redirect(mp_admin_url('theme.php'));
    }
}

$activePackage = function_exists('mp_active_public_theme_slug') ? mp_active_public_theme_slug() : 'default';
$packages = function_exists('mp_public_theme_packages') ? mp_public_theme_packages() : ['default' => ['slug' => 'default', 'name' => 'Midnight Ledger', 'description' => 'The default Bonumark Stream public theme.']];
$discoveryIssues = function_exists('mp_public_theme_discovery_issues') ? mp_public_theme_discovery_issues() : [];
mp_admin_header('Themes', [
    ['label' => 'Install Theme', 'href' => mp_admin_url('theme-install.php'), 'style' => 'primary'],
    ['label' => 'Theme Settings', 'href' => mp_admin_url('theme-settings.php'), 'style' => 'secondary'],
]);
?>
<section class="panel page-intro-panel theme-manager-intro">
  <p class="eyebrow">Appearance</p>
  <h2>Manage public themes.</h2>
  <p class="meta">Install, inspect, activate, configure, and delete Bonumark Stream public themes.</p>
</section>

<?php if ($discoveryIssues): ?>
  <section class="panel settings-panel theme-boundary-note">
    <p class="eyebrow">Needs attention</p>
    <h2>Theme folders that cannot load</h2>
    <p class="meta">These folders exist under `_bonumark_stream/themes/`, but Bonumark will not treat them as installable themes until the issue is corrected.</p>
    <ul class="theme-health-list theme-health-errors">
      <?php foreach ($discoveryIssues as $issue): ?>
        <li><strong><?= htmlspecialchars((string)($issue['slug'] ?? 'theme'), ENT_QUOTES, 'UTF-8') ?></strong>: <?= htmlspecialchars((string)($issue['message'] ?? 'Invalid theme.'), ENT_QUOTES, 'UTF-8') ?></li>
      <?php endforeach; ?>
    </ul>
  </section>
<?php endif; ?>

<section class="theme-card-grid theme-package-grid theme-manager-grid">
  <?php foreach ($packages as $key => $theme): ?>
    <?php
      $slug = function_exists('mp_theme_slug') ? mp_theme_slug($key) : $key;
      $screenshotUrl = function_exists('mp_public_theme_screenshot_url') ? mp_public_theme_screenshot_url($theme) : '';
      $health = is_array($theme['health'] ?? null) ? $theme['health'] : (function_exists('mp_public_theme_package_health') ? mp_public_theme_package_health($theme) : ['valid' => true, 'label' => 'Ready', 'errors' => [], 'warnings' => []]);
      $summary = function_exists('mp_public_theme_manager_summary') ? mp_public_theme_manager_summary($theme) : ['valid' => !empty($health['valid']), 'status_class' => !empty($health['valid']) ? 'ready' : 'needs-attention', 'template_total' => count((array)($theme['templates'] ?? [])), 'template_missing' => 0, 'asset_total' => 0, 'asset_missing' => 0, 'setting_total' => count((array)($theme['settings'] ?? []))];
      $isActive = $slug === $activePackage;
      $isValidTheme = !empty($summary['valid']);
      $deleteStatus = function_exists('mp_public_theme_delete_status') ? mp_public_theme_delete_status($slug) : ['can_delete' => false];
      $canDeleteTheme = !empty($deleteStatus['can_delete']);
      $statusClass = preg_replace('/[^a-z0-9_-]+/i', '-', (string)($summary['status_class'] ?? 'ready'));
      $healthErrors = is_array($health['errors'] ?? null) ? $health['errors'] : [];
      $healthWarnings = is_array($health['warnings'] ?? null) ? $health['warnings'] : [];
    ?>
    <article class="panel theme-card theme-package-card theme-manager-card <?= $isActive ? 'active-theme' : '' ?> <?= !$isValidTheme ? 'invalid-theme' : '' ?> status-<?= htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8') ?>">
      <div class="theme-card-media">
        <?php if ($screenshotUrl !== ''): ?>
          <img class="theme-card-screenshot" src="<?= htmlspecialchars($screenshotUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars((string)($theme['name'] ?? $slug), ENT_QUOTES, 'UTF-8') ?> screenshot">
        <?php else: ?>
          <div class="theme-preview theme-preview-empty"><span>No screenshot</span></div>
        <?php endif; ?>
        <div class="theme-card-badges">
          <?php if ($isActive): ?><span class="status-pill published">Active</span><?php endif; ?>
          <span class="status-pill <?= $isValidTheme ? 'published' : 'trash' ?>"><?= htmlspecialchars((string)($health['label'] ?? ($isValidTheme ? 'Safe to activate' : 'Not safe to activate')), ENT_QUOTES, 'UTF-8') ?></span>
        </div>
      </div>

      <div class="theme-card-body">
        <h3><?= htmlspecialchars((string)($theme['name'] ?? $slug), ENT_QUOTES, 'UTF-8') ?></h3>
        <p class="meta"><?= htmlspecialchars((string)($theme['description'] ?? 'A Bonumark Stream public theme.'), ENT_QUOTES, 'UTF-8') ?></p>

        <div class="theme-meta-list compact-theme-meta">
          <span><strong>Slug</strong> <code><?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?></code></span>
          <span><strong>Version</strong> <code><?= htmlspecialchars((string)($theme['version'] ?? '1.0.0'), ENT_QUOTES, 'UTF-8') ?></code></span>
          <span><strong>Author</strong> <code><?= htmlspecialchars((string)($theme['author'] ?? 'Bonumark'), ENT_QUOTES, 'UTF-8') ?></code></span>
        </div>

        <p class="theme-card-summary meta">
          <?= (int)($summary['template_total'] ?? 0) ?> templates · <?= (int)($summary['asset_total'] ?? 0) ?> assets · <?= (int)($summary['setting_total'] ?? 0) ?> settings
          <?php if ((int)($summary['template_missing'] ?? 0) > 0): ?> · <?= (int)$summary['template_missing'] ?> missing templates<?php endif; ?>
          <?php if ((int)($summary['asset_missing'] ?? 0) > 0): ?> · <?= (int)$summary['asset_missing'] ?> missing assets<?php endif; ?>
          <?php if ($healthWarnings): ?> · <?= count($healthWarnings) ?> warning<?= count($healthWarnings) === 1 ? '' : 's' ?><?php endif; ?>
        </p>

        <?php if ($healthErrors): ?>
          <ul class="theme-health-list theme-health-errors">
            <?php foreach ($healthErrors as $error): ?><li><?= htmlspecialchars((string)$error, ENT_QUOTES, 'UTF-8') ?></li><?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>

      <div class="theme-card-actions theme-manager-actions">
        <a class="button-link secondary" href="<?= htmlspecialchars(mp_admin_url('theme-details.php?slug=' . rawurlencode($slug)), ENT_QUOTES, 'UTF-8') ?>">Details</a>
        <?php if (!$isActive && $isValidTheme): ?>
          <form method="post" class="inline-theme-action-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(mp_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="theme_action" value="activate">
            <input type="hidden" name="slug" value="<?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit">Activate</button>
          </form>
        <?php elseif (!$isValidTheme): ?>
          <span class="field-help">Fix validation before activation</span>
        <?php else: ?>
          <a class="button-link secondary" href="<?= htmlspecialchars(mp_admin_url('theme-settings.php'), ENT_QUOTES, 'UTF-8') ?>">Settings</a>
        <?php endif; ?>
        <?php if ($canDeleteTheme): ?>
          <a class="button-link danger-link" href="<?= htmlspecialchars(mp_admin_url('theme-delete.php?slug=' . rawurlencode($slug)), ENT_QUOTES, 'UTF-8') ?>">Delete</a>
        <?php endif; ?>
      </div>
    </article>
  <?php endforeach; ?>
</section>

<?php mp_admin_footer(); ?>

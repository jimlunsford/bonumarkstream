<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/renderer.php';
require_once __DIR__ . '/../_bonumark_stream/app/appearance.php';
require_once __DIR__ . '/_layout.php';
bms_require_login();
bms_require_capability('view_system');

$slug = function_exists('bms_theme_slug') ? bms_theme_slug((string)($_GET['slug'] ?? $_POST['slug'] ?? '')) : '';
$packages = function_exists('bms_public_theme_packages') ? bms_public_theme_packages() : [];
$theme = $packages[$slug] ?? null;
$status = function_exists('bms_public_theme_delete_status') ? bms_public_theme_delete_status($slug) : ['can_delete' => false, 'errors' => ['Theme deletion is not available.']];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    bms_verify_csrf();
    try {
        $confirm = trim((string)($_POST['confirm_slug'] ?? ''));
        if ($confirm !== $slug) {
            throw new RuntimeException('Confirmation did not match the theme slug.');
        }
        $result = bms_delete_public_theme($slug);
        $deleted = is_array($result['deleted'] ?? null) ? $result['deleted'] : [];
        $message = 'Theme deleted: ' . $slug . '.';
        if ($deleted) {
            $message .= ' Removed ' . implode(' and ', $deleted) . '.';
        }
        bms_flash($message, 'success');
        bms_redirect(bms_admin_url('theme.php'));
    } catch (Throwable $e) {
        bms_log_admin_exception('theme-delete', $e);

        bms_flash('Theme delete failed. Please try again.', 'error');
        bms_redirect(bms_admin_url('theme-delete.php?slug=' . rawurlencode($slug)));
    }
}

$name = is_array($theme) ? (string)($theme['name'] ?? $slug) : $slug;
$errors = is_array($status['errors'] ?? null) ? $status['errors'] : [];
$screenshotUrl = is_array($theme) && function_exists('bms_public_theme_screenshot_url') ? bms_public_theme_screenshot_url($theme) : '';

bms_admin_header('Delete Theme', [
    ['label' => 'Themes', 'href' => bms_admin_url('theme.php'), 'style' => 'secondary'],
    ['label' => 'Install Theme', 'href' => bms_admin_url('theme-install.php'), 'style' => 'secondary'],
]);
?>
<section class="panel page-intro-panel danger-zone">
  <p class="eyebrow">Appearance</p>
  <h2>Delete public theme</h2>
  <p class="meta">Theme deletion removes the private theme folder and its public assets. This does not delete posts, users, media, comments, feeds, settings outside this theme, or core files.</p>
</section>

<section class="panel settings-panel theme-delete-panel">
  <p class="eyebrow">Theme</p>
  <h2><?= htmlspecialchars($name !== '' ? $name : 'Unknown theme', ENT_QUOTES, 'UTF-8') ?></h2>
  <?php if ($screenshotUrl !== ''): ?>
    <img class="theme-settings-screenshot theme-delete-screenshot" src="<?= htmlspecialchars($screenshotUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?> screenshot">
  <?php endif; ?>
  <div class="theme-meta-list">
    <span><strong>Slug</strong> <code><?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?></code></span>
    <span><strong>Private folder</strong> <code>_bonumark_stream/themes/<?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?></code></span>
    <span><strong>Public assets</strong> <code>assets/themes/<?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?></code></span>
  </div>

  <?php if (!$slug || $errors): ?>
    <div class="alert error">
      <strong>This theme cannot be deleted.</strong>
      <ul>
        <?php foreach ($errors ?: ['Invalid theme slug.'] as $error): ?>
          <li><?= htmlspecialchars((string)$error, ENT_QUOTES, 'UTF-8') ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <p><a class="button-link secondary" href="<?= htmlspecialchars(bms_admin_url('theme.php'), ENT_QUOTES, 'UTF-8') ?>">Back to Themes</a></p>
  <?php else: ?>
    <div class="alert warning">
      <strong>Confirm deletion.</strong> This cannot be undone from inside Bonumark. Keep a copy of the theme ZIP if you may need it again.
    </div>
    <form method="post" class="settings-form theme-delete-form">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="slug" value="<?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?>">
      <label for="confirm_slug">Type the theme slug to confirm</label>
      <input id="confirm_slug" type="text" name="confirm_slug" required placeholder="<?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?>" autocomplete="off">
      <p class="field-help">Bonumark blocks deletion of the active theme and protected core themes. Optional themes can be removed when they are not active.</p>
      <div class="form-actions-row">
        <button type="submit" class="danger">Delete Theme</button>
        <a class="button-link secondary" href="<?= htmlspecialchars(bms_admin_url('theme.php'), ENT_QUOTES, 'UTF-8') ?>">Cancel</a>
      </div>
    </form>
  <?php endif; ?>
</section>
<?php bms_admin_footer(); ?>

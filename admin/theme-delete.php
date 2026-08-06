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
]);
?>
<section class="panel appearance-hero-panel appearance-danger-hero">
  <div class="appearance-hero-copy">
    <p class="eyebrow">Theme deletion</p>
    <h2>Remove <?= htmlspecialchars($name !== '' ? $name : 'theme', ENT_QUOTES, 'UTF-8') ?>.</h2>
    <p class="meta">Deleting a theme removes its private package and public assets. It does not remove content, accounts, media, comments, navigation, or unrelated settings.</p>
  </div>
  <span class="status-pill trash">Permanent</span>
</section>

<section class="panel appearance-delete-panel">
  <div class="appearance-delete-summary">
    <?php if ($screenshotUrl !== ''): ?><img src="<?= htmlspecialchars($screenshotUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?> screenshot"><?php endif; ?>
    <div>
      <p class="eyebrow">Selected theme</p>
      <h2><?= htmlspecialchars($name !== '' ? $name : 'Unknown theme', ENT_QUOTES, 'UTF-8') ?></h2>
      <dl class="appearance-detail-facts compact">
        <div><dt>Slug</dt><dd><code><?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?></code></dd></div>
        <div><dt>Private package</dt><dd><code>_bonumark_stream/themes/<?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?></code></dd></div>
        <div><dt>Public assets</dt><dd><code>assets/themes/<?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?></code></dd></div>
      </dl>
    </div>
  </div>

  <?php if (!$slug || $errors): ?>
    <div class="appearance-blocked-state">
      <p class="eyebrow">Deletion blocked</p>
      <h3>This theme cannot be deleted.</h3>
      <ul class="appearance-issue-list"><?php foreach ($errors ?: ['Invalid theme slug.'] as $error): ?><li><span><?= htmlspecialchars((string)$error, ENT_QUOTES, 'UTF-8') ?></span></li><?php endforeach; ?></ul>
      <a class="button-link secondary" href="<?= htmlspecialchars(bms_admin_url('theme.php'), ENT_QUOTES, 'UTF-8') ?>">Back to Themes</a>
    </div>
  <?php else: ?>
    <form method="post" class="appearance-delete-form">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="slug" value="<?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?>">
      <div class="appearance-delete-warning"><strong>Keep a copy of the theme ZIP.</strong><span>This action cannot be reversed from inside Bonumark.</span></div>
      <label for="confirm_slug">Type <code><?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?></code> to confirm</label>
      <input id="confirm_slug" type="text" name="confirm_slug" required autocomplete="off" spellcheck="false">
      <p class="field-help">Active and protected core themes remain blocked from deletion.</p>
      <div class="appearance-form-actions"><button type="submit" class="danger">Delete Theme</button><a class="button-link secondary" href="<?= htmlspecialchars(bms_admin_url('theme.php'), ENT_QUOTES, 'UTF-8') ?>">Cancel</a></div>
    </form>
  <?php endif; ?>
</section>
<?php bms_admin_footer(); ?>

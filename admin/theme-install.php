<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/renderer.php';
require_once __DIR__ . '/../_bonumark_stream/app/theme-installer.php';
require_once __DIR__ . '/_layout.php';
mp_require_login();
mp_require_capability('view_system');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    mp_verify_csrf();
    try {
        $replaceExisting = !empty($_POST['replace_existing']);
        $activate = !empty($_POST['activate_after_install']);
        $result = mp_install_public_theme_zip($_FILES['theme_zip'] ?? [], $replaceExisting, $activate);
        $message = 'Theme installed: ' . (string)($result['name'] ?? $result['slug']) . ', v' . (string)($result['version'] ?? '1.0.0') . '.';
        if (!empty($result['activated'])) {
            $message .= ' It was activated and dynamic public routes will use it immediately.';
        }
        mp_flash($message, 'success');
        mp_redirect(mp_admin_url('theme.php'));
    } catch (Throwable $e) {
        mp_flash('Theme install failed: ' . $e->getMessage(), 'error');
    }
}

$zipAvailable = class_exists('ZipArchive');
$uploadMax = ini_get('upload_max_filesize') ?: 'server limit';
$postMax = ini_get('post_max_size') ?: 'server limit';

mp_admin_header('Install Theme', [
    ['label' => 'Themes', 'href' => mp_admin_url('theme.php'), 'style' => 'secondary'],
    ['label' => 'Theme Settings', 'href' => mp_admin_url('theme-settings.php'), 'style' => 'secondary'],
]);
?>
<section class="panel page-intro-panel">
  <p class="eyebrow">Appearance</p>
  <h2>Install a Bonumark Stream theme ZIP.</h2>
  <p class="meta">Theme uploads are validated before installation. Bonumark installs private templates under `_bonumark_stream/themes/` and public assets under `assets/themes/`.</p>
</section>

<section class="panel settings-panel">
  <p class="eyebrow">Theme ZIP</p>
  <h2>Upload theme package</h2>
  <?php if (!$zipAvailable): ?>
    <p class="alert error">PHP ZipArchive is not available on this server. Theme ZIP uploads cannot run until your host enables it.</p>
  <?php endif; ?>
  <form method="post" enctype="multipart/form-data" class="settings-form theme-install-form">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(mp_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <label for="theme_zip">Theme ZIP file</label>
    <input id="theme_zip" type="file" name="theme_zip" accept=".zip,application/zip,application/x-zip-compressed" required <?= !$zipAvailable ? 'disabled' : '' ?>>
    <p class="field-help">Maximum size depends on your server. Current PHP limits: upload <?= htmlspecialchars((string)$uploadMax, ENT_QUOTES, 'UTF-8') ?>, post <?= htmlspecialchars((string)$postMax, ENT_QUOTES, 'UTF-8') ?>.</p>

    <label class="checkbox-row">
      <input type="checkbox" name="replace_existing" value="1" <?= !$zipAvailable ? 'disabled' : '' ?>>
      Update this theme if it already exists
    </label>
    <p class="field-help">Optional themes can be updated. Core themes are protected.</p>

    <label class="checkbox-row">
      <input type="checkbox" name="activate_after_install" value="1" <?= !$zipAvailable ? 'disabled' : '' ?>>
      Activate after install
    </label>

    <div class="form-actions-row">
      <button type="submit" <?= !$zipAvailable ? 'disabled' : '' ?>>Install Theme</button>
      <a class="button-link secondary" href="<?= htmlspecialchars(mp_admin_url('theme.php'), ENT_QUOTES, 'UTF-8') ?>">Cancel</a>
    </div>
  </form>
</section>

<section class="panel settings-panel">
  <p class="eyebrow">Package rules</p>
  <h2>Accepted Bonumark theme structure</h2>
  <p class="meta">A theme ZIP should contain one theme only. The simplest structure is:</p>
  <pre><code>theme-name/
  theme.json
  templates/
    layout.php
    header.php
    footer.php
    home.php
    archive.php
    single.php
    page.php
    profile.php
    account.php
    comments.php
    comments-mount.php
    search.php
    card.php
    link-preview.php
    media.php
    composer.php
    pagination.php
    empty.php
  assets/
    css/theme.css
    js/theme.js
    images/screenshot.svg
  README.md</code></pre>
  <p class="field-help">The installer blocks unsafe paths, symbolic links, unsupported file types, missing required templates, missing declared assets, and invalid manifests before activation.</p>
</section>
<?php mp_admin_footer(); ?>

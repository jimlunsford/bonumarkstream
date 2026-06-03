<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/renderer.php';
require_once __DIR__ . '/../_bonumark_stream/app/theme-installer.php';
require_once __DIR__ . '/_layout.php';
bms_require_login();
bms_require_capability('manage_appearance');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    bms_verify_csrf();
    try {
        $replaceExisting = !empty($_POST['replace_existing']);
        $activate = !empty($_POST['activate_theme']);
        $result = bms_install_public_theme_zip($_FILES['theme_zip'] ?? [], $replaceExisting, $activate);
        $message = 'Theme installed: ' . (string)($result['name'] ?? $result['slug'] ?? 'theme') . ', v' . (string)($result['version'] ?? '1.0.0') . '.';
        if (!empty($result['activated'])) {
            $message .= ' It is now active.';
        }
        bms_flash($message, 'success');
        bms_redirect(bms_admin_url('theme.php'));
    } catch (Throwable $e) {
        bms_flash('Theme install failed: ' . $e->getMessage(), 'error');
        bms_redirect(bms_admin_url('theme-install.php'));
    }
}

bms_admin_header('Install Theme', [
    ['label' => 'Themes', 'href' => bms_admin_url('theme.php'), 'style' => 'secondary'],
    ['label' => 'Theme Settings', 'href' => bms_admin_url('theme-settings.php'), 'style' => 'secondary'],
]);
?>
<section class="panel page-intro-panel">
  <p class="eyebrow">Appearance</p>
  <h2>Install a code-free presentation theme.</h2>
  <p class="meta">Bonumark Stream themes are presentation packages only. Core owns routing, rendering, permissions, data access, users, media, comments, importers, and publishing logic.</p>
</section>

<section class="panel settings-panel">
  <p class="eyebrow">Theme ZIP</p>
  <h2>Upload theme package</h2>
  <p class="meta">A valid theme ZIP must include a <code>theme.json</code> manifest and may include CSS, images, fonts, screenshots, and documentation. PHP, JavaScript, HTML files, server config files, symlinks, and executable code are rejected.</p>

  <form method="post" enctype="multipart/form-data" class="settings-form theme-install-form">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">

    <div class="form-field">
      <label for="theme_zip">Theme ZIP</label>
      <input id="theme_zip" type="file" name="theme_zip" accept=".zip,application/zip" required>
      <p class="field-help">Upload one code-free theme at a time. Protected bundled themes cannot be replaced through the uploader.</p>
    </div>

    <label class="checkbox-line" for="replace_existing">
      <input id="replace_existing" type="checkbox" name="replace_existing" value="1">
      <span>Update this theme if the slug already exists</span>
    </label>

    <label class="checkbox-line" for="activate_theme">
      <input id="activate_theme" type="checkbox" name="activate_theme" value="1">
      <span>Activate after install</span>
    </label>

    <div class="form-actions-row">
      <button type="submit">Install Theme</button>
      <a class="button-link secondary" href="<?= htmlspecialchars(bms_admin_url('theme.php'), ENT_QUOTES, 'UTF-8') ?>">Cancel</a>
    </div>
  </form>
</section>

<section class="panel settings-panel">
  <p class="eyebrow">Allowed structure</p>
  <h2>Theme packages cannot run code.</h2>
  <pre><code>theme-name/
  theme.json
  README.md
  assets/
    css/theme.css
    images/screenshot.svg</code></pre>
  <p class="meta">The manifest declares metadata, assets, supports, and editable settings. Bonumark Stream core renders the public site; the theme supplies presentation only.</p>
</section>
<?php bms_admin_footer(); ?>

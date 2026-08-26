<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/renderer.php';
require_once __DIR__ . '/../_bonumark_stream/app/theme-installer.php';
require_once __DIR__ . '/_layout.php';
bms_require_login();
bms_require_capability('manage_appearance');

$themeInstallCapability = bms_theme_zip_install_capability();
$themeInstallAvailable = !empty($themeInstallCapability['available']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    bms_verify_csrf();
    if (!$themeInstallAvailable) {
        bms_flash((string)($themeInstallCapability['message'] ?? 'Admin theme ZIP installation is unavailable on this hosting configuration.'), 'warning');
        bms_redirect(bms_admin_url('theme-install.php'));
    }
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
        bms_log_admin_exception('theme-install', $e);

        bms_flash('Theme install failed. Please try again.', 'error');
        bms_redirect(bms_admin_url('theme-install.php'));
    }
}

bms_admin_header('Install Theme', [
    ['label' => 'Themes', 'href' => bms_admin_url('theme.php'), 'style' => 'secondary'],
]);
?>
<section class="panel appearance-hero-panel">
  <div class="appearance-hero-copy">
    <p class="eyebrow">Theme installation</p>
    <h2>Add a code-free presentation package.</h2>
    <p class="meta">Theme ZIPs can provide metadata, CSS, images, screenshots, fonts, documentation, settings declarations, and validated private JSON layout files. Core keeps control of application behavior.</p>
  </div>
  <span class="status-pill published">Code-free only</span>
</section>
<?php if (!$themeInstallAvailable): ?>
<section class="panel">
  <p class="eyebrow">Hosting capability</p>
  <h2>Theme ZIP installation requires manual deployment here.</h2>
  <p class="meta"><?= htmlspecialchars((string)($themeInstallCapability['message'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
</section>
<?php endif; ?>

<div class="appearance-install-layout">
  <section class="panel appearance-upload-panel">
    <p class="eyebrow">Theme ZIP</p>
    <h2>Upload package</h2>
    <p class="meta">Bonumark validates the archive before installing it. Executable code and server configuration files are rejected.</p>
    <form method="post" enctype="multipart/form-data" class="appearance-upload-form">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
      <label class="appearance-file-drop" for="theme_zip">
        <span class="appearance-file-drop-title">Choose a theme ZIP</span>
        <span class="field-help">The archive must contain a <code>theme.json</code> manifest.</span>
        <input id="theme_zip" type="file" name="theme_zip" accept=".zip,application/zip" required <?= $themeInstallAvailable ? '' : 'disabled' ?>>
      </label>
      <div class="appearance-option-list">
        <label class="appearance-toggle-card" for="replace_existing"><input id="replace_existing" type="checkbox" name="replace_existing" value="1" <?= $themeInstallAvailable ? '' : 'disabled' ?>><span><strong>Update existing theme</strong><small>Replace an optional installed theme when the slug matches.</small></span></label>
        <label class="appearance-toggle-card" for="activate_theme"><input id="activate_theme" type="checkbox" name="activate_theme" value="1" <?= $themeInstallAvailable ? '' : 'disabled' ?>><span><strong>Activate after install</strong><small>Use the theme immediately after validation and installation.</small></span></label>
      </div>
      <div class="appearance-form-actions"><button type="submit" <?= $themeInstallAvailable ? '' : 'disabled' ?>>Install Theme</button><a class="button-link secondary" href="<?= htmlspecialchars(bms_admin_url('theme.php'), ENT_QUOTES, 'UTF-8') ?>">Cancel</a></div>
    </form>
  </section>

  <aside class="panel appearance-package-rules-panel">
    <p class="eyebrow">Package contract</p>
    <h2>Presentation, not application code.</h2>
    <ul class="appearance-rule-list">
      <li><strong>Required</strong><span><code>theme.json</code> with valid theme metadata.</span></li>
      <li><strong>Allowed</strong><span>CSS, images, fonts, screenshots, Markdown, text documentation, and declared private <code>layouts/*.json</code> files.</span></li>
      <li><strong>Rejected</strong><span>PHP, JavaScript, HTML, server configuration, symlinks, and executable files.</span></li>
      <li><strong>Protected</strong><span>Bundled core themes cannot be replaced through the uploader.</span></li>
    </ul>
    <pre class="appearance-package-tree"><code>theme-name/
  theme.json
  README.md
  assets/
    css/theme.css
    images/screenshot.svg
  layouts/
    profile.json</code></pre>
  </aside>
</div>
<?php bms_admin_footer(); ?>

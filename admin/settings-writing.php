<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/_layout.php';
bms_require_login();
bms_require_capability('manage_settings');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    bms_verify_csrf();
    $defaultEditor = (string)($_POST['default_editor_mode'] ?? 'visual');
    if (!in_array($defaultEditor, ['visual', 'markdown'], true)) {
        $defaultEditor = 'visual';
    }
    $defaultStatus = (string)($_POST['default_content_status'] ?? 'draft');
    if (!in_array($defaultStatus, ['draft', 'published'], true)) {
        $defaultStatus = 'draft';
    }
    $autosaveEnabled = isset($_POST['autosave_enabled']) ? '1' : '0';
    $mediaLimit = max(1, min(128, (int)($_POST['media_upload_limit_mb'] ?? 32)));

    try {
        bms_set_setting('default_editor_mode', $defaultEditor);
        bms_set_setting('default_content_status', $defaultStatus);
        bms_set_setting('media_upload_limit_mb', (string)$mediaLimit);
        bms_set_setting('autosave_enabled', $autosaveEnabled);
        bms_flash('Writing settings saved. New stream posts will use these defaults.', 'success');
        bms_redirect(bms_admin_url('settings-writing.php'));
    } catch (Throwable $e) {
        bms_flash('Could not save writing settings: ' . $e->getMessage(), 'error');
    }
}

$defaultEditor = (string)bms_setting_or_config('default_editor_mode', 'visual');
$defaultStatus = (string)bms_setting_or_config('default_content_status', 'draft');
$mediaLimit = (int)bms_setting_or_config('media_upload_limit_mb', '32');
$autosaveEnabled = (string)bms_setting_or_config('autosave_enabled', '1') === '1';
bms_admin_header('Writing Settings', []);
?>
<section class="panel page-intro-panel">
  <p class="eyebrow">Settings</p>
  <h2>Writing</h2>
  <p class="meta">Control the Stream editor. Admin is the sole publisher. Commenter accounts can participate through comments when allowed.</p>
</section>

<section class="panel settings-panel">
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">

    <label for="default_editor_mode">Default editor mode</label>
    <select id="default_editor_mode" name="default_editor_mode">
      <option value="visual" <?= $defaultEditor === 'visual' ? 'selected' : '' ?>>Visual</option>
      <option value="markdown" <?= $defaultEditor === 'markdown' ? 'selected' : '' ?>>Markdown</option>
    </select>
    <p class="field-help">Admin can still switch between Visual, Markdown, and Preview on each stream post.</p>

    <label for="default_content_status">Default stream post status</label>
    <select id="default_content_status" name="default_content_status">
      <option value="draft" <?= $defaultStatus === 'draft' ? 'selected' : '' ?>>Save new admin posts as Draft</option>
      <option value="published" <?= $defaultStatus === 'published' ? 'selected' : '' ?>>Publish new admin posts immediately</option>
    </select>
    <p class="field-help">This setting controls the New Stream Post screen. Front-page composer posts publish immediately.</p>

    <label for="media_upload_limit_mb">Admin media upload limit</label>
    <input type="number" id="media_upload_limit_mb" name="media_upload_limit_mb" min="1" max="128" value="<?= (int)$mediaLimit ?>">
    <p class="field-help">Limit is measured in megabytes. Server upload limits can still be lower.</p>

    <label class="checkbox-line"><input type="checkbox" name="autosave_enabled" value="1" <?= $autosaveEnabled ? 'checked' : '' ?>> Enable server autosave and recovery prompts in the editor</label>
    <p class="field-help">Bonumark Stream saves autosaves to the server first so recovery can follow your account, while keeping a browser backup only if the server save fails.</p>

    <button type="submit">Save Writing Settings</button>
  </form>
</section>

<section class="panel">
  <h2>Current writing support</h2>
  <div class="info-grid">
    <div class="info-card"><strong>Sole publisher</strong><p>Only the Admin account can write, edit, publish, import, export, and manage Stream content.</p></div>
    <div class="info-card"><strong>Database-first source</strong><p>Every stream post is stored in the database first. Markdown export keeps your content portable.</p></div>
    <div class="info-card"><strong>Commenter accounts</strong><p>Commenters can participate through comments and account features when those settings are enabled.</p></div>
  </div>
</section>
<?php bms_admin_footer(); ?>

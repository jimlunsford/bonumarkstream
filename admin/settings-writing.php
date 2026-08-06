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
    $autosaveEnabled = isset($_POST['autosave_enabled']) ? '1' : '0';
    $mediaLimit = max(1, min(128, (int)($_POST['media_upload_limit_mb'] ?? 32)));
    $mediaPrivacyMode = (string)($_POST['media_privacy_mode'] ?? 'best_effort');
    if (!in_array($mediaPrivacyMode, ['best_effort', 'strict'], true)) {
        $mediaPrivacyMode = 'best_effort';
    }

    try {
        bms_set_setting('default_editor_mode', $defaultEditor);
        bms_set_setting('media_upload_limit_mb', (string)$mediaLimit);
        bms_set_setting('media_privacy_mode', $mediaPrivacyMode);
        bms_set_setting('autosave_enabled', $autosaveEnabled);
        bms_flash('Full-editor writing settings saved.', 'success');
        bms_redirect(bms_admin_url('settings-writing.php'));
    } catch (Throwable $e) {
        bms_log_admin_exception('settings-writing', $e);
        bms_flash('Could not save writing settings. Please try again.', 'error');
    }
}

$defaultEditor = (string)bms_setting_or_config('default_editor_mode', 'visual');
$mediaLimit = (int)bms_setting_or_config('media_upload_limit_mb', '32');
$mediaPrivacyMode = (string)bms_setting_or_config('media_privacy_mode', 'best_effort');
if (!in_array($mediaPrivacyMode, ['best_effort', 'strict'], true)) {
    $mediaPrivacyMode = 'best_effort';
}
$autosaveEnabled = (string)bms_setting_or_config('autosave_enabled', '1') === '1';

bms_admin_header('Writing Settings', [
    ['label' => 'Stream Composer', 'href' => bms_url_path(), 'style' => 'secondary', 'target' => true],
    ['label' => 'Media', 'href' => bms_admin_url('media.php'), 'style' => 'secondary'],
]);
?>
<section class="panel settings-workflow-hero">
  <div class="settings-workflow-hero-copy">
    <p class="eyebrow">Settings</p>
    <h2>Control the full writing workspace.</h2>
    <p class="meta">New Stream Posts begin in the stream composer. These settings control the full editor used for saved drafts, existing posts, uploads, privacy, and recovery.</p>
  </div>
  <span class="static-pill generated">WRITING</span>
</section>

<section class="panel settings-summary-panel">
  <div class="settings-summary-grid">
    <div><span>Default editor</span><strong><?= htmlspecialchars(ucfirst($defaultEditor), ENT_QUOTES, 'UTF-8') ?></strong></div>
    <div><span>Autosave</span><strong><?= $autosaveEnabled ? 'Enabled' : 'Disabled' ?></strong></div>
    <div><span>Upload limit</span><strong><?= (int)$mediaLimit ?> MB</strong></div>
    <div><span>Media privacy</span><strong><?= $mediaPrivacyMode === 'strict' ? 'Strict' : 'Best effort' ?></strong></div>
  </div>
</section>

<section class="panel settings-section-panel">
  <div class="settings-section-header">
    <div><p class="eyebrow">Writing defaults</p><h2>Editor and media behavior</h2><p class="meta">Set safe defaults without removing the controls available inside each post editor.</p></div>
  </div>
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <div class="settings-field-grid">
      <div class="settings-field-card">
        <label for="default_editor_mode">Default editor mode</label>
        <select id="default_editor_mode" name="default_editor_mode">
          <option value="visual" <?= $defaultEditor === 'visual' ? 'selected' : '' ?>>Visual</option>
          <option value="markdown" <?= $defaultEditor === 'markdown' ? 'selected' : '' ?>>Markdown</option>
        </select>
        <p class="field-help">The editor can still switch between Visual, Markdown, and Preview for each post.</p>
      </div>
      <div class="settings-field-card">
        <label for="media_upload_limit_mb">Admin media upload limit</label>
        <input type="number" id="media_upload_limit_mb" name="media_upload_limit_mb" min="1" max="128" value="<?= (int)$mediaLimit ?>">
        <p class="field-help">Measured in megabytes. Server upload limits can still be lower.</p>
      </div>
      <div class="settings-field-card">
        <label for="media_privacy_mode">Media privacy mode</label>
        <select id="media_privacy_mode" name="media_privacy_mode">
          <option value="best_effort" <?= $mediaPrivacyMode === 'best_effort' ? 'selected' : '' ?>>Best effort, recommended</option>
          <option value="strict" <?= $mediaPrivacyMode === 'strict' ? 'selected' : '' ?>>Strict privacy</option>
        </select>
        <p class="field-help">Best effort randomizes filenames and attempts metadata removal. Strict rejects supported images when removal cannot be confirmed.</p>
      </div>
    </div>

    <div class="settings-option-list">
      <label class="settings-option-card">
        <input type="checkbox" name="autosave_enabled" value="1" <?= $autosaveEnabled ? 'checked' : '' ?>>
        <span class="settings-option-copy"><strong>Enable server autosave and recovery prompts</strong><small>Autosaves follow the signed-in account. A browser backup is used only when the server save fails.</small></span>
      </label>
    </div>

    <div class="settings-save-bar">
      <div><strong>Save writing settings</strong><p class="meta">New editor sessions use the updated defaults.</p></div>
      <button type="submit">Save Writing Settings</button>
    </div>
  </form>
</section>

<section class="settings-support-grid">
  <div class="settings-support-card"><h3>Unified creation</h3><p>New Stream Posts begin in the stream composer. Save a draft or continue in the full editor when the post needs more work.</p></div>
  <div class="settings-support-card"><h3>Database-first source</h3><p>Every Stream Post is stored in the database first. Markdown export keeps the content portable.</p></div>
  <div class="settings-support-card"><h3>Privacy-aware media</h3><p>Public filenames are randomized and image metadata handling follows the selected privacy mode.</p></div>
</section>
<?php bms_admin_footer(); ?>

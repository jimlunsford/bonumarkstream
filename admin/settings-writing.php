<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/_layout.php';
mp_require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    mp_verify_csrf();
    $defaultEditor = (string)($_POST['default_editor_mode'] ?? 'visual');
    if (!in_array($defaultEditor, ['visual', 'markdown'], true)) {
        $defaultEditor = 'visual';
    }
    $defaultStatus = (string)($_POST['default_content_status'] ?? 'draft');
    if (!in_array($defaultStatus, ['draft', 'published'], true)) {
        $defaultStatus = 'draft';
    }
    $autosaveEnabled = isset($_POST['autosave_enabled']) ? '1' : '0';
    $userPublishMode = (string)($_POST['user_publish_mode'] ?? 'draft_review');
    if (!in_array($userPublishMode, ['direct', 'draft_review'], true)) {
        $userPublishMode = 'draft_review';
    }
    $adminMediaLimit = max(1, min(128, (int)($_POST['media_limit_administrator_mb'] ?? 32)));
    $userMediaLimit = max(1, min(128, (int)($_POST['media_limit_user_mb'] ?? 8)));
    $commenterMediaLimit = max(1, min(128, (int)($_POST['media_limit_commenter_mb'] ?? 2)));

    try {
        mp_set_setting('default_editor_mode', $defaultEditor);
        mp_set_setting('default_content_status', $defaultStatus);
        mp_set_setting('user_publish_mode', $userPublishMode);
        mp_set_setting('media_limit_administrator_mb', (string)$adminMediaLimit);
        mp_set_setting('media_limit_user_mb', (string)$userMediaLimit);
        mp_set_setting('media_limit_commenter_mb', (string)$commenterMediaLimit);
        mp_set_setting('autosave_enabled', $autosaveEnabled);
        mp_flash('Writing settings saved. New stream posts will use these defaults.', 'success');
        mp_redirect(mp_admin_url('settings-writing.php'));
    } catch (Throwable $e) {
        mp_flash('Could not save writing settings: ' . $e->getMessage(), 'error');
    }
}

$defaultEditor = (string)mp_setting_or_config('default_editor_mode', 'visual');
$defaultStatus = (string)mp_setting_or_config('default_content_status', 'draft');
$userPublishMode = function_exists('mp_user_publish_mode') ? mp_user_publish_mode() : (string)mp_setting_or_config('user_publish_mode', 'draft_review');
$adminMediaLimit = (int)mp_setting_or_config('media_limit_administrator_mb', '32');
$userMediaLimit = (int)mp_setting_or_config('media_limit_user_mb', '8');
$commenterMediaLimit = (int)mp_setting_or_config('media_limit_commenter_mb', '2');
$autosaveEnabled = (string)mp_setting_or_config('autosave_enabled', '1') === '1';
mp_admin_header('Writing Settings', []);
?>
<section class="panel page-intro-panel">
  <p class="eyebrow">Settings</p>
  <h2>Writing</h2>
  <p class="meta">Control the Stream editor without adding extra publishing modes.</p>
</section>

<section class="panel settings-panel">
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(mp_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">

    <label for="default_editor_mode">Default editor mode</label>
    <select id="default_editor_mode" name="default_editor_mode">
      <option value="visual" <?= $defaultEditor === 'visual' ? 'selected' : '' ?>>Visual</option>
      <option value="markdown" <?= $defaultEditor === 'markdown' ? 'selected' : '' ?>>Markdown</option>
    </select>
    <p class="field-help">Users can still switch between Visual, Markdown, and Preview on each stream post.</p>

    <label for="default_content_status">Default stream post status</label>
    <select id="default_content_status" name="default_content_status">
      <option value="draft" <?= $defaultStatus === 'draft' ? 'selected' : '' ?>>Save new admin posts as Draft</option>
      <option value="published" <?= $defaultStatus === 'published' ? 'selected' : '' ?>>Publish new admin posts immediately</option>
    </select>
    <p class="field-help">This setting controls the New Stream Post screen. Front-page composer posts publish immediately.</p>

    <label for="user_publish_mode">User publishing control</label>
    <select id="user_publish_mode" name="user_publish_mode">
      <option value="draft_review" <?= $userPublishMode === 'draft_review' ? 'selected' : '' ?>>Users submit drafts for admin review</option>
      <option value="direct" <?= $userPublishMode === 'direct' ? 'selected' : '' ?>>Users can publish directly</option>
    </select>
    <p class="field-help">Administrators can always publish. This controls standard User accounts created through registration or by an admin.</p>

    <fieldset class="settings-fieldset">
      <legend>Per-role media upload limits</legend>
      <p class="field-help">Limits are measured in megabytes and apply to the Media Library upload flow. Server upload limits can still be lower.</p>
      <label for="media_limit_administrator_mb">Administrator limit</label>
      <input type="number" id="media_limit_administrator_mb" name="media_limit_administrator_mb" min="1" max="128" value="<?= (int)$adminMediaLimit ?>">
      <label for="media_limit_user_mb">User limit</label>
      <input type="number" id="media_limit_user_mb" name="media_limit_user_mb" min="1" max="128" value="<?= (int)$userMediaLimit ?>">
      <label for="media_limit_commenter_mb">Commenter limit</label>
      <input type="number" id="media_limit_commenter_mb" name="media_limit_commenter_mb" min="1" max="128" value="<?= (int)$commenterMediaLimit ?>">
    </fieldset>

    <label class="checkbox-line"><input type="checkbox" name="autosave_enabled" value="1" <?= $autosaveEnabled ? 'checked' : '' ?>> Enable server autosave and recovery prompts in the editor</label>
    <p class="field-help">Bonumark Stream saves autosaves to the server first so recovery can follow your account, while keeping a browser backup only if the server save fails.</p>

    <button type="submit">Save Writing Settings</button>
  </form>
</section>

<section class="panel">
  <h2>Current writing support</h2>
  <div class="info-grid">
    <div class="info-card"><strong>Stream posts only</strong><p>Bonumark Stream publishes short-form timeline posts only.</p></div>
    <div class="info-card"><strong>Database-first source</strong><p>Every stream post is stored in the database first. Markdown export keeps your content portable.</p></div>
    <div class="info-card"><strong>Multiuser publishing</strong><p><?= htmlspecialchars(function_exists('mp_user_publish_mode_label') ? mp_user_publish_mode_label() : 'Users submit drafts for review', ENT_QUOTES, 'UTF-8') ?>.</p></div>
  </div>
</section>
<?php mp_admin_footer(); ?>

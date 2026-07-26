<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/media.php';
require_once __DIR__ . '/_layout.php';
bms_require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    bms_verify_csrf();
    $file = $_FILES['media_file'] ?? null;
    if (!$file) {
        bms_flash('Upload failed. Choose a media file and try again.', 'error');
        bms_redirect(bms_admin_url('media-upload.php'));
    }

    try {
        $media = bms_media_upload($file, (string)($_POST['alt_text'] ?? ''), (string)($_POST['caption'] ?? ''));
        $privacy = function_exists('bms_media_privacy_status') ? bms_media_privacy_status($media) : [];
        $privacyStatus = (string)($privacy['status'] ?? '');
        if ($privacyStatus === 'unconfirmed') {
            bms_flash('Media uploaded with a randomized filename, but metadata removal could not be confirmed. Review the media privacy warning.', 'warning');
        } else {
            bms_flash('Media uploaded. “' . ((string)($media['original_filename'] ?? $media['filename'] ?? 'Media')) . '” is ready to use.', 'success');
        }
        bms_redirect(bms_admin_url('media-edit.php?id=' . urlencode((string)($media['id'] ?? ''))));
    } catch (Throwable $e) {
        bms_log_admin_exception('media-upload', $e);

        $message = trim($e->getMessage());
        bms_flash($message !== '' ? ('Media upload failed. ' . $message) : 'Media upload failed. Please try again.', 'error');
        bms_redirect(bms_admin_url('media-upload.php'));
    }
}

bms_admin_header('Add New Media', [
    ['label' => 'Media Library', 'href' => bms_admin_url('media.php'), 'style' => 'secondary'],
]);
?>
<section class="panel page-intro-panel">
  <p class="eyebrow">Media</p>
  <h2>Add New Media</h2>
  <p class="meta">Upload a supported media file. Bonumark Stream randomizes the public filename, attempts image metadata removal on shared-hosting PHP, and shows a warning when metadata removal cannot be confirmed.</p>
</section>

<section class="panel upload-media-panel">
  <form method="post" enctype="multipart/form-data" class="settings-form">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <label for="media_file">Media file</label>
    <input id="media_file" type="file" name="media_file" accept="<?= htmlspecialchars(bms_allowed_media_accept_attribute(), ENT_QUOTES, 'UTF-8') ?>" required>
    <p class="field-help">Supported formats: <?= htmlspecialchars(bms_allowed_media_extensions_label(), ENT_QUOTES, 'UTF-8') ?>. Maximum size: <?= function_exists('bms_current_media_upload_limit_mb') ? (int)bms_current_media_upload_limit_mb() : 8 ?> MB.</p>

    <label for="alt_text">Alt text / description</label>
    <input id="alt_text" type="text" name="alt_text" maxlength="255" placeholder="Describe the media for accessibility">

    <label for="caption">Caption</label>
    <textarea id="caption" name="caption" class="small-textarea" maxlength="500" placeholder="Optional caption"></textarea>

    <button type="submit">Upload Media</button>
  </form>
</section>
<?php bms_admin_footer(); ?>

<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/media.php';
require_once __DIR__ . '/_layout.php';
bms_require_login();
bms_require_capability('manage_media');

$id = (int)($_GET['id'] ?? ($_POST['id'] ?? 0));
$media = bms_media_find($id);
if (!$media) {
    bms_admin_error_page('Media item not found', 'The requested media item could not be found.', 404, [
        ['label' => 'Media Library', 'href' => bms_admin_url('media.php'), 'style' => 'primary'],
        ['label' => 'Dashboard', 'href' => bms_admin_url(), 'style' => 'secondary'],
    ]);
}
bms_require_media_item_access($media);

$isTrashed = function_exists('bms_media_is_trashed') ? bms_media_is_trashed($media) : false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    bms_verify_csrf();
    $action = (string)($_POST['action'] ?? 'save');
    try {
        $name = (string)($media['original_filename'] ?? $media['filename'] ?? 'Media item');
        if ($action === 'trash') {
            bms_media_trash($id);
            bms_flash('Moved media item “' . $name . '” to trash.', 'success');
            bms_redirect(bms_admin_url('media.php'));
        }
        if ($action === 'restore') {
            bms_media_restore($id);
            bms_flash('Restored media item “' . $name . '”.', 'success');
            bms_redirect(bms_admin_url('media-edit.php?id=' . urlencode((string)$id)));
        }
        if ($action === 'delete_permanently') {
            bms_media_delete_permanently($id);
            bms_flash('Permanently deleted media item “' . $name . '”.', 'success');
            bms_redirect(bms_admin_url('media.php?status=trash'));
        }
        if ($isTrashed) {
            throw new RuntimeException('Restore this media item before editing its metadata.');
        }
        if ($action === 'regenerate_variants') {
            $report = bms_media_regenerate_image_variants($id);
            $message = bms_media_variant_status_text($report);
            bms_flash('Image variant refresh complete. ' . $message . '.', 'success');
            bms_redirect(bms_admin_url('media-edit.php?id=' . urlencode((string)$id)));
        }
        bms_media_update($id, (string)($_POST['alt_text'] ?? ''), (string)($_POST['caption'] ?? ''));
        bms_flash('Media details saved.', 'success');
        bms_redirect(bms_admin_url('media-edit.php?id=' . urlencode((string)$id)));
    } catch (Throwable $e) {
        bms_log_admin_exception('media-edit', $e);

        bms_flash('Media update failed. Please try again.', 'error');
        bms_redirect(bms_admin_url('media-edit.php?id=' . urlencode((string)$id)));
    }
}

$url = bms_media_public_url_for_item($media);
$markdown = bms_media_markdown($media);
$kind = function_exists('bms_media_kind_label') ? bms_media_kind_label($media) : 'Media';
$isImage = function_exists('bms_media_is_image_item') ? bms_media_is_image_item($media) : str_starts_with((string)($media['mime_type'] ?? ''), 'image/');
$width = (int)($media['width'] ?? 0);
$height = (int)($media['height'] ?? 0);
$dimensions = $width > 0 && $height > 0 ? ($width . '×' . $height) : 'Not applicable';
$usageSummary = function_exists('bms_media_usage_summary') ? bms_media_usage_summary($media) : 'Usage detection unavailable.';
$variantReport = $isImage && function_exists('bms_media_image_variant_status') ? bms_media_image_variant_status($media) : [];
$variantEnvironment = is_array($variantReport['environment'] ?? null) ? $variantReport['environment'] : [];
$variantTargets = is_array($variantReport['targets'] ?? null) ? $variantReport['targets'] : [];
$variantSummary = $variantReport ? bms_media_variant_status_text($variantReport) : 'Not applicable';
bms_admin_header($isTrashed ? 'Edit Trashed Media' : 'Edit Media', [
    ['label' => 'Media Library', 'href' => bms_admin_url('media.php'), 'style' => 'secondary'],
    ['label' => 'Media Trash', 'href' => bms_admin_url('media.php?status=trash'), 'style' => 'secondary'],
    ['label' => 'Add New Media', 'href' => bms_admin_url('media-upload.php'), 'style' => 'primary'],
    ['label' => 'View Media', 'href' => $url, 'style' => 'secondary', 'target' => true],
]);
?>
<section class="panel media-edit-panel<?= $isTrashed ? ' media-edit-trashed' : '' ?>">
  <?php if ($isTrashed): ?>
    <div class="notice warning"><p>This media item is in trash. It is hidden from the normal library and composer, but the file remains on disk until permanent deletion.</p></div>
  <?php endif; ?>
  <div class="media-edit-layout">
    <div class="media-edit-preview">
      <?php if ($isImage): ?>
        <img src="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars((string)($media['alt_text'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
      <?php else: ?>
        <div class="media-file-preview"><?= htmlspecialchars($kind, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>
      <div class="media-detail-list">
        <div><span>File</span><strong><?= htmlspecialchars((string)($media['original_filename'] ?? $media['filename']), ENT_QUOTES, 'UTF-8') ?></strong></div>
        <div><span>Kind</span><strong><?= htmlspecialchars($kind, ENT_QUOTES, 'UTF-8') ?></strong></div>
        <div><span>Size</span><strong><?= htmlspecialchars(bms_media_human_size((int)($media['file_size'] ?? 0)), ENT_QUOTES, 'UTF-8') ?></strong></div>
        <div><span>Dimensions</span><strong><?= htmlspecialchars($dimensions, ENT_QUOTES, 'UTF-8') ?></strong></div>
        <div><span>Type</span><strong><?= htmlspecialchars((string)($media['mime_type'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong></div>
        <div><span>Usage</span><strong><?= htmlspecialchars($usageSummary, ENT_QUOTES, 'UTF-8') ?></strong></div>
        <?php if ($isImage): ?>
          <div><span>Optimized variants</span><strong><?= htmlspecialchars($variantSummary, ENT_QUOTES, 'UTF-8') ?></strong></div>
        <?php endif; ?>
        <?php if ($isTrashed): ?>
          <div><span>Trashed</span><strong><?= htmlspecialchars((string)($media['trashed_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong></div>
        <?php endif; ?>
      </div>
    </div>

    <div class="media-edit-fields">
      <?php if ($isImage): ?>
        <div class="media-diagnostic-card media-variant-card">
          <div class="media-diagnostic-header">
            <div>
              <h2>Optimized image variants</h2>
              <p class="field-help">Bonumark keeps the original file and uses verified smaller variants when they are available. Missing variants fall back safely to the original image.</p>
            </div>
            <span class="status-pill <?= (int)($variantReport['created_count'] ?? 0) > 0 ? 'published' : 'draft' ?>"><?= htmlspecialchars($variantSummary, ENT_QUOTES, 'UTF-8') ?></span>
          </div>
          <?php if ($variantTargets): ?>
            <div class="media-variant-list">
              <?php foreach ($variantTargets as $target): ?>
                <div class="media-variant-row">
                  <div>
                    <strong><?= htmlspecialchars((string)($target['label'] ?? ''), ENT_QUOTES, 'UTF-8') ?> · <?= (int)($target['target_width'] ?? 0) ?>w</strong>
                    <?php if (!empty($target['path'])): ?><code><?= htmlspecialchars((string)$target['path'], ENT_QUOTES, 'UTF-8') ?></code><?php endif; ?>
                    <?php if (empty($target['exists']) && trim((string)($target['reason'] ?? '')) !== ''): ?>
                      <p class="field-help"><?= htmlspecialchars((string)$target['reason'], ENT_QUOTES, 'UTF-8') ?></p>
                    <?php endif; ?>
                  </div>
                  <span class="status-pill <?= !empty($target['exists']) ? 'published' : 'draft' ?>"><?= !empty($target['exists']) ? htmlspecialchars(bms_media_human_size((int)($target['file_size'] ?? 0)), ENT_QUOTES, 'UTF-8') : 'Missing' ?></span>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <details class="media-troubleshooting-details">
            <summary>Variant troubleshooting details</summary>
            <p class="field-help">These checks explain whether this server can create optimized image variants.</p>
            <div class="media-diagnostic-grid">
              <div><span>GD resize support</span><strong><?= !empty($variantEnvironment['gd_available']) ? 'Available' : 'Unavailable' ?></strong></div>
              <div><span>Imagick support</span><strong><?= !empty($variantEnvironment['imagick_available']) ? 'Available' : 'Unavailable' ?></strong></div>
              <div><span>Generated folder</span><strong><?= !empty($variantEnvironment['generated_root_writable']) ? 'Writable' : 'Not writable' ?></strong></div>
              <div><span>Memory limit</span><strong><?= htmlspecialchars((string)($variantEnvironment['memory_limit'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong></div>
            </div>
          </details>
          <?php if (!$isTrashed): ?>
            <form method="post" class="form-actions-row media-regenerate-form">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
              <input type="hidden" name="id" value="<?= (int)$id ?>">
              <input type="hidden" name="action" value="regenerate_variants">
              <button type="submit" class="secondary-button">Refresh variants</button>
              <p class="field-help">Rebuild optimized variants for this image only. The original file is preserved.</p>
            </form>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <form method="post" class="settings-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="id" value="<?= (int)$id ?>">
        <label for="alt_text">Alt text / description</label>
        <input id="alt_text" type="text" name="alt_text" maxlength="255" value="<?= htmlspecialchars((string)($media['alt_text'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"<?= $isTrashed ? ' disabled' : '' ?>>
        <p class="field-help">Alt text or a short description helps readers and gives the media cleaner context.</p>

        <label for="caption">Caption</label>
        <textarea id="caption" name="caption" class="small-textarea" maxlength="500"<?= $isTrashed ? ' disabled' : '' ?>><?= htmlspecialchars((string)($media['caption'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>

        <label for="media_url">Public URL</label>
        <input id="media_url" class="copy-field" type="text" readonly value="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>">

        <label for="media_markdown">Markdown</label>
        <input id="media_markdown" class="copy-field" type="text" readonly value="<?= htmlspecialchars($markdown, ENT_QUOTES, 'UTF-8') ?>">
        <p class="field-help">Copy this into the Markdown editor, or use the public URL where needed.</p>

        <div class="form-actions-row media-edit-actions">
          <?php if (!$isTrashed): ?>
            <button type="submit">Save Media</button>
          <?php endif; ?>
          <button type="button" class="secondary-button" data-copy-target="media_markdown">Copy Markdown</button>
          <button type="button" class="secondary-button" data-copy-target="media_url">Copy URL</button>
        </div>
      </form>

      <?php if ($isTrashed): ?>
        <form method="post" class="form-actions-row restore-zone">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="id" value="<?= (int)$id ?>">
          <input type="hidden" name="action" value="restore">
          <button type="submit">Restore Media</button>
        </form>
        <form method="post" class="danger-zone" data-confirm="Permanently delete this media item? This deletes the file from disk and cannot be undone.">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="id" value="<?= (int)$id ?>">
          <input type="hidden" name="action" value="delete_permanently">
          <button type="submit" class="danger">Delete Permanently</button>
          <p class="field-help"><?= htmlspecialchars($usageSummary, ENT_QUOTES, 'UTF-8') ?> Check posts before permanent deletion.</p>
        </form>
      <?php else: ?>
        <form method="post" class="danger-zone" data-confirm="Move this media item to trash? Existing post references may still display while the file remains on disk.">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="id" value="<?= (int)$id ?>">
          <input type="hidden" name="action" value="trash">
          <button type="submit" class="danger">Move to Trash</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</section>
<script src="<?= htmlspecialchars(bms_asset_url('assets/editor.js'), ENT_QUOTES, 'UTF-8') ?>" defer></script>
<?php bms_admin_footer(); ?>

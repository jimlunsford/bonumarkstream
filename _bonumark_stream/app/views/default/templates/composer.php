<?php
$data = is_array($bms_theme_data ?? null) ? $bms_theme_data : [];
$textareaId = (string)($data['textarea_id'] ?? 'stream_body');
$fileId = (string)($data['file_id'] ?? 'stream_media');
$helpId = (string)($data['help_id'] ?? 'stream-compose-help');
$previewId = (string)($data['preview_id'] ?? 'stream-compose-preview');
$linkPreviewId = (string)($data['link_preview_id'] ?? 'stream-link-preview');
$linkPreviewEndpoint = (string)($data['link_preview_endpoint'] ?? '');
$flashes = is_array($data['flashes'] ?? null) ? $data['flashes'] : [];
?>
<section class="stream-compose" aria-label="Quick stream post">
  <form class="stream-compose-form" method="post" action="<?= htmlspecialchars((string)($data['action_url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" enctype="multipart/form-data" data-stream-form>
    <div class="stream-compose-inner">
      <label for="<?= htmlspecialchars($textareaId, ENT_QUOTES, 'UTF-8') ?>" class="screen-reader-text">Write a stream post</label>
      <textarea id="<?= htmlspecialchars($textareaId, ENT_QUOTES, 'UTF-8') ?>" name="stream_body" class="stream-compose-textarea" rows="3" maxlength="5000" placeholder="<?= htmlspecialchars((string)($data['placeholder'] ?? 'What is happening?'), ENT_QUOTES, 'UTF-8') ?>" aria-describedby="<?= htmlspecialchars($helpId . ' ' . $previewId, ENT_QUOTES, 'UTF-8') ?>" data-stream-body></textarea>

      <div class="stream-compose-footer">
        <div class="stream-compose-left">
          <label class="stream-compose-attach" for="<?= htmlspecialchars($fileId, ENT_QUOTES, 'UTF-8') ?>">
            <span class="stream-compose-attach-icon" aria-hidden="true">📎</span>
            <span class="stream-compose-attach-label"><?= htmlspecialchars((string)($data['attach_label'] ?? 'Attach media'), ENT_QUOTES, 'UTF-8') ?></span>
            <input id="<?= htmlspecialchars($fileId, ENT_QUOTES, 'UTF-8') ?>" type="file" name="stream_media" class="stream-compose-file" accept="<?= htmlspecialchars((string)($data['accept'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" aria-describedby="<?= htmlspecialchars($helpId . ' ' . $previewId, ENT_QUOTES, 'UTF-8') ?>" data-stream-file>
          </label>
        </div>
        <button type="submit" class="stream-compose-submit" data-stream-submit data-ready-label="<?= htmlspecialchars((string)($data['submit_label'] ?? 'Post'), ENT_QUOTES, 'UTF-8') ?>" data-busy-label="<?= htmlspecialchars((string)($data['busy_label'] ?? 'Posting...'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)($data['submit_label'] ?? 'Post'), ENT_QUOTES, 'UTF-8') ?></button>
      </div>

      <p id="<?= htmlspecialchars($helpId, ENT_QUOTES, 'UTF-8') ?>" class="stream-compose-hint"><?= htmlspecialchars((string)($data['help_text'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
      <div id="<?= htmlspecialchars($previewId, ENT_QUOTES, 'UTF-8') ?>" class="stream-compose-preview" role="status" aria-live="polite" aria-atomic="true" data-stream-preview></div>
      <div id="<?= htmlspecialchars($linkPreviewId, ENT_QUOTES, 'UTF-8') ?>" class="stream-link-preview-composer" role="status" aria-live="polite" aria-atomic="true" data-link-preview data-link-preview-endpoint="<?= htmlspecialchars($linkPreviewEndpoint, ENT_QUOTES, 'UTF-8') ?>" hidden></div>
    </div>
    <?php if ((string)($data['csrf'] ?? '') !== ''): ?><input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$data['csrf'], ENT_QUOTES, 'UTF-8') ?>"><?php endif; ?>
    <input type="hidden" name="return_to" value="<?= htmlspecialchars((string)($data['return_to'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="link_preview_enabled" value="0" data-link-preview-enabled>
    <input type="hidden" name="link_preview_url" value="" data-link-preview-field="url">
    <input type="hidden" name="link_preview_title" value="" data-link-preview-field="title">
    <input type="hidden" name="link_preview_description" value="" data-link-preview-field="description">
    <input type="hidden" name="link_preview_image" value="" data-link-preview-field="image">
    <input type="hidden" name="link_preview_site_name" value="" data-link-preview-field="site_name">
  </form>
  <?php foreach ($flashes as $flash): ?>
    <p class="stream-compose-notice <?= htmlspecialchars((string)($flash['class'] ?? 'is-warning'), ENT_QUOTES, 'UTF-8') ?> stream-notice stream-notice-<?= htmlspecialchars((string)($flash['type'] ?? 'info'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)($flash['message'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
  <?php endforeach; ?>
</section>

<?php
$data = is_array($bms_theme_data ?? null) ? $bms_theme_data : [];
$textareaId = (string)($data['textarea_id'] ?? 'stream_body');
$fileId = (string)($data['file_id'] ?? 'stream_media');
$helpId = (string)($data['help_id'] ?? 'stream-compose-help');
$previewId = (string)($data['preview_id'] ?? 'stream-compose-preview');
$linkPreviewId = (string)($data['link_preview_id'] ?? 'stream-link-preview');
$linkPreviewEndpoint = (string)($data['link_preview_endpoint'] ?? '');
$timezoneLabel = (string)($data['timezone_label'] ?? 'UTC');
$schedulePanelId = 'stream-compose-schedule-panel';
$scheduleInputId = 'stream_scheduled_at_front';
$flashes = is_array($data['flashes'] ?? null) ? $data['flashes'] : [];
?>
<section class="stream-compose" aria-label="Quick stream post">
  <form class="stream-compose-form" method="post" action="<?= htmlspecialchars((string)($data['action_url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" enctype="multipart/form-data" data-stream-form data-stream-scheduled-runner-url="<?= htmlspecialchars((string)($data['scheduled_runner_url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
    <div class="stream-compose-inner">
      <label for="<?= htmlspecialchars($textareaId, ENT_QUOTES, 'UTF-8') ?>" class="screen-reader-text">Write a stream post</label>
      <textarea id="<?= htmlspecialchars($textareaId, ENT_QUOTES, 'UTF-8') ?>" name="stream_body" class="stream-compose-textarea" rows="3" maxlength="5000" placeholder="<?= htmlspecialchars((string)($data['placeholder'] ?? 'What is happening?'), ENT_QUOTES, 'UTF-8') ?>" aria-describedby="<?= htmlspecialchars($helpId . ' ' . $previewId, ENT_QUOTES, 'UTF-8') ?>" data-stream-body><?= htmlspecialchars((string)($data['body_value'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>

      <div id="<?= htmlspecialchars($schedulePanelId, ENT_QUOTES, 'UTF-8') ?>" class="stream-compose-schedule-panel" data-stream-schedule-panel hidden>
        <div class="stream-compose-schedule-fields">
          <label class="stream-compose-schedule-label" for="<?= htmlspecialchars($scheduleInputId, ENT_QUOTES, 'UTF-8') ?>">Schedule for</label>
          <input id="<?= htmlspecialchars($scheduleInputId, ENT_QUOTES, 'UTF-8') ?>" type="datetime-local" name="stream_scheduled_at" class="stream-compose-schedule-input" data-stream-scheduled-at>
          <p class="stream-compose-schedule-timezone">Timezone: <strong><?= htmlspecialchars($timezoneLabel, ENT_QUOTES, 'UTF-8') ?></strong></p>
        </div>
        <button type="button" class="stream-compose-schedule-cancel" data-stream-schedule-cancel>Cancel schedule</button>
      </div>

      <div class="stream-compose-footer stream-compose-toolbar">
        <div class="stream-compose-left stream-compose-actions" aria-label="Composer actions">
          <label class="stream-compose-attach stream-compose-tool" for="<?= htmlspecialchars($fileId, ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars((string)($data['attach_label'] ?? 'Attach media'), ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars((string)($data['attach_label'] ?? 'Attach media'), ENT_QUOTES, 'UTF-8') ?>">
            <span class="stream-compose-tool-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24" focusable="false"><path d="M4 5.5A2.5 2.5 0 0 1 6.5 3h11A2.5 2.5 0 0 1 20 5.5v13a2.5 2.5 0 0 1-2.5 2.5h-11A2.5 2.5 0 0 1 4 18.5v-13Z"/><path d="m6.75 16.25 3.4-3.4a1.25 1.25 0 0 1 1.77 0l2.02 2.02.9-.9a1.25 1.25 0 0 1 1.77 0l.64.64"/><path d="M15.25 8.25h.01"/></svg>
            </span>
            <span class="stream-compose-attach-label screen-reader-text"><?= htmlspecialchars((string)($data['attach_label'] ?? 'Attach media'), ENT_QUOTES, 'UTF-8') ?></span>
            <input id="<?= htmlspecialchars($fileId, ENT_QUOTES, 'UTF-8') ?>" type="file" name="stream_media" class="stream-compose-file" accept="<?= htmlspecialchars((string)($data['accept'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" aria-describedby="<?= htmlspecialchars($helpId . ' ' . $previewId, ENT_QUOTES, 'UTF-8') ?>" data-stream-file>
          </label>
          <button type="button" class="stream-compose-tool stream-compose-schedule-toggle" title="Schedule post" aria-label="Schedule post" aria-controls="<?= htmlspecialchars($schedulePanelId, ENT_QUOTES, 'UTF-8') ?>" aria-expanded="false" data-stream-schedule-toggle>
            <span class="stream-compose-tool-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24" focusable="false"><path d="M7 3v3"/><path d="M17 3v3"/><path d="M4.5 9.5h15"/><path d="M6.5 5h11A2.5 2.5 0 0 1 20 7.5v10A2.5 2.5 0 0 1 17.5 20h-11A2.5 2.5 0 0 1 4 17.5v-10A2.5 2.5 0 0 1 6.5 5Z"/><path d="M12 13v3l2 1"/></svg>
            </span>
            <span class="screen-reader-text">Schedule post</span>
          </button>
        </div>
        <button type="submit" class="stream-compose-submit" data-stream-submit data-ready-label="<?= htmlspecialchars((string)($data['submit_label'] ?? 'Post'), ENT_QUOTES, 'UTF-8') ?>" data-busy-label="<?= htmlspecialchars((string)($data['busy_label'] ?? 'Posting...'), ENT_QUOTES, 'UTF-8') ?>" data-publish-label="<?= htmlspecialchars((string)($data['submit_label'] ?? 'Post'), ENT_QUOTES, 'UTF-8') ?>" data-publish-busy-label="<?= htmlspecialchars((string)($data['busy_label'] ?? 'Posting...'), ENT_QUOTES, 'UTF-8') ?>" data-schedule-label="Schedule" data-schedule-busy-label="Scheduling..."><?= htmlspecialchars((string)($data['submit_label'] ?? 'Post'), ENT_QUOTES, 'UTF-8') ?></button>
      </div>

      <p id="<?= htmlspecialchars($helpId, ENT_QUOTES, 'UTF-8') ?>" class="stream-compose-hint stream-compose-help-text"><?= htmlspecialchars((string)($data['help_text'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
      <div id="<?= htmlspecialchars($previewId, ENT_QUOTES, 'UTF-8') ?>" class="stream-compose-preview" role="status" aria-live="polite" aria-atomic="true" data-stream-preview></div>
      <div id="<?= htmlspecialchars($linkPreviewId, ENT_QUOTES, 'UTF-8') ?>" class="stream-link-preview-composer" role="status" aria-live="polite" aria-atomic="true" data-link-preview data-link-preview-endpoint="<?= htmlspecialchars($linkPreviewEndpoint, ENT_QUOTES, 'UTF-8') ?>" hidden></div>
    </div>
    <?php if ((string)($data['csrf'] ?? '') !== ''): ?><input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$data['csrf'], ENT_QUOTES, 'UTF-8') ?>"><?php endif; ?>
    <input type="hidden" name="return_to" value="<?= htmlspecialchars((string)($data['return_to'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="stream_submit_action" value="publish" data-stream-submit-action>
    <input type="hidden" name="stream_schedule_enabled" value="0" data-stream-schedule-enabled>
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

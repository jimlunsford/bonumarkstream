<?php
$data = is_array($bms_component_data ?? null) ? $bms_component_data : [];
$quickEdit = is_array($data['quick_edit'] ?? null) ? $data['quick_edit'] : [];
$hasQuickEdit = !empty($quickEdit['enabled'])
  && (string)($quickEdit['endpoint'] ?? '') !== ''
  && (string)($quickEdit['filename'] ?? '') !== '';
?>
      <div class="stream-card-content" data-stream-quick-edit-content><?= (string)($data['body_html'] ?? '') ?></div>
      <?php if ($hasQuickEdit): ?>
        <form class="stream-inline-edit" method="post" action="<?= htmlspecialchars((string)$quickEdit['endpoint'], ENT_QUOTES, 'UTF-8') ?>" data-stream-quick-edit-form hidden>
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)($quickEdit['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="file" value="<?= htmlspecialchars((string)$quickEdit['filename'], ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="content_hash" value="<?= htmlspecialchars((string)($quickEdit['content_hash'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" data-stream-quick-edit-hash>
          <textarea name="body" rows="4" aria-label="Edit post text" data-stream-quick-edit-textarea><?= htmlspecialchars((string)($quickEdit['body'] ?? ''), ENT_NOQUOTES, 'UTF-8') ?></textarea>
          <div class="stream-inline-edit-footer">
            <p class="stream-inline-edit-status" role="status" aria-live="polite" data-stream-quick-edit-status></p>
            <div class="stream-inline-edit-actions">
              <button type="button" class="stream-inline-edit-cancel" data-stream-quick-edit-cancel>Cancel</button>
              <button type="submit" class="stream-inline-edit-save" data-stream-quick-edit-save>Save</button>
            </div>
          </div>
        </form>
      <?php endif; ?>

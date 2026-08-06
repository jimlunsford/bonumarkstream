<?php
$data = is_array($bms_theme_data ?? null) ? $bms_theme_data : [];
$single = !empty($data['single']);
$pageUrl = (string)($data['page_url'] ?? '#');
$cardClasses = trim((string)($data['classes'] ?? 'stream-card') . ' ledger-stream-card');
$like = is_array($data['like'] ?? null) ? $data['like'] : [];
$comments = is_array($data['comments'] ?? null) ? $data['comments'] : [];
$likeLabel = (string)($like['label'] ?? '0 likes');
$likeAction = (string)($like['action_label'] ?? 'Like this post.');
$previewMode = !empty($data['preview_mode']);
$backLabel = (string)($data['back_label'] ?? 'Back to stream');
$quickEdit = is_array($data['quick_edit'] ?? null) ? $data['quick_edit'] : [];
$trashAction = is_array($data['trash_action'] ?? null) ? $data['trash_action'] : [];
$hasQuickEdit = !empty($quickEdit['enabled'])
  && (string)($quickEdit['endpoint'] ?? '') !== ''
  && (string)($quickEdit['filename'] ?? '') !== '';
$hasTrashAction = !empty($trashAction['enabled'])
  && (string)($trashAction['endpoint'] ?? '') !== ''
  && (string)($trashAction['filename'] ?? '') !== '';
?>
<article class="<?= htmlspecialchars($cardClasses, ENT_QUOTES, 'UTF-8') ?>" data-stream-card data-stream-url="<?= htmlspecialchars($pageUrl, ENT_QUOTES, 'UTF-8') ?>"<?= $previewMode ? ' data-preview-mode="1"' : '' ?>>
  <div class="stream-card-inner">
    <div class="stream-card-avatar"><?= (string)($data['avatar_html'] ?? '') ?></div>
    <div class="stream-card-main">
      <div class="stream-card-headerline">
        <?php if ((string)($data['author_profile_url'] ?? '') !== ''): ?>
          <a class="stream-card-author" href="<?= htmlspecialchars((string)$data['author_profile_url'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)($data['author_name'] ?? 'Admin'), ENT_QUOTES, 'UTF-8') ?></a>
        <?php else: ?>
          <span class="stream-card-author"><?= htmlspecialchars((string)($data['author_name'] ?? 'Admin'), ENT_QUOTES, 'UTF-8') ?></span>
        <?php endif; ?>
        <?php if (!empty($data['show_dates']) && (string)($data['date_label'] ?? '') !== ''): ?>
          <span class="stream-card-separator" aria-hidden="true">&middot;</span><a class="stream-card-datetime stream-card-permalink stream-permalink" href="<?= htmlspecialchars($pageUrl, ENT_QUOTES, 'UTF-8') ?>"><time datetime="<?= htmlspecialchars((string)($data['date_iso'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)$data['date_label'], ENT_QUOTES, 'UTF-8') ?></time></a>
        <?php elseif (!$single): ?>
          <span class="stream-card-separator" aria-hidden="true">&middot;</span><a class="stream-card-datetime stream-card-permalink stream-permalink" href="<?= htmlspecialchars($pageUrl, ENT_QUOTES, 'UTF-8') ?>">Permalink</a>
        <?php endif; ?>
      </div>


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
      <?= (string)($data['location_html'] ?? '') ?>
      <?= (string)($data['link_preview_html'] ?? '') ?>
      <?= (string)($data['media_html'] ?? '') ?>

      <div class="stream-card-meta">
        <div class="stream-card-tags"></div>
        <div class="stream-card-actions">
          <?php if (!empty($like['enabled'])): ?>
            <button type="button" class="stream-like-button stream-meta-pill<?= !empty($like['liked']) ? ' is-liked' : '' ?>" data-stream-like data-like-slug="<?= htmlspecialchars((string)($like['slug'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" data-like-endpoint="<?= htmlspecialchars((string)($like['endpoint'] ?? 'stream-like.php'), ENT_QUOTES, 'UTF-8') ?>" data-like-endpoint-alt="<?= htmlspecialchars((string)($like['endpoint_alt'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" data-like-count="<?= (int)($like['count'] ?? 0) ?>" aria-pressed="<?= !empty($like['liked']) ? 'true' : 'false' ?>" aria-label="<?= htmlspecialchars($likeAction . ' ' . $likeLabel, ENT_QUOTES, 'UTF-8') ?>"><span class="stream-meta-pill-icon" aria-hidden="true"></span><span class="screen-reader-text stream-like-sr-text"><?= htmlspecialchars($likeAction, ENT_QUOTES, 'UTF-8') ?></span><span class="stream-like-text"><?= htmlspecialchars($likeLabel, ENT_QUOTES, 'UTF-8') ?></span></button>
          <?php endif; ?>
          <?php if (!empty($comments['enabled'])): ?>
            <a class="stream-meta-pill" href="<?= htmlspecialchars((string)($comments['url'] ?? '#comments'), ENT_QUOTES, 'UTF-8') ?>"><span class="stream-meta-pill-icon" aria-hidden="true"></span><span><?= htmlspecialchars((string)($comments['label'] ?? '0 Comments'), ENT_QUOTES, 'UTF-8') ?></span></a>
          <?php endif; ?>
          <?php if ($single): ?>
            <?php
              $backUrl = trim((string)($data['back_url'] ?? ''));
              if ($backUrl === '' || $backUrl === 'stream/' || $backUrl === '/stream/') {
                  $backUrl = function_exists('bms_stream_home_url') ? bms_stream_home_url() : '/';
              }
            ?>
            <a class="stream-meta-pill" href="<?= htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8') ?>"><span class="stream-meta-pill-icon" aria-hidden="true"></span><span><?= htmlspecialchars($backLabel, ENT_QUOTES, 'UTF-8') ?></span></a>
          <?php endif; ?>
          <?php
            $hasEditAction = (string)($data['edit_url'] ?? '') !== '';
            $hasPinAction = (string)($data['pin_action'] ?? '') !== ''
              && (string)($data['pin_action_url'] ?? '') !== ''
              && (string)($data['pin_filename'] ?? '') !== '';
          ?>
          <?php if ($hasQuickEdit || $hasEditAction || $hasPinAction || $hasTrashAction): ?>
            <details class="stream-post-actions-menu" data-stream-actions-menu>
              <summary class="stream-post-actions-toggle" aria-label="Post options" title="Post options">
                <span class="stream-post-actions-dots" aria-hidden="true">•••</span>
                <span class="screen-reader-text">Post options</span>
              </summary>
              <div class="stream-post-actions-popover" role="group" aria-label="Post options">
                <?php if ($hasQuickEdit): ?>
                  <button type="button" class="stream-post-action-item" data-stream-quick-edit-open>Quick edit</button>
                <?php endif; ?>
                <?php if ($hasEditAction): ?>
                  <a class="stream-post-action-item" href="<?= htmlspecialchars((string)$data['edit_url'], ENT_QUOTES, 'UTF-8') ?>">Open full editor</a>
                <?php endif; ?>
                <?php if ($hasPinAction): ?>
                  <?php $pinning = (string)$data['pin_action'] === 'pin'; ?>
                  <form method="post" action="<?= htmlspecialchars((string)$data['pin_action_url'], ENT_QUOTES, 'UTF-8') ?>" class="stream-post-action-form stream-pin-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)($data['pin_csrf'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="file" value="<?= htmlspecialchars((string)$data['pin_filename'], ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="<?= $pinning ? 'pin' : 'unpin' ?>">
                    <input type="hidden" name="return_to" value="<?= htmlspecialchars((string)($data['pin_return_to'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                    <button type="submit" class="stream-post-action-item stream-pin-button"><?= $pinning ? 'Pin to Stream' : 'Unpin from Stream' ?></button>
                  </form>
                <?php endif; ?>
                <?php if ($hasTrashAction): ?>
                  <form method="post" action="<?= htmlspecialchars((string)$trashAction['endpoint'], ENT_QUOTES, 'UTF-8') ?>" class="stream-post-action-form stream-trash-form" data-stream-trash-form data-stream-trash-single="<?= !empty($trashAction['single']) ? '1' : '0' ?>">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)($trashAction['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="file" value="<?= htmlspecialchars((string)$trashAction['filename'], ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="content_hash" value="<?= htmlspecialchars((string)($trashAction['content_hash'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="return_to" value="<?= htmlspecialchars((string)($trashAction['return_to'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                    <button type="submit" class="stream-post-action-item stream-post-action-danger" data-stream-trash-submit>Move to trash</button>
                  </form>
                <?php endif; ?>
              </div>
            </details>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</article>

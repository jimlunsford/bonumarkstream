<?php
$data = is_array($mp_theme_data ?? null) ? $mp_theme_data : [];
?>
<nav class="stream-pagination pagination-load-more" aria-label="Stream pagination">
  <?php if (!empty($data['has_older'])): ?>
    <div class="pagination-older"><a href="<?= htmlspecialchars((string)($data['older_url'] ?? '#'), ENT_QUOTES, 'UTF-8') ?>" data-load-more-url="<?= htmlspecialchars((string)($data['older_ajax_url'] ?? $data['older_url'] ?? '#'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)($data['older_label'] ?? 'Load More'), ENT_QUOTES, 'UTF-8') ?></a></div>
  <?php else: ?>
    <div class="pagination-older"><span class="is-disabled"><?= htmlspecialchars((string)($data['complete_label'] ?? 'No more posts'), ENT_QUOTES, 'UTF-8') ?></span></div>
  <?php endif; ?>
</nav>
<div class="pagination-back-to-top"><a class="back-to-top-chip" href="<?= htmlspecialchars((string)($data['back_to_top_url'] ?? '#site-main'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)($data['back_to_top_label'] ?? 'Back to top'), ENT_QUOTES, 'UTF-8') ?></a></div>
<div id="<?= htmlspecialchars((string)($data['status_id'] ?? 'stream-load-status'), ENT_QUOTES, 'UTF-8') ?>" class="screen-reader-text" aria-live="polite" aria-atomic="true"></div>

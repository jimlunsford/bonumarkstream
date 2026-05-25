<?php
$data = is_array($mp_theme_data ?? null) ? $mp_theme_data : [];
$single = !empty($data['single']);
$pageUrl = (string)($data['page_url'] ?? '#');
$cardClasses = trim((string)($data['classes'] ?? 'stream-card') . ' ledger-stream-card');
$like = is_array($data['like'] ?? null) ? $data['like'] : [];
$comments = is_array($data['comments'] ?? null) ? $data['comments'] : [];
$likeLabel = (string)($like['label'] ?? '0 likes');
$likeAction = (string)($like['action_label'] ?? 'Like this post.');
?>
<article class="<?= htmlspecialchars($cardClasses, ENT_QUOTES, 'UTF-8') ?>" data-stream-card data-stream-url="<?= htmlspecialchars($pageUrl, ENT_QUOTES, 'UTF-8') ?>">
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


      <div class="stream-card-content"><?= (string)($data['body_html'] ?? '') ?></div>
      <?= (string)($data['link_preview_html'] ?? '') ?>
      <?= (string)($data['media_html'] ?? '') ?>

      <div class="stream-card-meta">
        <div class="stream-card-tags"></div>
        <div class="stream-card-actions">
          <button type="button" class="stream-like-button stream-meta-pill<?= !empty($like['liked']) ? ' is-liked' : '' ?>" data-stream-like data-like-slug="<?= htmlspecialchars((string)($like['slug'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" data-like-endpoint="<?= htmlspecialchars((string)($like['endpoint'] ?? 'stream-like.php'), ENT_QUOTES, 'UTF-8') ?>" data-like-endpoint-alt="<?= htmlspecialchars((string)($like['endpoint_alt'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" data-like-count="<?= (int)($like['count'] ?? 0) ?>" aria-pressed="<?= !empty($like['liked']) ? 'true' : 'false' ?>" aria-label="<?= htmlspecialchars($likeAction . ' ' . $likeLabel, ENT_QUOTES, 'UTF-8') ?>"><span class="stream-meta-pill-icon" aria-hidden="true"></span><span class="screen-reader-text stream-like-sr-text"><?= htmlspecialchars($likeAction, ENT_QUOTES, 'UTF-8') ?></span><span class="stream-like-text"><?= htmlspecialchars($likeLabel, ENT_QUOTES, 'UTF-8') ?></span></button>
          <?php if (!empty($comments['enabled'])): ?>
            <a class="stream-meta-pill" href="<?= htmlspecialchars((string)($comments['url'] ?? '#comments'), ENT_QUOTES, 'UTF-8') ?>"><span class="stream-meta-pill-icon" aria-hidden="true"></span><span><?= htmlspecialchars((string)($comments['label'] ?? '0 Comments'), ENT_QUOTES, 'UTF-8') ?></span></a>
          <?php endif; ?>
          <?php if ($single): ?>
            <a class="stream-meta-pill" href="<?= htmlspecialchars((string)($data['back_url'] ?? 'stream/'), ENT_QUOTES, 'UTF-8') ?>"><span class="stream-meta-pill-icon" aria-hidden="true"></span><span>Back to stream</span></a>
          <?php endif; ?>
          <?php if ((string)($data['edit_url'] ?? '') !== ''): ?>
            <a class="stream-meta-pill" href="<?= htmlspecialchars((string)$data['edit_url'], ENT_QUOTES, 'UTF-8') ?>"><span class="stream-meta-pill-icon" aria-hidden="true"></span><span>Edit</span></a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</article>

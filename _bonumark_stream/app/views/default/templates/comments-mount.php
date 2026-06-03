<?php
$data = is_array($bms_theme_data ?? null) ? $bms_theme_data : [];
?>
<section class="stream-comments-mount ledger-comments-mount" data-comments-mount data-comments-slug="<?= htmlspecialchars((string)($data['slug'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" data-comments-endpoint="<?= htmlspecialchars((string)($data['endpoint'] ?? 'comments.php'), ENT_QUOTES, 'UTF-8') ?>">
  <p class="comment-note"><?= htmlspecialchars((string)($data['loading_text'] ?? 'Loading comments...'), ENT_QUOTES, 'UTF-8') ?></p>
  <noscript><p><a href="<?= htmlspecialchars((string)($data['noscript_url'] ?? '#comments'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)($data['noscript_text'] ?? 'View comments'), ENT_QUOTES, 'UTF-8') ?></a></p></noscript>
</section>

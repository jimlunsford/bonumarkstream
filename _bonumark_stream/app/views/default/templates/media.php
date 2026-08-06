<?php
$data = is_array($bms_theme_data ?? null) ? $bms_theme_data : [];
$type = (string)($data['type'] ?? 'file');
$url = (string)($data['url'] ?? '');
$count = max(1, min(4, (int)($data['count'] ?? 1)));
$layout = preg_replace('/[^a-z0-9_-]+/i', '', (string)($data['layout'] ?? 'single')) ?: 'single';
$items = is_array($data['items'] ?? null) ? array_slice($data['items'], 0, 4) : [];
?>
<?php if ($type === 'gallery' && count($items) > 1): ?>
  <div class="stream-card-media stream-media-gallery stream-media-gallery-count-<?= (int)$count ?> stream-media-gallery-layout-<?= htmlspecialchars($layout, ENT_QUOTES, 'UTF-8') ?>" data-media-count="<?= (int)$count ?>" role="group" aria-label="Photo gallery with <?= (int)$count ?> photos">
    <?php foreach ($items as $index => $item): ?>
      <?php $position = (int)($item['position'] ?? ($index + 1)); ?>
      <a class="stream-media-gallery-item stream-media-gallery-item-<?= (int)$position ?>" data-stream-media-viewer href="<?= htmlspecialchars((string)($item['url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" aria-label="Open photo <?= (int)$position ?> of <?= (int)$count ?>">
        <?php if ((string)($item['image_attributes'] ?? '') !== ''): ?>
          <img <?= (string)$item['image_attributes'] ?>>
        <?php else: ?>
          <img class="stream-media-gallery-image" src="<?= htmlspecialchars((string)($item['url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars((string)($item['alt'] ?? 'Stream post photo'), ENT_QUOTES, 'UTF-8') ?>" loading="lazy" decoding="async">
        <?php endif; ?>
      </a>
    <?php endforeach; ?>
  </div>
<?php elseif ($type === 'image'): ?>
  <div class="stream-card-media"><a data-stream-media-viewer href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>"><?php if ((string)($data['image_attributes'] ?? '') !== ''): ?><img <?= (string)$data['image_attributes'] ?>><?php else: ?><img src="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars((string)($data['alt'] ?? 'Stream post media'), ENT_QUOTES, 'UTF-8') ?>" loading="lazy" decoding="async"><?php endif; ?></a></div>
<?php elseif ($type === 'audio'): ?>
  <div class="stream-card-media stream-card-media-audio"><audio controls preload="metadata" src="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>"></audio></div>
<?php elseif ($type === 'video'): ?>
  <div class="stream-card-media stream-card-media-video"><video controls preload="metadata" src="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>"></video></div>
<?php else: ?>
  <div class="stream-card-media stream-card-media-file"><a class="stream-doc-chip" href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>"><span class="stream-doc-chip-icon" aria-hidden="true">📄</span><span><?= htmlspecialchars((string)($data['label'] ?? 'Attached media'), ENT_QUOTES, 'UTF-8') ?></span></a></div>
<?php endif; ?>

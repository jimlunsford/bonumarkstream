<?php
$data = is_array($bms_theme_data ?? null) ? $bms_theme_data : [];
$type = (string)($data['type'] ?? 'file');
$url = (string)($data['url'] ?? '');
?>
<?php if ($type === 'image'): ?>
  <div class="stream-card-media"><a href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>"><?php if ((string)($data['image_attributes'] ?? '') !== ''): ?><img <?= (string)$data['image_attributes'] ?>><?php else: ?><img src="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars((string)($data['alt'] ?? 'Stream post media'), ENT_QUOTES, 'UTF-8') ?>" loading="lazy" decoding="async"><?php endif; ?></a></div>
<?php elseif ($type === 'audio'): ?>
  <div class="stream-card-media stream-card-media-audio"><audio controls preload="metadata" src="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>"></audio></div>
<?php elseif ($type === 'video'): ?>
  <div class="stream-card-media stream-card-media-video"><video controls preload="metadata" src="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>"></video></div>
<?php else: ?>
  <div class="stream-card-media stream-card-media-file"><a class="stream-doc-chip" href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>"><span class="stream-doc-chip-icon" aria-hidden="true">📄</span><span><?= htmlspecialchars((string)($data['label'] ?? 'Attached media'), ENT_QUOTES, 'UTF-8') ?></span></a></div>
<?php endif; ?>

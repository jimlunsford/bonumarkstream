<?php
$data = is_array($bms_theme_data ?? null) ? $bms_theme_data : [];
$url = (string)($data['url'] ?? '');
$title = (string)($data['title'] ?? $url);
$description = (string)($data['description'] ?? '');
$image = (string)($data['image'] ?? '');
$siteName = (string)($data['site_name'] ?? '');
$previewClasses = 'stream-link-preview ledger-link-preview' . ($image === '' ? ' no-image' : ' has-image');
?>
<?php if ($url !== ''): ?>
  <a class="<?= htmlspecialchars($previewClasses, ENT_QUOTES, 'UTF-8') ?>" href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener nofollow">
    <?php if ($image !== ''): ?>
      <span class="stream-link-preview-image ledger-link-preview-image"><img src="<?= htmlspecialchars($image, ENT_QUOTES, 'UTF-8') ?>" alt="" loading="lazy"></span>
    <?php endif; ?>
    <span class="stream-link-preview-body ledger-link-preview-body">
      <?php if ($siteName !== ''): ?><span class="stream-link-preview-site ledger-link-preview-site"><?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
      <span class="stream-link-preview-title ledger-link-preview-title"><?= htmlspecialchars($title !== '' ? $title : $url, ENT_QUOTES, 'UTF-8') ?></span>
      <?php if ($description !== ''): ?><span class="stream-link-preview-description ledger-link-preview-description"><?= htmlspecialchars($description, ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
      <span class="stream-link-preview-url ledger-link-preview-url"><?= htmlspecialchars((string)(parse_url($url, PHP_URL_HOST) ?: $url), ENT_QUOTES, 'UTF-8') ?></span>
    </span>
  </a>
<?php endif; ?>

<?php
$data = is_array($bms_component_data ?? null) ? $bms_component_data : [];
$siteName = (string)($data['site_name'] ?? 'Bonumark Stream');
$tagline = (string)($data['tagline'] ?? '');
$taglineHtml = (string)($data['tagline_html'] ?? htmlspecialchars($tagline, ENT_QUOTES, 'UTF-8'));
$homeUrl = (string)($data['home_url'] ?? '/');
$titleTag = (string)($data['title_tag'] ?? 'p');
if (!in_array($titleTag, ['h1', 'p'], true)) {
    $titleTag = 'p';
}
?>
<div class="site-branding">
  <div class="site-title-group">
    <<?= $titleTag ?> class="site-title"><a href="<?= htmlspecialchars($homeUrl, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?></a></<?= $titleTag ?>>
    <?php if ($tagline !== ''): ?><p class="site-tagline"><?= $taglineHtml ?></p><?php endif; ?>
  </div>
</div>

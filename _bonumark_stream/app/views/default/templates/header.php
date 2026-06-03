<?php
$data = is_array($bms_theme_data ?? null) ? $bms_theme_data : [];
$settings = is_array($data['theme_settings'] ?? null) ? $data['theme_settings'] : [];
$context = (string)($data['context'] ?? 'page');
$siteName = (string)($data['site_name'] ?? 'Bonumark Stream');
$tagline = (string)($data['tagline'] ?? '');
$taglineHtml = (string)($data['tagline_html'] ?? htmlspecialchars($tagline, ENT_QUOTES, 'UTF-8'));
$homeUrl = (string)($data['home_url'] ?? '/');
$countLabel = (string)($data['count_label'] ?? '0 posts');
$navigationHtml = (string)($data['navigation_html'] ?? '');
$titleTag = (string)($data['title_tag'] ?? ($context === 'home' ? 'h1' : 'p'));
if (!in_array($titleTag, ['h1', 'p'], true)) {
    $titleTag = 'p';
}
$showStatusChip = (string)($settings['show_status_chip'] ?? '1') === '1';
$statusLabel = trim((string)($settings['status_label'] ?? 'Live microblog')) ?: 'Live microblog';
$showPostCount = (string)($settings['show_post_count'] ?? '1') === '1';
$menuLabel = trim((string)($settings['menu_label'] ?? 'Menu')) ?: 'Menu';
$menuButton = $navigationHtml !== '' ? '<button type="button" class="meta-chip site-nav-toggle ledger-menu-toggle" aria-expanded="false" aria-controls="site-primary-nav" aria-label="Open menu" data-stream-menu-toggle><span class="site-nav-toggle-label">' . htmlspecialchars($menuLabel, ENT_QUOTES, 'UTF-8') . '</span><span class="site-nav-toggle-icon" aria-hidden="true"><span></span><span></span><span></span></span></button>' : '';
?>
<header class="site-header public-site-header stream-site-header ledger-header">
  <div class="site-header-inner ledger-header-inner">
    <div class="site-branding ledger-branding">
      <div class="site-title-group ledger-title-group">
        <<?= $titleTag ?> class="site-title"><a href="<?= htmlspecialchars($homeUrl, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?></a></<?= $titleTag ?>>
        <?php if ($tagline !== ''): ?><p class="site-tagline"><?= $taglineHtml ?></p><?php endif; ?>
      </div>
    </div>
    <div class="site-meta-chips ledger-chips" aria-label="Stream status and menu">
      <?php if ($showStatusChip): ?><div class="meta-chip meta-chip-status"><span class="meta-dot" aria-hidden="true"></span><span><?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?></span></div><?php endif; ?>
      <?php if ($showPostCount): ?><div class="meta-chip meta-chip-count"><?= htmlspecialchars($countLabel, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
      <?= $menuButton ?>
    </div>
  </div>
  <?= $navigationHtml ?>
</header>

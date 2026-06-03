<?php
$data = is_array($bms_theme_data ?? null) ? $bms_theme_data : [];
$footerText = (string)($data['footer_text'] ?? '');
$footerHtml = (string)($data['footer_html'] ?? htmlspecialchars($footerText, ENT_QUOTES, 'UTF-8'));
$showPowered = !empty($data['show_powered_by']);
if ($footerHtml === '' && !$showPowered) {
    return;
}
$hasFooterItem = false;
?>
<footer class="site-footer ledger-footer">
  <div class="site-info ledger-footer-inner">
    <?php if ($footerHtml !== ''): ?>
      <span><?= $footerHtml ?></span><?php $hasFooterItem = true; ?>
    <?php endif; ?>
    <?php if ($showPowered): ?>
      <?php if ($hasFooterItem): ?><span class="footer-separator" aria-hidden="true">/</span><?php endif; ?><span>Published with <a href="https://bonumark.org" target="_blank" rel="noopener noreferrer">Bonumark Stream</a>.</span>
    <?php endif; ?>
  </div>
</footer>

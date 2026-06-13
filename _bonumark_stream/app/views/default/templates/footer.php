<?php
$data = is_array($bms_theme_data ?? null) ? $bms_theme_data : [];
$footerItems = is_array($data['footer_items'] ?? null) ? $data['footer_items'] : [];
if (!$footerItems) {
    $footerText = (string)($data['footer_text'] ?? '');
    $footerHtml = trim((string)($data['footer_html'] ?? htmlspecialchars($footerText, ENT_QUOTES, 'UTF-8')));
    if ($footerHtml !== '' && trim(strip_tags($footerHtml)) !== '') {
        $footerItems[] = [
            'key' => 'custom',
            'html' => $footerHtml,
        ];
    }
    if (!empty($data['show_powered_by'])) {
        $footerItems[] = [
            'key' => 'powered',
            'html' => 'Published with <a href="https://bonumark.org" target="_blank" rel="noopener noreferrer">Bonumark Stream</a>.',
        ];
    }
}
$footerItems = array_values(array_filter($footerItems, static function ($item): bool {
    if (!is_array($item)) {
        return false;
    }
    $html = trim((string)($item['html'] ?? ''));
    return $html !== '' && trim(strip_tags($html)) !== '';
}));
if (!$footerItems) {
    return;
}
$separator = trim((string)($data['footer_separator'] ?? ''));
?>
<footer class="site-footer ledger-footer">
  <div class="site-info ledger-footer-inner">
    <?php foreach ($footerItems as $index => $item): ?>
      <?php if ($index > 0 && $separator !== ''): ?><span class="footer-separator" aria-hidden="true"><?= htmlspecialchars($separator, ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
      <span class="footer-item footer-item-<?= htmlspecialchars(preg_replace('/[^a-z0-9_-]+/i', '-', (string)($item['key'] ?? 'item')) ?: 'item', ENT_QUOTES, 'UTF-8') ?>"><?= (string)($item['html'] ?? '') ?></span>
    <?php endforeach; ?>
  </div>
</footer>

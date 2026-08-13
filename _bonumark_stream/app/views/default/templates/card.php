<?php
$data = is_array($bms_theme_data ?? null) ? $bms_theme_data : [];
$pageUrl = (string)($data['page_url'] ?? '#');
$cardClasses = trim((string)($data['classes'] ?? 'stream-card') . ' ledger-stream-card');
$previewMode = !empty($data['preview_mode']);
$cardTheme = is_array($data['theme'] ?? null) ? $data['theme'] : null;
$declarativeCardHtml = bms_render_public_theme_layout_surface('stream-card', $data, $cardTheme);
?>
<article class="<?= htmlspecialchars($cardClasses, ENT_QUOTES, 'UTF-8') ?>" data-stream-card data-stream-url="<?= htmlspecialchars($pageUrl, ENT_QUOTES, 'UTF-8') ?>"<?= $previewMode ? ' data-preview-mode="1"' : '' ?>>
<?php if ($declarativeCardHtml !== null): ?>
<?= $declarativeCardHtml ?>
<?php else: ?>
  <div class="stream-card-inner">
<?= bms_render_core_public_component('stream-card.avatar', $data) ?>
    <div class="stream-card-main">
<?= bms_render_core_public_component('stream-card.header', $data) ?>


<?= bms_render_core_public_component('stream-card.body', $data) ?>
<?= bms_render_core_public_component('stream-card.location', $data) ?>
<?= bms_render_core_public_component('stream-card.link-preview', $data) ?>
<?= bms_render_core_public_component('stream-card.media', $data) ?>

<?= bms_render_core_public_component('stream-card.actions', $data) ?>
    </div>
  </div>
<?php endif; ?>
</article>

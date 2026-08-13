<?php
$data = is_array($bms_component_data ?? null) ? $bms_component_data : [];
$previewMode = !empty($data['preview_mode']);
$showPublicMenu = array_key_exists('show_public_menu', $data) ? !empty($data['show_public_menu']) : !$previewMode;
$navigationHtml = (string)($data['navigation_html'] ?? '');
$menuLabel = trim((string)($data['menu_label'] ?? 'Menu')) ?: 'Menu';
if ($previewMode || !$showPublicMenu || $navigationHtml === '') {
    return;
}
?>
<button type="button" class="site-nav-toggle" aria-expanded="false" aria-controls="site-primary-nav" aria-label="Open menu" data-stream-menu-toggle><span class="site-nav-toggle-label"><?= htmlspecialchars($menuLabel, ENT_QUOTES, 'UTF-8') ?></span><span class="site-nav-toggle-icon" aria-hidden="true"><span></span><span></span><span></span></span></button>

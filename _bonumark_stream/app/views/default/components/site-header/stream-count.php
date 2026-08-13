<?php
$data = is_array($bms_component_data ?? null) ? $bms_component_data : [];
$previewMode = !empty($data['preview_mode']);
$showCount = array_key_exists('show_count_chip', $data) ? !empty($data['show_count_chip']) : !$previewMode;
$countLabel = trim((string)($data['count_label'] ?? ''));
if ($previewMode || !$showCount || $countLabel === '') {
    return;
}
?>
<div class="site-header-stream-count"><span class="site-header-stream-count-label"><?= htmlspecialchars($countLabel, ENT_QUOTES, 'UTF-8') ?></span></div>

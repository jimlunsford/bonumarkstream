<?php
$data = is_array($bms_component_data ?? null) ? $bms_component_data : [];
$navigationHtml = (string)($data['navigation_html'] ?? '');
if ($navigationHtml === '') {
    return;
}
echo $navigationHtml;

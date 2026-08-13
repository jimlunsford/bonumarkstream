<?php
$data = is_array($bms_component_data ?? null) ? $bms_component_data : [];
echo (string)($data['link_preview_html'] ?? '');

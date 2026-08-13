<?php
$data = is_array($bms_component_data ?? null) ? $bms_component_data : [];
echo (string)($data['pinned_posts_html'] ?? '');

<?php
$data = is_array($bms_component_data ?? null) ? $bms_component_data : [];
?>
    <div class="stream-card-avatar"><?= (string)($data['avatar_html'] ?? '') ?></div>

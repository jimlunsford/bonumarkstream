<?php
require_once __DIR__ . '/../../templates/_helpers.php';
$data = ml_theme_data($bms_component_data ?? []);
?>
          <div class="profile-hero-avatar ledger-profile-avatar" aria-hidden="true"><?= (string)($data['avatar_markup'] ?? '') ?></div>

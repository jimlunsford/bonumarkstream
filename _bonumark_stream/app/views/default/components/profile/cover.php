<?php
require_once __DIR__ . '/../../templates/_helpers.php';
$data = ml_theme_data($bms_component_data ?? []);
?>
        <?php if ((string)($data['cover_markup'] ?? '') !== ''): ?>
          <section class="profile-cover-panel ledger-profile-cover" aria-label="Profile cover image">
            <?= (string)$data['cover_markup'] ?>
          </section>
        <?php endif; ?>

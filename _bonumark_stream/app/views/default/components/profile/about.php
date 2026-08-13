<?php
require_once __DIR__ . '/../../templates/_helpers.php';
$data = ml_theme_data($bms_component_data ?? []);
?>
        <?php if ((string)($data['about_html'] ?? '') !== ''): ?>
          <section class="profile-section-card profile-about-card ledger-panel ledger-profile-section">
            <div class="profile-section-heading ledger-profile-section-heading">
              <h2>About</h2>
            </div>
            <div class="profile-about-content"><?= (string)$data['about_html'] ?></div>
          </section>
        <?php endif; ?>

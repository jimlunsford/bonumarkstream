<?php
require_once __DIR__ . '/../../templates/_helpers.php';
$data = ml_theme_data($bms_component_data ?? []);
?>
        <?php if ((string)($data['now_text'] ?? '') !== ''): ?>
          <section class="profile-section-card profile-now-card ledger-panel ledger-profile-section">
            <div class="profile-section-heading ledger-profile-section-heading">
              <h2>Now</h2>
            </div>
            <p class="profile-now-text"><?= nl2br(ml_h((string)$data['now_text'])) ?></p>
          </section>
        <?php endif; ?>

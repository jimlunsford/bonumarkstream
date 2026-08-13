<?php
require_once __DIR__ . '/../../templates/_helpers.php';
$data = ml_theme_data($bms_component_data ?? []);
$interests = is_array($data['interests'] ?? null) ? $data['interests'] : [];
?>
        <?php if ($interests): ?>
          <section class="profile-section-card profile-interests-card ledger-panel ledger-profile-section">
            <div class="profile-section-heading ledger-profile-section-heading">
              <h2>Interests</h2>
            </div>
            <ul class="profile-interest-list" aria-label="Interests">
              <?php foreach ($interests as $interest): ?>
                <li><?= ml_h((string)$interest) ?></li>
              <?php endforeach; ?>
            </ul>
          </section>
        <?php endif; ?>

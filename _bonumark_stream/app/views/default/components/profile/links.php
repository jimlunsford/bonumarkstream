<?php
require_once __DIR__ . '/../../templates/_helpers.php';
$data = ml_theme_data($bms_component_data ?? []);
$profileLinks = is_array($data['profile_links'] ?? null) ? $data['profile_links'] : [];
?>
        <?php if ((string)($data['website'] ?? '') !== '' || $profileLinks): ?>
          <section class="profile-section-card profile-links-card ledger-panel ledger-profile-section">
            <div class="profile-section-heading ledger-profile-section-heading">
              <h2>Links</h2>
            </div>
            <div class="profile-link-list">
              <?php if ((string)($data['website'] ?? '') !== ''): ?>
                <a class="profile-action-link ledger-action-link" href="<?= ml_h((string)$data['website']) ?>" rel="me nofollow noopener noreferrer" target="_blank">Website</a>
              <?php endif; ?>
              <?php foreach ($profileLinks as $link): ?>
                <?php if (is_array($link) && (string)($link['url'] ?? '') !== '' && (string)($link['label'] ?? '') !== ''): ?>
                  <a class="profile-action-link ledger-action-link" href="<?= ml_h((string)$link['url']) ?>" rel="me nofollow noopener noreferrer" target="_blank"><?= ml_h((string)$link['label']) ?></a>
                <?php endif; ?>
              <?php endforeach; ?>
            </div>
          </section>
        <?php endif; ?>

<?php
require_once __DIR__ . '/../../templates/_helpers.php';
$data = ml_theme_data($bms_component_data ?? []);
?>
          <div class="profile-hero-body ledger-profile-body">
            <div class="profile-identity-heading">
              <div>
                <h1 id="profile-name"><?= ml_h((string)($data['display_name'] ?? '')) ?></h1>
                <p class="profile-handle ledger-profile-handle">@<?= ml_h((string)($data['username'] ?? '')) ?></p>
              </div>
              <?php if (!empty($data['is_profile_owner']) && (string)($data['edit_profile_url'] ?? '') !== ''): ?>
                <a class="profile-action-link ledger-action-link profile-owner-edit" href="<?= ml_h((string)$data['edit_profile_url']) ?>">Edit Profile</a>
              <?php endif; ?>
            </div>

            <?php if ((string)($data['headline'] ?? '') !== ''): ?>
              <p class="profile-headline"><?= ml_h((string)$data['headline']) ?></p>
            <?php endif; ?>

            <?php if ((string)($data['location'] ?? '') !== ''): ?>
              <p class="profile-location"><?= ml_h((string)$data['location']) ?></p>
            <?php endif; ?>

            <?php if ((string)($data['bio'] ?? '') !== ''): ?>
              <p class="profile-bio-text ledger-profile-bio"><?= nl2br(ml_h((string)$data['bio'])) ?></p>
            <?php endif; ?>
          </div>

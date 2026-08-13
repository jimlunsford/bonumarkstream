<?php
require_once __DIR__ . '/../../templates/_helpers.php';
$data = ml_theme_data($bms_component_data ?? []);
$profilePhotos = is_array($data['profile_photos'] ?? null) ? $data['profile_photos'] : [];
?>
        <?php if ($profilePhotos): ?>
          <section class="profile-section-card profile-photos-card ledger-panel ledger-profile-section">
            <div class="profile-section-heading ledger-profile-section-heading">
              <h2>Photos</h2>
            </div>
            <div class="profile-photo-gallery profile-photo-gallery-count-<?= count($profilePhotos) ?>" role="group" aria-label="Profile photos">
              <?php foreach ($profilePhotos as $index => $photo): ?>
                <?php if (is_array($photo) && (string)($photo['url'] ?? '') !== '' && (string)($photo['image_attributes'] ?? '') !== ''): ?>
                  <figure class="profile-photo-item profile-photo-item-<?= $index + 1 ?>">
                    <a class="profile-photo-link" data-stream-media-viewer href="<?= ml_h((string)$photo['url']) ?>" aria-label="Open profile photo <?= $index + 1 ?> of <?= count($profilePhotos) ?>">
                      <?php if ((string)($photo['image_markup'] ?? '') !== ''): ?>
                        <?= (string)$photo['image_markup'] ?>
                      <?php else: ?>
                        <img <?= (string)$photo['image_attributes'] ?>>
                      <?php endif; ?>
                    </a>
                    <?php if ((string)($photo['caption'] ?? '') !== ''): ?>
                      <figcaption class="profile-photo-caption"><?= nl2br(ml_h((string)$photo['caption'])) ?></figcaption>
                    <?php endif; ?>
                  </figure>
                <?php endif; ?>
              <?php endforeach; ?>
            </div>
          </section>
        <?php endif; ?>

<?php
require_once __DIR__ . '/../../templates/_helpers.php';
$data = ml_theme_data($bms_component_data ?? []);
$featuredItems = is_array($data['featured_items'] ?? null) ? $data['featured_items'] : [];
?>
        <?php if ($featuredItems): ?>
          <section class="profile-section-card profile-featured-card ledger-panel ledger-profile-section">
            <div class="profile-section-heading ledger-profile-section-heading">
              <h2>Featured</h2>
            </div>
            <div class="profile-featured-list">
              <?php foreach ($featuredItems as $item): ?>
                <?php if (is_array($item) && (string)($item['url'] ?? '') !== '' && (string)($item['title'] ?? '') !== ''): ?>
                  <a class="profile-featured-item profile-featured-item-<?= ml_h((string)($item['type'] ?? 'external')) ?>" href="<?= ml_h((string)$item['url']) ?>"<?= !empty($item['external']) ? ' target="_blank" rel="noopener noreferrer"' : '' ?>>
                    <span class="profile-featured-title"><?= ml_h((string)$item['title']) ?></span>
                    <?php if ((string)($item['description'] ?? '') !== ''): ?>
                      <span class="profile-featured-description"><?= nl2br(ml_h((string)$item['description'])) ?></span>
                    <?php endif; ?>
                    <span class="profile-featured-arrow" aria-hidden="true">→</span>
                  </a>
                <?php endif; ?>
              <?php endforeach; ?>
            </div>
          </section>
        <?php endif; ?>

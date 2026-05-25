<?php
require_once __DIR__ . '/_helpers.php';
$data = ml_theme_data($mp_theme_data ?? []);
$user = is_array($data['user'] ?? null) ? $data['user'] : null;
$postCount = (int)($data['post_count'] ?? 0);
$commentCount = (int)($data['comment_count'] ?? 0);
$profileLinks = is_array($data['profile_links'] ?? null) ? $data['profile_links'] : [];
ml_open_document($data, [
    'fallback_title' => 'Profile',
    'append_site_name' => true,
    'og_type' => 'profile',
    'main_class' => 'site-main profile-page-main profile-layout-shell ledger-profile-shell',
]);
?>
      <?php if (!$user): ?>
        <section class="profile-empty-panel ledger-panel ledger-profile-empty">
          <p class="eyebrow">Profile</p>
          <h1>Profile not found</h1>
          <p>This account does not exist or is not public.</p>
          <p><a class="profile-action-link ledger-action-link" href="<?= ml_h((string)($data['home_url'] ?? '/')) ?>">Back to stream</a></p>
        </section>
      <?php else: ?>
        <section class="profile-hero ledger-profile-hero" aria-labelledby="profile-name">
          <div class="profile-hero-avatar ledger-profile-avatar" aria-hidden="true"><?= (string)($data['avatar_markup'] ?? '') ?></div>
          <div class="profile-hero-body ledger-profile-body">
            <h1 id="profile-name"><?= ml_h((string)($data['display_name'] ?? '')) ?></h1>
            <p class="profile-handle ledger-profile-handle">@<?= ml_h((string)($data['username'] ?? '')) ?></p>
            <?php if ((string)($data['bio'] ?? '') !== ''): ?>
              <p class="profile-bio-text ledger-profile-bio"><?= nl2br(ml_h((string)$data['bio'])) ?></p>
            <?php else: ?>
              <p class="profile-bio-text profile-muted ledger-profile-muted">No profile bio has been added yet.</p>
            <?php endif; ?>
            <?php if ((string)($data['website'] ?? '') !== '' || $profileLinks): ?>
              <div class="profile-hero-actions ledger-profile-actions">
                <?php if ((string)($data['website'] ?? '') !== ''): ?>
                  <a class="profile-action-link ledger-action-link" href="<?= ml_h((string)$data['website']) ?>" rel="me nofollow noopener noreferrer" target="_blank">Website</a>
                <?php endif; ?>
                <?php foreach ($profileLinks as $link): ?>
                  <?php if (is_array($link) && (string)($link['url'] ?? '') !== '' && (string)($link['label'] ?? '') !== ''): ?>
                    <a class="profile-action-link ledger-action-link" href="<?= ml_h((string)$link['url']) ?>" rel="me nofollow noopener noreferrer" target="_blank"><?= ml_h((string)$link['label']) ?></a>
                  <?php endif; ?>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </section>

        <section class="profile-stat-grid ledger-profile-stat-grid" aria-label="Profile stats">
          <div class="profile-stat ledger-profile-stat"><strong><?= $postCount ?></strong><span>Published post<?= $postCount === 1 ? '' : 's' ?></span></div>
          <div class="profile-stat ledger-profile-stat"><strong><?= $commentCount ?></strong><span>Comment<?= $commentCount === 1 ? '' : 's' ?></span></div>
          <?php if ((string)($data['member_since'] ?? '') !== ''): ?><div class="profile-stat ledger-profile-stat"><strong><?= ml_h((string)$data['member_since']) ?></strong><span>Joined</span></div><?php endif; ?>
        </section>

        <section class="profile-activity-panel ledger-profile-activity-panel">
            <div class="profile-section-heading ledger-profile-section-heading">
              <div>
                <p class="eyebrow">Activity</p>
                <h2>Stream posts</h2>
              </div>
            </div>
            <?php if (!empty($data['recent_posts']) && is_array($data['recent_posts'])): ?>
              <ol class="profile-activity-list ledger-profile-activity-list">
                <?php foreach ($data['recent_posts'] as $post): ?>
                  <li class="profile-activity-item ledger-profile-activity-item">
                    <div>
                      <p><?= ml_h((string)($post['excerpt'] ?? 'Stream post')) ?></p>
                      <?php if ((string)($post['date_label'] ?? '') !== ''): ?><span><?= ml_h((string)$post['date_label']) ?></span><?php endif; ?>
                    </div>
                    <?php if ((string)($post['url'] ?? '') !== ''): ?><a href="<?= ml_h((string)$post['url']) ?>"><?= ml_h((string)($post['label'] ?? 'Open post')) ?></a><?php endif; ?>
                  </li>
                <?php endforeach; ?>
              </ol>
            <?php else: ?>
              <p class="profile-muted ledger-profile-muted">No published stream posts yet.</p>
            <?php endif; ?>
        </section>
      <?php endif; ?>
<?php ml_close_document($data); ?>

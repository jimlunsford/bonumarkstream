<?php
require_once __DIR__ . '/../../templates/_helpers.php';
$data = ml_theme_data($bms_component_data ?? []);
$postCount = (int)($data['post_count'] ?? 0);
$commentCount = (int)($data['comment_count'] ?? 0);
$showPostCount = !empty($data['show_post_count']);
$showCommentCount = !empty($data['show_comment_count']);
$showMemberSince = !empty($data['show_member_since']) && (string)($data['member_since'] ?? '') !== '';
?>
        <?php if ($showPostCount || $showCommentCount || $showMemberSince): ?>
          <section class="profile-section-card profile-details-card ledger-panel ledger-profile-section">
            <div class="profile-section-heading ledger-profile-section-heading">
              <h2>Details</h2>
            </div>
            <div class="profile-stat-grid ledger-profile-stat-grid">
              <?php if ($showPostCount): ?>
                <div class="profile-stat ledger-profile-stat"><strong><?= $postCount ?></strong><span>Published post<?= $postCount === 1 ? '' : 's' ?></span></div>
              <?php endif; ?>
              <?php if ($showCommentCount): ?>
                <div class="profile-stat ledger-profile-stat"><strong><?= $commentCount ?></strong><span>Comment<?= $commentCount === 1 ? '' : 's' ?></span></div>
              <?php endif; ?>
              <?php if ($showMemberSince): ?>
                <div class="profile-stat ledger-profile-stat"><strong><?= ml_h((string)$data['member_since']) ?></strong><span>Joined</span></div>
              <?php endif; ?>
            </div>
          </section>
        <?php endif; ?>

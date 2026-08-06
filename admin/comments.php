<?php
require_once __DIR__ . '/../_bonumark_stream/app/comments.php';
require_once __DIR__ . '/_layout.php';
bms_require_login();
bms_require_capability('manage_comments');

require_once __DIR__ . '/_comments-ui.php';

$status = bms_comment_normalize_status((string)($_GET['status'] ?? 'approved'));
$q = trim((string)($_GET['q'] ?? ''));
$returnUrl = bms_comments_admin_return_url($status, $q);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    bms_verify_csrf();
    $action = trim((string)($_POST['moderation_action'] ?? $_POST['action'] ?? ''));
    $singleId = (int)($_POST['comment_id'] ?? 0);
    $selected = is_array($_POST['selected'] ?? null) ? bms_comment_normalize_ids($_POST['selected']) : [];

    if (str_starts_with($action, 'bulk_') && !$selected) {
        bms_flash('Select at least one comment before applying a bulk action.', 'error');
        bms_redirect($returnUrl);
    }

    try {
        if ($action === 'approve' && $singleId > 0) {
            bms_update_comment_status($singleId, 'approved');
            bms_flash('Comment approved.', 'success');
        } elseif ($action === 'pending' && $singleId > 0) {
            bms_update_comment_status($singleId, 'pending');
            bms_flash('Comment moved to pending.', 'success');
        } elseif ($action === 'trash' && $singleId > 0) {
            bms_update_comment_status($singleId, 'trash');
            bms_flash('Comment moved to Trash.', 'success');
        } elseif ($action === 'delete' && $singleId > 0) {
            bms_delete_comment_permanently($singleId);
            bms_flash('Comment permanently deleted.', 'success');
        } elseif (str_starts_with($action, 'bulk_')) {
            $bulkAction = substr($action, 5);
            if ($bulkAction === 'approve') {
                $changed = bms_update_comment_statuses($selected, 'approved');
                bms_flash($changed . ' comment' . ($changed === 1 ? '' : 's') . ' approved.', 'success');
            } elseif ($bulkAction === 'pending') {
                $changed = bms_update_comment_statuses($selected, 'pending');
                bms_flash($changed . ' comment' . ($changed === 1 ? '' : 's') . ' moved to pending.', 'success');
            } elseif ($bulkAction === 'trash') {
                $changed = bms_update_comment_statuses($selected, 'trash');
                bms_flash($changed . ' comment' . ($changed === 1 ? '' : 's') . ' moved to Trash.', 'success');
            } elseif ($bulkAction === 'delete') {
                $changed = bms_delete_trashed_comments_permanently($selected);
                bms_flash($changed . ' comment' . ($changed === 1 ? '' : 's') . ' permanently deleted.', 'success');
            } else {
                throw new RuntimeException('Choose a valid moderation action.');
            }
        } else {
            throw new RuntimeException('Choose a valid moderation action.');
        }
    } catch (Throwable $e) {
        bms_log_admin_exception('comments', $e);
        bms_flash('The requested action could not be completed. Please try again.', 'error');
    }
    bms_redirect($returnUrl);
}

$counts = bms_admin_comment_status_counts();
$comments = bms_list_admin_comments($status, 200, $q);
$csrf = htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8');
$canManageUsers = bms_current_user_can('manage_users');
$statusLabel = bms_comment_status_label($status);

bms_admin_header('Comments', [bms_view_site_action()]);
?>
<nav class="content-filter comments-content-filter" aria-label="Comment status filters">
  <?php foreach (['approved' => 'Approved', 'pending' => 'Pending', 'trash' => 'Trash'] as $key => $label): ?>
    <?php
      $filterArgs = ['status' => $key];
      if ($q !== '') {
          $filterArgs['q'] = $q;
      }
      $filterUrl = bms_admin_url('comments.php?' . http_build_query($filterArgs));
    ?>
    <a class="content-filter-link <?= $status === $key ? 'active' : '' ?>" href="<?= htmlspecialchars($filterUrl, ENT_QUOTES, 'UTF-8') ?>">
      <span class="content-filter-label"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
      <span class="content-filter-count"><?= (int)($counts[$key] ?? 0) ?></span>
    </a>
  <?php endforeach; ?>
</nav>

<p class="content-list-description comments-list-description">Review responses to published Stream Posts, keep pending conversation moving, and remove comments without losing the post or commenter context.</p>

<section class="panel content-record-panel comments-record-panel">
  <div class="content-list-search-region">
    <form method="get" class="content-search-form">
      <input type="hidden" name="status" value="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>">
      <label class="sr-only" for="comments_q">Search comments</label>
      <input id="comments_q" type="search" name="q" value="<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?>" placeholder="Search comments, authors, usernames, posts, or slugs">
      <button type="submit">Search</button>
      <?php if ($q !== ''): ?>
        <a class="button-link secondary" href="<?= htmlspecialchars(bms_admin_url('comments.php?status=' . rawurlencode($status)), ENT_QUOTES, 'UTF-8') ?>">Clear</a>
      <?php endif; ?>
    </form>
  </div>

  <?php if (!$comments): ?>
    <div class="empty-state content-list-empty-state comments-empty-state">
      <h2><?= $q !== '' ? 'No matching comments.' : 'No ' . htmlspecialchars(strtolower($statusLabel), ENT_QUOTES, 'UTF-8') . ' comments.' ?></h2>
      <p><?= $q !== '' ? 'Try a different comment, author, username, post title, or slug.' : ($status === 'pending' ? 'The moderation queue is clear.' : ($status === 'trash' ? 'Deleted comments will appear here until permanently removed.' : 'Approved comments will appear here after they are published or moderated.')) ?></p>
    </div>
  <?php else: ?>
    <div class="content-list-controlbar comments-list-controlbar">
      <form id="comment-bulk-form" method="post" action="<?= htmlspecialchars($returnUrl, ENT_QUOTES, 'UTF-8') ?>" class="content-list-bulk-form comments-bulk-form"<?= $status === 'trash' ? ' onsubmit="return this.moderation_action.value !== \'bulk_delete\' || confirm(\'Permanently delete the selected comments? This cannot be undone.\');"' : '' ?>>
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <label class="content-list-select-all">
          <input type="checkbox" data-select-all data-select-scope="#comment-record-list">
          <span>Select all</span>
        </label>
        <label class="sr-only" for="comment_bulk_action">Bulk moderation action</label>
        <select id="comment_bulk_action" name="moderation_action" required>
          <option value="">Bulk actions</option>
          <?php if ($status !== 'approved'): ?><option value="bulk_approve">Approve</option><?php endif; ?>
          <?php if ($status !== 'pending'): ?><option value="bulk_pending">Move to pending</option><?php endif; ?>
          <?php if ($status !== 'trash'): ?><option value="bulk_trash">Move to Trash</option><?php endif; ?>
          <?php if ($status === 'trash'): ?><option value="bulk_delete">Delete permanently</option><?php endif; ?>
        </select>
        <button type="submit" class="secondary-button">Apply</button>
      </form>
      <div class="content-list-summary">
        <span><?= count($comments) ?> comment<?= count($comments) === 1 ? '' : 's' ?> shown</span>
        <span>Newest first</span>
      </div>
    </div>

    <div class="comment-record-header" aria-hidden="true">
      <span></span>
      <span>Comment</span>
      <span>Author</span>
      <span>Stream Post</span>
      <span>Date</span>
      <span>Actions</span>
    </div>

    <div id="comment-record-list" class="comment-record-list" role="list" aria-label="<?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?> comments">
      <?php foreach ($comments as $comment): ?>
        <?php
          $commentId = (int)($comment['id'] ?? 0);
          $displayName = trim((string)($comment['display_name'] ?? '')) ?: 'Commenter';
          $username = trim((string)($comment['username'] ?? ''));
          $postSlug = trim((string)($comment['post_slug'] ?? ''));
          $postTitle = trim((string)($comment['post_title'] ?? '')) ?: ($postSlug !== '' ? $postSlug : 'Stream Post unavailable');
          $postUrl = $postSlug !== '' ? bms_stream_url($postSlug) : '';
          $profileUrl = function_exists('bms_public_profile_url_for_user') ? bms_public_profile_url_for_user($comment) : '';
          $authorUrl = $canManageUsers && (int)($comment['user_id'] ?? 0) > 0
              ? bms_admin_url('user-edit.php?id=' . (int)$comment['user_id'])
              : $profileUrl;
          $avatarMarkup = function_exists('bms_user_avatar_markup')
              ? bms_user_avatar_markup($comment, 'comment-admin-avatar-image', 96, 40, false)
              : '';
          $createdAt = bms_comments_admin_date((string)($comment['created_at'] ?? ''));
          $statusClass = bms_comments_admin_status_class($status);
        ?>
        <article class="comment-record comment-record-<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>" role="listitem">
          <div class="comment-record-select">
            <input type="checkbox" form="comment-bulk-form" name="selected[]" value="<?= $commentId ?>" aria-label="Select comment by <?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?>">
          </div>

          <div class="comment-record-main">
            <div class="comment-record-title-row">
              <span class="status-pill <?= htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8') ?> comment-record-mobile-status"><?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <div class="comment-record-body"><?= nl2br(htmlspecialchars((string)($comment['body'] ?? ''), ENT_QUOTES, 'UTF-8')) ?></div>
          </div>

          <div class="comment-record-author">
            <span class="comment-record-mobile-label">Author</span>
            <div class="comment-author-summary">
              <?php if ($avatarMarkup !== ''): ?><span class="comment-author-avatar"><?= $avatarMarkup ?></span><?php endif; ?>
              <div class="comment-author-text">
                <?php if ($authorUrl !== ''): ?>
                  <a href="<?= htmlspecialchars($authorUrl, ENT_QUOTES, 'UTF-8') ?>"><strong><?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></strong></a>
                <?php else: ?>
                  <strong><?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></strong>
                <?php endif; ?>
                <?php if ($username !== ''): ?><span>@<?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
              </div>
            </div>
          </div>

          <div class="comment-record-post">
            <span class="comment-record-mobile-label">Stream Post</span>
            <?php if ($postUrl !== ''): ?>
              <a href="<?= htmlspecialchars($postUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener"><?= htmlspecialchars($postTitle, ENT_QUOTES, 'UTF-8') ?></a>
              <code><?= htmlspecialchars($postSlug, ENT_QUOTES, 'UTF-8') ?></code>
            <?php else: ?>
              <span><?= htmlspecialchars($postTitle, ENT_QUOTES, 'UTF-8') ?></span>
            <?php endif; ?>
          </div>

          <div class="comment-record-date">
            <span class="comment-record-mobile-label">Date</span>
            <time><?= htmlspecialchars($createdAt, ENT_QUOTES, 'UTF-8') ?></time>
          </div>

          <div class="comment-record-actions">
            <details class="content-actions-menu" data-content-actions>
              <summary>Actions</summary>
              <div class="content-actions-menu-panel">
                <?php if ($postUrl !== ''): ?><a href="<?= htmlspecialchars($postUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">View Stream Post</a><?php endif; ?>
                <?php if ($status !== 'approved'): ?>
                  <form method="post" action="<?= htmlspecialchars($returnUrl, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="comment_id" value="<?= $commentId ?>">
                    <button name="moderation_action" value="approve" type="submit" class="content-action-button state-link"><?= $status === 'trash' ? 'Restore as approved' : 'Approve' ?></button>
                  </form>
                <?php endif; ?>
                <?php if ($status !== 'pending'): ?>
                  <form method="post" action="<?= htmlspecialchars($returnUrl, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="comment_id" value="<?= $commentId ?>">
                    <button name="moderation_action" value="pending" type="submit" class="content-action-button state-link"><?= $status === 'trash' ? 'Restore as pending' : 'Hold for review' ?></button>
                  </form>
                <?php endif; ?>
                <?php if ($status !== 'trash'): ?>
                  <form method="post" action="<?= htmlspecialchars($returnUrl, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="comment_id" value="<?= $commentId ?>">
                    <button name="moderation_action" value="trash" type="submit" class="content-action-button danger-link">Move to Trash</button>
                  </form>
                <?php else: ?>
                  <form method="post" action="<?= htmlspecialchars($returnUrl, ENT_QUOTES, 'UTF-8') ?>" onsubmit="return confirm('Permanently delete this comment? This cannot be undone.');">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="comment_id" value="<?= $commentId ?>">
                    <button name="moderation_action" value="delete" type="submit" class="content-action-button danger-link">Delete permanently</button>
                  </form>
                <?php endif; ?>
              </div>
            </details>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
<?php bms_admin_footer(); ?>

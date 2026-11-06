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
        // A native confirmation page keeps irreversible actions deliberate
        // without JavaScript. Final submission rechecks CSRF and the existing
        // core delete methods enforce Trash-only deletion again.
        if (($action === 'delete' && $singleId > 0 || $action === 'bulk_delete')
            && (string)($_POST['confirm_delete'] ?? '') !== '1') {
            bms_comments_admin_delete_confirmation($returnUrl, $action === 'bulk_delete' ? $selected : [$singleId], $action === 'bulk_delete');
            exit;
        }

        if (in_array($action, ['approve', 'pending', 'trash'], true) && $singleId > 0) {
            if (!bms_find_comment_by_id($singleId)) {
                throw new RuntimeException('Comment was not found.');
            }
            $newStatus = $action === 'approve' ? 'approved' : $action;
            bms_update_comment_status($singleId, $newStatus);
            bms_flash(match ($action) {
                'approve' => 'Comment approved.',
                'pending' => 'Comment moved to pending.',
                default => 'Comment moved to Trash.',
            }, 'success');
        } elseif ($action === 'delete' && $singleId > 0) {
            bms_delete_comment_permanently($singleId);
            bms_flash('Comment permanently deleted.', 'success');
        } elseif (str_starts_with($action, 'bulk_')) {
            $bulkAction = substr($action, 5);
            if ($bulkAction === 'approve') {
                $changed = bms_update_comment_statuses($selected, 'approved');
                $resultLabel = 'approved';
            } elseif ($bulkAction === 'pending') {
                $changed = bms_update_comment_statuses($selected, 'pending');
                $resultLabel = 'moved to pending';
            } elseif ($bulkAction === 'trash') {
                $changed = bms_update_comment_statuses($selected, 'trash');
                $resultLabel = 'moved to Trash';
            } elseif ($bulkAction === 'delete') {
                $changed = bms_delete_trashed_comments_permanently($selected);
                $resultLabel = 'permanently deleted';
            } else {
                throw new RuntimeException('Choose a valid moderation action.');
            }
            // Affected rows can exclude valid no-op updates. Only when the
            // count is short, verify the requested state through the existing
            // lookup so unchanged targets are not reported as failures.
            $completed = $changed;
            if ($bulkAction !== 'delete' && $changed < count($selected)) {
                $targetStatus = $bulkAction === 'approve' ? 'approved' : $bulkAction;
                $completed = 0;
                foreach ($selected as $selectedId) {
                    $updatedComment = bms_find_comment_by_id($selectedId);
                    if ($updatedComment && (string)$updatedComment['status'] === $targetStatus) {
                        $completed++;
                    }
                }
                $resultLabel = ($completed === 1 ? 'is now ' : 'are now ') . ($targetStatus === 'trash' ? 'in Trash' : $targetStatus);
            }
            $remaining = count($selected) - $completed;
            if ($remaining > 0) {
                $message = $completed . ' of ' . count($selected) . ' selected comments ' . $resultLabel . '. ';
                $message .= $remaining . ' comment' . ($remaining === 1 ? ' was' : 's were');
                $message .= $bulkAction === 'delete'
                    ? ' not deleted. Only comments in Trash can be deleted permanently.'
                    : ' not found or not in the requested state. Review the remaining comments before trying again.';
                bms_flash($message, 'error');
            } else {
                bms_flash($completed . ' comment' . ($completed === 1 ? '' : 's') . ' ' . $resultLabel . '.', 'success');
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

bms_admin_header('Comments');
?>
<nav class="content-filter comments-content-filter" aria-label="Comment status filters">
  <?php foreach (['approved' => 'Approved', 'pending' => 'Pending', 'trash' => 'Trash'] as $key => $label): ?>
    <?php $filterUrl = bms_comments_admin_return_url($key, $q); ?>
    <a class="content-filter-link <?= $status === $key ? 'active' : '' ?>"<?= $status === $key ? ' aria-current="page"' : '' ?> href="<?= htmlspecialchars($filterUrl, ENT_QUOTES, 'UTF-8') ?>">
      <span class="content-filter-label"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
      <span class="content-filter-count"><?= (int)($counts[$key] ?? 0) ?></span>
    </a>
  <?php endforeach; ?>
</nav>

<form method="get" class="comments-search-form">
  <input type="hidden" name="status" value="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>">
  <div class="comments-search-field">
    <label for="comments_q">Search comments</label>
    <input id="comments_q" type="search" name="q" value="<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?>" placeholder="Comments, authors, usernames, or posts">
  </div>
  <div class="comments-search-actions">
    <button type="submit">Search</button>
    <?php if ($q !== ''): ?>
      <a class="button-link secondary" href="<?= htmlspecialchars(bms_comments_admin_return_url($status), ENT_QUOTES, 'UTF-8') ?>">Clear search</a>
    <?php endif; ?>
  </div>
</form>

<section class="comments-record-panel" aria-label="<?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?> comments">
  <?php if (!$comments): ?>
    <div class="empty-state comments-empty-state">
      <h2><?= $q !== '' ? 'No matching comments.' : 'No ' . htmlspecialchars(strtolower($statusLabel), ENT_QUOTES, 'UTF-8') . ' comments.' ?></h2>
      <p><?= $q !== '' ? 'Try a different comment, author, username, post title, or slug.' : ($status === 'pending' ? 'The moderation queue is clear.' : ($status === 'trash' ? 'Comments moved to Trash will appear here until permanently removed.' : 'Approved comments will appear here after they are published or moderated.')) ?></p>
    </div>
  <?php else: ?>
    <div class="comments-list-controlbar">
      <form id="comment-bulk-form" method="post" action="<?= htmlspecialchars($returnUrl, ENT_QUOTES, 'UTF-8') ?>" class="comments-bulk-form">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <label class="comments-select-all">
          <input type="checkbox" data-select-all data-select-scope="#comment-record-list">
          <span>Select all shown</span>
        </label>
        <div class="comments-bulk-field">
          <label for="comment_bulk_action">Bulk action</label>
          <select id="comment_bulk_action" name="moderation_action" required<?= $status === 'trash' ? ' aria-describedby="comments_delete_help"' : '' ?>>
            <option value="">Choose action</option>
            <?php if ($status !== 'approved'): ?><option value="bulk_approve"><?= $status === 'trash' ? 'Restore as approved' : 'Approve' ?></option><?php endif; ?>
            <?php if ($status !== 'pending'): ?><option value="bulk_pending"><?= $status === 'trash' ? 'Restore as pending' : 'Move to pending' ?></option><?php endif; ?>
            <?php if ($status !== 'trash'): ?><option value="bulk_trash">Move to Trash</option><?php endif; ?>
            <?php if ($status === 'trash'): ?><option value="bulk_delete">Delete permanently</option><?php endif; ?>
          </select>
        </div>
        <button type="submit" class="secondary-button">Apply</button>
      </form>
      <p class="comments-list-summary"><?= count($comments) ?> comment<?= count($comments) === 1 ? '' : 's' ?> shown<?= count($comments) === 200 ? ' (200-comment limit)' : '' ?> · Newest first</p>
    </div>
    <noscript><p class="field-help">Select individual comments below, then choose a bulk action and Apply.</p></noscript>
    <?php if ($status === 'trash'): ?><p id="comments_delete_help" class="field-help comments-delete-help">Permanent deletion removes the selected comments. This cannot be undone. You will be asked to confirm.</p><?php endif; ?>

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
              ? bms_user_avatar_markup($comment, 'comment-admin-avatar-image', 96, 32, false)
              : '';
          $createdAt = bms_comments_admin_date((string)($comment['created_at'] ?? ''));
          $statusClass = bms_comments_admin_status_class($status);
          $primaryAction = $status === 'approved' ? 'pending' : 'approve';
          $primaryLabel = $status === 'approved' ? 'Move to pending' : ($status === 'trash' ? 'Restore as approved' : 'Approve');
          $contextLabel = 'comment ' . $commentId . ' by ' . $displayName;
        ?>
        <article class="comment-record comment-record-<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>" role="listitem" data-comment-id="<?= $commentId ?>">
          <label class="comment-record-select">
            <input type="checkbox" form="comment-bulk-form" name="selected[]" value="<?= $commentId ?>" aria-label="Select <?= htmlspecialchars($contextLabel, ENT_QUOTES, 'UTF-8') ?>">
          </label>
          <div class="comment-record-main">
            <div class="comment-record-heading">
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
              <div class="comment-record-meta">
                <span class="status-pill <?= htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?></span>
                <time><?= htmlspecialchars($createdAt, ENT_QUOTES, 'UTF-8') ?></time>
              </div>
            </div>
            <div class="comment-record-body"><?= nl2br(htmlspecialchars((string)($comment['body'] ?? ''), ENT_QUOTES, 'UTF-8')) ?></div>
            <p class="comment-record-post">
              <span>On:</span>
              <?php if ($postUrl !== ''): ?>
                <a href="<?= htmlspecialchars($postUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener"><?= htmlspecialchars($postTitle, ENT_QUOTES, 'UTF-8') ?><span class="sr-only"> (opens in a new tab)</span></a>
              <?php else: ?>
                <span><?= htmlspecialchars($postTitle, ENT_QUOTES, 'UTF-8') ?></span>
              <?php endif; ?>
            </p>
            <div class="comment-record-actions">
              <form class="comment-record-primary" method="post" action="<?= htmlspecialchars($returnUrl, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="comment_id" value="<?= $commentId ?>">
                <button name="moderation_action" value="<?= htmlspecialchars($primaryAction, ENT_QUOTES, 'UTF-8') ?>" type="submit"><?= htmlspecialchars($primaryLabel, ENT_QUOTES, 'UTF-8') ?><span class="sr-only">: <?= htmlspecialchars($contextLabel, ENT_QUOTES, 'UTF-8') ?></span></button>
              </form>
              <details class="content-actions-menu" data-content-actions>
                <summary>Actions<span class="sr-only"> for <?= htmlspecialchars($contextLabel, ENT_QUOTES, 'UTF-8') ?></span></summary>
                <div class="content-actions-menu-panel">
                  <?php if ($postUrl !== ''): ?><a href="<?= htmlspecialchars($postUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">View Stream Post<span class="sr-only"> for <?= htmlspecialchars($contextLabel, ENT_QUOTES, 'UTF-8') ?> (opens in a new tab)</span></a><?php endif; ?>
                  <?php if ($canManageUsers && $authorUrl !== ''): ?><a href="<?= htmlspecialchars($authorUrl, ENT_QUOTES, 'UTF-8') ?>">Manage commenter account<span class="sr-only"> for <?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></span></a><?php endif; ?>
                  <?php if ($status === 'trash'): ?>
                    <form method="post" action="<?= htmlspecialchars($returnUrl, ENT_QUOTES, 'UTF-8') ?>">
                      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                      <input type="hidden" name="comment_id" value="<?= $commentId ?>">
                      <button name="moderation_action" value="pending" type="submit" class="content-action-button state-link">Restore as pending<span class="sr-only">: <?= htmlspecialchars($contextLabel, ENT_QUOTES, 'UTF-8') ?></span></button>
                    </form>
                  <?php endif; ?>
                  <form method="post" action="<?= htmlspecialchars($returnUrl, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="comment_id" value="<?= $commentId ?>">
                    <button name="moderation_action" value="<?= $status === 'trash' ? 'delete' : 'trash' ?>" type="submit" class="content-action-button danger-link"><?= $status === 'trash' ? 'Delete permanently' : 'Move to Trash' ?><span class="sr-only">: <?= htmlspecialchars($contextLabel, ENT_QUOTES, 'UTF-8') ?></span></button>
                  </form>
                </div>
              </details>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
<?php bms_admin_footer(); ?>

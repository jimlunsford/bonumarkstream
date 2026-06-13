<?php
require_once __DIR__ . '/../_bonumark_stream/app/comments.php';
require_once __DIR__ . '/_layout.php';
bms_require_login();
bms_require_capability('manage_comments');

$status = bms_comment_normalize_status((string)($_GET['status'] ?? 'approved'));

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    bms_verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['comment_id'] ?? 0);
    try {
        if ($action === 'approve') {
            bms_update_comment_status($id, 'approved');
            bms_flash('Comment approved.', 'success');
        } elseif ($action === 'pending') {
            bms_update_comment_status($id, 'pending');
            bms_flash('Comment moved to pending.', 'success');
        } elseif ($action === 'trash') {
            bms_update_comment_status($id, 'trash');
            bms_flash('Comment moved to trash.', 'success');
        } elseif ($action === 'delete') {
            bms_delete_comment_permanently($id);
            bms_flash('Comment permanently deleted.', 'success');
        }
    } catch (Throwable $e) {
        bms_flash($e->getMessage(), 'error');
    }
    bms_redirect(bms_admin_url('comments.php?status=' . urlencode($status)));
}

$comments = bms_list_admin_comments($status);
$csrf = htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8');
bms_admin_header('Comments', []);
?>
<section class="panel page-intro-panel">
  <p class="eyebrow">Conversation</p>
  <h2>Review Stream Post comments.</h2>
  <p class="meta">Comment accounts can respond to published Stream Posts. Admins can approve, hold, trash, or delete comments here.</p>
</section>
<section class="panel comments-list-panel">
  <div class="filter-tabs">
    <?php foreach (['approved' => 'Approved', 'pending' => 'Pending', 'trash' => 'Trash'] as $key => $label): ?>
      <a class="button-link secondary <?= $status === $key ? 'is-active' : '' ?>" href="<?= htmlspecialchars(bms_admin_url('comments.php?status=' . $key), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a>
    <?php endforeach; ?>
  </div>
  <?php if (!$comments): ?>
    <p class="meta">No <?= htmlspecialchars(bms_comment_status_label($status), ENT_QUOTES, 'UTF-8') ?> comments.</p>
  <?php else: ?>
    <table class="admin-table compact-table comments-table">
      <thead><tr><th>Comment</th><th>Author</th><th>Post</th><th>Date</th><th>Actions</th></tr></thead>
      <tbody>
        <?php foreach ($comments as $comment): ?>
          <tr>
            <td><?= nl2br(htmlspecialchars((string)($comment['body'] ?? ''), ENT_QUOTES, 'UTF-8')) ?></td>
            <td><strong><?= htmlspecialchars((string)($comment['display_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong><br><span class="meta">@<?= htmlspecialchars((string)($comment['username'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span></td>
            <td><a href="<?= htmlspecialchars(bms_stream_url((string)($comment['post_slug'] ?? '')), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener"><?= htmlspecialchars((string)($comment['post_title'] ?? $comment['post_slug'] ?? 'Stream Post'), ENT_QUOTES, 'UTF-8') ?></a></td>
            <td><?= htmlspecialchars((string)($comment['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
            <td>
              <form method="post" class="inline-user-form comment-action-form">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="comment_id" value="<?= (int)($comment['id'] ?? 0) ?>">
                <?php if ($status !== 'approved'): ?><button name="action" value="approve" type="submit">Approve</button><?php endif; ?>
                <?php if ($status !== 'pending'): ?><button name="action" value="pending" type="submit">Hold</button><?php endif; ?>
                <?php if ($status !== 'trash'): ?><button name="action" value="trash" type="submit">Trash</button><?php endif; ?>
                <?php if ($status === 'trash'): ?><button name="action" value="delete" type="submit" class="danger-button">Delete</button><?php endif; ?>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>
<?php bms_admin_footer(); ?>

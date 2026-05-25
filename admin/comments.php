<?php
require_once __DIR__ . '/../_bonumark_stream/app/comments.php';
require_once __DIR__ . '/_layout.php';
mp_require_login();
mp_require_capability('manage_comments');

$status = mp_comment_normalize_status((string)($_GET['status'] ?? 'approved'));

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    mp_verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['comment_id'] ?? 0);
    try {
        if ($action === 'approve') {
            mp_update_comment_status($id, 'approved');
            mp_flash('Comment approved.', 'success');
        } elseif ($action === 'pending') {
            mp_update_comment_status($id, 'pending');
            mp_flash('Comment moved to pending.', 'success');
        } elseif ($action === 'trash') {
            mp_update_comment_status($id, 'trash');
            mp_flash('Comment moved to trash.', 'success');
        } elseif ($action === 'delete') {
            mp_delete_comment_permanently($id);
            mp_flash('Comment permanently deleted.', 'success');
        }
    } catch (Throwable $e) {
        mp_flash($e->getMessage(), 'error');
    }
    mp_redirect(mp_admin_url('comments.php?status=' . urlencode($status)));
}

$comments = mp_list_admin_comments($status);
$csrf = htmlspecialchars(mp_csrf_token(), ENT_QUOTES, 'UTF-8');
mp_admin_header('Comments', []);
?>
<section class="panel page-intro-panel">
  <p class="eyebrow">Conversation</p>
  <h2>Review Stream Post comments.</h2>
  <p class="meta">Comment accounts can respond to published Stream Posts. Admins can approve, hold, trash, or delete comments here.</p>
</section>
<section class="panel">
  <div class="filter-tabs">
    <?php foreach (['approved' => 'Approved', 'pending' => 'Pending', 'trash' => 'Trash'] as $key => $label): ?>
      <a class="button-link secondary <?= $status === $key ? 'is-active' : '' ?>" href="<?= htmlspecialchars(mp_admin_url('comments.php?status=' . $key), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a>
    <?php endforeach; ?>
  </div>
  <?php if (!$comments): ?>
    <p class="meta">No <?= htmlspecialchars(mp_comment_status_label($status), ENT_QUOTES, 'UTF-8') ?> comments.</p>
  <?php else: ?>
    <table class="admin-table compact-table comments-table">
      <thead><tr><th>Comment</th><th>Author</th><th>Post</th><th>Date</th><th>Actions</th></tr></thead>
      <tbody>
        <?php foreach ($comments as $comment): ?>
          <tr>
            <td><?= nl2br(htmlspecialchars((string)($comment['body'] ?? ''), ENT_QUOTES, 'UTF-8')) ?></td>
            <td><strong><?= htmlspecialchars((string)($comment['display_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong><br><span class="meta">@<?= htmlspecialchars((string)($comment['username'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span></td>
            <td><a href="<?= htmlspecialchars(mp_stream_url((string)($comment['post_slug'] ?? '')), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener"><?= htmlspecialchars((string)($comment['post_title'] ?? $comment['post_slug'] ?? 'Stream Post'), ENT_QUOTES, 'UTF-8') ?></a></td>
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
<?php mp_admin_footer(); ?>

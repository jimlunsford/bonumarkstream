<?php
$data = is_array($bms_theme_data ?? null) ? $bms_theme_data : [];
$comments = is_array($data['comments'] ?? null) ? $data['comments'] : [];
$slug = (string)($data['slug'] ?? '');
?>
<section class="stream-comments ledger-comments" id="comments">
  <div class="comments-header">
    <h2><?= htmlspecialchars((string)($data['label'] ?? '0 Comments'), ENT_QUOTES, 'UTF-8') ?></h2>
  </div>

  <?php if ((string)($data['notice'] ?? '') !== ''): ?>
    <p class="comment-notice"><?= htmlspecialchars((string)$data['notice'], ENT_QUOTES, 'UTF-8') ?></p>
  <?php endif; ?>

  <?php if (!$comments): ?>
    <div class="comment-empty">No comments yet.</div>
  <?php else: ?>
    <ol class="comment-list">
      <?php foreach ($comments as $comment): ?>
        <li class="comment-item">
          <div class="comment-avatar"><?= (string)($comment['avatar_html'] ?? '') ?></div>
          <div class="comment-body">
            <div class="comment-meta">
              <a href="<?= htmlspecialchars((string)($comment['profile_url'] ?? '#'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)($comment['author_name'] ?? 'Commenter'), ENT_QUOTES, 'UTF-8') ?></a>
              <span>@<?= htmlspecialchars((string)($comment['username'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
              <time datetime="<?= htmlspecialchars((string)($comment['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)($comment['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></time>
            </div>
            <p><?= nl2br(htmlspecialchars((string)($comment['body'] ?? ''), ENT_QUOTES, 'UTF-8')) ?></p>
          </div>
        </li>
      <?php endforeach; ?>
    </ol>
  <?php endif; ?>

  <?php if (empty($data['comments_enabled'])): ?>
    <p class="comment-note">Comments are closed.</p>
  <?php elseif (empty($data['can_comment'])): ?>
    <p class="comment-note">
      <?php if (!empty($data['can_create_comment_account'])): ?>
        <a class="comment-inline-link" href="<?= htmlspecialchars((string)($data['register_url'] ?? $data['login_url'] ?? '#'), ENT_QUOTES, 'UTF-8') ?>">Sign in or create a comment account</a> to comment.
      <?php else: ?>
        <a class="comment-inline-link" href="<?= htmlspecialchars((string)($data['login_url'] ?? '#'), ENT_QUOTES, 'UTF-8') ?>">Sign in to comment</a>.
      <?php endif; ?>
    </p>
  <?php else: ?>
    <form class="comment-form" method="post" action="<?= htmlspecialchars((string)($data['comments_url'] ?? 'comments.php'), ENT_QUOTES, 'UTF-8') ?>" data-comment-form>
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)($data['csrf'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="slug" value="<?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?>">

      <label for="comment_body_<?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?>">Add a comment</label>
      <textarea id="comment_body_<?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?>" name="body" rows="4" maxlength="5000" required></textarea>

      <div class="comment-form-actions">
        <button type="submit">Post Comment</button>
      </div>
    </form>
  <?php endif; ?>
</section>

<?php

function bms_comments_admin_date(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return 'Unknown';
    }

    try {
        return (new DateTimeImmutable($value, bms_site_timezone()))->format('M j, Y g:i A');
    } catch (Throwable $e) {
        return $value;
    }
}

function bms_comments_admin_status_class(string $status): string
{
    return match ($status) {
        'approved' => 'published',
        'trash' => 'trash',
        default => 'draft',
    };
}

function bms_comments_admin_return_url(string $status, string $query = ''): string
{
    $args = ['status' => $status];
    if ($query !== '') {
        $args['q'] = $query;
    }
    return bms_admin_url('comments.php?' . http_build_query($args));
}

/** Same-route confirmation shared by single and bulk native form submissions. */
function bms_comments_admin_delete_confirmation(string $returnUrl, array $ids, bool $bulk): void
{
    $safeReturnUrl = htmlspecialchars($returnUrl, ENT_QUOTES, 'UTF-8');
    $csrf = htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8');
    $previews = [];
    foreach (array_slice($ids, 0, 5) as $id) {
        $comment = bms_find_comment_by_id((int)$id);
        if (!$comment) {
            $previews[] = ['author' => 'Comment no longer available', 'excerpt' => 'This selection cannot be deleted.'];
            continue;
        }
        $body = trim((string)($comment['body'] ?? ''));
        $excerpt = bms_text_substr($body, 0, 160);
        $previews[] = [
            'author' => trim((string)($comment['display_name'] ?? '')) ?: 'Commenter',
            'excerpt' => $excerpt . ($excerpt !== $body ? '…' : ''),
        ];
    }
    bms_admin_header('Comments');
    ?>
    <section class="comments-delete-confirmation" aria-labelledby="comments_delete_title">
      <h2 id="comments_delete_title">Permanently delete <?= count($ids) === 1 ? 'this comment' : count($ids) . ' comments' ?>?</h2>
      <p>The selected comments will be removed permanently. This cannot be undone. Only comments currently in Trash can be deleted.</p>
      <ul class="comments-delete-previews">
        <?php foreach ($previews as $preview): ?>
          <li><strong><?= htmlspecialchars($preview['author'], ENT_QUOTES, 'UTF-8') ?></strong><p><?= htmlspecialchars($preview['excerpt'], ENT_QUOTES, 'UTF-8') ?></p></li>
        <?php endforeach; ?>
      </ul>
      <?php if (count($ids) > count($previews)): ?><p class="field-help">And <?= count($ids) - count($previews) ?> more selected comments.</p><?php endif; ?>
      <form method="post" action="<?= $safeReturnUrl ?>">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="moderation_action" value="<?= $bulk ? 'bulk_delete' : 'delete' ?>">
        <input type="hidden" name="confirm_delete" value="1">
        <?php foreach ($ids as $id): ?>
          <input type="hidden" name="<?= $bulk ? 'selected[]' : 'comment_id' ?>" value="<?= (int)$id ?>">
        <?php endforeach; ?>
        <div class="comments-confirm-actions">
          <button type="submit" class="comments-confirm-delete">Delete permanently</button>
          <a class="button-link secondary" href="<?= $safeReturnUrl ?>">Cancel</a>
        </div>
      </form>
    </section>
    <?php
    bms_admin_footer();
}

<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/renderer.php';
require_once __DIR__ . '/../_bonumark_stream/app/dashboard.php';
require_once __DIR__ . '/_layout.php';
bms_require_login();

function bms_dashboard_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function bms_dashboard_scheduled_details(array $item): void
{
    $title = trim((string)$item['title']);
    if ($title === '') {
        $title = bms_plain_excerpt(trim(strip_tags((string)$item['body'])), 72) ?: 'Untitled';
    }
    echo '<h3>' . bms_dashboard_escape($title) . '</h3>';
    if (isset($item['due'])) {
        $due = $item['due'];
        echo '<p>Scheduled for <time datetime="' . bms_dashboard_escape($due->format(DateTimeInterface::ATOM)) . '">'
            . bms_dashboard_escape($due->format('M j, Y \\a\\t g:i A')) . '</time><br>'
            . bms_dashboard_escape($due->getTimezone()->getName() . ' (' . $due->format('T') . ')') . '</p>';
    } else {
        echo '<p>The saved publication time is missing or invalid. Review this scheduled record.</p>';
    }
    echo '<a class="dashboard-review-link" href="' . bms_dashboard_escape(bms_admin_url('content.php?status=scheduled')) . '">Open scheduled content</a>';
}

// Preserve the shared header's scheduler behavior; read the resulting saved state.
bms_admin_header('Dashboard');
$comments = bms_current_user_can('manage_comments') ? bms_dashboard_pending_review('comments') : null;
$accounts = bms_current_user_can('manage_users') ? bms_dashboard_pending_review('accounts') : null;
$scheduled = bms_current_user_can('edit_content') ? bms_dashboard_scheduled_content() : null;
$sources = array_filter(['comments' => $comments, 'accounts' => $accounts, 'scheduled' => $scheduled], static fn($source): bool => $source !== null);
$quiet = count($sources) === 3 && count(array_filter($sources, static fn(array $source): bool => $source['state'] === 'empty')) === 3;
$unavailable = array_filter($sources, static fn(array $source): bool => $source['state'] === 'unavailable');
$attention = ($comments['state'] ?? '') === 'populated' || ($accounts['state'] ?? '') === 'populated' || ($scheduled['needs_review'] ?? false);
?>
<div class="dashboard-review">
  <p class="dashboard-context">Pending review and scheduled content.</p>

  <?php if ($attention): ?>
  <section class="dashboard-review-section" aria-labelledby="dashboard-attention-heading">
    <h2 id="dashboard-attention-heading">Needs attention</h2>
    <?php if (($comments['state'] ?? '') === 'populated'): ?>
    <div class="dashboard-review-row" data-dashboard-source="comments">
      <h3><?= $comments['count'] ?> <?= $comments['count'] === 1 ? 'comment' : 'comments' ?> awaiting review</h3>
      <p>Review <?= $comments['count'] === 1 ? 'this comment' : 'these comments' ?> before <?= $comments['count'] === 1 ? 'it appears' : 'they appear' ?> on your site.</p>
      <a class="dashboard-review-link" href="<?= bms_dashboard_escape(bms_admin_url('comments.php?status=pending')) ?>">Review comments</a>
    </div>
    <?php endif; ?>
    <?php if (($accounts['state'] ?? '') === 'populated'): ?>
    <div class="dashboard-review-row" data-dashboard-source="accounts">
      <h3><?= $accounts['count'] ?> <?= $accounts['count'] === 1 ? 'commenter' : 'commenters' ?> awaiting your approval</h3>
      <p>Review <?= $accounts['count'] === 1 ? 'this account' : 'these accounts' ?> before allowing <?= $accounts['count'] === 1 ? 'it' : 'them' ?> to comment.</p>
      <a class="dashboard-review-link" href="<?= bms_dashboard_escape(bms_admin_url('users.php?status=pending')) ?>">Review commenter accounts</a>
    </div>
    <?php endif; ?>
    <?php if ($scheduled['needs_review'] ?? false): ?>
    <div class="dashboard-review-row" data-dashboard-source="scheduled">
      <h3>Scheduled content needs review</h3>
      <?php bms_dashboard_scheduled_details($scheduled['next']); ?>
    </div>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <?php foreach ($unavailable as $name => $source): ?>
  <?php
    $details = [
        'comments' => ['Comments', 'The number awaiting review is unknown.', 'comments.php?status=pending', 'Open Comments'],
        'accounts' => ['Commenter accounts', 'The number awaiting owner approval is unknown.', 'users.php?status=pending', 'Open commenter accounts'],
        'scheduled' => ['Scheduled content', 'The saved schedule could not be read.', 'content.php?status=scheduled', 'Open scheduled content'],
    ][$name];
  ?>
  <section class="dashboard-review-section dashboard-unavailable" aria-labelledby="dashboard-<?= $name ?>-unavailable" data-dashboard-source="<?= $name ?>">
    <h2 id="dashboard-<?= $name ?>-unavailable"><?= $details[0] ?> unavailable</h2>
    <p class="dashboard-unavailable-message"><?= $details[0] ?> could not be loaded.</p>
    <p><?= $details[1] ?></p>
    <a class="dashboard-review-link" href="<?= bms_dashboard_escape(bms_admin_url($details[2])) ?>"><?= $details[3] ?></a>
  </section>
  <?php endforeach; ?>

  <?php if ($unavailable): ?>
  <div class="dashboard-known-empty">
    <?php if (($comments['state'] ?? '') === 'empty'): ?><p>No comments awaiting review.</p><?php endif; ?>
    <?php if (($accounts['state'] ?? '') === 'empty'): ?><p>No commenter accounts awaiting approval.</p><?php endif; ?>
    <?php if (($scheduled['state'] ?? '') === 'empty'): ?><p>No scheduled content.</p><?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if (($scheduled['state'] ?? '') === 'populated' && !$scheduled['needs_review']): ?>
  <section class="dashboard-review-section" aria-labelledby="dashboard-scheduled-heading" data-dashboard-source="scheduled">
    <h2 id="dashboard-scheduled-heading">Scheduled next</h2>
    <?php bms_dashboard_scheduled_details($scheduled['next']); ?>
  </section>
  <?php endif; ?>

  <?php if ($quiet): ?>
  <section class="dashboard-review-section dashboard-quiet" aria-labelledby="dashboard-quiet-heading">
    <h2 id="dashboard-quiet-heading">Nothing awaiting your review</h2>
    <p>No comments awaiting review.</p>
    <p>No commenter accounts awaiting approval.</p>
    <p>No scheduled content.</p>
  </section>
  <?php endif; ?>
</div>
<?php bms_admin_footer(); ?>

<?php
require_once __DIR__ . '/../_bonumark_stream/app/profiles.php';
require_once __DIR__ . '/_layout.php';
bms_require_login();
bms_require_capability('manage_users');

function bms_accounts_admin_status_class(string $status): string
{
    return match (bms_normalize_user_status($status)) {
        'active' => 'published',
        'inactive' => 'trash',
        default => 'draft',
    };
}

function bms_accounts_admin_filter_url(string $status): string
{
    return $status === 'all'
        ? bms_admin_url('users.php')
        : bms_admin_url('users.php?status=' . rawurlencode($status));
}

$requestedStatus = strtolower(trim((string)($_GET['status'] ?? $_POST['return_status'] ?? 'all')));
$statusFilter = in_array($requestedStatus, ['all', 'active', 'pending', 'inactive'], true) ? $requestedStatus : 'all';
$returnUrl = bms_accounts_admin_filter_url($statusFilter);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    bms_verify_csrf();
    $action = trim((string)($_POST['action'] ?? ''));
    $userId = (int)($_POST['user_id'] ?? 0);

    try {
        if ($action === 'set_status' && $userId > 0) {
            $existing = bms_find_user_by_id_any($userId);
            if (!$existing) {
                throw new RuntimeException('Account was not found.');
            }

            $newStatus = bms_normalize_user_status((string)($_POST['status'] ?? 'active'));
            bms_update_user_role_status($userId, (string)($existing['role'] ?? 'commenter'), $newStatus);
            bms_flash('Account status changed to ' . strtolower(bms_user_status_label($newStatus)) . '.', 'success');
        } elseif (in_array($action, ['approve', 'deactivate'], true) && $userId > 0) {
            $existing = bms_find_user_by_id_any($userId);
            if (!$existing) {
                throw new RuntimeException('Account was not found.');
            }

            $newStatus = $action === 'approve' ? 'active' : 'inactive';
            bms_update_user_role_status($userId, (string)($existing['role'] ?? 'commenter'), $newStatus);
            bms_flash('Account status changed to ' . strtolower(bms_user_status_label($newStatus)) . '.', 'success');
        } else {
            throw new RuntimeException('Choose a valid account action.');
        }
    } catch (Throwable $e) {
        bms_log_admin_exception('users', $e);
        bms_flash('The requested account action could not be completed. Please try again.', 'error');
    }

    bms_redirect($returnUrl);
}

$allUsers = function_exists('bms_list_users') ? bms_list_users() : [bms_current_user()];
$pendingCounts = function_exists('bms_user_pending_counts') ? bms_user_pending_counts() : ['pending_verification' => 0, 'pending_approval' => 0];
$statusCounts = ['all' => count($allUsers), 'active' => 0, 'pending' => 0, 'inactive' => 0];
foreach ($allUsers as $row) {
    $rowStatus = bms_normalize_user_status((string)($row['status'] ?? 'active'));
    $statusCounts[$rowStatus] = (int)($statusCounts[$rowStatus] ?? 0) + 1;
}

$users = array_values(array_filter($allUsers, static function (array $row) use ($statusFilter): bool {
    return $statusFilter === 'all' || bms_normalize_user_status((string)($row['status'] ?? 'active')) === $statusFilter;
}));

$currentUserId = (int)(bms_current_user()['id'] ?? 0);
$csrf = htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8');

bms_admin_header('Commenter accounts', [
    ['label' => 'Add Commenter', 'href' => bms_admin_url('user-new.php')],
    ['label' => 'Profile', 'href' => bms_admin_url('user.php'), 'style' => 'secondary'],
]);
?>
<p class="accounts-pending-summary">
  <span>Pending verification <strong><?= (int)($pendingCounts['pending_verification'] ?? 0) ?></strong></span>
  <span>Pending approval <strong><?= (int)($pendingCounts['pending_approval'] ?? 0) ?></strong></span>
</p>

<nav class="content-filter accounts-content-filter" aria-label="Account status filters">
  <?php foreach (['all' => 'All', 'active' => 'Active', 'pending' => 'Pending', 'inactive' => 'Inactive'] as $key => $label): ?>
    <a class="content-filter-link <?= $statusFilter === $key ? 'active' : '' ?>"<?= $statusFilter === $key ? ' aria-current="page"' : '' ?> href="<?= htmlspecialchars(bms_accounts_admin_filter_url($key), ENT_QUOTES, 'UTF-8') ?>">
      <span class="content-filter-label"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
      <span class="content-filter-count"><?= (int)($statusCounts[$key] ?? 0) ?></span>
    </a>
  <?php endforeach; ?>
</nav>

<section class="accounts-record-panel" aria-label="Account records">
  <?php if (!$users): ?>
    <div class="empty-state content-list-empty-state accounts-empty-state">
      <h2>No <?= htmlspecialchars(strtolower($statusFilter), ENT_QUOTES, 'UTF-8') ?> accounts.</h2>
      <p>Accounts with this status will appear here.</p>
    </div>
  <?php else: ?>
    <div class="accounts-list-summary">
      <span><?= count($users) ?> account<?= count($users) === 1 ? '' : 's' ?> shown</span>
      <span>Sorted by name</span>
    </div>

    <div class="account-record-header" aria-hidden="true">
      <span>Account</span>
      <span>Email</span>
      <span>Type</span>
      <span>Status</span>
      <span>Actions</span>
    </div>

    <div class="account-record-list" role="list" aria-label="Accounts">
      <?php foreach ($users as $row): ?>
        <?php
          $userId = (int)($row['id'] ?? 0);
          $displayName = trim((string)($row['display_name'] ?? '')) ?: (string)($row['username'] ?? 'Account');
          $username = trim((string)($row['username'] ?? ''));
          $email = trim((string)($row['email'] ?? ''));
          $role = bms_normalize_role((string)($row['role'] ?? 'commenter'));
          $roleLabel = bms_role_label($role);
          $rowStatus = bms_normalize_user_status((string)($row['status'] ?? 'active'));
          $statusLabel = bms_user_status_label($rowStatus);
          $statusClass = bms_accounts_admin_status_class($rowStatus);
          $pendingReason = function_exists('bms_user_pending_reason') ? bms_user_pending_reason($row) : '';
          $emailVerified = trim((string)($row['email_verified_at'] ?? '')) !== '';
          $manageUrl = bms_admin_url('user-edit.php?id=' . $userId);
          $profileUrl = bms_public_profile_url_for_user($row);
          $avatarMarkup = bms_user_avatar_markup($row, 'account-admin-avatar-image', 96, 44, false);
          $canChangeStatus = $role !== 'admin' && $userId !== $currentUserId;
        ?>
        <article class="account-record" role="listitem" aria-labelledby="account_name_<?= $userId ?>">
          <div class="account-record-identity">
            <span class="account-record-avatar"><?= $avatarMarkup ?></span>
            <div class="account-record-name">
              <a id="account_name_<?= $userId ?>" href="<?= htmlspecialchars($manageUrl, ENT_QUOTES, 'UTF-8') ?>"><strong><?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></strong></a>
              <?php if ($username !== ''): ?><span>@<?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
            </div>
          </div>

          <div class="account-record-email">
            <span class="account-record-mobile-label">Email</span>
            <span class="account-email-value"><?= $email !== '' ? htmlspecialchars($email, ENT_QUOTES, 'UTF-8') : 'No email address' ?></span>
            <span class="status-pill <?= $emailVerified ? 'published' : 'draft' ?> account-verification-pill"><?= $emailVerified ? 'Email verified' : 'Email not verified' ?></span>
          </div>

          <div class="account-record-type">
            <span class="account-record-mobile-label">Type</span>
            <span><?= htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8') ?></span>
          </div>

          <div class="account-record-status">
            <span class="account-record-mobile-label">Status</span>
            <span class="status-pill <?= htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?></span>
            <?php if ($pendingReason !== ''): ?><small>Awaiting <?= htmlspecialchars(strtolower($pendingReason), ENT_QUOTES, 'UTF-8') ?></small><?php endif; ?>
          </div>

          <div class="account-record-actions">
            <a class="account-record-manage" href="<?= htmlspecialchars($manageUrl, ENT_QUOTES, 'UTF-8') ?>">Manage Account<span class="sr-only"> for <?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?> (@<?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?>)</span></a>
            <details class="content-actions-menu" data-content-actions>
              <summary>Actions<span class="sr-only"> for <?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?> (@<?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?>)</span></summary>
              <div class="content-actions-menu-panel">
                <?php if ($rowStatus === 'active'): ?><a href="<?= htmlspecialchars($profileUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">View Profile<span class="sr-only"> for <?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?> (@<?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?>), opens in a new tab</span></a><?php endif; ?>
                <?php if ($canChangeStatus && $rowStatus !== 'active'): ?>
                  <form method="post" action="<?= htmlspecialchars($returnUrl, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="action" value="set_status">
                    <input type="hidden" name="user_id" value="<?= $userId ?>">
                    <input type="hidden" name="status" value="active">
                    <input type="hidden" name="return_status" value="<?= htmlspecialchars($statusFilter, ENT_QUOTES, 'UTF-8') ?>">
                    <?php if (!$emailVerified): ?><p id="account_activation_<?= $userId ?>" class="account-action-help">Approving or activating this account also marks its email verified.</p><?php endif; ?>
                    <button type="submit" class="content-action-button state-link"<?= !$emailVerified ? ' aria-describedby="account_activation_' . $userId . '"' : '' ?>><?= $rowStatus === 'pending' ? 'Approve Account' : 'Activate Account' ?><span class="sr-only"> for <?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?> (@<?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?>)</span></button>
                  </form>
                <?php endif; ?>
                <?php if ($canChangeStatus && $rowStatus !== 'pending'): ?>
                  <form method="post" action="<?= htmlspecialchars($returnUrl, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="action" value="set_status">
                    <input type="hidden" name="user_id" value="<?= $userId ?>">
                    <input type="hidden" name="status" value="pending">
                    <input type="hidden" name="return_status" value="<?= htmlspecialchars($statusFilter, ENT_QUOTES, 'UTF-8') ?>">
                    <button type="submit" class="content-action-button state-link">Move to Pending<span class="sr-only"> for <?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?> (@<?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?>)</span></button>
                  </form>
                <?php endif; ?>
                <?php if ($canChangeStatus && $rowStatus !== 'inactive'): ?>
                  <form method="post" action="<?= htmlspecialchars($returnUrl, ENT_QUOTES, 'UTF-8') ?>" onsubmit="return confirm('Deactivate this commenter account?');">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="action" value="set_status">
                    <input type="hidden" name="user_id" value="<?= $userId ?>">
                    <input type="hidden" name="status" value="inactive">
                    <input type="hidden" name="return_status" value="<?= htmlspecialchars($statusFilter, ENT_QUOTES, 'UTF-8') ?>">
                    <button type="submit" class="content-action-button danger-link">Deactivate Account<span class="sr-only"> for <?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?> (@<?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?>)</span></button>
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

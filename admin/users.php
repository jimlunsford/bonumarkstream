<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/_layout.php';
bms_require_login();
bms_require_capability('manage_users');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    bms_verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'create') {
            bms_require_capability('manage_users');
            $user = bms_create_user((string)($_POST['username'] ?? ''), (string)($_POST['display_name'] ?? ''), (string)($_POST['email'] ?? ''), 'commenter', (string)($_POST['password'] ?? ''));
            bms_flash('Commenter account created. “' . ($user['display_name'] ?? 'New commenter') . '” can now sign in.', 'success');
            bms_redirect(bms_admin_url('users.php'));
        }
        if ($action === 'update_role_status') {
            bms_require_capability('manage_users');
            bms_update_user_role_status((int)($_POST['user_id'] ?? 0), 'commenter', (string)($_POST['status'] ?? 'active'));
            bms_flash('Commenter status updated.', 'success');
            bms_redirect(bms_admin_url('users.php'));
        }
        if ($action === 'approve') {
            bms_require_capability('manage_users');
            $userId = (int)($_POST['user_id'] ?? 0);
            $existing = bms_find_user_by_id_any($userId);
            if (!$existing) {
                throw new RuntimeException('Account was not found.');
            }
            bms_update_user_role_status($userId, (string)($existing['role'] ?? 'commenter'), 'active');
            bms_flash('Pending account approved.', 'success');
            bms_redirect(bms_admin_url('users.php'));
        }
        if ($action === 'deactivate') {
            bms_require_capability('manage_users');
            $userId = (int)($_POST['user_id'] ?? 0);
            $existing = bms_find_user_by_id_any($userId);
            if (!$existing) {
                throw new RuntimeException('Account was not found.');
            }
            bms_update_user_role_status($userId, (string)($existing['role'] ?? 'commenter'), 'inactive');
            bms_flash('Account deactivated.', 'success');
            bms_redirect(bms_admin_url('users.php'));
        }
    } catch (Throwable $e) {
        bms_log_admin_exception('users', $e);

        bms_flash('The requested action could not be completed. Please try again.', 'error');
        bms_redirect(bms_admin_url('users.php'));
    }
}

$users = function_exists('bms_list_users') ? bms_list_users() : [bms_current_user()];
$canManage = bms_current_user_can('manage_users');
$pendingCounts = function_exists('bms_user_pending_counts') ? bms_user_pending_counts() : ['pending_verification' => 0, 'pending_approval' => 0];
bms_admin_header('Accounts', [
    ['label' => 'Profile', 'href' => bms_admin_url('user.php'), 'style' => 'secondary'],
]);
?>
<section class="panel page-intro-panel">
  <p class="eyebrow">Accounts</p>
  <h2>Manage the admin account and commenter accounts.</h2>
  <p class="meta">The installer-created admin is the sole publisher. Commenters can manage a profile and participate through comments, but they cannot publish stream posts or access the admin publishing system.</p>
</section>

<section class="panel">
  <div class="info-grid">
    <div class="info-card"><strong>Pending verification</strong><p><?= (int)($pendingCounts['pending_verification'] ?? 0) ?></p></div>
    <div class="info-card"><strong>Pending approval</strong><p><?= (int)($pendingCounts['pending_approval'] ?? 0) ?></p></div>
    <div class="info-card"><strong>Total accounts</strong><p><?= count($users) ?></p></div>
  </div>
</section>

<section class="panel">
  <table class="admin-table compact-table">
    <thead><tr><th>Username</th><th>Display name</th><th>Email</th><th>Verified</th><th>Type</th><th>Status</th><th>Pending reason</th><th>Quick update</th><th>Manage</th></tr></thead>
    <tbody>
      <?php foreach ($users as $row): ?>
        <?php $pendingReason = function_exists('bms_user_pending_reason') ? bms_user_pending_reason($row) : ''; ?>
        <tr>
          <td><strong><?= htmlspecialchars((string)($row['username'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong></td>
          <td><?= htmlspecialchars((string)($row['display_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
          <td><?= htmlspecialchars((string)($row['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
          <td><?= trim((string)($row['email_verified_at'] ?? '')) !== '' ? '<span class="status-pill published">Yes</span>' : '<span class="status-pill draft">No</span>' ?></td>
          <td><?= htmlspecialchars(bms_role_label((string)($row['role'] ?? 'commenter')), ENT_QUOTES, 'UTF-8') ?></td>
          <td><span class="status-pill <?= (string)($row['status'] ?? 'active') === 'active' ? 'published' : 'draft' ?>"><?= htmlspecialchars(bms_user_status_label((string)($row['status'] ?? 'active')), ENT_QUOTES, 'UTF-8') ?></span></td>
          <td><?= $pendingReason !== '' ? htmlspecialchars($pendingReason, ENT_QUOTES, 'UTF-8') : '<span class="meta">None</span>' ?></td>
          <td>
            <?php if ($canManage): ?>
              <form method="post" class="inline-user-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="update_role_status">
                <input type="hidden" name="user_id" value="<?= (int)($row['id'] ?? 0) ?>">
                <input type="hidden" name="role" value="<?= bms_normalize_role((string)($row['role'] ?? 'commenter')) ?>">
                <select name="status" aria-label="Status"><?php foreach (bms_user_status_options() as $statusKey => $statusLabel): ?><option value="<?= htmlspecialchars($statusKey, ENT_QUOTES, 'UTF-8') ?>" <?= bms_normalize_user_status((string)($row['status'] ?? 'active')) === $statusKey ? 'selected' : '' ?>><?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select>
                <button type="submit">Update</button>
              </form>
              <?php if ((string)($row['status'] ?? '') === 'pending' && trim((string)($row['email_verified_at'] ?? '')) !== ''): ?>
                <form method="post" class="inline-user-form">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                  <input type="hidden" name="action" value="approve">
                  <input type="hidden" name="user_id" value="<?= (int)($row['id'] ?? 0) ?>">
                  <button type="submit">Approve</button>
                </form>
              <?php endif; ?>
              <?php if ((string)($row['status'] ?? '') === 'pending'): ?>
                <form method="post" class="inline-user-form">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                  <input type="hidden" name="action" value="deactivate">
                  <input type="hidden" name="user_id" value="<?= (int)($row['id'] ?? 0) ?>">
                  <button type="submit">Reject</button>
                </form>
              <?php endif; ?>
            <?php else: ?>
              <span class="meta">No access</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($canManage): ?>
              <a class="button secondary-button compact-button" href="<?= htmlspecialchars(bms_admin_url('user-edit.php?id=' . (int)($row['id'] ?? 0)), ENT_QUOTES, 'UTF-8') ?>">Manage</a>
            <?php else: ?>
              <span class="meta">No access</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php if ($canManage): ?>
<section class="panel settings-panel">
  <h2>Add commenter</h2>
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="action" value="create">
    <label for="username">Username</label><input id="username" name="username" type="text" required>
    <label for="display_name">Display name</label><input id="display_name" name="display_name" type="text" required>
    <label for="email">Email</label><input id="email" name="email" type="email">
    <label for="password">Temporary password</label><input id="password" name="password" type="password" required>
    <p class="field-help">Commenters can sign in, manage their profile, and participate through comments if comments are enabled.</p>
    <button type="submit">Create Commenter</button>
  </form>
</section>
<?php endif; ?>
<?php bms_admin_footer(); ?>

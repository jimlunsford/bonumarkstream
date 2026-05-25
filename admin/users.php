<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/_layout.php';
mp_require_login();
mp_require_capability('view_admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    mp_verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'create') {
            mp_require_capability('manage_users');
            $user = mp_create_user((string)($_POST['username'] ?? ''), (string)($_POST['display_name'] ?? ''), (string)($_POST['email'] ?? ''), (string)($_POST['role'] ?? 'user'), (string)($_POST['password'] ?? ''));
            mp_flash('Account created. “' . ($user['display_name'] ?? 'New user') . '” can now sign in.', 'success');
            mp_redirect(mp_admin_url('users.php'));
        }
        if ($action === 'update_role_status') {
            mp_require_capability('manage_users');
            mp_update_user_role_status((int)($_POST['user_id'] ?? 0), (string)($_POST['role'] ?? 'user'), (string)($_POST['status'] ?? 'active'));
            mp_flash('User role and status updated.', 'success');
            mp_redirect(mp_admin_url('users.php'));
        }
        if ($action === 'approve') {
            mp_require_capability('manage_users');
            $userId = (int)($_POST['user_id'] ?? 0);
            $existing = mp_find_user_by_id_any($userId);
            if (!$existing) {
                throw new RuntimeException('User was not found.');
            }
            mp_update_user_role_status($userId, (string)($existing['role'] ?? 'commenter'), 'active');
            mp_flash('Pending account approved.', 'success');
            mp_redirect(mp_admin_url('users.php'));
        }
        if ($action === 'deactivate') {
            mp_require_capability('manage_users');
            $userId = (int)($_POST['user_id'] ?? 0);
            $existing = mp_find_user_by_id_any($userId);
            if (!$existing) {
                throw new RuntimeException('User was not found.');
            }
            mp_update_user_role_status($userId, (string)($existing['role'] ?? 'commenter'), 'inactive');
            mp_flash('Account deactivated.', 'success');
            mp_redirect(mp_admin_url('users.php'));
        }
    } catch (Throwable $e) {
        mp_flash($e->getMessage(), 'error');
        mp_redirect(mp_admin_url('users.php'));
    }
}

$users = function_exists('mp_list_users') ? mp_list_users() : [mp_current_user()];
$canManage = mp_current_user_can('manage_users');
$pendingCounts = function_exists('mp_user_pending_counts') ? mp_user_pending_counts() : ['pending_verification' => 0, 'pending_approval' => 0];
mp_admin_header('Users', [
    ['label' => 'Profile', 'href' => mp_admin_url('user.php'), 'style' => 'secondary'],
]);
?>
<section class="panel page-intro-panel">
  <p class="eyebrow">Accounts and roles</p>
  <h2>Control who can publish, manage, and comment inside Bonumark Stream.</h2>
  <p class="meta">Admin has full control. User can create a profile and publish Stream Posts. Commenter can create a profile and comment on published posts.</p>
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
    <thead><tr><th>Username</th><th>Display name</th><th>Email</th><th>Verified</th><th>Role</th><th>Status</th><th>Pending reason</th><th>Quick update</th><th>Manage</th></tr></thead>
    <tbody>
      <?php foreach ($users as $row): ?>
        <?php $pendingReason = function_exists('mp_user_pending_reason') ? mp_user_pending_reason($row) : ''; ?>
        <tr>
          <td><strong><?= htmlspecialchars((string)($row['username'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong></td>
          <td><?= htmlspecialchars((string)($row['display_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
          <td><?= htmlspecialchars((string)($row['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
          <td><?= trim((string)($row['email_verified_at'] ?? '')) !== '' ? '<span class="status-pill published">Yes</span>' : '<span class="status-pill draft">No</span>' ?></td>
          <td><?= htmlspecialchars(mp_role_label((string)($row['role'] ?? 'user')), ENT_QUOTES, 'UTF-8') ?></td>
          <td><span class="status-pill <?= (string)($row['status'] ?? 'active') === 'active' ? 'published' : 'draft' ?>"><?= htmlspecialchars(mp_user_status_label((string)($row['status'] ?? 'active')), ENT_QUOTES, 'UTF-8') ?></span></td>
          <td><?= $pendingReason !== '' ? htmlspecialchars($pendingReason, ENT_QUOTES, 'UTF-8') : '<span class="meta">None</span>' ?></td>
          <td>
            <?php if ($canManage): ?>
              <form method="post" class="inline-user-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(mp_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="update_role_status">
                <input type="hidden" name="user_id" value="<?= (int)($row['id'] ?? 0) ?>">
                <select name="role" aria-label="Role">
                  <?php foreach (mp_roles() as $key => $label): ?><option value="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>" <?= mp_normalize_role((string)($row['role'] ?? 'user')) === $key ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?>
                </select>
                <select name="status" aria-label="Status"><?php foreach (mp_user_status_options() as $statusKey => $statusLabel): ?><option value="<?= htmlspecialchars($statusKey, ENT_QUOTES, 'UTF-8') ?>" <?= mp_normalize_user_status((string)($row['status'] ?? 'active')) === $statusKey ? 'selected' : '' ?>><?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select>
                <button type="submit">Update</button>
              </form>
              <?php if ((string)($row['status'] ?? '') === 'pending' && trim((string)($row['email_verified_at'] ?? '')) !== ''): ?>
                <form method="post" class="inline-user-form">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(mp_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                  <input type="hidden" name="action" value="approve">
                  <input type="hidden" name="user_id" value="<?= (int)($row['id'] ?? 0) ?>">
                  <button type="submit">Approve</button>
                </form>
              <?php endif; ?>
              <?php if ((string)($row['status'] ?? '') === 'pending'): ?>
                <form method="post" class="inline-user-form">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(mp_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
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
              <a class="button secondary-button compact-button" href="<?= htmlspecialchars(mp_admin_url('user-edit.php?id=' . (int)($row['id'] ?? 0)), ENT_QUOTES, 'UTF-8') ?>">Manage</a>
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
  <h2>Add account</h2>
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(mp_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="action" value="create">
    <label for="username">Username</label><input id="username" name="username" type="text" required>
    <label for="display_name">Display name</label><input id="display_name" name="display_name" type="text" required>
    <label for="email">Email</label><input id="email" name="email" type="email">
    <label for="role">Role</label><select id="role" name="role"><?php foreach (mp_roles() as $key => $label): ?><option value="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select>
    <label for="password">Temporary password</label><input id="password" name="password" type="password" required>
    <p class="field-help">Password rules match Bonumark Stream’s admin password policy. The user should change it after first login.</p>
    <button type="submit">Create User</button>
  </form>
</section>
<?php endif; ?>
<?php mp_admin_footer(); ?>

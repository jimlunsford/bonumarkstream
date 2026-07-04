<?php
require_once __DIR__ . '/../_bonumark_stream/app/profiles.php';
require_once __DIR__ . '/_layout.php';
bms_require_login();
bms_require_capability('manage_users');

$userId = (int)($_GET['id'] ?? $_POST['user_id'] ?? 0);
$user = $userId > 0 ? bms_find_user_by_id_any($userId) : null;
if (!$user) {
    bms_flash('Account was not found.', 'error');
    bms_redirect(bms_admin_url('users.php'));
}

$currentUser = bms_current_user();
$currentUserId = (int)($currentUser['id'] ?? 0);
$isCurrentUser = (int)($user['id'] ?? 0) === $currentUserId;
$reassignTargets = bms_user_delete_reassign_targets((int)($user['id'] ?? 0));
$defaultReassignId = 0;
foreach ($reassignTargets as $target) {
    if ((int)($target['id'] ?? 0) === $currentUserId) {
        $defaultReassignId = $currentUserId;
        break;
    }
}
if ($defaultReassignId === 0 && !empty($reassignTargets)) {
    $defaultReassignId = (int)($reassignTargets[0]['id'] ?? 0);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    bms_verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'update_account') {
            $user = bms_admin_update_user_account(
                $userId,
                (string)($_POST['username'] ?? ''),
                (string)($_POST['display_name'] ?? ''),
                (string)($_POST['email'] ?? ''),
                bms_normalize_role((string)($user['role'] ?? 'commenter')),
                (string)($_POST['status'] ?? 'active'),
                (string)($_POST['profile_visibility'] ?? 'public'),
                !empty($_POST['email_verified'])
            );
            bms_flash('Account updated.', 'success');
            bms_redirect(bms_admin_url('user-edit.php?id=' . $userId));
        }

        if ($action === 'reset_password') {
            bms_admin_reset_user_password(
                $userId,
                (string)($_POST['new_password'] ?? ''),
                (string)($_POST['confirm_password'] ?? '')
            );
            bms_flash('Password reset. Share the temporary password securely.', 'success');
            bms_redirect(bms_admin_url('user-edit.php?id=' . $userId . '#reset-password'));
        }

        if ($action === 'delete_user') {
            bms_admin_delete_user(
                $userId,
                (int)($_POST['reassign_to'] ?? 0),
                (string)($_POST['confirm_username'] ?? '')
            );
            bms_flash('Account deleted and owned records reassigned.', 'success');
            bms_redirect(bms_admin_url('users.php'));
        }

        bms_flash('Unknown account management action.', 'error');
        bms_redirect(bms_admin_url('user-edit.php?id=' . $userId));
    } catch (Throwable $e) {
        bms_log_admin_exception('user-edit', $e);

        bms_flash('The requested action could not be completed. Please try again.', 'error');
        bms_redirect(bms_admin_url('user-edit.php?id=' . $userId));
    }
}

function bms_admin_user_count_rows(int $userId): array
{
    $counts = ['stream_posts' => 0, 'comments' => 0, 'media' => 0];
    try {
        $stmt = bms_db()->prepare('SELECT COUNT(*) FROM ' . bms_table('posts') . ' WHERE author_id = :id');
        $stmt->execute(['id' => $userId]);
        $counts['stream_posts'] = (int)$stmt->fetchColumn();
    } catch (Throwable $e) {}
    try {
        $stmt = bms_db()->prepare('SELECT COUNT(*) FROM ' . bms_table('comments') . ' WHERE user_id = :id');
        $stmt->execute(['id' => $userId]);
        $counts['comments'] = (int)$stmt->fetchColumn();
    } catch (Throwable $e) {}
    try {
        $stmt = bms_db()->prepare('SELECT COUNT(*) FROM ' . bms_table('media') . ' WHERE uploaded_by = :id');
        $stmt->execute(['id' => $userId]);
        $counts['media'] = (int)$stmt->fetchColumn();
    } catch (Throwable $e) {}
    return $counts;
}

$counts = bms_admin_user_count_rows((int)($user['id'] ?? 0));
$viewProfileUrl = function_exists('bms_public_profile_url_for_user') ? bms_public_profile_url_for_user($user) : bms_url_path('profile.php?user=' . rawurlencode((string)($user['username'] ?? '')));

bms_admin_header('Edit Account', [
    ['label' => 'Accounts', 'href' => bms_admin_url('users.php'), 'style' => 'secondary'],
    ['label' => 'View Profile', 'href' => $viewProfileUrl, 'style' => 'secondary', 'target' => true],
]);
?>
<section class="panel page-intro-panel">
  <p class="eyebrow">Account management</p>
  <h2><?= htmlspecialchars((string)($user['display_name'] ?? $user['username'] ?? 'Account'), ENT_QUOTES, 'UTF-8') ?></h2>
  <p class="meta">Edit account details, reset the password, and manage account deletion from one place.</p>
</section>

<section class="panel">
  <div class="info-grid">
    <div class="info-card"><strong>Account type</strong><p><?= htmlspecialchars(bms_role_label((string)($user['role'] ?? 'commenter')), ENT_QUOTES, 'UTF-8') ?></p></div>
    <div class="info-card"><strong>Status</strong><p><?= htmlspecialchars(bms_user_status_label((string)($user['status'] ?? 'active')), ENT_QUOTES, 'UTF-8') ?></p></div>
    <div class="info-card"><strong>Email</strong><p><?= trim((string)($user['email_verified_at'] ?? '')) !== '' ? 'Verified' : 'Not verified' ?></p></div>
  </div>
</section>

<section class="panel settings-panel">
  <h2>Account details</h2>
  <form method="post" class="settings-form">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="action" value="update_account">
    <input type="hidden" name="user_id" value="<?= (int)($user['id'] ?? 0) ?>">

    <label for="username">Username</label>
    <input id="username" name="username" type="text" value="<?= htmlspecialchars((string)($user['username'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required>

    <label for="display_name">Display name</label>
    <input id="display_name" name="display_name" type="text" value="<?= htmlspecialchars((string)($user['display_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required>

    <label for="email">Email</label>
    <input id="email" name="email" type="email" value="<?= htmlspecialchars((string)($user['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">

    <p class="field-help"><strong>Account type:</strong> <?= htmlspecialchars(bms_role_label((string)($user['role'] ?? 'commenter')), ENT_QUOTES, 'UTF-8') ?>. Account type is fixed in Bonumark Stream: one admin, plus commenter accounts.</p>

    <label for="status">Status</label>
    <select id="status" name="status">
      <?php foreach (bms_user_status_options() as $statusKey => $statusLabel): ?>
        <option value="<?= htmlspecialchars($statusKey, ENT_QUOTES, 'UTF-8') ?>" <?= bms_normalize_user_status((string)($user['status'] ?? 'active')) === $statusKey ? 'selected' : '' ?>><?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?></option>
      <?php endforeach; ?>
    </select>
    <?php if ($isCurrentUser): ?><p class="field-help">You cannot remove your own active admin access from this screen.</p><?php endif; ?>

    <label for="profile_visibility">Profile visibility</label>
    <select id="profile_visibility" name="profile_visibility">
      <option value="public" <?= ((string)($user['profile_visibility'] ?? 'public') === 'public') ? 'selected' : '' ?>>Public</option>
      <option value="private" <?= ((string)($user['profile_visibility'] ?? 'public') === 'private') ? 'selected' : '' ?>>Private</option>
    </select>

    <label class="checkbox-row"><input type="checkbox" name="email_verified" value="1" <?= trim((string)($user['email_verified_at'] ?? '')) !== '' ? 'checked' : '' ?>> Mark email as verified</label>

    <div class="form-actions-row user-edit-actions">
      <button type="submit">Save Account</button>
      <a class="button secondary-button" href="<?= htmlspecialchars(bms_admin_url('users.php'), ENT_QUOTES, 'UTF-8') ?>">Cancel</a>
    </div>
  </form>
</section>

<section id="reset-password" class="panel settings-panel">
  <h2>Reset password</h2>
  <p class="meta">Set a temporary password for this account. They should change it after signing in.</p>
  <form method="post" class="settings-form">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="action" value="reset_password">
    <input type="hidden" name="user_id" value="<?= (int)($user['id'] ?? 0) ?>">

    <label for="new_password">Temporary password</label>
    <input id="new_password" name="new_password" type="password" autocomplete="new-password" required>

    <label for="confirm_password">Confirm temporary password</label>
    <input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" required>

    <button type="submit">Reset Password</button>
  </form>
</section>

<section class="panel">
  <h2>Owned records</h2>
  <div class="info-grid">
    <div class="info-card"><strong>Posts and pages</strong><p><?= (int)$counts['stream_posts'] ?></p></div>
    <div class="info-card"><strong>Comments</strong><p><?= (int)$counts['comments'] ?></p></div>
    <div class="info-card"><strong>Media uploads</strong><p><?= (int)$counts['media'] ?></p></div>
  </div>
</section>

<section class="panel danger-zone">
  <h2>Delete account</h2>
  <?php if ($isCurrentUser): ?>
    <p class="notice warning">You cannot delete your own account while signed in. Create or sign in as another admin first.</p>
  <?php elseif (empty($reassignTargets)): ?>
    <p class="notice warning">Create or activate another admin before deleting this account so owned records have somewhere safe to go.</p>
  <?php else: ?>
    <p class="meta">Deleting an account removes the login and reassigns posts, comments, media ownership, and moderation records to another active account.</p>
    <form method="post" class="settings-form" data-confirm="Delete this account? This removes the login and reassigns owned records.">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="action" value="delete_user">
      <input type="hidden" name="user_id" value="<?= (int)($user['id'] ?? 0) ?>">

      <label for="reassign_to">Reassign owned records to</label>
      <select id="reassign_to" name="reassign_to" required>
        <?php foreach ($reassignTargets as $target): ?>
          <?php $targetId = (int)($target['id'] ?? 0); ?>
          <option value="<?= $targetId ?>" <?= $targetId === $defaultReassignId ? 'selected' : '' ?>><?= htmlspecialchars((string)($target['display_name'] ?? $target['username'] ?? 'Account'), ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars(bms_role_label((string)($target['role'] ?? 'commenter')), ENT_QUOTES, 'UTF-8') ?>)</option>
        <?php endforeach; ?>
      </select>

      <label for="confirm_username">Type username to confirm deletion</label>
      <input id="confirm_username" name="confirm_username" type="text" autocomplete="off" placeholder="<?= htmlspecialchars((string)($user['username'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required>

      <button type="submit" class="danger">Delete Account</button>
    </form>
  <?php endif; ?>
</section>
<?php bms_admin_footer(); ?>

<?php
require_once __DIR__ . '/../_bonumark_stream/app/profiles.php';
require_once __DIR__ . '/_layout.php';
mp_require_login();

$user = mp_current_user();
$socialLinkDefinitions = function_exists('mp_profile_social_link_definitions') ? mp_profile_social_link_definitions() : [];
$socialLinkValues = function_exists('mp_profile_social_link_form_values') ? mp_profile_social_link_form_values($user) : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    mp_verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'profile') {
            $user = mp_update_current_user_profile((string)($_POST['username'] ?? ''), (string)($_POST['display_name'] ?? ''), (string)($_POST['email'] ?? ''), (string)($_POST['bio'] ?? ''), (string)($_POST['website'] ?? ''), (string)($_POST['profile_visibility'] ?? 'public'), is_array($_POST['social_links'] ?? null) ? $_POST['social_links'] : []);
            mp_apply_current_user_avatar_from_request($_FILES, !empty($_POST['remove_avatar']));
            mp_flash('Profile updated. Your admin display details and profile picture were saved.', 'success');
            mp_redirect(mp_admin_url('user.php'));
        }

        if ($action === 'password') {
            mp_update_current_user_password(
                (string)($_POST['current_password'] ?? ''),
                (string)($_POST['new_password'] ?? ''),
                (string)($_POST['confirm_password'] ?? '')
            );
            mp_flash('Password updated. Use the new password the next time you log in.', 'success');
            mp_redirect(mp_admin_url('user.php'));
        }

        mp_flash('Unknown account action.', 'error');
        mp_redirect(mp_admin_url('user.php'));
    } catch (Throwable $e) {
        mp_flash($e->getMessage(), 'error');
        mp_redirect(mp_admin_url('user.php'));
    }
}

mp_admin_header('Account', [
    ['label' => 'View Profile', 'href' => function_exists('mp_public_profile_url_for_user') ? mp_public_profile_url_for_user($user) : mp_url_path('profile.php?user=' . rawurlencode((string)($user['username'] ?? ''))), 'style' => 'secondary', 'target' => true],
]);
?>
<section class="panel">
  <h2>User profile</h2>
  <p class="meta">Change the login username, display name, and public profile details shown across Bonumark Stream.</p>
  <form method="post" class="settings-form" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(mp_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="action" value="profile">

    <div class="admin-avatar-profile-row">
      <div class="stream-card-avatar admin-avatar-preview"><?= mp_user_avatar_markup($user, 'admin-avatar-image', 192, 192) ?></div>
      <div>
        <label for="avatar">Profile picture</label>
        <input id="avatar" type="file" name="avatar" accept="image/jpeg,image/png,image/gif,image/webp">
        <p class="field-help">Upload a JPG, PNG, GIF, or WebP image under 4 MB. This image appears on your public profile, Stream Posts, and comments.</p>
        <?php if (function_exists('mp_user_avatar_url') && mp_user_avatar_url($user) !== ''): ?>
          <label class="checkbox-row"><input type="checkbox" name="remove_avatar" value="1"> Remove current profile picture</label>
        <?php endif; ?>
      </div>
    </div>

    <label for="username">Username</label>
    <input id="username" type="text" name="username" value="<?= htmlspecialchars((string)($user['username'] ?? 'admin'), ENT_QUOTES, 'UTF-8') ?>" autocomplete="username" required>
    <p class="field-help">Used for login. Letters, numbers, periods, underscores, and hyphens are safest.</p>

    <label for="display_name">Display name</label>
    <input id="display_name" type="text" name="display_name" value="<?= htmlspecialchars((string)($user['display_name'] ?? 'Admin'), ENT_QUOTES, 'UTF-8') ?>" required>
    <p class="field-help">Shown in the admin header, for example John Smith.</p>

    <label for="email">Email</label>
    <input id="email" type="text" name="email" value="<?= htmlspecialchars((string)($user['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" autocomplete="email">
    <p class="field-help">Optional for account recovery and notifications.</p>

    <label for="website">Website</label>
    <input id="website" type="text" name="website" value="<?= htmlspecialchars((string)($user['website'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" autocomplete="url">
    <p class="field-help">Optional public profile link.</p>

    <div class="profile-links-editor">
      <div class="profile-links-editor-header">
        <h3>Profile links</h3>
        <p class="field-help">Add optional public social or profile links. Filled links display as pills beside Website on your public profile.</p>
      </div>
      <div class="profile-links-grid">
        <?php foreach ($socialLinkDefinitions as $linkId => $definition): ?>
          <?php $fieldId = 'social_link_' . preg_replace('/[^a-z0-9_\-]/i', '_', (string)$linkId); ?>
          <div class="profile-link-field">
            <label for="<?= htmlspecialchars($fieldId, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)($definition['label'] ?? $linkId), ENT_QUOTES, 'UTF-8') ?></label>
            <input id="<?= htmlspecialchars($fieldId, ENT_QUOTES, 'UTF-8') ?>" type="url" name="social_links[<?= htmlspecialchars((string)$linkId, ENT_QUOTES, 'UTF-8') ?>]" value="<?= htmlspecialchars((string)($socialLinkValues[$linkId] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="<?= htmlspecialchars((string)($definition['placeholder'] ?? 'https://example.com'), ENT_QUOTES, 'UTF-8') ?>">
          </div>
        <?php endforeach; ?>
      </div>
      <div class="profile-links-custom-grid" aria-label="Custom profile links">
        <div class="profile-links-custom-card">
          <h4>Custom link 1</h4>
          <label for="custom_1_label">Label</label>
          <input id="custom_1_label" type="text" name="social_links[custom_1_label]" value="<?= htmlspecialchars((string)($socialLinkValues['custom_1_label'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" maxlength="60" placeholder="Newsletter">
          <label for="custom_1_url">URL</label>
          <input id="custom_1_url" type="url" name="social_links[custom_1_url]" value="<?= htmlspecialchars((string)($socialLinkValues['custom_1_url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="https://example.com">
        </div>
        <div class="profile-links-custom-card">
          <h4>Custom link 2</h4>
          <label for="custom_2_label">Label</label>
          <input id="custom_2_label" type="text" name="social_links[custom_2_label]" value="<?= htmlspecialchars((string)($socialLinkValues['custom_2_label'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" maxlength="60" placeholder="Shop">
          <label for="custom_2_url">URL</label>
          <input id="custom_2_url" type="url" name="social_links[custom_2_url]" value="<?= htmlspecialchars((string)($socialLinkValues['custom_2_url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="https://example.com">
        </div>
      </div>
    </div>

    <label for="bio">Profile bio</label>
    <textarea id="bio" name="bio" rows="5" maxlength="1000"><?= htmlspecialchars((string)($user['bio'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
    <p class="field-help">Optional public profile text. Keep it direct and useful.</p>

    <label for="profile_visibility">Profile visibility</label>
    <select id="profile_visibility" name="profile_visibility">
      <option value="public" <?= ((string)($user['profile_visibility'] ?? 'public') === 'public') ? 'selected' : '' ?>>Public</option>
      <option value="private" <?= ((string)($user['profile_visibility'] ?? 'public') === 'private') ? 'selected' : '' ?>>Private</option>
    </select>
    <p class="field-help">Private profiles stay hidden from public profile pages unless you are signed in as that account or as an admin.</p>

    <button type="submit">Save profile</button>
  </form>
</section>

<section class="panel">
  <h2>Change password</h2>
  <p class="meta">Update the password for this admin account. New passwords must be at least 12 characters and pass the stronger Bonumark Stream password policy.</p>
  <form method="post" class="settings-form">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(mp_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="action" value="password">

    <label for="current_password">Current password</label>
    <input id="current_password" type="password" name="current_password" autocomplete="current-password" required>

    <label for="new_password">New password</label>
    <input id="new_password" type="password" name="new_password" autocomplete="new-password" required>

    <label for="confirm_password">Confirm new password</label>
    <input id="confirm_password" type="password" name="confirm_password" autocomplete="new-password" required>

    <button type="submit">Change password</button>
  </form>
</section>

<section class="panel">
  <h2>Account storage</h2>
  <p>User accounts are stored in the Bonumark Stream database. Passwords are stored as one-way hashes using PHP password hashing.</p>
  <p class="meta">The database handles users, settings, content records, revisions, upgrade history, and security logs. Markdown is available through import and export tools only.</p>
</section>
<?php mp_admin_footer(); ?>

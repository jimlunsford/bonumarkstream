<?php
require_once __DIR__ . '/_helpers.php';
$data = ml_theme_data($mp_theme_data ?? []);

$user = is_array($data['user'] ?? null) ? $data['user'] : null;
$csrf = (string)($data['csrf'] ?? '');
$siteName = (string)($data['site_name'] ?? 'Bonumark Stream');
$notice = (string)($data['notice'] ?? '');
$noticeType = (string)($data['notice_type'] ?? 'info');
$postCounts = is_array($data['account_post_counts'] ?? null) ? $data['account_post_counts'] : ['published' => 0, 'draft' => 0, 'total' => 0];
$commentCounts = is_array($data['account_comment_counts'] ?? null) ? $data['account_comment_counts'] : ['approved' => 0, 'pending' => 0, 'total' => 0];
$recentComments = is_array($data['account_recent_comments'] ?? null) ? $data['account_recent_comments'] : [];
$recentPosts = is_array($data['account_recent_posts'] ?? null) ? $data['account_recent_posts'] : [];
$socialLinkDefinitions = is_array($data['profile_social_link_definitions'] ?? null) ? $data['profile_social_link_definitions'] : [];
$socialLinkValues = is_array($data['profile_social_link_values'] ?? null) ? $data['profile_social_link_values'] : [];
$h = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$formatDate = static function ($value): string {
    $value = trim((string)$value);
    if ($value === '') {
        return 'Not recorded';
    }
    $timestamp = strtotime($value);
    return $timestamp ? date('M j, Y', $timestamp) : $value;
};
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Account | <?= $h($siteName) ?></title>
  <link rel="stylesheet" href="<?= $h((string)($data['style_url'] ?? '')) ?>">
<?= (string)($data['theme_stylesheet_links'] ?? '') ?></head>
<body class="<?= $h(ml_body_class($data, 'ledger-account-template')) ?>">
  <a class="skip-link" href="#site-main">Skip to content</a>

  <div class="site-wrapper stream-site-wrapper ledger-site-wrapper">
    <div class="site-shell stream-site-shell ledger-site-shell">
      <?= (string)($data['header_html'] ?? '') ?>

      <main id="site-main" class="site-main stream-shell account-shell timeline ledger-account-shell">
        <?php if ($notice !== ''): ?>
          <div class="account-notice account-notice-<?= $h($noticeType) ?>"><?= $h($notice) ?></div>
        <?php endif; ?>

        <?php if ($user): ?>
          <section class="profile-card stream-state-card account-card account-overview-card ledger-panel ledger-account-panel ledger-account-overview">
            <div class="profile-header">
              <div class="stream-card-avatar account-avatar"><?= (string)($data['avatar_markup'] ?? '') ?></div>
              <div>
                <p class="eyebrow">Account</p>
                <h1>Your Bonumark profile</h1>
                <p class="meta">Signed in as @<?= $h((string)($user['username'] ?? '')) ?>.</p>
              </div>
            </div>
            <div class="account-status-row">
              <span class="account-status-pill">Role: <?= $h((string)($data['account_role_label'] ?? '')) ?></span>
              <span class="account-status-pill">Account: <?= $h((string)($data['account_status_label'] ?? '')) ?></span>
              <span class="account-status-pill">Email: <?= $h((string)($data['account_email_status_label'] ?? '')) ?></span>
              <span class="account-status-pill">Profile: <?= $h((string)($data['account_visibility_label'] ?? '')) ?></span>
            </div>
            <div class="stream-card-actions">
              <?php if (!empty($data['can_view_admin']) && (string)($data['admin_url'] ?? '') !== ''): ?>
                <a class="stream-meta-pill ledger-action-pill" href="<?= $h((string)$data['admin_url']) ?>"><?= $h((string)($data['admin_label'] ?? 'Open Admin')) ?></a>
              <?php endif; ?>
              <a class="stream-meta-pill ledger-action-pill" href="<?= $h((string)($data['profile_url'] ?? '#')) ?>">View Profile</a>
              <form method="post" class="inline-account-form">
                <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
                <input type="hidden" name="action" value="logout">
                <button type="submit" class="stream-meta-pill ledger-action-pill">Logout</button>
              </form>
            </div>
          </section>

          <section class="stream-state-card account-card account-dashboard-card ledger-panel ledger-account-panel ledger-account-dashboard">
            <p class="eyebrow">Dashboard</p>
            <h2>Your activity</h2>
            <div class="account-stat-grid">
              <div class="account-stat"><strong><?= (int)($postCounts['published'] ?? 0) ?></strong><span>Published posts</span></div>
              <div class="account-stat"><strong><?= (int)($postCounts['draft'] ?? 0) ?></strong><span>Draft posts</span></div>
              <div class="account-stat"><strong><?= (int)($commentCounts['approved'] ?? 0) ?></strong><span>Approved comments</span></div>
              <div class="account-stat"><strong><?= (int)($commentCounts['pending'] ?? 0) ?></strong><span>Pending comments</span></div>
            </div>
            <p class="meta">Member since <?= $h($formatDate((string)($data['account_member_since'] ?? ''))) ?>.</p>
          </section>

          <section class="stream-state-card account-card ledger-panel ledger-account-panel">
            <h2>Profile details</h2>
            <p class="meta">This controls your public profile, avatar, and how your name appears on posts and comments.</p>
            <form method="post" class="account-form" enctype="multipart/form-data">
              <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
              <input type="hidden" name="action" value="profile">

              <div class="avatar-upload-row">
                <div class="stream-card-avatar account-avatar-preview"><?= (string)($data['avatar_markup'] ?? '') ?></div>
                <label>Profile picture<input name="avatar" type="file" accept="image/jpeg,image/png,image/gif,image/webp"></label>
              </div>

              <?php if (!empty($data['has_avatar'])): ?>
                <label class="avatar-remove-control"><input type="checkbox" name="remove_avatar" value="1"> Remove current profile picture</label>
              <?php endif; ?>

              <p class="field-help account-profile-help">Upload a JPG, PNG, GIF, or WebP image under 4 MB. Square images look best.</p>
              <label>Username<input name="username" value="<?= $h((string)($user['username'] ?? '')) ?>" autocomplete="username" maxlength="60" required></label>
              <p class="field-help account-profile-help">Your username is used for your profile URL and public handle.</p>
              <label>Display name<input name="display_name" value="<?= $h((string)($user['display_name'] ?? '')) ?>" autocomplete="name" maxlength="120" required></label>
              <label>Email<input name="email" type="email" value="<?= $h((string)($user['email'] ?? '')) ?>" autocomplete="email"></label>
              <label>Website<input name="website" type="url" value="<?= $h((string)($user['website'] ?? '')) ?>" placeholder="https://example.com" autocomplete="url"></label>
              <div class="profile-links-editor">
                <div class="profile-links-editor-header">
                  <h3>Profile links</h3>
                  <p class="field-help account-profile-help">Optional social and profile links. Filled links display as pills beside Website on your public profile.</p>
                </div>
                <div class="profile-links-grid">
                  <?php foreach ($socialLinkDefinitions as $linkId => $definition): ?>
                    <label class="profile-link-field"><?= $h((string)($definition['label'] ?? $linkId)) ?><input name="social_links[<?= $h((string)$linkId) ?>]" type="url" value="<?= $h((string)($socialLinkValues[$linkId] ?? '')) ?>" placeholder="<?= $h((string)($definition['placeholder'] ?? 'https://example.com')) ?>"></label>
                  <?php endforeach; ?>
                </div>
                <div class="profile-links-custom-grid">
                  <div class="profile-links-custom-card">
                    <h4>Custom link 1</h4>
                    <label>Label<input name="social_links[custom_1_label]" value="<?= $h((string)($socialLinkValues['custom_1_label'] ?? '')) ?>" maxlength="60" placeholder="Newsletter"></label>
                    <label>URL<input name="social_links[custom_1_url]" type="url" value="<?= $h((string)($socialLinkValues['custom_1_url'] ?? '')) ?>" placeholder="https://example.com"></label>
                  </div>
                  <div class="profile-links-custom-card">
                    <h4>Custom link 2</h4>
                    <label>Label<input name="social_links[custom_2_label]" value="<?= $h((string)($socialLinkValues['custom_2_label'] ?? '')) ?>" maxlength="60" placeholder="Shop"></label>
                    <label>URL<input name="social_links[custom_2_url]" type="url" value="<?= $h((string)($socialLinkValues['custom_2_url'] ?? '')) ?>" placeholder="https://example.com"></label>
                  </div>
                </div>
              </div>
              <label>Bio<textarea name="bio" rows="5" maxlength="1000" placeholder="A short public bio."><?= $h((string)($user['bio'] ?? '')) ?></textarea></label>
              <label>Profile visibility
                <select name="profile_visibility">
                  <option value="public"<?= ((string)($user['profile_visibility'] ?? 'public') === 'public') ? ' selected' : '' ?>>Public</option>
                  <option value="private"<?= ((string)($user['profile_visibility'] ?? 'public') === 'private') ? ' selected' : '' ?>>Private</option>
                </select>
              </label>
              <p class="field-help account-profile-help">Private profiles hide your public profile page, but they do not erase comments or posts already visible elsewhere.</p>
              <button type="submit">Save Profile</button>
            </form>
          </section>

          <section class="stream-state-card account-card account-activity-card ledger-panel ledger-account-panel ledger-account-activity">
            <h2>My comments</h2>
            <?php if ($recentComments): ?>
              <div class="account-activity-list">
                <?php foreach ($recentComments as $comment): ?>
                  <article class="account-activity-item">
                    <div>
                      <a href="<?= $h((string)($comment['post_url'] ?? '#')) ?>"><?= $h((string)($comment['post_title'] ?? 'Stream Post')) ?></a>
                      <p><?= $h((string)($comment['excerpt'] ?? '')) ?></p>
                    </div>
                    <span class="account-status-pill"><?= $h((string)($comment['status_label'] ?? 'Pending')) ?></span>
                  </article>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <p class="meta account-empty">No comments yet.</p>
            <?php endif; ?>
          </section>

          <?php if (!empty($data['account_can_write_posts'])): ?>
            <section class="stream-state-card account-card account-activity-card ledger-panel ledger-account-panel ledger-account-activity">
              <h2>My stream posts</h2>
              <?php if ($recentPosts): ?>
                <div class="account-activity-list">
                  <?php foreach ($recentPosts as $post): ?>
                    <article class="account-activity-item">
                      <div>
                        <?php if ((string)($post['public_url'] ?? '') !== ''): ?>
                          <a href="<?= $h((string)$post['public_url']) ?>"><?= $h((string)($post['title'] ?? 'Stream Post')) ?></a>
                        <?php else: ?>
                          <strong><?= $h((string)($post['title'] ?? 'Stream Post')) ?></strong>
                        <?php endif; ?>
                        <p>Updated <?= $h($formatDate((string)($post['updated_at'] ?? $post['created_at'] ?? ''))) ?>.</p>
                      </div>
                      <span class="account-status-pill"><?= $h((string)($post['status_label'] ?? 'Draft')) ?></span>
                    </article>
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <p class="meta account-empty">No stream posts yet.</p>
              <?php endif; ?>
            </section>
          <?php endif; ?>

          <section class="stream-state-card account-card ledger-panel ledger-account-panel">
            <h2>Change password</h2>
            <form method="post" class="account-form">
              <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
              <input type="hidden" name="action" value="password">
              <label>Current password<input name="current_password" type="password" autocomplete="current-password" required></label>
              <label>New password<input name="new_password" type="password" autocomplete="new-password" required></label>
              <label>Confirm new password<input name="confirm_password" type="password" autocomplete="new-password" required></label>
              <button type="submit">Change Password</button>
            </form>
          </section>
        <?php else: ?>
          <?php if ((string)($data['account_action'] ?? '') === 'reset' && !empty($data['password_reset_token_valid'])): ?>
            <section class="stream-state-card account-card ledger-panel ledger-account-panel">
              <p class="eyebrow">Password recovery</p>
              <h1>Reset your password</h1>
              <p class="meta">Choose a new password for your account. This reset link expires after 1 hour.</p>
              <form method="post" class="account-form">
                <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="token" value="<?= $h((string)($data['password_reset_token'] ?? '')) ?>">
                <label>New password<input name="new_password" type="password" autocomplete="new-password" required></label>
                <label>Confirm new password<input name="confirm_password" type="password" autocomplete="new-password" required></label>
                <button type="submit">Reset Password</button>
              </form>
              <p class="meta"><a href="<?= $h((string)($data['sign_in_url'] ?? 'account.php')) ?>">Return to sign in</a></p>
            </section>
          <?php elseif ((string)($data['account_action'] ?? '') === 'forgot'): ?>
            <section class="stream-state-card account-card ledger-panel ledger-account-panel">
              <p class="eyebrow">Password recovery</p>
              <h1>Forgot your password?</h1>
              <?php if (empty($data['password_recovery_mail_ready'])): ?>
                <div class="account-notice account-notice-warning">Password recovery is not available until mail is configured.</div>
              <?php else: ?>
                <p class="meta">Enter your username or email address. If an account matches, Bonumark Stream will send a reset link.</p>
                <form method="post" class="account-form">
                  <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
                  <input type="hidden" name="action" value="forgot_password">
                  <label>Username or email<input name="username_or_email" autocomplete="username" required></label>
                  <button type="submit">Send Reset Link</button>
                </form>
              <?php endif; ?>
              <p class="meta"><a href="<?= $h((string)($data['sign_in_url'] ?? 'account.php')) ?>">Return to sign in</a></p>
            </section>
          <?php endif; ?>

          <section class="stream-state-card account-card ledger-panel ledger-account-panel">
            <h1>Sign in</h1>
            <form method="post" class="account-form">
              <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
              <input type="hidden" name="action" value="login">
              <input type="hidden" name="return_to" value="<?= $h((string)($data['return_to'] ?? '')) ?>">
              <label>Username<input name="username" autocomplete="username" required></label>
              <label>Password<input name="password" type="password" autocomplete="current-password" required></label>
              <button type="submit">Sign In</button>
              <p class="meta"><a href="<?= $h((string)($data['forgot_password_url'] ?? 'account.php?action=forgot')) ?>">Forgot your password?</a></p>
            </form>
          </section>

          <?php if (!empty($data['registration_enabled'])): ?>
            <section id="create-account" class="stream-state-card account-card ledger-panel ledger-account-panel">
              <h2>Create an account</h2>
              <p class="meta">New accounts receive the <?= $h((string)($data['registration_default_role_label'] ?? 'Commenter')) ?> role. <?php if (!empty($data['registration_invite_required'])): ?>An invite code is required.<?php endif; ?> <?php if (!empty($data['registration_requires_email_verification'])): ?>Email verification is required before sign-in.<?php endif; ?> <?php if (!empty($data['registration_requires_admin_approval']) || (!empty($data['registration_user_role_requires_approval']) && (string)($data['registration_default_role'] ?? '') === 'user')): ?>Admin approval may be required before sign-in.<?php endif; ?></p>
              <?php if (!empty($data['registration_requires_email_verification']) && empty($data['registration_mail_ready'])): ?>
                <div class="account-notice account-notice-warning">Registration is enabled, but mail is not configured for verification emails yet.</div>
              <?php else: ?>
                <form method="post" class="account-form">
                  <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
                  <input type="hidden" name="action" value="register">
                  <label class="registration-honeypot" aria-hidden="true">Company URL<input name="company_url" tabindex="-1" autocomplete="off"></label>
                  <?php if (!empty($data['registration_invite_required'])): ?>
                    <label>Invite code<input name="invite_code" autocomplete="off" required></label>
                  <?php endif; ?>
                  <label>Username<input name="username" autocomplete="username" maxlength="60" required></label>
                  <label>Display name<input name="display_name" autocomplete="name" maxlength="120" required></label>
                  <label>Email<input name="email" type="email" autocomplete="email"<?= !empty($data['registration_requires_email_verification']) ? ' required' : '' ?>></label>
                  <label>Password<input name="password" type="password" autocomplete="new-password" required></label>
                  <label>Confirm password<input name="confirm_password" type="password" autocomplete="new-password" required></label>
                  <button type="submit">Create Account</button>
                </form>
              <?php endif; ?>
            </section>

            <?php if (!empty($data['registration_requires_email_verification'])): ?>
              <section class="stream-state-card account-card ledger-panel ledger-account-panel">
                <h2>Need a new verification email?</h2>
                <?php if (empty($data['verification_resend_available'])): ?>
                  <div class="account-notice account-notice-warning">Verification email resend is not available until mail is configured.</div>
                <?php else: ?>
                  <p class="meta">Enter the username or email address. If that account is pending email verification, Bonumark Stream will send a fresh link.</p>
                  <form method="post" class="account-form">
                    <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
                    <input type="hidden" name="action" value="resend_verification">
                    <label>Username or email<input name="username_or_email" autocomplete="username" required></label>
                    <button type="submit">Resend Verification Email</button>
                  </form>
                <?php endif; ?>
              </section>
            <?php endif; ?>
          <?php endif; ?>
        <?php endif; ?>
      </main>

      <?= (string)($data['footer_html'] ?? '') ?>
    </div>
  </div>

  <script src="<?= $h((string)($data['script_url'] ?? '')) ?>" defer></script>
<?= (string)($data['theme_script_tags'] ?? '') ?></body>
</html>

<?php
require_once __DIR__ . '/_helpers.php';
$data = ml_theme_data($bms_theme_data ?? []);

$user = is_array($data['user'] ?? null) ? $data['user'] : null;
$csrf = (string)($data['csrf'] ?? '');
$siteName = (string)($data['site_name'] ?? 'Bonumark Stream');
$notice = (string)($data['notice'] ?? '');
$noticeType = (string)($data['notice_type'] ?? 'info');
$accountSection = (string)($data['account_section'] ?? '');
$postCounts = is_array($data['account_post_counts'] ?? null) ? $data['account_post_counts'] : ['published' => 0, 'draft' => 0, 'total' => 0];
$commentCounts = is_array($data['account_comment_counts'] ?? null) ? $data['account_comment_counts'] : ['approved' => 0, 'pending' => 0, 'total' => 0];
$recentComments = is_array($data['account_recent_comments'] ?? null) ? $data['account_recent_comments'] : [];
$recentPosts = is_array($data['account_recent_posts'] ?? null) ? $data['account_recent_posts'] : [];
$profileIdentity = is_array($data['profile_identity'] ?? null) ? $data['profile_identity'] : [];
$profileLinkRows = is_array($data['profile_link_rows'] ?? null) ? $data['profile_link_rows'] : [];
$profileInterestsValue = (string)($data['profile_interests_value'] ?? '');
$profileFeaturedRows = is_array($data['profile_featured_rows'] ?? null) ? $data['profile_featured_rows'] : [];
$profileFeaturedStreamOptions = is_array($data['profile_featured_stream_options'] ?? null) ? $data['profile_featured_stream_options'] : [];
$profileFeaturedPageOptions = is_array($data['profile_featured_page_options'] ?? null) ? $data['profile_featured_page_options'] : [];
$profilePhotoRows = is_array($data['profile_photo_rows'] ?? null) ? $data['profile_photo_rows'] : [];
$h = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$formatDate = static function ($value): string {
    $value = trim((string)$value);
    if ($value === '') {
        return 'Not recorded';
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
        return $value;
    }
    try {
        return (new DateTimeImmutable($value, bms_utc_timezone()))->setTimezone(bms_site_timezone())->format('M j, Y');
    } catch (Throwable $e) {
        return $value;
    }
};
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $accountSection === 'profile' ? 'Edit Profile' : 'Account' ?> | <?= $h($siteName) ?></title>
  <?= (string)($data['favicon_tags'] ?? '') ?><?= (string)($data['pwa_tags'] ?? '') ?>  <link rel="stylesheet" href="<?= $h((string)($data['style_url'] ?? '')) ?>">
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
          <?php if ($accountSection === 'profile'): ?>
            <section class="profile-card stream-state-card account-card account-overview-card ledger-panel ledger-account-panel ledger-account-overview">
              <div class="profile-header">
                <div class="stream-card-avatar account-avatar"><?= (string)($data['avatar_markup'] ?? '') ?></div>
                <div>
                  <h1>Edit Profile</h1>
                  <p class="meta">Manage the public information and sections shown on your Profile.</p>
                </div>
              </div>
              <div class="stream-card-actions">
                <a class="stream-meta-pill ledger-action-pill" href="<?= $h((string)($data['profile_url'] ?? '#')) ?>">View Profile</a>
                <a class="stream-meta-pill ledger-action-pill" href="<?= $h((string)($data['account_url'] ?? 'account.php')) ?>">Back to Account</a>
              </div>
            </section>

            <form method="post" class="account-form profile-edit-form" enctype="multipart/form-data">
              <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
              <input type="hidden" name="action" value="profile">

              <section class="stream-state-card account-card ledger-panel ledger-account-panel profile-edit-section">
                <div class="profile-edit-section-heading">
                  <h2>Identity</h2>
                  <p class="meta">The basics people see first.</p>
                </div>

                <div class="profile-media-field profile-cover-field">
                  <?php if ((string)($data['profile_cover_url'] ?? '') !== ''): ?>
                    <div class="profile-cover-editor-preview">
                      <img src="<?= $h((string)$data['profile_cover_url']) ?>" alt="Current cover image">
                    </div>
                  <?php endif; ?>
                  <label class="profile-file-control"><?= !empty($data['has_profile_cover']) ? 'Change cover image' : 'Cover image' ?>
                    <input name="cover_image" type="file" accept="image/jpeg,image/png,image/webp">
                  </label>
                  <p class="field-help account-profile-help">Recommended size: 1600 × 600 pixels or wider. Upload a JPG, PNG, or WebP image under 6 MB.</p>
                  <?php if (!empty($data['has_profile_cover'])): ?>
                    <label class="profile-toggle-line profile-remove-control"><input type="checkbox" name="remove_cover_image" value="1"> <span>Remove cover image</span></label>
                  <?php endif; ?>
                </div>

                <div class="profile-media-field profile-avatar-field">
                  <div class="profile-avatar-editor">
                    <div class="stream-card-avatar account-avatar-preview"><?= (string)($data['avatar_markup'] ?? '') ?></div>
                    <div class="profile-avatar-controls">
                      <label class="profile-file-control"><?= !empty($data['has_avatar']) ? 'Change profile picture' : 'Profile picture' ?>
                        <input name="avatar" type="file" accept="image/jpeg,image/png,image/gif,image/webp">
                      </label>
                      <p class="field-help account-profile-help">Upload a JPG, PNG, GIF, or WebP image under 4 MB. Square images look best.</p>
                      <?php if (!empty($data['has_avatar'])): ?>
                        <label class="profile-toggle-line profile-remove-control"><input type="checkbox" name="remove_avatar" value="1"> <span>Remove profile picture</span></label>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>

                <label>Display name<input name="display_name" value="<?= $h((string)($user['display_name'] ?? '')) ?>" autocomplete="name" maxlength="120" required></label>
                <label>Headline<input name="headline" value="<?= $h((string)($profileIdentity['headline'] ?? '')) ?>" maxlength="180" placeholder="A short line that introduces you."></label>
                <p class="field-help account-profile-help">Keep this short. It should establish who you are before someone reads the rest of the profile.</p>

                <label>Location<input name="location" value="<?= $h((string)($profileIdentity['location'] ?? '')) ?>" maxlength="190" placeholder="City, region, or somewhere broader"></label>

                <label>Short bio<textarea name="bio" rows="4" maxlength="1000" placeholder="A concise public bio."><?= $h((string)($user['bio'] ?? '')) ?></textarea></label>
                <p class="field-help account-profile-help">The short bio is useful anywhere Bonumark needs a compact description of you.</p>

                <label>Website<input name="website" type="url" value="<?= $h((string)($user['website'] ?? '')) ?>" placeholder="https://example.com" autocomplete="url"></label>
              </section>

              <section class="stream-state-card account-card ledger-panel ledger-account-panel profile-edit-section">
                <div class="profile-edit-section-heading">
                  <h2>About</h2>
                  <p class="meta">Use Markdown for a fuller introduction.</p>
                </div>
                <label>About
                  <textarea class="profile-about-textarea" name="about_markdown" rows="9" maxlength="12000" placeholder="Tell people what matters to know about you."><?= $h((string)($profileIdentity['about_markdown'] ?? '')) ?></textarea>
                </label>
              </section>

              <section class="stream-state-card account-card ledger-panel ledger-account-panel profile-edit-section profile-featured-editor-section">
                <div class="profile-edit-section-heading">
                  <h2>Featured work</h2>
                  <p class="meta">Choose up to four things you want people to notice. Nothing is added automatically.</p>
                </div>

                <div class="profile-featured-editor" data-profile-featured-editor data-max-featured="4">
                  <div class="profile-featured-edit-list" data-profile-featured-list>
                    <?php foreach ($profileFeaturedRows as $row): ?>
                      <div class="profile-featured-edit-row" data-profile-featured-row>
                        <div class="profile-featured-edit-grid">
                          <label>Type
                            <select name="featured_items[type][]" data-profile-featured-type>
                              <option value="external"<?= (string)($row['type'] ?? 'external') === 'external' ? ' selected' : '' ?>>External link</option>
                              <option value="stream"<?= (string)($row['type'] ?? '') === 'stream' ? ' selected' : '' ?>>Stream post</option>
                              <option value="page"<?= (string)($row['type'] ?? '') === 'page' ? ' selected' : '' ?>>Page</option>
                            </select>
                          </label>
                          <label>Target
                            <input name="featured_items[target][]" value="<?= $h((string)($row['target'] ?? '')) ?>" maxlength="2048" data-profile-featured-target data-stream-list="profile-featured-stream-options" data-page-list="profile-featured-page-options" placeholder="https://example.com">
                          </label>
                          <label>Custom title
                            <input name="featured_items[title][]" value="<?= $h((string)($row['title'] ?? '')) ?>" maxlength="140" data-profile-featured-title placeholder="Optional for Bonumark content">
                          </label>
                        </div>
                        <label>Description
                          <textarea name="featured_items[description][]" rows="3" maxlength="320" placeholder="Optional short context for why this matters."><?= $h((string)($row['description'] ?? '')) ?></textarea>
                        </label>
                        <div class="profile-featured-row-actions">
                          <button class="button-link secondary profile-featured-remove" type="button" data-profile-featured-remove>Remove</button>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>

                  <div class="profile-featured-editor-actions">
                    <button class="button-link secondary profile-featured-add" type="button" data-profile-featured-add>Add featured item</button>
                    <p class="meta" data-profile-featured-limit hidden>Featured work is limited to four items.</p>
                  </div>

                  <template data-profile-featured-template>
                    <div class="profile-featured-edit-row" data-profile-featured-row>
                      <div class="profile-featured-edit-grid">
                        <label>Type
                          <select name="featured_items[type][]" data-profile-featured-type>
                            <option value="external" selected>External link</option>
                            <option value="stream">Stream post</option>
                            <option value="page">Page</option>
                          </select>
                        </label>
                        <label>Target
                          <input name="featured_items[target][]" maxlength="2048" data-profile-featured-target data-stream-list="profile-featured-stream-options" data-page-list="profile-featured-page-options" placeholder="https://example.com">
                        </label>
                        <label>Custom title
                          <input name="featured_items[title][]" maxlength="140" data-profile-featured-title placeholder="Optional for Bonumark content">
                        </label>
                      </div>
                      <label>Description
                        <textarea name="featured_items[description][]" rows="3" maxlength="320" placeholder="Optional short context for why this matters."></textarea>
                      </label>
                      <div class="profile-featured-row-actions">
                        <button class="button-link secondary profile-featured-remove" type="button" data-profile-featured-remove>Remove</button>
                      </div>
                    </div>
                  </template>
                </div>

                <p class="field-help account-profile-help">For Stream posts and Pages, use the published slug. Suggestions come from your current public content. External links require a title.</p>

                <datalist id="profile-featured-stream-options">
                  <?php foreach ($profileFeaturedStreamOptions as $option): ?>
                    <?php if (is_array($option) && (string)($option['slug'] ?? '') !== ''): ?>
                      <option value="<?= $h((string)$option['slug']) ?>" label="<?= $h((string)($option['title'] ?? 'Stream Post')) ?>"></option>
                    <?php endif; ?>
                  <?php endforeach; ?>
                </datalist>
                <datalist id="profile-featured-page-options">
                  <?php foreach ($profileFeaturedPageOptions as $option): ?>
                    <?php if (is_array($option) && (string)($option['slug'] ?? '') !== ''): ?>
                      <option value="<?= $h((string)$option['slug']) ?>" label="<?= $h((string)($option['title'] ?? 'Page')) ?>"></option>
                    <?php endif; ?>
                  <?php endforeach; ?>
                </datalist>
              </section>

              <section class="stream-state-card account-card ledger-panel ledger-account-panel profile-edit-section profile-photos-editor-section">
                <div class="profile-edit-section-heading">
                  <h2>Photos</h2>
                  <p class="meta">Add up to four photos that help make your Profile yours.</p>
                </div>

                <div class="profile-photos-editor" data-profile-photos-editor data-max-photos="4">
                  <div data-profile-photo-removals></div>
                  <div class="profile-photo-edit-list" data-profile-photo-list>
                    <?php foreach ($profilePhotoRows as $row): ?>
                      <?php $slot = max(0, min(3, (int)($row['slot'] ?? 0))); ?>
                      <div class="profile-photo-edit-row <?= (string)($row['url'] ?? '') !== '' ? 'has-preview' : 'no-preview' ?>" data-profile-photo-row data-profile-photo-slot="<?= $slot ?>"<?= !empty($row['starter']) ? ' data-profile-photo-starter="1"' : '' ?>>
                        <input type="hidden" name="profile_photo_order[]" value="<?= $slot ?>" data-profile-photo-order>
                        <input type="hidden" name="profile_photos[<?= $slot ?>][existing_path]" value="<?= $h((string)($row['path'] ?? '')) ?>" data-profile-photo-existing>
                        <?php if ((string)($row['url'] ?? '') !== ''): ?>
                          <div class="profile-photo-editor-preview">
                            <img src="<?= $h((string)$row['url']) ?>" alt="<?= $h((string)($row['alt'] ?? 'Current Profile photo')) ?>" loading="lazy" decoding="async">
                          </div>
                        <?php endif; ?>
                        <div class="profile-photo-edit-fields">
                          <label class="profile-file-control"><?= (string)($row['path'] ?? '') !== '' ? 'Change photo' : 'Photo' ?>
                            <input name="profile_photo_files[<?= $slot ?>]" type="file" accept="image/jpeg,image/png,image/webp" data-profile-photo-file>
                          </label>
                          <label>Alt text
                            <input name="profile_photos[<?= $slot ?>][alt]" value="<?= $h((string)($row['alt'] ?? '')) ?>" maxlength="240" placeholder="Describe the photo for people who cannot see it." data-profile-photo-alt>
                          </label>
                          <label>Caption
                            <textarea name="profile_photos[<?= $slot ?>][caption]" rows="2" maxlength="500" placeholder="Optional caption." data-profile-photo-caption><?= $h((string)($row['caption'] ?? '')) ?></textarea>
                          </label>
                        </div>
                        <div class="profile-photo-row-actions">
                          <button class="button-link secondary profile-photo-move" type="button" data-profile-photo-up>Move up</button>
                          <button class="button-link secondary profile-photo-move" type="button" data-profile-photo-down>Move down</button>
                          <button class="button-link secondary profile-photo-remove" type="button" data-profile-photo-remove>Remove</button>
                        </div>
                        <?php if ((string)($row['path'] ?? '') !== ''): ?>
                          <noscript><label class="profile-toggle-line profile-remove-control"><input type="checkbox" name="profile_photos[<?= $slot ?>][remove]" value="1"> <span>Remove this photo</span></label></noscript>
                        <?php endif; ?>
                      </div>
                    <?php endforeach; ?>
                  </div>

                  <div class="profile-photo-editor-actions">
                    <button class="button-link secondary profile-photo-add" type="button" data-profile-photo-add>Add Photo</button>
                    <p class="meta" data-profile-photo-limit hidden>Profile photos are limited to four images.</p>
                  </div>

                  <template data-profile-photo-template>
                    <div class="profile-photo-edit-row no-preview" data-profile-photo-row data-profile-photo-slot="">
                      <input type="hidden" value="" data-profile-photo-order>
                      <input type="hidden" value="" data-profile-photo-existing>
                      <div class="profile-photo-edit-fields">
                        <label class="profile-file-control">Photo
                          <input type="file" accept="image/jpeg,image/png,image/webp" data-profile-photo-file>
                        </label>
                        <label>Alt text
                          <input maxlength="240" placeholder="Describe the photo for people who cannot see it." data-profile-photo-alt>
                        </label>
                        <label>Caption
                          <textarea rows="2" maxlength="500" placeholder="Optional caption." data-profile-photo-caption></textarea>
                        </label>
                      </div>
                      <div class="profile-photo-row-actions">
                        <button class="button-link secondary profile-photo-move" type="button" data-profile-photo-up>Move up</button>
                        <button class="button-link secondary profile-photo-move" type="button" data-profile-photo-down>Move down</button>
                        <button class="button-link secondary profile-photo-remove" type="button" data-profile-photo-remove>Remove</button>
                      </div>
                    </div>
                  </template>
                </div>
                <p class="field-help account-profile-help">JPG, PNG, or WebP only. Bonumark applies the configured media upload limit, privacy cleaning, and responsive image generation. Photos stay Profile-only and do not create Stream posts.</p>
              </section>

              <section class="stream-state-card account-card ledger-panel ledger-account-panel profile-edit-section">
                <div class="profile-edit-section-heading">
                  <h2>Now</h2>
                  <p class="meta">A small optional snapshot of what has your attention right now.</p>
                </div>
                <label>Now
                  <textarea name="now_text" rows="5" maxlength="800" placeholder="What are you working on, reading, learning, or focused on?"><?= $h((string)($profileIdentity['now_text'] ?? '')) ?></textarea>
                </label>
              </section>

              <section class="stream-state-card account-card ledger-panel ledger-account-panel profile-edit-section">
                <div class="profile-edit-section-heading">
                  <h2>Interests</h2>
                  <p class="meta">Add up to 12. Use one interest per line.</p>
                </div>
                <label>Interests
                  <textarea name="interests" rows="7" placeholder="One interest per line"><?= $h($profileInterestsValue) ?></textarea>
                </label>
              </section>

              <section class="stream-state-card account-card ledger-panel ledger-account-panel profile-edit-section">
                <div class="profile-edit-section-heading">
                  <h2>Links</h2>
                  <p class="meta">Links appear publicly in this order. Add only the links you want to show.</p>
                </div>
                <div class="profile-flexible-links-editor" data-profile-links-editor data-max-links="8">
                  <div class="profile-flexible-links-list" data-profile-link-list>
                    <?php foreach ($profileLinkRows as $index => $row): ?>
                      <div class="profile-flexible-link-row" data-profile-link-row>
                        <label>Label
                          <input name="profile_links[label][]" value="<?= $h((string)($row['label'] ?? '')) ?>" maxlength="60" placeholder="Label">
                        </label>
                        <label>URL
                          <input name="profile_links[url][]" type="url" value="<?= $h((string)($row['url'] ?? '')) ?>" placeholder="https://example.com">
                        </label>
                        <div class="profile-link-row-actions">
                          <button type="button" class="profile-link-remove" data-profile-link-remove>Remove</button>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                  <div class="profile-link-editor-actions">
                    <button type="button" class="profile-add-link" data-profile-link-add>Add Link</button>
                    <span class="field-help account-profile-help" data-profile-link-limit hidden>Maximum of 8 links reached.</span>
                  </div>
                  <template data-profile-link-template>
                    <div class="profile-flexible-link-row" data-profile-link-row>
                      <label>Label
                        <input name="profile_links[label][]" maxlength="60" placeholder="Label">
                      </label>
                      <label>URL
                        <input name="profile_links[url][]" type="url" placeholder="https://example.com">
                      </label>
                      <div class="profile-link-row-actions">
                        <button type="button" class="profile-link-remove" data-profile-link-remove>Remove</button>
                      </div>
                    </div>
                  </template>
                </div>
              </section>

              <section class="stream-state-card account-card ledger-panel ledger-account-panel profile-edit-section profile-settings-section">
                <div class="profile-edit-section-heading">
                  <h2>Profile settings</h2>
                  <p class="meta">Control Profile visibility and optional public details.</p>
                </div>

                <label>Profile visibility
                  <select name="profile_visibility">
                    <option value="public"<?= ((string)($user['profile_visibility'] ?? 'public') === 'public') ? ' selected' : '' ?>>Public</option>
                    <option value="private"<?= ((string)($user['profile_visibility'] ?? 'public') === 'private') ? ' selected' : '' ?>>Private</option>
                  </select>
                </label>
                <p class="field-help account-profile-help">Private profiles stay hidden from public profile pages unless you are signed in as that account or as an admin.</p>

                <fieldset class="profile-options-fieldset">
                  <legend>Optional details</legend>
                  <p class="meta">Activity numbers stay secondary and only appear when you choose to show them.</p>
                  <label class="profile-toggle-line"><input type="checkbox" name="show_post_count" value="1"<?= !empty($profileIdentity['show_post_count']) ? ' checked' : '' ?>> <span>Show published post count</span></label>
                  <label class="profile-toggle-line"><input type="checkbox" name="show_comment_count" value="1"<?= !empty($profileIdentity['show_comment_count']) ? ' checked' : '' ?>> <span>Show approved comment count</span></label>
                  <label class="profile-toggle-line"><input type="checkbox" name="show_member_since" value="1"<?= !empty($profileIdentity['show_member_since']) ? ' checked' : '' ?>> <span>Show member-since date</span></label>
                </fieldset>
              </section>

              <section class="stream-state-card account-card ledger-panel ledger-account-panel profile-edit-save">
                <button type="submit">Save Profile</button>
              </section>
            </form>

            <section class="stream-state-card account-card ledger-panel ledger-account-panel profile-edit-section profile-portability-section">
              <div class="profile-edit-section-heading">
                <h2>Profile portability</h2>
                <p class="meta">Take your Profile identity with you without exporting private account or theme data.</p>
              </div>
              <p class="account-profile-portability-copy">The ZIP contains <code>profile.json</code>, a readable <code>profile.md</code>, and your original Profile picture and cover image when those local files are available.</p>
              <form method="post" action="<?= $h((string)($data['profile_export_url'] ?? 'profile-export.php')) ?>" class="profile-portability-form">
                <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
                <button type="submit"<?= !empty($data['profile_export_available']) ? '' : ' disabled' ?>>Download Profile ZIP</button>
              </form>
              <?php if (empty($data['profile_export_available'])): ?>
                <p class="field-help account-profile-help warning-text">Profile export requires the PHP ZipArchive extension.</p>
              <?php else: ?>
                <p class="field-help account-profile-help">Save Profile changes before exporting. The package excludes email, passwords, roles, activity counts, post/comment contents, security records, and theme presentation settings.</p>
              <?php endif; ?>
            </section>
          <?php else: ?>
            <section class="profile-card stream-state-card account-card account-overview-card ledger-panel ledger-account-panel ledger-account-overview">
              <div class="profile-header">
                <div class="stream-card-avatar account-avatar"><?= (string)($data['avatar_markup'] ?? '') ?></div>
                <div>
                  <p class="eyebrow">Account</p>
                  <h1>Your account</h1>
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
                <a class="stream-meta-pill ledger-action-pill" href="<?= $h((string)($data['profile_edit_url'] ?? 'account.php?section=profile')) ?>">Edit Profile</a>
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
              <h2>Public Profile</h2>
              <p class="meta">Manage the public identity information shown on your Profile.</p>
              <div class="stream-card-actions">
                <a class="stream-meta-pill ledger-action-pill" href="<?= $h((string)($data['profile_edit_url'] ?? 'account.php?section=profile')) ?>">Edit Profile</a>
                <a class="stream-meta-pill ledger-action-pill" href="<?= $h((string)($data['profile_url'] ?? '#')) ?>">View Profile</a>
              </div>
            </section>

            <section class="stream-state-card account-card ledger-panel ledger-account-panel">
              <h2>Account details</h2>
              <p class="meta">Login identity and recovery information live here, separate from public Profile content.</p>
              <form method="post" class="account-form">
                <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
                <input type="hidden" name="action" value="account_details">
                <label>Username<input name="username" value="<?= $h((string)($user['username'] ?? '')) ?>" autocomplete="username" maxlength="60" required></label>
                <p class="field-help account-profile-help">Your username is used for login, your public handle, and your profile URL.</p>
                <label>Email<input name="email" type="email" value="<?= $h((string)($user['email'] ?? '')) ?>" autocomplete="email"></label>
                <button type="submit">Save account details</button>
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
          <?php endif; ?>
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
              <?php if (!empty($data['remember_login_enabled'])): ?>
                <label class="checkbox-line"><input type="checkbox" name="remember_me" value="1"> <span>Remember this device</span></label>
                <p class="meta">Keeps this browser signed in for up to <?= $h((string)($data['remember_login_days'] ?? 30)) ?> days unless you log out, change your password, or reset the account password.</p>
              <?php endif; ?>
              <button type="submit">Sign In</button>
              <p class="meta"><a href="<?= $h((string)($data['forgot_password_url'] ?? 'account.php?action=forgot')) ?>">Forgot your password?</a></p>
            </form>
          </section>

          <?php if (!empty($data['registration_enabled'])): ?>
            <section id="create-account" class="stream-state-card account-card ledger-panel ledger-account-panel">
              <h2>Create an account</h2>
              <p class="meta">New accounts are commenter accounts. <?php if (!empty($data['registration_invite_required'])): ?>An invite code is required.<?php endif; ?> <?php if (!empty($data['registration_requires_email_verification'])): ?>Email verification is required before sign-in.<?php endif; ?> <?php if (!empty($data['registration_requires_admin_approval'])): ?>Admin approval may be required before sign-in.<?php endif; ?></p>
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

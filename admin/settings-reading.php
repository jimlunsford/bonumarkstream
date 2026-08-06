<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/renderer.php';
require_once __DIR__ . '/../_bonumark_stream/app/pwa.php';
require_once __DIR__ . '/_layout.php';
bms_require_login();
bms_require_capability('manage_settings');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    bms_verify_csrf();
    $streamDates = !empty($_POST['stream_show_dates']) ? '1' : '0';
    $sitemapEnabled = !empty($_POST['sitemap_enabled']) ? '1' : '0';
    $sitemapStreamPosts = !empty($_POST['sitemap_include_stream_posts']) ? '1' : '0';
    $sitemapPages = !empty($_POST['sitemap_include_pages']) ? '1' : '0';
    $sitemapProfiles = !empty($_POST['sitemap_include_profiles']) ? '1' : '0';
    $pwaEnabled = !empty($_POST['pwa_enabled']) ? '1' : '0';
    $pwaShareTarget = !empty($_POST['pwa_share_target_enabled']) ? '1' : '0';
    $rememberLoginEnabled = !empty($_POST['remember_login_enabled']) ? '1' : '0';
    $rememberLoginDays = max(1, min(90, (int)($_POST['remember_login_days'] ?? 30)));
    $streamIndexPolicy = (string)($_POST['stream_index_policy'] ?? 'smart');
    if (!in_array($streamIndexPolicy, ['all', 'smart', 'noindex'], true)) {
        $streamIndexPolicy = 'smart';
    }
    $streamCount = max(1, min(100, (int)($_POST['stream_posts_per_page'] ?? 20)));

    try {
        bms_set_setting('homepage_mode', 'stream');
        bms_set_setting('stream_posts_per_page', (string)$streamCount);
        bms_set_setting('stream_show_dates', $streamDates);
        bms_set_setting('stream_index_policy', $streamIndexPolicy);
        bms_set_setting('sitemap_enabled', $sitemapEnabled);
        bms_set_setting('sitemap_include_stream_posts', $sitemapStreamPosts);
        bms_set_setting('sitemap_include_pages', $sitemapPages);
        bms_set_setting('sitemap_include_profiles', $sitemapProfiles);
        bms_set_setting('pwa_enabled', $pwaEnabled);
        bms_set_setting('pwa_share_target_enabled', $pwaShareTarget);
        bms_set_setting('remember_login_enabled', $rememberLoginEnabled);
        bms_set_setting('remember_login_days', (string)$rememberLoginDays);
        bms_flash('Reading settings saved. Dynamic public routes use the updated settings immediately.', 'success');
        bms_redirect(bms_admin_url('settings-reading.php'));
    } catch (Throwable $e) {
        bms_log_admin_exception('settings-reading', $e);
        bms_flash('Could not save reading settings. Please try again.', 'error');
    }
}

$streamCount = bms_stream_posts_per_page();
$streamDates = bms_stream_show_dates();
$streamIndexPolicy = bms_stream_index_policy();
$sitemapEnabled = (string)bms_setting_or_config('sitemap_enabled', '1') === '1';
$sitemapStreamPosts = (string)bms_setting_or_config('sitemap_include_stream_posts', '1') === '1';
$sitemapPages = (string)bms_setting_or_config('sitemap_include_pages', '1') === '1';
$sitemapProfiles = (string)bms_setting_or_config('sitemap_include_profiles', '0') === '1';
$pwaEnabled = function_exists('bms_pwa_enabled') ? bms_pwa_enabled() : true;
$pwaShareTarget = function_exists('bms_pwa_share_target_enabled') ? bms_pwa_share_target_enabled() : true;
$rememberLoginEnabled = function_exists('bms_remember_login_enabled') ? bms_remember_login_enabled() : true;
$rememberLoginDays = function_exists('bms_remember_login_days') ? bms_remember_login_days() : 30;
$manifestUrl = function_exists('bms_pwa_manifest_url') ? bms_pwa_manifest_url() : bms_url_path('manifest.php');
$shareTargetUrl = bms_admin_url('share-target.php');
$clearPwaUrl = bms_admin_url('settings-reading.php?bms-pwa-clear=1');
$sitemapUrl = bms_site_url('sitemap.xml');
$robotsUrl = bms_site_url('robots.txt');
$indexPolicyLabel = match ($streamIndexPolicy) {
    'all' => 'Index all',
    'noindex' => 'Noindex all',
    default => 'Smart indexing',
};

bms_admin_header('Reading Settings', [
    ['label' => 'Sitemap', 'href' => $sitemapUrl, 'style' => 'secondary', 'target' => true],
    ['label' => 'View Stream', 'href' => bms_url_path(), 'style' => 'secondary', 'target' => true],
]);
?>
<section class="panel settings-workflow-hero">
  <div class="settings-workflow-hero-copy">
    <p class="eyebrow">Settings</p>
    <h2>Control how the stream is read and discovered.</h2>
    <p class="meta">Reading Settings covers timeline density, public search indexing, the XML sitemap, installed-app behavior, mobile sharing, and remembered device access.</p>
  </div>
  <span class="static-pill generated">READING</span>
</section>

<section class="panel settings-summary-panel">
  <div class="settings-summary-grid">
    <div><span>Posts per page</span><strong><?= (int)$streamCount ?></strong></div>
    <div><span>Post dates</span><strong><?= $streamDates ? 'Shown' : 'Hidden' ?></strong></div>
    <div><span>Index policy</span><strong><?= htmlspecialchars($indexPolicyLabel, ENT_QUOTES, 'UTF-8') ?></strong></div>
    <div><span>Installable app</span><strong><?= $pwaEnabled ? 'Enabled' : 'Disabled' ?></strong></div>
  </div>
</section>

<form method="post" class="settings-section-list">
  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">

  <section class="panel settings-section-panel">
    <div class="settings-section-header"><div><p class="eyebrow">Stream display</p><h2>Timeline reading</h2><p class="meta">Keep the public stream fast to scan without changing the database-first publishing model.</p></div></div>
    <div class="settings-field-grid">
      <div class="settings-field-card">
        <label for="stream_posts_per_page">Stream Posts per page</label>
        <input type="number" id="stream_posts_per_page" name="stream_posts_per_page" min="1" max="100" value="<?= htmlspecialchars((string)$streamCount, ENT_QUOTES, 'UTF-8') ?>">
        <p class="field-help">Allowed range is 1 to 100 posts.</p>
      </div>
      <div class="settings-field-card">
        <label for="stream_index_policy">Single-post search indexing</label>
        <select id="stream_index_policy" name="stream_index_policy">
          <option value="all" <?= $streamIndexPolicy === 'all' ? 'selected' : '' ?>>Index all Stream Post pages</option>
          <option value="smart" <?= $streamIndexPolicy === 'smart' ? 'selected' : '' ?>>Smart indexing, noindex media-only posts</option>
          <option value="noindex" <?= $streamIndexPolicy === 'noindex' ? 'selected' : '' ?>>Noindex all individual Stream Post pages</option>
        </select>
        <p class="field-help">The main stream remains public. This controls only the robots meta tag on single Stream Post pages.</p>
      </div>
    </div>
    <div class="settings-option-list">
      <label class="settings-option-card"><input id="stream_show_dates" type="checkbox" name="stream_show_dates" value="1" <?= $streamDates ? 'checked' : '' ?>><span class="settings-option-copy"><strong>Show dates on Stream Posts</strong><small>Keep publication dates visible inside public stream cards.</small></span></label>
    </div>
  </section>

  <section class="panel settings-section-panel">
    <div class="settings-section-header"><div><p class="eyebrow">Discovery</p><h2>XML sitemap and robots</h2><p class="meta">The sitemap is published dynamically at <code class="settings-technical-value"><?= htmlspecialchars($sitemapUrl, ENT_QUOTES, 'UTF-8') ?></code> and referenced from <code class="settings-technical-value"><?= htmlspecialchars($robotsUrl, ENT_QUOTES, 'UTF-8') ?></code>.</p></div></div>
    <div class="settings-option-list">
      <label class="settings-option-card"><input id="sitemap_enabled" type="checkbox" name="sitemap_enabled" value="1" <?= $sitemapEnabled ? 'checked' : '' ?>><span class="settings-option-copy"><strong>Enable XML sitemap and robots.txt reference</strong><small>Publish the sitemap and advertise it to search engines.</small></span></label>
      <label class="settings-option-card"><input id="sitemap_include_stream_posts" type="checkbox" name="sitemap_include_stream_posts" value="1" <?= $sitemapStreamPosts ? 'checked' : '' ?>><span class="settings-option-copy"><strong>Include indexable Stream Posts</strong><small>Only published Stream Posts allowed by the indexing policy are included.</small></span></label>
      <label class="settings-option-card"><input id="sitemap_include_pages" type="checkbox" name="sitemap_include_pages" value="1" <?= $sitemapPages ? 'checked' : '' ?>><span class="settings-option-copy"><strong>Include indexable Pages</strong><small>Drafts, Trash, and noindex Pages remain excluded.</small></span></label>
      <label class="settings-option-card"><input id="sitemap_include_profiles" type="checkbox" name="sitemap_include_profiles" value="1" <?= $sitemapProfiles ? 'checked' : '' ?>><span class="settings-option-copy"><strong>Include public profile URLs</strong><small>Private profiles and account screens are never included.</small></span></label>
    </div>
  </section>

  <section class="panel settings-section-panel">
    <div class="settings-section-header"><div><p class="eyebrow">Installed app</p><h2>App metadata and mobile sharing</h2><p class="meta">The service worker caches safe static assets only. Admin, account, API, draft, and private routes are not cached.</p></div></div>
    <div class="settings-option-list">
      <label class="settings-option-card"><input id="pwa_enabled" type="checkbox" name="pwa_enabled" value="1" <?= $pwaEnabled ? 'checked' : '' ?>><span class="settings-option-copy"><strong>Enable installable app metadata</strong><small>Expose the manifest and conservative service-worker support.</small></span></label>
      <label class="settings-option-card"><input id="pwa_share_target_enabled" type="checkbox" name="pwa_share_target_enabled" value="1" <?= $pwaShareTarget ? 'checked' : '' ?>><span class="settings-option-copy"><strong>Enable the mobile share target</strong><small>Allow shared text and URLs to open the Stream composer.</small></span></label>
    </div>
    <dl class="settings-fact-list">
      <div><dt>Manifest</dt><dd><code class="settings-technical-value"><?= htmlspecialchars($manifestUrl, ENT_QUOTES, 'UTF-8') ?></code></dd></div>
      <div><dt>Share target</dt><dd><code class="settings-technical-value"><?= htmlspecialchars($shareTargetUrl, ENT_QUOTES, 'UTF-8') ?></code></dd></div>
      <div><dt>Cache recovery</dt><dd><a href="<?= htmlspecialchars($clearPwaUrl, ENT_QUOTES, 'UTF-8') ?>">Clear Bonumark Stream PWA caches</a></dd></div>
    </dl>
  </section>

  <section class="panel settings-section-panel">
    <div class="settings-section-header"><div><p class="eyebrow">Sign-in persistence</p><h2>Remembered devices</h2><p class="meta">Remembered login uses a separate device token, not a longer normal session. It is cleared on logout or password changes.</p></div></div>
    <div class="settings-option-list">
      <label class="settings-option-card"><input id="remember_login_enabled" type="checkbox" name="remember_login_enabled" value="1" <?= $rememberLoginEnabled ? 'checked' : '' ?>><span class="settings-option-copy"><strong>Show Remember this device on login forms</strong><small>Useful when Bonumark Stream is installed like an app on a trusted device.</small></span></label>
    </div>
    <div class="settings-field-grid">
      <div class="settings-field-card">
        <label for="remember_login_days">Remember device for up to</label>
        <input id="remember_login_days" type="number" name="remember_login_days" min="1" max="90" value="<?= htmlspecialchars((string)$rememberLoginDays, ENT_QUOTES, 'UTF-8') ?>">
        <p class="field-help">Allowed range is 1 to 90 days. The default is 30.</p>
      </div>
    </div>
  </section>

  <section class="panel settings-save-bar">
    <div><strong>Save Reading Settings</strong><p class="meta">Dynamic public routes use the updated values immediately.</p></div>
    <button type="submit">Save Reading Settings</button>
  </section>
</form>
<?php bms_admin_footer(); ?>

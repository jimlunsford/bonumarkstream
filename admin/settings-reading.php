<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/renderer.php';
require_once __DIR__ . '/_layout.php';
mp_require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    mp_verify_csrf();

    $streamComposer = !empty($_POST['stream_composer_enabled']) ? '1' : '0';
    $streamDates = !empty($_POST['stream_show_dates']) ? '1' : '0';
    $streamEditLinks = !empty($_POST['stream_show_edit_links']) ? '1' : '0';
    $sitemapEnabled = !empty($_POST['sitemap_enabled']) ? '1' : '0';
    $sitemapStreamPosts = !empty($_POST['sitemap_include_stream_posts']) ? '1' : '0';
    $sitemapPages = !empty($_POST['sitemap_include_pages']) ? '1' : '0';
    $sitemapProfiles = !empty($_POST['sitemap_include_profiles']) ? '1' : '0';
    $streamIndexPolicy = (string)($_POST['stream_index_policy'] ?? 'smart');
    if (!in_array($streamIndexPolicy, ['all', 'smart', 'noindex'], true)) {
        $streamIndexPolicy = 'smart';
    }
    $streamCount = (int)($_POST['stream_posts_per_page'] ?? 20);
    if ($streamCount < 1) {
        $streamCount = 1;
    }
    if ($streamCount > 100) {
        $streamCount = 100;
    }

    try {
        mp_set_setting('homepage_mode', 'stream');
        mp_set_setting('stream_composer_enabled', $streamComposer);
        mp_set_setting('stream_posts_per_page', (string)$streamCount);
        mp_set_setting('stream_show_dates', $streamDates);
        mp_set_setting('stream_show_edit_links', $streamEditLinks);
        mp_set_setting('stream_index_policy', $streamIndexPolicy);
        mp_set_setting('sitemap_enabled', $sitemapEnabled);
        mp_set_setting('sitemap_include_stream_posts', $sitemapStreamPosts);
        mp_set_setting('sitemap_include_pages', $sitemapPages);
        mp_set_setting('sitemap_include_profiles', $sitemapProfiles);
        mp_flash('Reading settings saved. Dynamic public routes use the updated stream settings immediately.', 'success');
        mp_redirect(mp_admin_url('settings-reading.php'));
    } catch (Throwable $e) {
        mp_flash('Could not save reading settings: ' . $e->getMessage(), 'error');
    }
}

$streamCount = mp_stream_posts_per_page();
$streamComposer = mp_stream_composer_enabled();
$streamDates = mp_stream_show_dates();
$streamEditLinks = mp_stream_show_edit_links();
$streamIndexPolicy = mp_stream_index_policy();
$sitemapEnabled = (string)mp_setting_or_config('sitemap_enabled', '1') === '1';
$sitemapStreamPosts = (string)mp_setting_or_config('sitemap_include_stream_posts', '1') === '1';
$sitemapPages = (string)mp_setting_or_config('sitemap_include_pages', '1') === '1';
$sitemapProfiles = (string)mp_setting_or_config('sitemap_include_profiles', '0') === '1';
$sitemapUrl = mp_site_url('sitemap.xml');
$robotsUrl = mp_site_url('robots.txt');
mp_admin_header('Reading Settings', []);
?>
<section class="panel page-intro-panel">
  <p class="eyebrow">Settings</p>
  <h2>Reading</h2>
  <p class="meta">Bonumark Stream always uses the Stream timeline as the homepage. These settings control how that stream is presented.</p>
</section>
<section class="panel settings-panel">
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(mp_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">

    <label class="checkbox-line" for="stream_composer_enabled">
      <input id="stream_composer_enabled" type="checkbox" name="stream_composer_enabled" value="1" <?= $streamComposer ? 'checked' : '' ?>>
      <span>Show front-page composer to logged-in users</span>
    </label>

    <label for="stream_posts_per_page">Stream posts per page</label>
    <input type="number" id="stream_posts_per_page" name="stream_posts_per_page" min="1" max="100" value="<?= htmlspecialchars((string)$streamCount, ENT_QUOTES, 'UTF-8') ?>">

    <label class="checkbox-line" for="stream_show_dates">
      <input id="stream_show_dates" type="checkbox" name="stream_show_dates" value="1" <?= $streamDates ? 'checked' : '' ?>>
      <span>Show dates on stream posts</span>
    </label>

    <label class="checkbox-line" for="stream_show_edit_links">
      <input id="stream_show_edit_links" type="checkbox" name="stream_show_edit_links" value="1" <?= $streamEditLinks ? 'checked' : '' ?>>
      <span>Show edit links to logged-in users</span>
    </label>

    <label for="stream_index_policy">Search indexing for single stream posts</label>
    <select id="stream_index_policy" name="stream_index_policy">
      <option value="all" <?= $streamIndexPolicy === 'all' ? 'selected' : '' ?>>Index all stream post pages</option>
      <option value="smart" <?= $streamIndexPolicy === 'smart' ? 'selected' : '' ?>>Smart indexing, noindex media-only posts</option>
      <option value="noindex" <?= $streamIndexPolicy === 'noindex' ? 'selected' : '' ?>>Noindex all individual stream post pages</option>
    </select>
    <p class="field-help">The main stream remains public. This only controls the robots meta tag on individual stream post pages.</p>

    <hr>

    <h3>XML Sitemap</h3>
    <p class="field-help">Bonumark can publish a dynamic XML sitemap at <code><?= htmlspecialchars($sitemapUrl, ENT_QUOTES, 'UTF-8') ?></code> and add a sitemap reference at <code><?= htmlspecialchars($robotsUrl, ENT_QUOTES, 'UTF-8') ?></code>.</p>

    <label class="checkbox-line" for="sitemap_enabled">
      <input id="sitemap_enabled" type="checkbox" name="sitemap_enabled" value="1" <?= $sitemapEnabled ? 'checked' : '' ?>>
      <span>Enable XML sitemap and robots.txt sitemap reference</span>
    </label>

    <label class="checkbox-line" for="sitemap_include_stream_posts">
      <input id="sitemap_include_stream_posts" type="checkbox" name="sitemap_include_stream_posts" value="1" <?= $sitemapStreamPosts ? 'checked' : '' ?>>
      <span>Include published stream posts that are allowed to be indexed</span>
    </label>

    <label class="checkbox-line" for="sitemap_include_pages">
      <input id="sitemap_include_pages" type="checkbox" name="sitemap_include_pages" value="1" <?= $sitemapPages ? 'checked' : '' ?>>
      <span>Include published pages that are allowed to be indexed</span>
    </label>

    <label class="checkbox-line" for="sitemap_include_profiles">
      <input id="sitemap_include_profiles" type="checkbox" name="sitemap_include_profiles" value="1" <?= $sitemapProfiles ? 'checked' : '' ?>>
      <span>Include public profile URLs</span>
    </label>
    <p class="field-help">Search results, account pages, admin pages, drafts, trash, pending content, and noindex content are not included.</p>

    <button type="submit">Save Reading Settings</button>
  </form>
</section>
<?php mp_admin_footer(); ?>

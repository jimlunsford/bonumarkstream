<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/renderer.php';
require_once __DIR__ . '/../_bonumark_stream/app/media.php';
require_once __DIR__ . '/../_bonumark_stream/app/comments.php';
require_once __DIR__ . '/../_bonumark_stream/app/pages.php';
require_once __DIR__ . '/../_bonumark_stream/app/mail.php';
require_once __DIR__ . '/../_bonumark_stream/app/sitemap.php';
require_once __DIR__ . '/../_bonumark_stream/app/themes.php';
require_once __DIR__ . '/_layout.php';
bms_require_login();

function bms_dashboard_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function bms_dashboard_count_query(string $sql, array $params = []): int
{
    try {
        $stmt = bms_db()->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function bms_dashboard_date_label(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return 'Unknown';
    }
    $time = strtotime($raw);
    return $time ? date('M j, Y', $time) : $raw;
}


function bms_dashboard_time_label(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return 'Unknown';
    }
    $time = strtotime($raw);
    return $time ? date('M j, Y g:i A', $time) : $raw;
}

function bms_dashboard_metric(string $label, int|string $value, string $href = '', string $note = ''): string
{
    $valueHtml = '<span>' . bms_dashboard_escape((string)$value) . '</span><strong>' . bms_dashboard_escape($label) . '</strong>';
    if ($note !== '') {
        $valueHtml .= '<em>' . bms_dashboard_escape($note) . '</em>';
    }
    if ($href !== '') {
        return '<a class="dashboard-metric-card" href="' . bms_dashboard_escape($href) . '">' . $valueHtml . '</a>';
    }
    return '<div class="dashboard-metric-card">' . $valueHtml . '</div>';
}

function bms_dashboard_attention_item(string $label, int|string $value, string $href = '', string $severity = 'neutral', string $note = ''): string
{
    $class = preg_replace('/[^a-z0-9_-]+/i', '', $severity) ?: 'neutral';
    $inner = '<span class="attention-marker ' . bms_dashboard_escape($class) . '"></span>'
        . '<span class="attention-copy"><strong>' . bms_dashboard_escape($label) . '</strong>';
    if ($note !== '') {
        $inner .= '<small>' . bms_dashboard_escape($note) . '</small>';
    }
    $inner .= '</span><span class="attention-value">' . bms_dashboard_escape((string)$value) . '</span>';
    if ($href !== '') {
        return '<a class="dashboard-attention-item" href="' . bms_dashboard_escape($href) . '">' . $inner . '</a>';
    }
    return '<div class="dashboard-attention-item">' . $inner . '</div>';
}

function bms_dashboard_status_item(string $label, string $value, string $tone = 'neutral', string $href = ''): string
{
    $class = preg_replace('/[^a-z0-9_-]+/i', '', $tone) ?: 'neutral';
    $inner = '<span class="status-dot ' . bms_dashboard_escape($class) . '"></span><span>' . bms_dashboard_escape($label) . '</span><strong>' . bms_dashboard_escape($value) . '</strong>';
    if ($href !== '') {
        return '<a class="dashboard-status-item" href="' . bms_dashboard_escape($href) . '">' . $inner . '</a>';
    }
    return '<div class="dashboard-status-item">' . $inner . '</div>';
}

function bms_dashboard_content_title(array $item): string
{
    $title = trim((string)($item['title'] ?? ''));
    if ($title !== '') {
        return $title;
    }
    $body = trim(strip_tags((string)($item['body'] ?? '')));
    return $body !== '' ? bms_plain_excerpt($body, 72) : 'Untitled';
}

function bms_dashboard_recent_stream_rows(array $items): string
{
    if (!$items) {
        return '<p class="meta">No stream posts yet.</p>';
    }
    $html = '<div class="dashboard-list">';
    foreach ($items as $item) {
        $section = (string)($item['section'] ?? 'drafts');
        $status = $section === 'published' ? 'published' : 'draft';
        $type = $status === 'published' ? 'published' : 'draft';
        $title = bms_dashboard_content_title($item);
        $date = bms_dashboard_date_label((string)($item['updated_at'] ?? $item['stream_created_at'] ?? $item['date'] ?? ''));
        $badge = $status === 'published' ? 'Published' : 'Draft';
        $html .= '<a class="dashboard-list-row" href="' . bms_dashboard_escape(bms_admin_url('edit.php?type=' . urlencode($type) . '&file=' . urlencode((string)($item['filename'] ?? '')))) . '">'
            . '<span><strong>' . bms_dashboard_escape($title) . '</strong><small>' . bms_dashboard_escape($date) . '</small></span>'
            . '<em class="status-pill ' . bms_dashboard_escape($status) . '">' . bms_dashboard_escape($badge) . '</em>'
            . '</a>';
    }
    return $html . '</div>';
}

function bms_dashboard_recent_page_rows(array $items): string
{
    if (!$items) {
        return '<p class="meta">No pages yet.</p>';
    }
    $html = '<div class="dashboard-list">';
    foreach ($items as $item) {
        $section = (string)($item['section'] ?? 'pages/drafts');
        $status = str_contains($section, 'published') ? 'published' : 'draft';
        $type = $status === 'published' ? 'published' : 'draft';
        $title = bms_dashboard_content_title($item);
        $date = bms_dashboard_date_label((string)($item['updated_at'] ?? $item['date'] ?? ''));
        $html .= '<a class="dashboard-list-row" href="' . bms_dashboard_escape(bms_admin_url('page-edit.php?type=' . urlencode($type) . '&file=' . urlencode((string)($item['filename'] ?? '')))) . '">'
            . '<span><strong>' . bms_dashboard_escape($title) . '</strong><small>' . bms_dashboard_escape($date) . '</small></span>'
            . '<em class="status-pill ' . bms_dashboard_escape($status) . '">' . bms_dashboard_escape(ucfirst($status)) . '</em>'
            . '</a>';
    }
    return $html . '</div>';
}

$canManagePages = bms_current_user_can('manage_pages');
$canManageMedia = bms_current_user_can('manage_media');
$canManageComments = bms_current_user_can('manage_comments');
$canManageUsers = bms_current_user_can('manage_users');
$canManageSettings = bms_current_user_can('manage_settings');
$canManageAppearance = bms_current_user_can('manage_appearance');
$canViewSystem = bms_current_user_can('view_system');
$canEditContent = bms_current_user_can('edit_content');

$allDrafts = bms_filter_stream_posts(bms_list_content_records('drafts'));
$allPublished = bms_filter_stream_posts(bms_list_content_records('published'));
$drafts = function_exists('bms_filter_content_items_for_current_user') ? bms_filter_stream_posts(bms_filter_content_items_for_current_user($allDrafts)) : $allDrafts;
$published = function_exists('bms_filter_content_items_for_current_user') ? bms_filter_stream_posts(bms_filter_content_items_for_current_user($allPublished)) : $allPublished;
$recent = array_slice(bms_sort_stream_posts(array_merge($drafts, $published)), 0, 6);

$pageDrafts = $canManagePages ? bms_list_page_records('draft') : [];
$pagePublished = $canManagePages ? bms_list_page_records('published') : [];
$recentPages = $canManagePages ? array_slice(bms_sort_stream_posts(array_merge($pageDrafts, $pagePublished)), 0, 5) : [];

$mediaCount = $canManageMedia && function_exists('bms_media_count') ? bms_media_count('active') : 0;
$mediaTrashCount = $canManageMedia && function_exists('bms_media_count') ? bms_media_count('trash') : 0;
$streamTrashCount = function_exists('bms_list_trash_items') ? count(bms_list_trash_items()) : 0;
$pageTrashCount = $canManagePages && function_exists('bms_list_page_trash_items') ? count(bms_list_page_trash_items()) : 0;
$trashCount = $streamTrashCount + $pageTrashCount;

$commentApprovedCount = $canManageComments ? bms_dashboard_count_query('SELECT COUNT(*) FROM ' . bms_table('comments') . ' WHERE status = :status', ['status' => 'approved']) : 0;
$commentPendingCount = $canManageComments ? bms_dashboard_count_query('SELECT COUNT(*) FROM ' . bms_table('comments') . ' WHERE status = :status', ['status' => 'pending']) : 0;
$commentTrashCount = $canManageComments ? bms_dashboard_count_query('SELECT COUNT(*) FROM ' . bms_table('comments') . ' WHERE status = :status', ['status' => 'trash']) : 0;
$recentComments = $canManageComments && function_exists('bms_list_admin_comments') ? bms_list_admin_comments('pending', 5) : [];

$userCount = $canManageUsers ? bms_dashboard_count_query('SELECT COUNT(*) FROM ' . bms_table('users') . ' WHERE status = :status', ['status' => 'active']) : 0;
$pendingUsers = $canManageUsers && function_exists('bms_user_pending_counts') ? bms_user_pending_counts() : ['pending_verification' => 0, 'pending_approval' => 0];
$pendingUserTotal = (int)($pendingUsers['pending_verification'] ?? 0) + (int)($pendingUsers['pending_approval'] ?? 0);

$publishedPageCount = count($pagePublished);
$draftPageCount = count($pageDrafts);
$pageCount = $publishedPageCount + $draftPageCount;
$noindexCount = 0;
foreach (array_merge($published, $pagePublished) as $item) {
    $robots = strtolower(trim((string)($item['robots'] ?? ($item['front_matter']['robots'] ?? ''))));
    if ($robots !== '' && str_contains($robots, 'noindex')) {
        $noindexCount++;
    }
}

$activeTheme = function_exists('bms_active_public_theme_name') ? bms_active_public_theme_name() : 'Default';
$sitemapEnabled = function_exists('bms_sitemap_enabled') ? bms_sitemap_enabled() : ((string)bms_setting_or_config('sitemap_enabled', '1') === '1');
$sitemapUrl = bms_url_path('sitemap.xml');
$robotsUrl = bms_url_path('robots.txt');
$mailSettings = function_exists('bms_mail_settings') ? bms_mail_settings() : [];
$mailTransport = (string)($mailSettings['transport'] ?? bms_setting_or_config('mail_transport', 'php_mail'));
$mailLabel = function_exists('bms_mail_transport_label') ? bms_mail_transport_label($mailTransport) : ucfirst(str_replace('_', ' ', $mailTransport));
$registrationModeValue = (string)bms_setting_or_config('registration_mode', 'disabled');
$registrationMode = $registrationModeValue !== '' ? ucwords(str_replace(['_', '-'], ' ', $registrationModeValue)) : 'Disabled';
$latestPostTime = '';
foreach ($published as $item) {
    $candidate = (string)($item['updated_at'] ?? $item['stream_created_at'] ?? $item['date'] ?? '');
    if ($candidate !== '' && (strtotime($candidate) ?: 0) > (strtotime($latestPostTime) ?: 0)) {
        $latestPostTime = $candidate;
    }
}

bms_admin_header('Dashboard', [
    bms_view_site_action(),
]);
?>
<section class="dashboard-hero panel">
  <div class="dashboard-hero-copy">
    <p class="eyebrow">Bonumark Stream</p>
    <h2>Admin overview</h2>
    <p class="meta">A quick read on publishing, site health, attention items, and the next actions that matter.</p>
  </div>
  <div class="dashboard-hero-actions">
    <?php if ($canEditContent): ?><a class="primary-button" href="<?= bms_dashboard_escape(bms_admin_url('new.php')) ?>">New Stream Post</a><?php endif; ?>
    <?php if ($canManagePages): ?><a class="button-link secondary" href="<?= bms_dashboard_escape(bms_admin_url('page-new.php')) ?>">New Page</a><?php endif; ?>
    <?php if ($canManageMedia): ?><a class="button-link secondary" href="<?= bms_dashboard_escape(bms_admin_url('media-upload.php')) ?>">Upload Media</a><?php endif; ?>
    <a class="button-link secondary" href="<?= bms_dashboard_escape(bms_url_path()) ?>" target="_blank" rel="noopener">View Site</a>
  </div>
</section>

<section class="dashboard-metric-grid" aria-label="Site snapshot">
  <?= bms_dashboard_metric('Published Posts', count($published), bms_admin_url('content.php?status=published'), $latestPostTime !== '' ? 'Latest ' . bms_dashboard_date_label($latestPostTime) : '') ?>
  <?= bms_dashboard_metric('Drafts', count($drafts), bms_admin_url('content.php?status=draft')) ?>
  <?php if ($canManagePages): ?><?= bms_dashboard_metric('Pages', $pageCount, bms_admin_url('pages.php'), $publishedPageCount . ' published') ?><?php endif; ?>
  <?php if ($canManageMedia): ?><?= bms_dashboard_metric('Media', $mediaCount, bms_admin_url('media.php'), $mediaTrashCount > 0 ? $mediaTrashCount . ' in trash' : '') ?><?php endif; ?>
  <?php if ($canManageComments): ?><?= bms_dashboard_metric('Comments', $commentApprovedCount + $commentPendingCount, bms_admin_url('comments.php'), $commentPendingCount > 0 ? $commentPendingCount . ' pending' : '') ?><?php endif; ?>
  <?php if ($canManageUsers): ?><?= bms_dashboard_metric('Commenters', $userCount, bms_admin_url('users.php'), $pendingUserTotal > 0 ? $pendingUserTotal . ' pending' : '') ?><?php endif; ?>
</section>

<section class="dashboard-balanced-grid" aria-label="Dashboard overview">
  <div class="dashboard-column dashboard-column-primary">
    <div class="panel dashboard-card dashboard-actions-panel">
      <div class="section-header-row">
        <div>
          <h2>Quick Actions</h2>
          <p class="meta">Start the work you are most likely to do next.</p>
        </div>
      </div>
      <div class="dashboard-action-grid">
        <?php if ($canEditContent): ?><a class="button-link" href="<?= bms_dashboard_escape(bms_admin_url('new.php')) ?>">New Stream Post</a><?php endif; ?>
        <a class="button-link secondary" href="<?= bms_dashboard_escape(bms_admin_url('content.php')) ?>">Manage Stream Posts</a>
        <?php if ($canManagePages): ?><a class="button-link secondary" href="<?= bms_dashboard_escape(bms_admin_url('page-new.php')) ?>">New Page</a><?php endif; ?>
        <?php if ($canManageMedia): ?><a class="button-link secondary" href="<?= bms_dashboard_escape(bms_admin_url('media-upload.php')) ?>">Upload Media</a><?php endif; ?>
        <?php if ($canManageComments): ?><a class="button-link secondary" href="<?= bms_dashboard_escape(bms_admin_url('comments.php')) ?>">Moderate Comments</a><?php endif; ?>
        <?php if ($canViewSystem): ?><a class="button-link secondary" href="<?= bms_dashboard_escape(bms_admin_url('export.php')) ?>">Export Backup</a><?php endif; ?>
        <?php if ($canViewSystem): ?><a class="button-link secondary" href="<?= bms_dashboard_escape(bms_admin_url('system-check.php')) ?>">System Check</a><?php endif; ?>
        <a class="button-link secondary" href="<?= bms_dashboard_escape($sitemapUrl) ?>" target="_blank" rel="noopener">View Sitemap</a>
      </div>
    </div>

    <div class="panel dashboard-card dashboard-attention-card">
      <div class="section-header-row">
        <div>
          <h2>Needs Attention</h2>
          <p class="meta">Items that may need a decision or cleanup.</p>
        </div>
      </div>
      <div class="dashboard-attention-list">
        <?php if ($canManageComments): ?><?= bms_dashboard_attention_item('Pending comments', $commentPendingCount, bms_admin_url('comments.php?status=pending'), $commentPendingCount > 0 ? 'warning' : 'good', $commentPendingCount > 0 ? 'Moderation needed' : 'Comment queue is clear') ?><?php endif; ?>
        <?php if ($canManageUsers): ?><?= bms_dashboard_attention_item('Pending commenters', $pendingUserTotal, bms_admin_url('users.php?status=pending'), $pendingUserTotal > 0 ? 'warning' : 'good', $pendingUserTotal > 0 ? 'Approval or verification waiting' : 'No commenter approvals waiting') ?><?php endif; ?>
        <?= bms_dashboard_attention_item('Trash', $trashCount, bms_admin_url('content.php?status=trash'), $trashCount > 0 ? 'neutral' : 'good', $trashCount > 0 ? 'Recover or delete old content' : 'No trashed content') ?>
        <?php if ($canManageMedia): ?><?= bms_dashboard_attention_item('Media trash', $mediaTrashCount, bms_admin_url('media.php?status=trash'), $mediaTrashCount > 0 ? 'neutral' : 'good', $mediaTrashCount > 0 ? 'Review discarded files' : 'Media trash is clear') ?><?php endif; ?>
        <?php if ($canManageSettings): ?><?= bms_dashboard_attention_item('XML sitemap', $sitemapEnabled ? 'On' : 'Off', bms_admin_url('settings-reading.php'), $sitemapEnabled ? 'good' : 'warning', $sitemapEnabled ? 'Search index file is available' : 'Enable before search-focused launch') ?><?php endif; ?>
      </div>
    </div>

    <?php if ($canManagePages): ?>
    <div class="panel dashboard-card">
      <div class="section-header-row">
        <div>
          <h2>Recent Pages</h2>
          <p class="meta">Public pages and page drafts.</p>
        </div>
        <a class="button-link secondary" href="<?= bms_dashboard_escape(bms_admin_url('pages.php')) ?>">View Pages</a>
      </div>
      <?= bms_dashboard_recent_page_rows($recentPages) ?>
    </div>
    <?php endif; ?>
  </div>

  <div class="dashboard-column dashboard-column-secondary">
    <div class="panel dashboard-card dashboard-recent-stream-card">
      <div class="section-header-row">
        <div>
          <h2>Recent Stream Posts</h2>
          <p class="meta">Latest published posts and drafts.</p>
        </div>
        <a class="button-link secondary" href="<?= bms_dashboard_escape(bms_admin_url('content.php')) ?>">View All</a>
      </div>
      <?= bms_dashboard_recent_stream_rows($recent) ?>
    </div>

    <div class="panel dashboard-card dashboard-status-card">
      <div class="section-header-row">
        <div>
          <h2>System Status</h2>
          <p class="meta">Fast checks for the site layer admins care about.</p>
        </div>
      </div>
      <div class="dashboard-status-grid">
        <?= bms_dashboard_status_item('Version', bms_version(), 'good', $canViewSystem ? bms_admin_url('system-check.php') : '') ?>
        <?= bms_dashboard_status_item('Theme', $activeTheme, 'good', $canManageAppearance ? bms_admin_url('theme.php') : '') ?>
        <?= bms_dashboard_status_item('Sitemap', $sitemapEnabled ? 'Enabled' : 'Disabled', $sitemapEnabled ? 'good' : 'warning', $sitemapUrl) ?>
        <?= bms_dashboard_status_item('Robots', 'Available', 'good', $robotsUrl) ?>
        <?php if ($canManageSettings): ?><?= bms_dashboard_status_item('Mail', $mailLabel, $mailTransport === 'log_only' ? 'warning' : 'good', bms_admin_url('mail.php')) ?><?php endif; ?>
        <?php if ($canManageSettings): ?><?= bms_dashboard_status_item('Registration', $registrationMode, 'neutral', bms_admin_url('registration.php')) ?><?php endif; ?>
        <?= bms_dashboard_status_item('Noindex URLs', (string)$noindexCount, $noindexCount > 0 ? 'neutral' : 'good', $canManagePages ? bms_admin_url('pages.php') : '') ?>
      </div>
    </div>

    <?php if ($canManageComments || $canManageSettings): ?>
    <div class="panel dashboard-card dashboard-notes-card">
      <div class="section-header-row">
        <div>
          <h2>Admin Notes</h2>
          <p class="meta">Small checks worth knowing before you move on.</p>
        </div>
      </div>
      <div class="dashboard-note-list">
        <?php if ($canManageComments): ?>
          <p><strong>Comments:</strong> <?= bms_dashboard_escape((string)$commentPendingCount) ?> pending, <?= bms_dashboard_escape((string)$commentTrashCount) ?> in trash.</p>
        <?php endif; ?>
        <?php if ($canManageSettings): ?>
          <p><strong>Sitemap:</strong> <?= $sitemapEnabled ? 'Enabled and linked from robots.txt.' : 'Disabled. Enable it before a search-focused launch.' ?></p>
          <p><strong>Profiles in sitemap:</strong> <?= function_exists('bms_sitemap_include_profiles') && bms_sitemap_include_profiles() ? 'Included.' : 'Excluded by default.' ?></p>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</section>

<?php bms_admin_footer(); ?>

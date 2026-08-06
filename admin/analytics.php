<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/analytics.php';
require_once __DIR__ . '/_layout.php';

bms_require_login();
bms_require_capability('view_system');

function bms_analytics_admin_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function bms_analytics_admin_number(mixed $value): string
{
    return number_format(max(0, (int)$value));
}

function bms_analytics_admin_count_phrase(mixed $value): string
{
    $count = max(0, (int)$value);
    return number_format($count) . ' ' . ($count === 1 ? 'view' : 'views');
}

function bms_analytics_admin_human_label(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return 'Unknown';
    }
    $known = [
        'desktop' => 'Desktop',
        'mobile' => 'Mobile',
        'tablet' => 'Tablet',
        'other' => 'Other',
        'chrome' => 'Chrome',
        'safari' => 'Safari',
        'firefox' => 'Firefox',
        'edge' => 'Edge',
        'direct' => 'Direct',
    ];
    $lower = strtolower($value);
    if (isset($known[$lower])) {
        return $known[$lower];
    }
    return ucwords(str_replace(['_', '-'], ' ', $value));
}

function bms_analytics_admin_list_rows(array $rows, callable $labelCallback, string $emptyMessage): string
{
    if (!$rows) {
        return '<p class="analytics-empty-state">' . bms_analytics_admin_escape($emptyMessage) . '</p>';
    }

    $html = '<div class="analytics-report-list">';
    foreach ($rows as $row) {
        $label = (string)$labelCallback($row);
        $views = bms_analytics_admin_count_phrase($row['page_views'] ?? 0);
        $html .= '<div class="analytics-report-row"><span>' . bms_analytics_admin_escape($label) . '</span><strong>' . bms_analytics_admin_escape($views) . '</strong></div>';
    }
    $html .= '</div>';
    return $html;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    bms_verify_csrf();
    $action = (string)($_POST['analytics_action'] ?? '');

    if ($action === 'save_settings') {
        $enabled = !empty($_POST['analytics_enabled']) ? '1' : '0';
        $retention = (int)($_POST['analytics_retention_days'] ?? 90);
        if (!in_array($retention, bms_analytics_allowed_retention_days(), true)) {
            $retention = 90;
        }
        bms_set_setting('analytics_enabled', $enabled);
        bms_set_setting('analytics_retention_days', (string)$retention);
        if ($enabled === '1') {
            bms_analytics_cleanup_expired(false);
        }
        bms_flash($enabled === '1' ? 'Privacy-First Analytics is enabled. It will count eligible public page views only.' : 'Privacy-First Analytics is disabled. No new analytics data will be collected.', 'success');
        bms_redirect(bms_admin_url('analytics.php'));
    }

    if ($action === 'clear_data') {
        if (trim((string)($_POST['clear_confirmation'] ?? '')) !== 'CLEAR ANALYTICS DATA') {
            bms_flash('Analytics data was not cleared. Type CLEAR ANALYTICS DATA to confirm.', 'error');
            bms_redirect(bms_admin_url('analytics.php'));
        }
        $deleted = bms_analytics_clear_all_data();
        bms_flash('Cleared ' . number_format($deleted) . ' aggregate analytics row' . ($deleted === 1 ? '' : 's') . '.', 'success');
        bms_redirect(bms_admin_url('analytics.php'));
    }

    if ($action === 'export_csv') {
        $range = (string)($_POST['range'] ?? '30d');
        $csv = bms_analytics_export_csv($range);
        $dates = bms_analytics_range_dates($range);
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="bonumark-stream-analytics-' . $dates['start'] . '-to-' . $dates['end'] . '.csv"');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        echo $csv;
        exit;
    }
}

$range = (string)($_GET['range'] ?? '30d');
$summary = bms_analytics_summary($range);
$range = (string)$summary['dates']['key'];
$enabled = bms_analytics_enabled();
$retention = bms_analytics_retention_days();
$rangeOptions = ['today' => 'Today', '7d' => 'Last 7 Days', '30d' => 'Last 30 Days', '90d' => 'Last 90 Days'];

bms_admin_header('Analytics', [
    ['label' => 'Tools', 'href' => bms_admin_url('tools.php'), 'style' => 'secondary'],
    ['label' => 'System Check', 'href' => bms_admin_url('system-check.php'), 'style' => 'secondary'],
]);
?>
<section class="panel operations-hero analytics-intro-panel"><div class="operations-hero-copy">
  <p class="eyebrow">Privacy-First Analytics</p>
  <h2>Useful publishing signals, without tracking people.</h2>
  <p class="meta">Optional, self-hosted, cookieless aggregate reporting. A page reload counts as a page view. Bonumark Stream does not estimate unique visitors or follow people across pages.</p>
</div><span class="operation-risk-label is-safe">Aggregate only</span></section>

<section class="admin-two-column analytics-settings-grid">
  <form method="post" class="panel operations-panel">
    <input type="hidden" name="csrf_token" value="<?= bms_analytics_admin_escape(bms_csrf_token()) ?>">
    <input type="hidden" name="analytics_action" value="save_settings">
    <p class="eyebrow">Status and retention</p>
    <h2><?= $enabled ? 'Analytics is enabled' : 'Analytics is disabled' ?></h2>
    <label class="check-row"><input type="checkbox" name="analytics_enabled" value="1" <?= $enabled ? 'checked' : '' ?>> <span>Enable cookieless aggregate analytics</span></label>
    <p class="field-help">When disabled, Bonumark Stream sends no collector request and writes no analytics data.</p>
    <label for="analytics_retention_days">Keep aggregate reporting for</label>
    <select id="analytics_retention_days" name="analytics_retention_days">
      <?php foreach (bms_analytics_allowed_retention_days() as $days): ?>
        <option value="<?= $days ?>" <?= $retention === $days ? 'selected' : '' ?>><?= $days ?> days</option>
      <?php endforeach; ?>
    </select>
    <p class="field-help">Retention removes only expired analytics aggregate rows. It never touches posts, pages, users, media, comments, settings, or cron history.</p>
    <button type="submit">Save Analytics Settings</button>
  </form>

  <section class="panel operations-diagnostic-panel analytics-boundaries-card">
    <p class="eyebrow">Privacy boundaries</p>
    <h2>What this mode does and does not do</h2>
    <div class="analytics-boundaries-columns">
      <div><h3>Collected</h3><ul><li>Aggregate page views</li><li>Clean public page paths</li><li>Referrer domains</li><li>Broad device and browser groups</li><li>Approved UTM campaign fields</li></ul></div>
      <div><h3>Not collected</h3><ul><li>Cookies or browser storage</li><li>IPs, IP hashes, or user agents</li><li>Visitor IDs, sessions, or fingerprints</li><li>Full referrers or query strings</li><li>Private, admin, account, API, feed, sitemap, cron, install, or upgrade activity</li></ul></div>
    </div>
  </section>
</section>

<section class="panel operations-panel analytics-report-panel">
  <div class="section-header-row analytics-report-header">
    <div><p class="eyebrow">Aggregate report</p><h2><?= bms_analytics_admin_escape($rangeOptions[$range]) ?></h2><p class="meta"><?= bms_analytics_admin_escape((string)$summary['dates']['start']) ?> through <?= bms_analytics_admin_escape((string)$summary['dates']['end']) ?>, using the configured site timezone.</p></div>
    <form method="get" class="analytics-range-form"><label for="analytics-range" class="screen-reader-text">Date range</label><select id="analytics-range" name="range" onchange="this.form.submit()"><?php foreach ($rangeOptions as $key => $label): ?><option value="<?= bms_analytics_admin_escape($key) ?>" <?= $range === $key ? 'selected' : '' ?>><?= bms_analytics_admin_escape($label) ?></option><?php endforeach; ?></select><noscript><button type="submit">View</button></noscript></form>
  </div>
  <div class="dashboard-metric-grid analytics-metric-grid">
    <div class="dashboard-metric-card"><span><?= bms_analytics_admin_number($summary['total']) ?></span><strong>Page Views</strong></div>
    <div class="dashboard-metric-card"><span><?= bms_analytics_admin_number(count($summary['daily'])) ?></span><strong>Days With Views</strong></div>
    <div class="dashboard-metric-card"><span><?= bms_analytics_admin_number(count($summary['entries'])) ?></span><strong>Pages Reached</strong></div>
    <div class="dashboard-metric-card"><span><?= bms_analytics_admin_number(count($summary['referrers'])) ?></span><strong>Referrer Domains</strong></div>
  </div>
</section>

<section class="analytics-report-grid">
  <section class="panel analytics-report-card"><h2>Views by Day</h2><?= bms_analytics_admin_list_rows($summary['daily'], static fn($row) => (string)($row['report_date'] ?? 'Unknown date'), 'No page views recorded for this date range yet.') ?></section>
  <section class="panel analytics-report-card"><h2>Top Entry Pages</h2><?= bms_analytics_admin_list_rows($summary['entries'], static fn($row) => (string)($row['page_path'] ?? 'Unknown path'), 'No entry-page views recorded for this date range yet.') ?></section>
  <section class="panel analytics-report-card"><h2>Top Stream Posts</h2><?= bms_analytics_admin_list_rows($summary['posts'], static fn($row) => bms_analytics_content_label($row), 'No stream-post views recorded for this date range yet.') ?></section>
  <section class="panel analytics-report-card"><h2>Top Pages</h2><?= bms_analytics_admin_list_rows($summary['pages'], static fn($row) => bms_analytics_content_label($row), 'No page views recorded for this date range yet.') ?></section>
  <section class="panel analytics-report-card"><h2>Referrer Domains</h2><?= bms_analytics_admin_list_rows($summary['referrers'], static fn($row) => bms_analytics_admin_human_label((string)($row['referrer_domain'] ?? 'Direct')), 'No referrer domains recorded for this date range yet.') ?></section>
  <section class="panel analytics-report-card"><h2>Device Categories</h2><?= bms_analytics_admin_list_rows($summary['devices'], static fn($row) => bms_analytics_admin_human_label((string)($row['device_category'] ?? 'Other')), 'No device categories recorded for this date range yet.') ?></section>
  <section class="panel analytics-report-card"><h2>Browser Families</h2><?= bms_analytics_admin_list_rows($summary['browsers'], static fn($row) => bms_analytics_admin_human_label((string)($row['browser_family'] ?? 'Other')), 'No browser families recorded for this date range yet.') ?></section>
  <?php if ($summary['campaigns']): ?><section class="panel analytics-report-card"><h2>UTM Campaigns</h2><?= bms_analytics_admin_list_rows($summary['campaigns'], static fn($row) => trim(implode(' / ', array_filter([(string)($row['utm_source'] ?? ''), (string)($row['utm_medium'] ?? ''), (string)($row['utm_campaign'] ?? '')]))) ?: 'Campaign', 'No campaign data recorded for this date range yet.') ?></section><?php endif; ?>
</section>

<section class="admin-two-column analytics-settings-grid">
  <form method="post" class="panel operations-panel">
    <input type="hidden" name="csrf_token" value="<?= bms_analytics_admin_escape(bms_csrf_token()) ?>"><input type="hidden" name="analytics_action" value="export_csv"><input type="hidden" name="range" value="<?= bms_analytics_admin_escape($range) ?>">
    <p class="eyebrow">Aggregate export</p><h2>Download this report</h2><p class="meta">The CSV contains aggregate reporting rows only. It has no visitor-level records, IPs, raw user agents, cookies, sessions, or identifiers.</p><button type="submit">Export <?= bms_analytics_admin_escape($rangeOptions[$range]) ?> CSV</button>
  </form>
  <form method="post" class="panel operations-danger-zone analytics-danger-zone">
    <input type="hidden" name="csrf_token" value="<?= bms_analytics_admin_escape(bms_csrf_token()) ?>"><input type="hidden" name="analytics_action" value="clear_data">
    <p class="eyebrow">Clear analytics data</p><h2>Delete all aggregate analytics</h2><p class="meta">This cannot be undone. It deletes only analytics aggregate rows.</p><label for="clear_confirmation">Type <code>CLEAR ANALYTICS DATA</code> to confirm</label><input id="clear_confirmation" type="text" name="clear_confirmation" autocomplete="off"><button type="submit" class="danger-button">Clear All Analytics Data</button>
  </form>
</section>
<?php bms_admin_footer(); ?>

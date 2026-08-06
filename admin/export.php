<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/media.php';
require_once __DIR__ . '/_layout.php';
bms_require_login();
bms_require_capability('view_system');

function bms_export_add_directory(ZipArchive $zip, string $baseDir, string $zipPrefix = ''): void
{
    if (!is_dir($baseDir)) {
        return;
    }
    $baseDir = rtrim($baseDir, '/\\');
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($baseDir, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $path = $file->getPathname();
        $relative = ltrim(str_replace('\\', '/', substr($path, strlen($baseDir))), '/');
        $zip->addFile($path, trim($zipPrefix . '/' . $relative, '/'));
    }
}


function bms_export_markdown_from_database(ZipArchive $zip): int
{
    $count = 0;
    $manifest = [
        'generated_at' => date('c'),
        'source' => 'database-first-content-records',
        'version' => bms_version(),
        'items' => [],
    ];
    $sections = [
        'published' => 'posts/published',
        'drafts' => 'posts/drafts',
        'scheduled' => 'posts/scheduled',
        'pages/published' => 'pages/published',
        'pages/drafts' => 'pages/drafts',
    ];
    foreach ($sections as $section => $zipDir) {
        foreach (bms_list_content_records($section) as $page) {
            $slug = bms_slugify((string)($page['slug'] ?? ''));
            if ($slug === '') {
                $slug = 'content-' . (++$count);
            }
            $filename = $slug . '.md';
            $raw = function_exists('bms_database_content_raw') ? bms_database_content_raw($page) : bms_build_markdown_document($page, (string)($page['body'] ?? ''));
            $zipPath = 'markdown/' . trim($zipDir, '/') . '/' . $filename;
            $zip->addFromString($zipPath, $raw);
            $manifest['items'][] = [
                'path' => $zipPath,
                'title' => (string)($page['title'] ?? ''),
                'slug' => $slug,
                'status' => (string)($page['status'] ?? ''),
                'content_type' => (string)($page['content_type'] ?? $page['post_type'] ?? 'stream'),
                'date' => (string)($page['date'] ?? ''),
            ];
            $count++;
        }
    }
    $zip->addFromString('markdown/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $zip->addFromString('markdown/README.txt', "This Markdown export was generated from database-first Bonumark Stream content records. Markdown is export output and import material only.\n");
    return $count;
}

function bms_export_database_tables(): array
{
    $prefix = bms_table_prefix();
    $like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $prefix) . '%';
    try {
        $stmt = bms_db()->query('SHOW TABLES LIKE ' . bms_db()->quote($like));
        $tables = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
        sort($tables, SORT_NATURAL);
        return $tables;
    } catch (Throwable $e) {
        $fallback = [
            'settings', 'users', 'posts', 'terms', 'post_terms', 'revisions', 'login_attempts',
            'upgrade_history', 'migrations', 'media', 'trash', 'autosaves', 'stream_likes',
            'stream_like_attempts', 'comments', 'mail_test_deliveries', 'registration_invites',
            'password_reset_tokens', 'password_reset_attempts', 'email_verification_attempts',
            'api_tokens', 'api_audit_log', 'api_rate_limit_attempts', 'api_idempotency_keys',
            'analytics_daily',
        ];
        return array_map('bms_table', $fallback);
    }
}

function bms_export_database_sql(): string
{
    $tables = bms_export_database_tables();
    $sql = "-- Bonumark Stream database export\n-- Generated: " . date('c') . "\n-- Warning: this private backup may contain password hashes, email addresses, API token hashes, security logs, and account data.\n\n";
    foreach ($tables as $table) {
        $safeTable = preg_replace('/[^A-Za-z0-9_]/', '', $table) ?: '';
        if ($safeTable === '' || !str_starts_with($safeTable, bms_table_prefix())) {
            continue;
        }
        try {
            $rows = bms_db()->query('SELECT * FROM `' . $safeTable . '`')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            continue;
        }
        $sql .= "-- Table: {$safeTable}\n";
        foreach ($rows as $row) {
            $columns = array_map(fn($c) => '`' . str_replace('`', '``', $c) . '`', array_keys($row));
            $values = array_map(function ($value) {
                if ($value === null) { return 'NULL'; }
                return bms_db()->quote((string)$value);
            }, array_values($row));
            $sql .= 'INSERT INTO `' . $safeTable . '` (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ");\n";
        }
        $sql .= "\n";
    }
    return $sql;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    bms_verify_csrf();
    $kind = (string)($_POST['export_kind'] ?? '');
    if (!class_exists('ZipArchive')) {
        bms_flash('Export requires PHP ZipArchive. Ask the host to enable it.', 'error');
        bms_redirect(bms_admin_url('export.php'));
    }
    $tmp = bms_root_path('tmp/exports');
    if (!is_dir($tmp)) { @mkdir($tmp, 0755, true); }
    $filename = 'bonumark-stream-export-' . $kind . '-' . date('Ymd-His') . '.zip';
    $path = $tmp . '/' . $filename;
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        bms_flash('Could not create export ZIP.', 'error');
        bms_redirect(bms_admin_url('export.php'));
    }
    if ($kind === 'markdown' || $kind === 'full') {
        bms_export_markdown_from_database($zip);
        bms_export_add_directory($zip, bms_content_path('versions'), 'content/versions');
    }
    if ($kind === 'static' || $kind === 'full') {
        if (function_exists('bms_generate_static_site_export')) {
            $staticTargetRoot = bms_static_site_export_root('export-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)));
            try {
                bms_generate_static_site_export($staticTargetRoot);
                bms_export_add_directory($zip, $staticTargetRoot, 'static');
            } finally {
                if (is_dir($staticTargetRoot)) {
                    bms_delete_directory($staticTargetRoot);
                }
            }
        }
        bms_export_add_directory($zip, bms_public_path('assets'), 'static/assets');
        bms_export_add_directory($zip, bms_public_path('media'), 'static/media');
    }
    if ($kind === 'media' || $kind === 'full') {
        bms_export_add_directory($zip, bms_public_path('media'), 'media');
    }
    if ($kind === 'database' || $kind === 'full') {
        $zip->addFromString('database/bonumark.sql', bms_export_database_sql());
    }
    $zip->addFromString('EXPORT.txt', "Bonumark Stream export\nType: {$kind}\nVersion: " . bms_version() . "\nGenerated: " . date('c') . "\n");
    $zip->close();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    @unlink($path);
    exit;
}

bms_admin_header('Export', [
    ['label' => 'Tools', 'href' => bms_admin_url('tools.php'), 'style' => 'secondary'],
]);
$zipReady = class_exists('ZipArchive');
?>
<section class="panel operations-hero">
  <div class="operations-hero-copy">
    <p class="eyebrow">Ownership and backup</p>
    <h2>Take your content out without confusing portability with privacy.</h2>
    <p class="meta">Markdown, static HTML, and public media are portability outputs. Database and full exports are private backups that can contain account, authentication, API, registration, and security records.</p>
  </div>
  <span class="operation-risk-label <?= $zipReady ? 'is-safe' : 'is-destructive' ?>"><?= $zipReady ? 'ZIP ready' : 'ZIP unavailable' ?></span>
</section>

<section class="panel operations-summary-panel">
  <div class="operations-summary-grid">
    <div><span>Content source</span><strong>Database records</strong></div>
    <div><span>Portable formats</span><strong>Markdown and HTML</strong></div>
    <div><span>Private backups</span><strong>Database and Full</strong></div>
    <div><span>Temporary output</span><strong>Deleted after download</strong></div>
  </div>
</section>

<?php if (!$zipReady): ?>
<section class="panel operations-danger-zone">
  <div class="operations-panel-heading"><div><p class="eyebrow">Blocked</p><h2>PHP ZipArchive is unavailable.</h2><p class="meta">Exports cannot be created until the hosting environment enables the Zip extension.</p></div><span class="operation-risk-label is-destructive">Action unavailable</span></div>
</section>
<?php endif; ?>

<section class="panel operations-panel">
  <div class="operations-section-heading"><div><p class="eyebrow">Portable outputs</p><h2>Content you can move or publish elsewhere</h2><p class="meta">These packages contain public-facing content or media rather than the complete private application database.</p></div><span class="operation-risk-label is-safe">Lower sensitivity</span></div>
  <div class="operations-card-grid">
    <?php foreach ([
      'markdown' => ['Markdown Export', 'Database-first posts and pages exported as clean Markdown files with front matter.', 'Ownership format'],
      'static' => ['Static Site Export', 'A portable HTML copy with feeds, assets, and media. It does not replace dynamic rendering on the live site.', 'Portable site'],
      'media' => ['Media Library', 'Uploaded public media files from the site media directory.', 'Public files'],
    ] as $key => $copy): ?>
      <form method="post" class="operations-action-card">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="export_kind" value="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>">
        <span class="operation-risk-label is-safe"><?= htmlspecialchars($copy[2], ENT_QUOTES, 'UTF-8') ?></span>
        <h3><?= htmlspecialchars($copy[0], ENT_QUOTES, 'UTF-8') ?></h3>
        <p><?= htmlspecialchars($copy[1], ENT_QUOTES, 'UTF-8') ?></p>
        <div class="operations-form-actions"><button type="submit" <?= $zipReady ? '' : 'disabled' ?>>Export <?= htmlspecialchars($copy[0], ENT_QUOTES, 'UTF-8') ?></button></div>
      </form>
    <?php endforeach; ?>
  </div>
</section>

<section class="panel operations-danger-zone">
  <div class="operations-section-heading"><div><p class="eyebrow">Private backups</p><h2>Packages that contain sensitive system data</h2><p class="meta">Store these files securely. Do not upload them to a public repository, public media folder, shared drive, or support ticket without reviewing their contents.</p></div><span class="operation-risk-label is-destructive">Sensitive data</span></div>
  <div class="operations-card-grid">
    <?php foreach ([
      'database' => ['Database Backup', 'All Bonumark Stream database tables as SQL inserts. This can contain password hashes, email addresses, account metadata, API token hashes, invites, reset records, and security logs.'],
      'full' => ['Full Bonumark Stream Package', 'Markdown, static output, media, and the complete private database export in one ZIP. This is the most sensitive export.'],
    ] as $key => $copy): ?>
      <form method="post" class="operations-action-card is-destructive">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="export_kind" value="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>">
        <span class="operation-risk-label is-destructive">Private backup</span>
        <h3><?= htmlspecialchars($copy[0], ENT_QUOTES, 'UTF-8') ?></h3>
        <p><?= htmlspecialchars($copy[1], ENT_QUOTES, 'UTF-8') ?></p>
        <div class="operations-form-actions"><button type="submit" class="danger-button" <?= $zipReady ? '' : 'disabled' ?>>Export <?= htmlspecialchars($copy[0], ENT_QUOTES, 'UTF-8') ?></button></div>
      </form>
    <?php endforeach; ?>
  </div>
</section>
<?php bms_admin_footer(); ?>

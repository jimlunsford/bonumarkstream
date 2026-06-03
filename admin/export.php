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
        ];
        return array_map('bms_table', $fallback);
    }
}

function bms_export_database_sql(): string
{
    $tables = bms_export_database_tables();
    $sql = "-- Bonumark Stream database export\n-- Generated: " . date('c') . "\n-- Warning: this private backup may contain password hashes, email addresses, session-adjacent security logs, and account data.\n\n";
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
?>
<section class="panel page-intro-panel">
  <p class="eyebrow">Ownership tools</p>
  <h2>Your work goes in clean. Your work comes out clean.</h2>
  <p class="meta">Export Markdown from database content, optional static site output, media, database records, or a full Bonumark Stream package for backup and portability.</p>
  <p class="notice warning"><strong>Private backup warning:</strong> Database and full exports may contain password hashes, email addresses, account metadata, security logs, invites, reset tokens, and other sensitive records. Do not publish or share these ZIP files.</p>
</section>
<section class="dashboard-actions-grid">
  <?php foreach ([
    'markdown' => ['Markdown Export', 'Database-first posts and pages exported as clean Markdown files with front matter.'],
    'static' => ['Static Site Export', 'Generate and download a portable HTML copy, feeds, assets, and media without writing generated HTML into the live public root.'],
    'media' => ['Media Library', 'Uploaded public media files.'],
    'database' => ['Database Backup', 'All Bonumark Stream database tables as SQL inserts. This private backup may contain password hashes and account data.'],
    'full' => ['Full Bonumark Stream Package', 'Markdown export, static site output, media, and complete database export in one private ZIP.'],
  ] as $key => $copy): ?>
    <form method="post" class="action-card">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(bms_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="export_kind" value="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>">
      <h3><?= htmlspecialchars($copy[0], ENT_QUOTES, 'UTF-8') ?></h3>
      <p><?= htmlspecialchars($copy[1], ENT_QUOTES, 'UTF-8') ?></p>
      <button type="submit">Export <?= htmlspecialchars($copy[0], ENT_QUOTES, 'UTF-8') ?></button>
    </form>
  <?php endforeach; ?>
</section>
<?php bms_admin_footer(); ?>

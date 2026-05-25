<?php
require_once __DIR__ . '/functions.php';

function mp_table_prefix(): string
{
    $config = mp_config();
    $prefix = (string)($config['database']['prefix'] ?? 'bms_');
    $prefix = preg_replace('/[^A-Za-z0-9_]/', '', $prefix) ?? 'bms_';
    return $prefix !== '' ? $prefix : 'bms_';
}

function mp_table(string $name): string
{
    $name = preg_replace('/[^A-Za-z0-9_]/', '', $name) ?? '';
    return mp_table_prefix() . $name;
}

function mp_db_config(): array
{
    $config = mp_config();
    $db = $config['database'] ?? [];
    return is_array($db) ? $db : [];
}

function mp_has_database_config(): bool
{
    $db = mp_db_config();
    return !empty($db['name']) && !empty($db['user']) && array_key_exists('password', $db) && !empty($db['host']);
}

function mp_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $db = mp_db_config();
    if (!mp_has_database_config()) {
        throw new RuntimeException('Bonumark Stream is not connected to a database yet. Run the installer.');
    }

    $host = (string)($db['host'] ?? 'localhost');
    $name = (string)($db['name'] ?? '');
    $user = (string)($db['user'] ?? '');
    $pass = (string)($db['password'] ?? '');
    $charset = (string)($db['charset'] ?? 'utf8mb4');

    $dsn = "mysql:host={$host};dbname={$name};charset={$charset}";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

function mp_db_test_connection(array $db): void
{
    $host = (string)($db['host'] ?? 'localhost');
    $name = (string)($db['name'] ?? '');
    $user = (string)($db['user'] ?? '');
    $pass = (string)($db['password'] ?? '');
    $charset = (string)($db['charset'] ?? 'utf8mb4');

    if ($name === '' || $user === '' || $host === '') {
        throw new RuntimeException('Database host, name, and username are required.');
    }

    $dsn = "mysql:host={$host};dbname={$name};charset={$charset}";
    new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function mp_db_supports_mysql(): bool
{
    return extension_loaded('pdo') && in_array('mysql', PDO::getAvailableDrivers(), true);
}

function mp_setting(string $key, mixed $default = null): mixed
{
    static $cache = [];
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    if (!mp_is_installed() || !mp_has_database_config()) {
        $config = mp_config();
        return $config[$key] ?? $default;
    }

    try {
        $stmt = mp_db()->prepare('SELECT setting_value FROM ' . mp_table('settings') . ' WHERE setting_key = :setting_key LIMIT 1');
        $stmt->execute(['setting_key' => $key]);
        $value = $stmt->fetchColumn();
        if ($value === false) {
            $cache[$key] = $default;
            return $default;
        }
        $cache[$key] = $value;
        return $value;
    } catch (Throwable $e) {
        $config = mp_config();
        return $config[$key] ?? $default;
    }
}

function mp_set_setting(string $key, string $value): void
{
    $sql = 'INSERT INTO ' . mp_table('settings') . ' (setting_key, setting_value, updated_at) VALUES (:setting_key, :setting_value, NOW()) '
        . 'ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()';
    $stmt = mp_db()->prepare($sql);
    $stmt->execute(['setting_key' => $key, 'setting_value' => $value]);
}

function mp_migration_error_is_idempotent(Throwable $e, string $statement): bool
{
    $message = strtolower($e->getMessage());
    $statement = strtolower($statement);

    $safeMessages = [
        'duplicate column name',
        'duplicate key name',
        'check that column/key exists',
        'already exists',
    ];
    foreach ($safeMessages as $needle) {
        if (str_contains($message, $needle)) {
            return true;
        }
    }

    if (str_contains($statement, 'drop index') && (str_contains($message, 'check that column/key exists') || str_contains($message, "can't drop"))) {
        return true;
    }

    return false;
}

function mp_exec_migration_statement(PDO $pdo, string $statement, string $prefix): void
{
    $sql = str_replace('{{prefix}}', $prefix, $statement);
    if (preg_match('/{{[A-Za-z0-9_]+}}/', $sql, $matches)) {
        throw new RuntimeException('Migration contains an unsupported table placeholder: ' . $matches[0]);
    }
    try {
        $pdo->exec($sql);
    } catch (Throwable $e) {
        if (mp_migration_error_is_idempotent($e, $sql)) {
            return;
        }
        throw $e;
    }
}


function mp_install_schema(PDO $pdo, string $prefix): void
{
    $prefix = preg_replace('/[^A-Za-z0-9_]/', '', $prefix) ?: 'bms_';
    $files = glob(__DIR__ . '/../migrations/*.php') ?: [];
    sort($files);

    foreach ($files as $file) {
        $name = basename($file, '.php');
        $sql = require $file;
        if (!is_array($sql)) {
            throw new RuntimeException('Migration did not return a statement list: ' . $name);
        }
        foreach ($sql as $statement) {
            mp_exec_migration_statement($pdo, (string)$statement, $prefix);
        }
        if ($name !== '0001_initial_schema') {
            $stmt = $pdo->prepare('INSERT IGNORE INTO `' . $prefix . 'migrations` (migration, ran_at) VALUES (:migration, NOW())');
            $stmt->execute(['migration' => $name]);
        }
    }

    $stmt = $pdo->prepare('INSERT IGNORE INTO `' . $prefix . 'migrations` (migration, ran_at) VALUES (:migration, NOW())');
    $stmt->execute(['migration' => '0001_initial_schema']);
}

function mp_run_migrations(): array
{
    if (!mp_has_database_config()) {
        return [];
    }

    $lockPath = mp_root_path('tmp/migration.lock');
    $lockDir = dirname($lockPath);
    if (!is_dir($lockDir)) {
        mkdir($lockDir, 0755, true);
    }
    $lockHandle = fopen($lockPath, 'c');
    if (!$lockHandle) {
        throw new RuntimeException('Could not create migration lock file.');
    }
    if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
        fclose($lockHandle);
        throw new RuntimeException('Another migration or upgrade appears to be running. Try again in a moment.');
    }

    try {
        $pdo = mp_db();
        $prefix = mp_table_prefix();
        $migrationTable = $prefix . 'migrations';
        $pdo->exec('CREATE TABLE IF NOT EXISTS `' . $migrationTable . '` (migration VARCHAR(120) NOT NULL PRIMARY KEY, ran_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $doneStmt = $pdo->query('SELECT migration FROM `' . $migrationTable . '`');
        $done = [];
        foreach ($doneStmt->fetchAll() as $row) {
            $done[(string)$row['migration']] = true;
        }

        $ran = [];
        $files = glob(mp_root_path('migrations/*.php')) ?: [];
        sort($files);
        foreach ($files as $file) {
            $name = basename($file, '.php');
            if (isset($done[$name])) {
                continue;
            }

            $statements = require $file;
            if (!is_array($statements)) {
                throw new RuntimeException('Migration did not return a statement list: ' . $name);
            }

            try {
                if (!$pdo->inTransaction()) {
                    $pdo->beginTransaction();
                }
                foreach ($statements as $index => $statement) {
                    if (!is_int($index) || !is_string($statement) || trim($statement) === '') {
                        throw new RuntimeException('Migration must return a numeric list of SQL statement strings: ' . $name);
                    }
                    mp_exec_migration_statement($pdo, $statement, $prefix);
                }
                $stmt = $pdo->prepare('INSERT IGNORE INTO `' . $migrationTable . '` (migration, ran_at) VALUES (:migration, NOW())');
                $stmt->execute(['migration' => $name]);
                if ($pdo->inTransaction()) {
                    $pdo->commit();
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
            $ran[] = $name;
        }
        if ($ran && function_exists('mp_import_legacy_markdown_content_to_database')) {
            try {
                mp_import_legacy_markdown_content_to_database();
            } catch (Throwable $e) {
                // The legacy Markdown import is best-effort. Old files remain available for manual import review.
            }
        }
        return $ran;
    } finally {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}

function mp_db_insert_initial_data(array $site, array $admin): void
{
    $pdo = mp_db();
    $settings = [
        'site_name' => $site['site_name'] ?? 'Bonumark Stream',
        'site_tagline' => $site['site_tagline'] ?? 'A self-hosted microblog stream for owning short-form publishing.',
        'author_name' => $admin['display_name'] ?? 'Admin',
        'timezone' => $site['timezone'] ?? 'UTC',
        'base_path' => $site['base_path'] ?? '',
        'base_url' => $site['base_url'] ?? '',
        'public_path' => $site['public_path'] ?? '',
        'site_admin_email' => $admin['email'] ?? '',
        'default_editor_mode' => 'visual',
        'default_content_status' => 'draft',
        'user_publish_mode' => 'draft_review',
        'media_limit_administrator_mb' => '32',
        'media_limit_user_mb' => '8',
        'media_limit_commenter_mb' => '2',
        'homepage_mode' => 'stream',
        'active_public_theme' => 'default',
        'stream_composer_enabled' => '1',
        'stream_posts_per_page' => '20',
        'stream_show_dates' => '1',
        'stream_show_edit_links' => '0',
        'stream_index_policy' => 'smart',
        'sitemap_enabled' => '1',
        'sitemap_include_stream_posts' => '1',
        'sitemap_include_pages' => '1',
        'sitemap_include_profiles' => '0',
        'comments_enabled' => '1',
        'comment_registration_enabled' => '0',
        'comments_default_status' => 'approved',
        'registration_mode' => 'disabled',
        'registration_default_role' => 'commenter',
        'registration_require_email_verification' => '1',
        'registration_require_admin_approval' => '0',
        'registration_user_role_requires_approval' => '1',
        'registration_honeypot_enabled' => '1',
        'homepage_eyebrow' => 'Own your short-form publishing',
        'site_footer_text' => '',
        'show_powered_by' => '1',
        'primary_navigation_enabled' => '0',
        'public_navigation_account_links_enabled' => '1',
        'primary_navigation' => json_encode([
            ['label' => 'Home', 'url' => '/', 'target' => '_self'],
        ], JSON_UNESCAPED_SLASHES),
        'mail_transport' => 'disabled',
        'mail_from_name' => $site['site_name'] ?? 'Bonumark Stream',
        'mail_from_email' => $admin['email'] ?? '',
        'mail_reply_to' => '',
        'mail_smtp_host' => '',
        'mail_smtp_port' => '587',
        'mail_smtp_encryption' => 'tls',
        'mail_smtp_username' => '',
        'mail_smtp_password' => '',
        'mail_sendmail_path' => '/usr/sbin/sendmail',
        'content_storage_mode' => 'database',
        'fresh_install_baseline' => mp_version(),
        'version' => mp_version(),
    ];
    foreach ($settings as $key => $value) {
        mp_set_setting($key, (string)$value);
    }

    $stmt = $pdo->prepare('INSERT INTO ' . mp_table('users') . ' (username, display_name, email, email_verified_at, password_hash, role, status, created_at, updated_at) VALUES (:username, :display_name, :email, :email_verified_at, :password_hash, :role, :status, NOW(), NOW())');
    $stmt->execute([
        'username' => mp_normalize_username((string)$admin['username']),
        'display_name' => trim((string)$admin['display_name']),
        'email' => trim((string)$admin['email']),
        'email_verified_at' => date('Y-m-d H:i:s'),
        'password_hash' => password_hash((string)$admin['password'], PASSWORD_DEFAULT),
        'role' => 'administrator',
        'status' => 'active',
    ]);
}

function mp_current_user_id(): ?int
{
    $id = $_SESSION['mp_user_id'] ?? null;
    return is_numeric($id) ? (int)$id : null;
}


function mp_database_content_enabled(): bool
{
    if (!mp_is_installed() || !mp_has_database_config()) {
        return false;
    }
    try {
        return (string)mp_setting('content_storage_mode', 'database') === 'database';
    } catch (Throwable $e) {
        return true;
    }
}

function mp_content_status_for_section(string $section): string
{
    return str_contains(trim($section, '/'), 'published') ? 'published' : 'draft';
}

function mp_content_type_for_section(string $section): string
{
    return str_starts_with(trim($section, '/'), 'pages/') ? 'page' : 'stream';
}

function mp_section_for_content(string $postType, string $status): string
{
    $postType = $postType === 'page' ? 'page' : 'stream';
    $status = $status === 'published' ? 'published' : 'draft';
    if ($postType === 'page') {
        return $status === 'published' ? 'pages/published' : 'pages/drafts';
    }
    return $status === 'published' ? 'published' : 'drafts';
}

function mp_database_content_columns_ready(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    if (!mp_is_installed() || !mp_has_database_config()) {
        $ready = false;
        return false;
    }
    try {
        $stmt = mp_db()->query('SHOW COLUMNS FROM ' . mp_table('posts') . " LIKE 'content_body'");
        $ready = (bool)$stmt->fetch();
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

function mp_content_front_matter_for_database(array $page): array
{
    $frontMatter = is_array($page['front_matter'] ?? null) ? $page['front_matter'] : [];
    foreach (['seo_title','robots','featured_media','stream_created_at','link_preview_url','link_preview_title','link_preview_description','link_preview_image','link_preview_site_name'] as $key) {
        if (array_key_exists($key, $page) && !array_key_exists($key, $frontMatter)) {
            $frontMatter[$key] = $page[$key];
        }
    }
    return $frontMatter;
}

function mp_database_content_raw(array $page): string
{
    return mp_build_markdown_document([
        'title' => (string)($page['title'] ?? 'Untitled'),
        'slug' => (string)($page['slug'] ?? ''),
        'status' => (string)($page['status'] ?? 'draft'),
        'content_type' => (string)($page['content_type'] ?? $page['post_type'] ?? 'stream'),
        'date' => (string)($page['date'] ?? date('Y-m-d')),
        'description' => (string)($page['description'] ?? ''),
        'category' => (string)($page['category'] ?? 'Stream'),
        'tags' => $page['tags'] ?? [],
        'featured_media' => (string)($page['featured_media'] ?? $page['front_matter']['featured_media'] ?? ''),
        'stream_created_at' => (string)($page['stream_created_at'] ?? $page['front_matter']['stream_created_at'] ?? ''),
        'seo_title' => (string)($page['seo_title'] ?? $page['front_matter']['seo_title'] ?? ''),
        'robots' => (string)($page['robots'] ?? $page['front_matter']['robots'] ?? ''),
        'link_preview_url' => (string)($page['link_preview_url'] ?? $page['front_matter']['link_preview_url'] ?? ''),
        'link_preview_title' => (string)($page['link_preview_title'] ?? $page['front_matter']['link_preview_title'] ?? ''),
        'link_preview_description' => (string)($page['link_preview_description'] ?? $page['front_matter']['link_preview_description'] ?? ''),
        'link_preview_image' => (string)($page['link_preview_image'] ?? $page['front_matter']['link_preview_image'] ?? ''),
        'link_preview_site_name' => (string)($page['link_preview_site_name'] ?? $page['front_matter']['link_preview_site_name'] ?? ''),
    ], (string)($page['body'] ?? ''));
}

function mp_database_row_to_content_page(array $row): array
{
    $frontMatter = [];
    $encoded = (string)($row['content_front_matter'] ?? '');
    if ($encoded !== '') {
        $decoded = json_decode($encoded, true);
        if (is_array($decoded)) {
            $frontMatter = $decoded;
        }
    }

    $postType = (string)($row['post_type'] ?? 'stream') === 'page' ? 'page' : 'stream';
    $status = (string)($row['status'] ?? 'draft') === 'published' ? 'published' : 'draft';
    $section = mp_section_for_content($postType, $status);
    $slug = mp_slugify((string)($row['slug'] ?? ''));
    $filename = basename((string)($row['markdown_path'] ?? ''));
    if ($filename === '' || $filename === '.') {
        $filename = ($slug !== '' ? $slug : 'content-' . (int)($row['id'] ?? 0)) . '.md';
    }
    $body = (string)($row['content_body'] ?? '');
    $path = trim((string)($row['markdown_path'] ?? ''));
    if ($body === '' && $path !== '') {
        $absolute = mp_root_path($path);
        if (is_file($absolute)) {
            try {
                $legacy = mp_parse_markdown_file($absolute);
                $body = (string)($legacy['body'] ?? '');
                $frontMatter = $frontMatter ?: (is_array($legacy['front_matter'] ?? null) ? $legacy['front_matter'] : []);
            } catch (Throwable $e) {
                $body = '';
            }
        }
    }

    $date = (string)($row['date_published'] ?? '');
    if ($date === '' || $date === '0000-00-00') {
        $date = substr((string)($row['published_at'] ?? $row['created_at'] ?? date('Y-m-d')), 0, 10);
    }
    $page = [
        'post_id' => (int)($row['id'] ?? 0),
        'author_id' => isset($row['author_id']) ? (int)$row['author_id'] : 0,
        'title' => (string)($row['title'] ?? ($postType === 'page' ? 'Untitled Page' : 'Untitled')),
        'slug' => $slug,
        'status' => $status,
        'content_type' => $postType,
        'post_type' => $postType,
        'description' => (string)($row['description'] ?? ''),
        'category' => (string)($row['category'] ?? ($postType === 'page' ? 'Page' : 'Stream')),
        'category_slug' => (string)($row['category_slug'] ?? ($postType === 'page' ? 'page' : 'stream')),
        'date' => $date,
        'created_at' => (string)($row['created_at'] ?? ''),
        'updated_at' => (string)($row['updated_at'] ?? ''),
        'published_at' => (string)($row['published_at'] ?? ''),
        'date_published' => (string)($row['date_published'] ?? ''),
        'body' => $body,
        'front_matter' => $frontMatter,
        'featured_media' => (string)($frontMatter['featured_media'] ?? ''),
        'stream_created_at' => (string)($frontMatter['stream_created_at'] ?? ($row['published_at'] ?? $row['created_at'] ?? '')),
        'seo_title' => (string)($frontMatter['seo_title'] ?? ''),
        'robots' => (string)($frontMatter['robots'] ?? ''),
        'link_preview_url' => (string)($frontMatter['link_preview_url'] ?? ''),
        'link_preview_title' => (string)($frontMatter['link_preview_title'] ?? ''),
        'link_preview_description' => (string)($frontMatter['link_preview_description'] ?? ''),
        'link_preview_image' => (string)($frontMatter['link_preview_image'] ?? ''),
        'link_preview_site_name' => (string)($frontMatter['link_preview_site_name'] ?? ''),
        'filename' => $filename,
        'path' => $path !== '' ? mp_root_path($path) : '',
        'markdown_path' => $path,
        'section' => $section,
        'content_status' => $status,
        'review_status' => (string)($row['review_status'] ?? ''),
        'submitted_at' => (string)($row['submitted_at'] ?? ''),
        'content_storage' => 'database',
    ];
    $page['raw'] = mp_database_content_raw($page);
    return $page;
}

function mp_database_slug_exists(string $slug, string $currentSlug = '', string $postType = ''): bool
{
    if (!mp_database_content_enabled() || !mp_database_content_columns_ready()) {
        return false;
    }
    $slug = mp_slugify($slug);
    $currentSlug = mp_slugify($currentSlug);
    if ($slug === '' || ($currentSlug !== '' && $slug === $currentSlug)) {
        return false;
    }
    try {
        $sql = 'SELECT COUNT(*) FROM ' . mp_table('posts') . ' WHERE slug = :slug';
        $params = ['slug' => $slug];
        if ($postType === 'stream' || $postType === 'page') {
            $sql .= ' AND post_type = :post_type';
            $params['post_type'] = $postType;
        }
        $stmt = mp_db()->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function mp_find_database_content_by_slug_status(string $slug, string $status = 'published', string $postType = 'stream'): ?array
{
    if (!mp_database_content_enabled() || !mp_database_content_columns_ready()) {
        return null;
    }
    try {
        $stmt = mp_db()->prepare('SELECT * FROM ' . mp_table('posts') . ' WHERE slug = :slug AND status = :status AND post_type = :post_type LIMIT 1');
        $stmt->execute(['slug' => mp_slugify($slug), 'status' => $status === 'published' ? 'published' : 'draft', 'post_type' => $postType === 'page' ? 'page' : 'stream']);
        $row = $stmt->fetch();
        return is_array($row) ? mp_database_row_to_content_page($row) : null;
    } catch (Throwable $e) {
        return null;
    }
}

function mp_find_database_content_by_markdown_path(string $section, string $filename): ?array
{
    if (!mp_database_content_enabled() || !mp_database_content_columns_ready()) {
        return null;
    }
    $section = trim(str_replace('\\', '/', $section), '/');
    $status = mp_content_status_for_section($section);
    $postType = mp_content_type_for_section($section);
    $path = 'content/' . $section . '/' . basename($filename);
    $slug = mp_slugify(pathinfo(basename($filename), PATHINFO_FILENAME));
    try {
        $stmt = mp_db()->prepare('SELECT * FROM ' . mp_table('posts') . ' WHERE ((markdown_path = :markdown_path) OR (slug = :slug)) AND status = :status AND post_type = :post_type LIMIT 1');
        $stmt->execute(['markdown_path' => $path, 'slug' => $slug, 'status' => $status, 'post_type' => $postType]);
        $row = $stmt->fetch();
        return is_array($row) ? mp_database_row_to_content_page($row) : null;
    } catch (Throwable $e) {
        return null;
    }
}

function mp_list_database_content_for_section(string $section): array
{
    if (!mp_database_content_enabled() || !mp_database_content_columns_ready()) {
        return [];
    }
    $section = trim(str_replace('\\', '/', $section), '/');
    $status = mp_content_status_for_section($section);
    $postType = mp_content_type_for_section($section);
    try {
        $stmt = mp_db()->prepare('SELECT * FROM ' . mp_table('posts') . ' WHERE status = :status AND post_type = :post_type ORDER BY COALESCE(published_at, created_at) DESC, id DESC');
        $stmt->execute(['status' => $status, 'post_type' => $postType]);
        $rows = $stmt->fetchAll() ?: [];
        return array_map('mp_database_row_to_content_page', $rows);
    } catch (Throwable $e) {
        return [];
    }
}

function mp_database_content_record_from_page(array $page, string $section, string $filename, ?int $authorId = null): array
{
    $postType = mp_content_type_for_section($section);
    $status = mp_content_status_for_section($section);
    $sectionPath = trim($section, '/');
    $markdownPath = 'content/' . $sectionPath . '/' . basename($filename);
    $htmlPath = null;
    if ($status === 'published') {
        $htmlPath = $postType === 'page'
            ? trim(mp_page_relative_directory_for_page($page), '/') . '/index.html'
            : trim(mp_stream_relative_directory_for_post($page), '/') . '/index.html';
    }
    $frontMatter = mp_content_front_matter_for_database($page);
    $raw = (string)($page['raw'] ?? mp_database_content_raw($page));
    $contentHash = hash('sha256', (string)($page['body'] ?? '') . "\n" . json_encode($frontMatter, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    return [
        'author_id' => $authorId,
        'title' => (string)($page['title'] ?? ($postType === 'page' ? 'Untitled Page' : 'Untitled')),
        'slug' => (string)($page['slug'] ?? pathinfo($filename, PATHINFO_FILENAME)),
        'status' => $status,
        'post_type' => $postType,
        'description' => (string)($page['description'] ?? ''),
        'content_body' => (string)($page['body'] ?? ''),
        'content_front_matter' => json_encode($frontMatter, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'content_source' => 'database',
        'storage_mode' => 'database',
        'category' => $postType === 'page' ? 'Page' : (string)($page['category'] ?? 'Stream'),
        'category_slug' => $postType === 'page' ? 'page' : (string)($page['category_slug'] ?? mp_term_slug((string)($page['category'] ?? 'Stream'))),
        'markdown_path' => $markdownPath,
        'html_path' => $htmlPath,
        'date_published' => (string)($page['date'] ?? date('Y-m-d')),
        'content_hash' => $contentHash,
        'raw' => $raw,
    ];
}

function mp_upsert_database_content(array $page, string $section, string $filename, ?int $authorId = null): int
{
    if (!mp_is_installed() || !mp_database_content_columns_ready()) {
        return 0;
    }
    $record = mp_database_content_record_from_page($page, $section, $filename, $authorId);
    $pdo = mp_db();
    $existing = null;
    try {
        $stmt = $pdo->prepare('SELECT * FROM ' . mp_table('posts') . ' WHERE slug = :slug AND status = :status AND post_type = :post_type LIMIT 1');
        $stmt->execute(['slug' => mp_slugify((string)$record['slug']), 'status' => $record['status'], 'post_type' => $record['post_type']]);
        $row = $stmt->fetch();
        $existing = is_array($row) ? $row : null;
    } catch (Throwable $e) {
        $existing = null;
    }

    if ($authorId === null && $existing) {
        $existingAuthor = (int)($existing['author_id'] ?? 0);
        $record['author_id'] = $existingAuthor > 0 ? $existingAuthor : null;
    } elseif ($record['author_id'] === null && !$existing) {
        $record['author_id'] = mp_current_user_id();
    }

    if ($existing) {
        $stmt = $pdo->prepare('UPDATE ' . mp_table('posts') . ' SET author_id = COALESCE(:author_id, author_id), title = :title, slug = :slug, status = :status, post_type = :post_type, description = :description, content_body = :content_body, content_front_matter = :content_front_matter, content_source = :content_source, storage_mode = :storage_mode, category = :category, category_slug = :category_slug, markdown_path = :markdown_path, html_path = :html_path, date_published = :date_published, content_hash = :content_hash, updated_at = NOW(), published_at = CASE WHEN :published_status = \'published\' THEN COALESCE(published_at, NOW()) ELSE published_at END WHERE id = :id');
        $stmt->execute([
            'author_id' => $record['author_id'], 'title' => $record['title'], 'slug' => mp_slugify((string)$record['slug']), 'status' => $record['status'], 'post_type' => $record['post_type'], 'description' => $record['description'],
            'content_body' => $record['content_body'], 'content_front_matter' => $record['content_front_matter'], 'content_source' => $record['content_source'], 'storage_mode' => $record['storage_mode'],
            'category' => $record['category'], 'category_slug' => $record['category_slug'], 'markdown_path' => $record['markdown_path'], 'html_path' => $record['html_path'], 'date_published' => $record['date_published'], 'content_hash' => $record['content_hash'], 'published_status' => $record['status'], 'id' => (int)$existing['id'],
        ]);
        $postId = (int)$existing['id'];
    } else {
        $stmt = $pdo->prepare('INSERT INTO ' . mp_table('posts') . ' (author_id, title, slug, status, post_type, description, content_body, content_front_matter, content_source, storage_mode, category, category_slug, markdown_path, html_path, date_published, content_hash, created_at, updated_at, published_at) VALUES (:author_id, :title, :slug, :status, :post_type, :description, :content_body, :content_front_matter, :content_source, :storage_mode, :category, :category_slug, :markdown_path, :html_path, :date_published, :content_hash, NOW(), NOW(), :published_at)');
        $stmt->execute([
            'author_id' => $record['author_id'], 'title' => $record['title'], 'slug' => mp_slugify((string)$record['slug']), 'status' => $record['status'], 'post_type' => $record['post_type'], 'description' => $record['description'],
            'content_body' => $record['content_body'], 'content_front_matter' => $record['content_front_matter'], 'content_source' => $record['content_source'], 'storage_mode' => $record['storage_mode'],
            'category' => $record['category'], 'category_slug' => $record['category_slug'], 'markdown_path' => $record['markdown_path'], 'html_path' => $record['html_path'], 'date_published' => $record['date_published'], 'content_hash' => $record['content_hash'], 'published_at' => $record['status'] === 'published' ? date('Y-m-d H:i:s') : null,
        ]);
        $postId = (int)$pdo->lastInsertId();
    }
    mp_sync_post_terms($postId, $page);
    return $postId;
}

function mp_import_legacy_markdown_content_to_database(bool $force = false): int
{
    if (!mp_database_content_enabled() || !mp_database_content_columns_ready()) {
        return 0;
    }
    if (!$force && (string)mp_setting('database_first_import_complete', '0') === '1') {
        return 0;
    }
    if (!function_exists('mp_list_legacy_markdown_files')) {
        return 0;
    }
    $count = 0;
    foreach (['drafts', 'published', 'pages/drafts', 'pages/published'] as $section) {
        foreach (mp_list_legacy_markdown_files($section) as $page) {
            $filename = (string)($page['filename'] ?? ((string)($page['slug'] ?? 'content') . '.md'));
            mp_upsert_database_content($page, $section, $filename, null);
            $count++;
        }
    }
    mp_set_setting('database_first_import_complete', '1');
    mp_set_setting('content_storage_mode', 'database');
    return $count;
}


function mp_database_content_filename_for_page(array $page): string
{
    $slug = mp_slugify((string)($page['slug'] ?? ''));
    if ($slug === '') {
        $slug = mp_slugify((string)($page['title'] ?? 'content')) ?: 'content-' . date('Ymd-His');
    }
    return $slug . '.md';
}

function mp_database_content_page_for_status(array $page, string $status, string $postType = 'stream'): array
{
    $status = $status === 'published' ? 'published' : 'draft';
    $postType = $postType === 'page' ? 'page' : 'stream';
    $frontMatter = is_array($page['front_matter'] ?? null) ? $page['front_matter'] : [];
    $raw = mp_build_markdown_document([
        'title' => (string)($page['title'] ?? ($postType === 'page' ? 'Untitled Page' : 'Untitled')),
        'slug' => (string)($page['slug'] ?? ''),
        'status' => $status,
        'content_type' => $postType,
        'date' => (string)($page['date'] ?? date('Y-m-d')),
        'description' => (string)($page['description'] ?? ''),
        'category' => $postType === 'page' ? 'Page' : (string)($page['category'] ?? 'Stream'),
        'tags' => $page['tags'] ?? [],
        'featured_media' => (string)($page['featured_media'] ?? $frontMatter['featured_media'] ?? ''),
        'stream_created_at' => (string)($page['stream_created_at'] ?? $frontMatter['stream_created_at'] ?? ''),
        'seo_title' => (string)($page['seo_title'] ?? $frontMatter['seo_title'] ?? ''),
        'robots' => (string)($page['robots'] ?? $frontMatter['robots'] ?? ''),
        'link_preview_url' => (string)($page['link_preview_url'] ?? $frontMatter['link_preview_url'] ?? ''),
        'link_preview_title' => (string)($page['link_preview_title'] ?? $frontMatter['link_preview_title'] ?? ''),
        'link_preview_description' => (string)($page['link_preview_description'] ?? $frontMatter['link_preview_description'] ?? ''),
        'link_preview_image' => (string)($page['link_preview_image'] ?? $frontMatter['link_preview_image'] ?? ''),
        'link_preview_site_name' => (string)($page['link_preview_site_name'] ?? $frontMatter['link_preview_site_name'] ?? ''),
    ], (string)($page['body'] ?? ''));
    $updated = mp_parse_markdown_string($raw);
    $updated['content_type'] = $postType;
    $updated['post_type'] = $postType;
    $updated['status'] = $status;
    $updated['content_status'] = $status;
    $updated['raw'] = $raw;
    if (isset($page['author_id'])) {
        $updated['author_id'] = (int)$page['author_id'];
    }
    return $updated;
}

function mp_delete_database_content_record(string $section, string $filename): void
{
    if (!mp_is_installed()) {
        return;
    }
    mp_delete_post_metadata_by_filename($section, $filename);
}

function mp_revision_page_from_row(array $revision): array
{
    $frontMatter = [];
    $encoded = (string)($revision['content_front_matter'] ?? '');
    if ($encoded !== '') {
        $decoded = json_decode($encoded, true);
        if (is_array($decoded)) {
            $frontMatter = $decoded;
        }
    }
    $body = (string)($revision['content_body'] ?? '');
    if ($body === '') {
        $path = mp_root_path((string)($revision['markdown_path'] ?? ''));
        if (is_file($path)) {
            return mp_parse_markdown_file($path);
        }
    }
    return [
        'title' => (string)($revision['title'] ?? 'Restored Revision'),
        'slug' => (string)($revision['slug'] ?? ''),
        'status' => (string)($revision['status'] ?? 'draft'),
        'content_type' => 'stream',
        'post_type' => 'stream',
        'date' => substr((string)($revision['created_at'] ?? date('Y-m-d')), 0, 10),
        'description' => (string)($frontMatter['description'] ?? ''),
        'category' => 'Stream',
        'tags' => [],
        'featured_media' => (string)($frontMatter['featured_media'] ?? ''),
        'stream_created_at' => (string)($frontMatter['stream_created_at'] ?? ''),
        'seo_title' => (string)($frontMatter['seo_title'] ?? ''),
        'robots' => (string)($frontMatter['robots'] ?? ''),
        'body' => $body,
        'front_matter' => $frontMatter,
    ];
}

function mp_record_revision_from_page(array $page, string $status = 'published', string $originalFilename = '', ?int $authorId = null): void
{
    if (!mp_is_installed()) {
        return;
    }
    $status = in_array($status, ['draft', 'published'], true) ? $status : 'published';
    $slug = mp_slugify((string)($page['slug'] ?? ''));
    if ($slug === '') {
        return;
    }
    $post = mp_find_post_by_slug_status($slug, $status) ?: mp_find_post_by_slug_status($slug, 'published') ?: mp_find_post_by_slug_status($slug, 'draft');
    $frontMatter = mp_content_front_matter_for_database($page);
    $frontMatterJson = json_encode($frontMatter, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $body = (string)($page['body'] ?? '');
    $hash = hash('sha256', $body . "\n" . ($frontMatterJson ?: '{}'));
    $virtualPath = 'content/revisions/' . $slug . '/' . date('Ymd-His') . '-' . $status . '.md';
    try {
        $stmt = mp_db()->prepare('INSERT INTO ' . mp_table('revisions') . ' (post_id, slug, title, status, original_filename, markdown_path, content_body, content_front_matter, content_source, content_hash, author_id, created_at) VALUES (:post_id, :slug, :title, :status, :original_filename, :markdown_path, :content_body, :content_front_matter, :content_source, :content_hash, :author_id, NOW())');
        $stmt->execute([
            'post_id' => $post['id'] ?? null,
            'slug' => $slug,
            'title' => (string)($page['title'] ?? 'Untitled'),
            'status' => $status,
            'original_filename' => basename($originalFilename),
            'markdown_path' => $virtualPath,
            'content_body' => $body,
            'content_front_matter' => $frontMatterJson ?: '{}',
            'content_source' => 'database',
            'content_hash' => $hash,
            'author_id' => $authorId ?? mp_current_user_id(),
        ]);
    } catch (Throwable $e) {
        try {
            $stmt = mp_db()->prepare('INSERT INTO ' . mp_table('revisions') . ' (post_id, slug, title, status, original_filename, markdown_path, content_hash, author_id, created_at) VALUES (:post_id, :slug, :title, :status, :original_filename, :markdown_path, :content_hash, :author_id, NOW())');
            $stmt->execute([
                'post_id' => $post['id'] ?? null,
                'slug' => $slug,
                'title' => (string)($page['title'] ?? 'Untitled'),
                'status' => $status,
                'original_filename' => basename($originalFilename),
                'markdown_path' => $virtualPath,
                'content_hash' => $hash,
                'author_id' => $authorId ?? mp_current_user_id(),
            ]);
        } catch (Throwable $ignored) {
            // Revision recording should never block a content save.
        }
    }
}

function mp_find_post_by_slug_status(string $slug, string $status, string $postType = 'stream'): ?array
{
    if (!mp_is_installed()) {
        return null;
    }
    $postType = $postType === 'page' ? 'page' : 'stream';
    $stmt = mp_db()->prepare('SELECT * FROM ' . mp_table('posts') . ' WHERE slug = :slug AND status = :status AND post_type = :post_type LIMIT 1');
    $stmt->execute(['slug' => mp_slugify($slug), 'status' => $status, 'post_type' => $postType]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function mp_sync_stream_metadata(array $page, string $section, string $filename, ?int $authorId = null): void
{
    if (!mp_is_installed()) {
        return;
    }

    if (function_exists('mp_upsert_database_content') && mp_database_content_columns_ready()) {
        mp_upsert_database_content($page, $section, $filename, $authorId);
        return;
    }

    $status = $section === 'published' ? 'published' : 'draft';
    $markdownPath = 'content/' . $section . '/' . basename($filename);
    $htmlPath = $status === 'published' ? trim(mp_stream_relative_directory_for_post($page), '/') . '/index.html' : null;
    $contentHash = hash('sha256', (string)($page['raw'] ?? ''));

    $pdo = mp_db();
    $existing = mp_find_post_by_slug_status((string)$page['slug'], $status);

    if ($authorId === null && $existing) {
        $existingAuthorId = (int)($existing['author_id'] ?? 0);
        $authorId = $existingAuthorId > 0 ? $existingAuthorId : null;
    }

    if ($authorId === null) {
        $pathAuthorId = mp_content_author_id_for_file($section, $filename);
        $authorId = $pathAuthorId !== null ? $pathAuthorId : null;
    }

    if ($authorId === null && !$existing) {
        $authorId = mp_current_user_id();
    }

    if ($existing) {
        $stmt = $pdo->prepare('UPDATE ' . mp_table('posts') . ' SET author_id = COALESCE(:author_id, author_id), title = :title, post_type = :post_type, description = :description, category = :category, category_slug = :category_slug, markdown_path = :markdown_path, html_path = :html_path, date_published = :date_published, content_hash = :content_hash, updated_at = NOW(), published_at = CASE WHEN :published_status = \'published\' THEN COALESCE(published_at, NOW()) ELSE published_at END WHERE id = :id');
        $stmt->execute([
            'author_id' => $authorId,
            'title' => (string)$page['title'],
            'post_type' => 'stream',
            'description' => (string)($page['description'] ?? ''),
            'category' => (string)($page['category'] ?? 'Uncategorized'),
            'category_slug' => (string)($page['category_slug'] ?? mp_term_slug((string)($page['category'] ?? 'Uncategorized'))),
            'markdown_path' => $markdownPath,
            'html_path' => $htmlPath,
            'date_published' => (string)($page['date'] ?? date('Y-m-d')),
            'content_hash' => $contentHash,
            'published_status' => $status,
            'id' => (int)$existing['id'],
        ]);
        $postId = (int)$existing['id'];
    } else {
        $stmt = $pdo->prepare('INSERT INTO ' . mp_table('posts') . ' (author_id, title, slug, status, post_type, description, category, category_slug, markdown_path, html_path, date_published, content_hash, created_at, updated_at, published_at) VALUES (:author_id, :title, :slug, :status, :post_type, :description, :category, :category_slug, :markdown_path, :html_path, :date_published, :content_hash, NOW(), NOW(), :published_at)');
        $stmt->execute([
            'author_id' => $authorId,
            'title' => (string)$page['title'],
            'slug' => (string)$page['slug'],
            'status' => $status,
            'post_type' => 'stream',
            'description' => (string)($page['description'] ?? ''),
            'category' => (string)($page['category'] ?? 'Uncategorized'),
            'category_slug' => (string)($page['category_slug'] ?? mp_term_slug((string)($page['category'] ?? 'Uncategorized'))),
            'markdown_path' => $markdownPath,
            'html_path' => $htmlPath,
            'date_published' => (string)($page['date'] ?? date('Y-m-d')),
            'content_hash' => $contentHash,
            'published_at' => $status === 'published' ? date('Y-m-d H:i:s') : null,
        ]);
        $postId = (int)$pdo->lastInsertId();
    }

    mp_sync_post_terms($postId, $page);
}


function mp_sync_page_metadata(array $page, string $section, string $filename, ?int $authorId = null): void
{
    if (!mp_is_installed()) {
        return;
    }

    if (function_exists('mp_upsert_database_content') && mp_database_content_columns_ready()) {
        mp_upsert_database_content($page, $section, $filename, $authorId);
        return;
    }

    $status = str_contains($section, 'published') ? 'published' : 'draft';
    $sectionPath = $status === 'published' ? 'pages/published' : 'pages/drafts';
    $markdownPath = 'content/' . $sectionPath . '/' . basename($filename);
    $htmlPath = $status === 'published' ? trim(mp_page_relative_directory_for_page($page), '/') . '/index.html' : null;
    $contentHash = hash('sha256', (string)($page['raw'] ?? ''));

    $pdo = mp_db();
    $stmt = $pdo->prepare('SELECT * FROM ' . mp_table('posts') . ' WHERE markdown_path = :markdown_path AND status = :status AND post_type = :post_type LIMIT 1');
    $stmt->execute(['markdown_path' => $markdownPath, 'status' => $status, 'post_type' => 'page']);
    $existing = $stmt->fetch();
    $existing = is_array($existing) ? $existing : null;

    if ($authorId === null && $existing) {
        $existingAuthorId = (int)($existing['author_id'] ?? 0);
        $authorId = $existingAuthorId > 0 ? $existingAuthorId : null;
    }

    if ($authorId === null) {
        $pathAuthorId = mp_content_author_id_for_file($sectionPath, $filename);
        $authorId = $pathAuthorId !== null ? $pathAuthorId : null;
    }

    if ($authorId === null && !$existing) {
        $authorId = mp_current_user_id();
    }

    if ($existing) {
        $stmt = $pdo->prepare('UPDATE ' . mp_table('posts') . ' SET author_id = COALESCE(:author_id, author_id), title = :title, slug = :slug, post_type = :post_type, description = :description, category = :category, category_slug = :category_slug, markdown_path = :markdown_path, html_path = :html_path, date_published = :date_published, content_hash = :content_hash, updated_at = NOW(), published_at = CASE WHEN :published_status = \'published\' THEN COALESCE(published_at, NOW()) ELSE published_at END WHERE id = :id');
        $stmt->execute([
            'author_id' => $authorId,
            'title' => (string)$page['title'],
            'slug' => (string)$page['slug'],
            'post_type' => 'page',
            'description' => (string)($page['description'] ?? ''),
            'category' => 'Page',
            'category_slug' => 'page',
            'markdown_path' => $markdownPath,
            'html_path' => $htmlPath,
            'date_published' => (string)($page['date'] ?? date('Y-m-d')),
            'content_hash' => $contentHash,
            'published_status' => $status,
            'id' => (int)$existing['id'],
        ]);
        $postId = (int)$existing['id'];
    } else {
        $stmt = $pdo->prepare('INSERT INTO ' . mp_table('posts') . ' (author_id, title, slug, status, post_type, description, category, category_slug, markdown_path, html_path, date_published, content_hash, created_at, updated_at, published_at) VALUES (:author_id, :title, :slug, :status, :post_type, :description, :category, :category_slug, :markdown_path, :html_path, :date_published, :content_hash, NOW(), NOW(), :published_at)');
        $stmt->execute([
            'author_id' => $authorId,
            'title' => (string)$page['title'],
            'slug' => (string)$page['slug'],
            'status' => $status,
            'post_type' => 'page',
            'description' => (string)($page['description'] ?? ''),
            'category' => 'Page',
            'category_slug' => 'page',
            'markdown_path' => $markdownPath,
            'html_path' => $htmlPath,
            'date_published' => (string)($page['date'] ?? date('Y-m-d')),
            'content_hash' => $contentHash,
            'published_at' => $status === 'published' ? date('Y-m-d H:i:s') : null,
        ]);
        $postId = (int)$pdo->lastInsertId();
    }

    mp_sync_post_terms($postId, array_replace($page, ['category' => 'Page', 'tags' => []]));
}

function mp_sync_post_terms(int $postId, array $page): void
{
    $pdo = mp_db();
    $pdo->prepare('DELETE FROM ' . mp_table('post_terms') . ' WHERE post_id = :post_id')->execute(['post_id' => $postId]);

    $category = trim((string)($page['category'] ?? 'Uncategorized')) ?: 'Uncategorized';
    $categoryId = mp_get_or_create_term('category', $category);
    $stmt = $pdo->prepare('INSERT IGNORE INTO ' . mp_table('post_terms') . ' (post_id, term_id, is_primary) VALUES (:post_id, :term_id, 1)');
    $stmt->execute(['post_id' => $postId, 'term_id' => $categoryId]);

    foreach (($page['tags'] ?? []) as $tag) {
        $tag = trim((string)$tag);
        if ($tag === '') {
            continue;
        }
        $tagId = mp_get_or_create_term('tag', $tag);
        $stmt = $pdo->prepare('INSERT IGNORE INTO ' . mp_table('post_terms') . ' (post_id, term_id, is_primary) VALUES (:post_id, :term_id, 0)');
        $stmt->execute(['post_id' => $postId, 'term_id' => $tagId]);
    }
}

function mp_get_or_create_term(string $type, string $name): int
{
    $pdo = mp_db();
    $slug = mp_term_slug($name);
    $stmt = $pdo->prepare('SELECT id FROM ' . mp_table('terms') . ' WHERE term_type = :term_type AND slug = :slug LIMIT 1');
    $stmt->execute(['term_type' => $type, 'slug' => $slug]);
    $id = $stmt->fetchColumn();
    if ($id !== false) {
        return (int)$id;
    }

    $stmt = $pdo->prepare('INSERT INTO ' . mp_table('terms') . ' (term_type, name, slug, created_at, updated_at) VALUES (:term_type, :name, :slug, NOW(), NOW())');
    $stmt->execute(['term_type' => $type, 'name' => $name, 'slug' => $slug]);
    return (int)$pdo->lastInsertId();
}

function mp_delete_post_metadata(string $slug, string $status): void
{
    if (!mp_is_installed()) {
        return;
    }
    $post = mp_find_post_by_slug_status($slug, $status);
    if (!$post) {
        return;
    }
    $pdo = mp_db();
    $pdo->prepare('DELETE FROM ' . mp_table('post_terms') . ' WHERE post_id = :post_id')->execute(['post_id' => (int)$post['id']]);
    $pdo->prepare('DELETE FROM ' . mp_table('posts') . ' WHERE id = :id')->execute(['id' => (int)$post['id']]);
}

function mp_delete_post_metadata_by_filename(string $section, string $filename): void
{
    if (!mp_is_installed()) {
        return;
    }
    $status = str_contains($section, 'published') ? 'published' : 'draft';
    $path = 'content/' . trim($section, '/') . '/' . basename($filename);
    $stmt = mp_db()->prepare('SELECT id FROM ' . mp_table('posts') . ' WHERE markdown_path = :markdown_path AND status = :status LIMIT 1');
    $stmt->execute(['markdown_path' => $path, 'status' => $status]);
    $id = $stmt->fetchColumn();
    if ($id === false) {
        return;
    }
    mp_db()->prepare('DELETE FROM ' . mp_table('post_terms') . ' WHERE post_id = :post_id')->execute(['post_id' => (int)$id]);
    mp_db()->prepare('DELETE FROM ' . mp_table('posts') . ' WHERE id = :id')->execute(['id' => (int)$id]);
}

function mp_list_revisions(?string $slug = null, int $limit = 100): array
{
    if (!mp_is_installed()) {
        return [];
    }
    $limit = max(1, min(250, $limit));
    $sql = 'SELECT r.*, u.display_name AS author_name FROM ' . mp_table('revisions') . ' r LEFT JOIN ' . mp_table('users') . ' u ON u.id = r.author_id';
    $params = [];
    if ($slug !== null && trim($slug) !== '') {
        $sql .= ' WHERE r.slug = :slug';
        $params['slug'] = mp_slugify($slug);
    }
    $sql .= ' ORDER BY r.created_at DESC, r.id DESC LIMIT ' . $limit;
    $stmt = mp_db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll() ?: [];
}

function mp_revision_count_for_slug(string $slug): int
{
    if (!mp_is_installed()) {
        return 0;
    }
    $stmt = mp_db()->prepare('SELECT COUNT(*) FROM ' . mp_table('revisions') . ' WHERE slug = :slug');
    $stmt->execute(['slug' => mp_slugify($slug)]);
    return (int)$stmt->fetchColumn();
}

function mp_get_revision(int $id): ?array
{
    if (!mp_is_installed()) {
        return null;
    }
    $stmt = mp_db()->prepare('SELECT r.*, u.display_name AS author_name FROM ' . mp_table('revisions') . ' r LEFT JOIN ' . mp_table('users') . ' u ON u.id = r.author_id WHERE r.id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function mp_restore_revision_as_draft(int $id): array
{
    $revision = mp_get_revision($id);
    if (!$revision) {
        throw new RuntimeException('Revision not found.');
    }
    $page = mp_revision_page_from_row($revision);
    $slug = mp_slugify((string)($page['slug'] ?? $revision['slug'] ?? 'restored-revision'));
    $baseSlug = $slug !== '' ? $slug : 'restored-revision';
    $slug = $baseSlug;
    $i = 1;
    while (mp_find_database_content_by_slug_status($slug, 'draft', 'stream') || mp_find_database_content_by_slug_status($slug, 'published', 'stream')) {
        $slug = $baseSlug . '-revision-' . date('Ymd-His') . ($i > 1 ? '-' . $i : '');
        $i++;
    }

    $page['slug'] = $slug;
    $restored = mp_database_content_page_for_status($page, 'draft', 'stream');
    $filename = mp_database_content_filename_for_page($restored);
    mp_sync_stream_metadata($restored, 'drafts', $filename, mp_revision_original_author_id($revision));
    return $restored + ['filename' => $filename];
}

function mp_record_trashed_content(array $page, string $originalStatus, string $originalFilename, string $trashFilename, string $trashPath = ''): void
{
    if (!mp_is_installed()) {
        return;
    }
    $normalizedStatus = $originalStatus === 'published' ? 'published' : 'draft';
    $originalSection = $normalizedStatus === 'published' ? 'published' : 'drafts';
    $originalAuthorId = mp_content_author_id_for_file($originalSection, $originalFilename);
    if ($originalAuthorId === null && (int)($page['author_id'] ?? 0) > 0) {
        $originalAuthorId = (int)$page['author_id'];
    }
    $frontMatter = mp_content_front_matter_for_database($page);
    $frontMatterJson = json_encode($frontMatter, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $body = (string)($page['body'] ?? '');
    $hash = hash('sha256', $body . "\n" . ($frontMatterJson ?: '{}'));
    $virtualPath = trim($trashPath) !== ''
        ? str_replace(rtrim(mp_root_path(), '/\\') . '/', '', $trashPath)
        : 'content/trash/' . basename($trashFilename ?: (date('Ymd-His') . '-' . $normalizedStatus . '-' . mp_database_content_filename_for_page($page)));

    try {
        $stmt = mp_db()->prepare('INSERT INTO ' . mp_table('trash') . ' (title, slug, original_status, original_filename, trash_filename, markdown_path, post_type, content_body, content_front_matter, content_source, content_hash, original_author_id, deleted_by, deleted_at) VALUES (:title, :slug, :original_status, :original_filename, :trash_filename, :markdown_path, :post_type, :content_body, :content_front_matter, :content_source, :content_hash, :original_author_id, :deleted_by, NOW())');
        $stmt->execute([
            'title' => (string)($page['title'] ?? 'Untitled'),
            'slug' => (string)($page['slug'] ?? mp_slugify((string)($page['title'] ?? 'untitled'))),
            'original_status' => $normalizedStatus,
            'original_filename' => basename($originalFilename),
            'trash_filename' => basename($trashFilename),
            'markdown_path' => $virtualPath,
            'post_type' => 'stream',
            'content_body' => $body,
            'content_front_matter' => $frontMatterJson ?: '{}',
            'content_source' => 'database',
            'content_hash' => $hash,
            'original_author_id' => $originalAuthorId,
            'deleted_by' => mp_current_user_id(),
        ]);
    } catch (Throwable $e) {
        $stmt = mp_db()->prepare('INSERT INTO ' . mp_table('trash') . ' (title, slug, original_status, original_filename, trash_filename, markdown_path, content_hash, original_author_id, deleted_by, deleted_at) VALUES (:title, :slug, :original_status, :original_filename, :trash_filename, :markdown_path, :content_hash, :original_author_id, :deleted_by, NOW())');
        $stmt->execute([
            'title' => (string)($page['title'] ?? 'Untitled'),
            'slug' => (string)($page['slug'] ?? mp_slugify((string)($page['title'] ?? 'untitled'))),
            'original_status' => $normalizedStatus,
            'original_filename' => basename($originalFilename),
            'trash_filename' => basename($trashFilename),
            'markdown_path' => $virtualPath,
            'content_hash' => $hash,
            'original_author_id' => $originalAuthorId,
            'deleted_by' => mp_current_user_id(),
        ]);
    }
}

function mp_trash_row_to_content_page(array $row, string $postType = 'stream'): array
{
    $frontMatter = [];
    $encoded = (string)($row['content_front_matter'] ?? '');
    if ($encoded !== '') {
        $decoded = json_decode($encoded, true);
        if (is_array($decoded)) {
            $frontMatter = $decoded;
        }
    }
    $body = (string)($row['content_body'] ?? '');
    if ($body === '') {
        $path = mp_root_path((string)($row['markdown_path'] ?? ''));
        if (is_file($path)) {
            try {
                return mp_parse_markdown_file($path);
            } catch (Throwable $e) {
                $body = '';
            }
        }
    }
    $postType = $postType === 'page' ? 'page' : 'stream';
    return [
        'title' => (string)($row['title'] ?? ($postType === 'page' ? 'Untitled Page' : 'Untitled')),
        'slug' => (string)($row['slug'] ?? ''),
        'status' => (string)($row['original_status'] ?? 'draft'),
        'content_type' => $postType,
        'post_type' => $postType,
        'date' => substr((string)($row['deleted_at'] ?? date('Y-m-d')), 0, 10),
        'description' => (string)($frontMatter['description'] ?? ''),
        'category' => $postType === 'page' ? 'Page' : 'Stream',
        'tags' => [],
        'body' => $body,
        'front_matter' => $frontMatter,
        'featured_media' => (string)($frontMatter['featured_media'] ?? ''),
        'stream_created_at' => (string)($frontMatter['stream_created_at'] ?? ''),
        'seo_title' => (string)($frontMatter['seo_title'] ?? ''),
        'robots' => (string)($frontMatter['robots'] ?? ''),
    ];
}

function mp_list_trash_items(): array
{
    if (!mp_is_installed()) {
        return [];
    }
    try {
        $stmt = mp_db()->query('SELECT t.*, u.display_name AS deleted_by_name FROM ' . mp_table('trash') . ' t LEFT JOIN ' . mp_table('users') . ' u ON u.id = t.deleted_by WHERE t.original_status NOT IN (\'page_draft\', \'page_published\') ORDER BY t.deleted_at DESC, t.id DESC');
        $rows = $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }

    $items = [];
    foreach ($rows as $row) {
        $parsed = mp_trash_row_to_content_page($row, 'stream');
        $items[] = array_replace($parsed, [
            'trash_id' => (int)$row['id'],
            'title' => (string)($parsed['title'] ?? $row['title'] ?? 'Untitled'),
            'slug' => (string)($parsed['slug'] ?? $row['slug'] ?? ''),
            'filename' => (string)$row['trash_filename'],
            'original_filename' => (string)$row['original_filename'],
            'original_status' => (string)$row['original_status'],
            'content_status' => 'trash',
            'section' => 'trash',
            'path' => mp_root_path((string)($row['markdown_path'] ?? '')),
            'deleted_at' => (string)$row['deleted_at'],
            'author_id' => (int)($row['original_author_id'] ?? 0),
            'original_author_id' => (int)($row['original_author_id'] ?? 0),
            'deleted_by' => (int)($row['deleted_by'] ?? 0),
            'deleted_by_name' => (string)($row['deleted_by_name'] ?? ''),
            'date' => (string)($parsed['date'] ?? substr((string)$row['deleted_at'], 0, 10)),
            'category' => 'Stream',
            'tags' => [],
            'content_storage' => 'database-trash',
        ]);
    }
    return $items;
}

function mp_get_trash_item(int $id): ?array
{
    if (!mp_is_installed()) {
        return null;
    }
    $stmt = mp_db()->prepare('SELECT * FROM ' . mp_table('trash') . ' WHERE id = :id AND original_status NOT IN (\'page_draft\', \'page_published\') LIMIT 1');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function mp_restore_trash_item(int $id): array
{
    $item = mp_get_trash_item($id);
    if (!$item) {
        throw new RuntimeException('Trash item not found.');
    }
    $page = mp_trash_row_to_content_page($item, 'stream');
    $originalStatus = (string)($item['original_status'] ?? 'draft');
    $status = $originalStatus === 'published' ? 'published' : 'draft';
    $section = $status === 'published' ? 'published' : 'drafts';
    $restored = mp_database_content_page_for_status($page, $status, 'stream');
    $filename = basename((string)($item['original_filename'] ?: mp_database_content_filename_for_page($restored)));
    $slug = mp_slugify((string)($restored['slug'] ?? pathinfo($filename, PATHINFO_FILENAME)));
    if (mp_find_database_content_by_slug_status($slug, $status, 'stream')) {
        throw new RuntimeException('A ' . ($status === 'published' ? 'published stream post' : 'draft') . ' already uses this slug. Rename or remove it first.');
    }
    mp_db()->prepare('DELETE FROM ' . mp_table('trash') . ' WHERE id = :id')->execute(['id' => $id]);
    $originalAuthorId = (int)($item['original_author_id'] ?? 0);
    mp_sync_stream_metadata($restored, $section, $filename, $originalAuthorId > 0 ? $originalAuthorId : null);
    return $restored + ['filename' => $filename, 'restored_status' => $status];
}

function mp_delete_trash_item_permanently(int $id): ?array
{
    $item = mp_get_trash_item($id);
    if (!$item) {
        return null;
    }
    $path = mp_root_path((string)($item['markdown_path'] ?? ''));
    if (is_file($path)) {
        @unlink($path);
    }
    mp_db()->prepare('DELETE FROM ' . mp_table('trash') . ' WHERE id = :id')->execute(['id' => $id]);
    return $item;
}

function mp_empty_trash(): int
{
    $items = mp_list_trash_items();
    $count = 0;
    foreach ($items as $item) {
        $id = (int)($item['trash_id'] ?? 0);
        if ($id > 0) {
            mp_delete_trash_item_permanently($id);
            $count++;
        }
    }
    return $count;
}

function mp_sync_all_content_metadata(): void
{
    if (!mp_is_installed()) {
        return;
    }
    foreach (['drafts', 'published'] as $section) {
        foreach (mp_list_content_records($section) as $page) {
            mp_sync_stream_metadata($page, $section, (string)$page['filename']);
        }
    }
    foreach (['pages/drafts', 'pages/published'] as $section) {
        foreach (mp_list_content_records($section) as $page) {
            mp_sync_page_metadata($page, $section, (string)$page['filename']);
        }
    }
}

function mp_find_post_by_markdown_path(string $section, string $filename): ?array
{
    if (!mp_is_installed()) {
        return null;
    }
    $section = trim(str_replace('\\', '/', $section), '/');
    if (!in_array($section, ['published', 'drafts', 'pages/published', 'pages/drafts'], true)) {
        $section = $section === 'published' ? 'published' : 'drafts';
    }
    $status = str_contains($section, 'published') ? 'published' : 'draft';
    $path = 'content/' . $section . '/' . basename($filename);
    $stmt = mp_db()->prepare('SELECT * FROM ' . mp_table('posts') . ' WHERE markdown_path = :markdown_path AND status = :status LIMIT 1');
    $stmt->execute(['markdown_path' => $path, 'status' => $status]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function mp_content_author_id_for_file(string $section, string $filename): ?int
{
    try {
        $post = mp_find_post_by_markdown_path($section, $filename);
    } catch (Throwable $e) {
        return null;
    }
    $authorId = (int)($post['author_id'] ?? 0);
    return $authorId > 0 ? $authorId : null;
}

function mp_post_author_id_by_id(int $postId): ?int
{
    if ($postId < 1 || !mp_is_installed()) {
        return null;
    }
    try {
        $stmt = mp_db()->prepare('SELECT author_id FROM ' . mp_table('posts') . ' WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $postId]);
        $authorId = (int)$stmt->fetchColumn();
        return $authorId > 0 ? $authorId : null;
    } catch (Throwable $e) {
        return null;
    }
}

function mp_revision_original_author_id(array $revision): ?int
{
    $postAuthorId = mp_post_author_id_by_id((int)($revision['post_id'] ?? 0));
    if ($postAuthorId !== null) {
        return $postAuthorId;
    }
    $revisionAuthorId = (int)($revision['author_id'] ?? 0);
    return $revisionAuthorId > 0 ? $revisionAuthorId : null;
}

function mp_content_subject_for_file(string $section, string $filename, array $page = []): array
{
    $section = trim(str_replace('\\', '/', $section), '/');
    if (!in_array($section, ['published', 'drafts', 'pages/published', 'pages/drafts'], true)) {
        $section = $section === 'published' ? 'published' : 'drafts';
    }
    $post = null;
    try {
        $post = mp_find_post_by_markdown_path($section, $filename);
    } catch (Throwable $e) {
        $post = null;
    }
    return array_replace($page, [
        'author_id' => (int)($post['author_id'] ?? 0),
        'post_id' => (int)($post['id'] ?? 0),
        'section' => $section,
        'filename' => basename($filename),
    ]);
}


function mp_review_status_options(): array
{
    return [
        '' => 'Not submitted',
        'pending' => 'Pending review',
        'approved' => 'Approved',
        'rejected' => 'Needs changes',
    ];
}

function mp_normalize_review_status(string $status): string
{
    $status = strtolower(trim($status));
    return array_key_exists($status, mp_review_status_options()) ? $status : '';
}

function mp_review_status_label(string $status): string
{
    $options = mp_review_status_options();
    $status = mp_normalize_review_status($status);
    return $options[$status] ?? 'Not submitted';
}

function mp_mark_post_review_status(string $section, string $filename, string $reviewStatus, ?int $reviewedBy = null): void
{
    if (!mp_is_installed()) {
        return;
    }
    $section = $section === 'published' ? 'published' : 'drafts';
    $reviewStatus = mp_normalize_review_status($reviewStatus);
    $path = 'content/' . $section . '/' . basename($filename);

    try {
        $post = mp_find_post_by_markdown_path($section, $filename);
        if (!$post) {
            return;
        }

        $submittedSql = $reviewStatus === 'pending' ? ', submitted_at = COALESCE(submitted_at, NOW())' : '';
        $reviewedSql = in_array($reviewStatus, ['approved', 'rejected'], true) ? ', reviewed_at = NOW(), reviewed_by = :reviewed_by' : ', reviewed_at = NULL, reviewed_by = NULL';
        $sql = 'UPDATE ' . mp_table('posts') . ' SET review_status = :review_status' . $submittedSql . $reviewedSql . ', updated_at = NOW() WHERE markdown_path = :markdown_path LIMIT 1';
        $stmt = mp_db()->prepare($sql);
        $params = [
            'review_status' => $reviewStatus,
            'markdown_path' => $path,
        ];
        if (in_array($reviewStatus, ['approved', 'rejected'], true)) {
            $params['reviewed_by'] = $reviewedBy ?? mp_current_user_id();
        }
        $stmt->execute($params);
    } catch (Throwable $e) {
        // Review metadata is an upgrade-time enhancement. Missing columns should not break basic publishing.
    }
}

function mp_mark_draft_pending_review(string $filename): void
{
    mp_mark_post_review_status('drafts', $filename, 'pending');
}

function mp_review_status_for_file(string $section, string $filename): string
{
    try {
        $post = mp_find_post_by_markdown_path($section, $filename);
        return mp_normalize_review_status((string)($post['review_status'] ?? ''));
    } catch (Throwable $e) {
        return '';
    }
}

function mp_review_queue_count(): int
{
    try {
        $stmt = mp_db()->query("SELECT COUNT(*) FROM " . mp_table('posts') . " WHERE status = 'draft' AND review_status = 'pending'");
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function mp_post_review_badge_for_file(string $section, string $filename): array
{
    $status = mp_review_status_for_file($section, $filename);
    return [
        'status' => $status,
        'label' => mp_review_status_label($status),
        'class' => $status !== '' ? $status : 'none',
    ];
}

function mp_save_autosave(string $draftKey, array $fields, string $markdown): void
{
    if (!mp_is_installed()) {
        return;
    }
    $draftKey = substr(hash('sha256', trim($draftKey) !== '' ? $draftKey : 'editor'), 0, 64);
    $userId = mp_current_user_id();
    $title = trim((string)($fields['title'] ?? ''));
    $slug = mp_slugify((string)($fields['slug'] ?? $title ?: 'autosave'));
    $section = (string)($fields['section'] ?? 'drafts');
    $section = $section === 'published' ? 'published' : 'drafts';
    $filename = basename((string)($fields['filename'] ?? ''));
    $fieldsJson = json_encode($fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $sql = 'INSERT INTO ' . mp_table('autosaves') . ' (user_id, draft_key, title, slug, section, filename, markdown, fields_json, created_at, updated_at) '
        . 'VALUES (:user_id, :draft_key, :title, :slug, :section, :filename, :markdown, :fields_json, NOW(), NOW()) '
        . 'ON DUPLICATE KEY UPDATE title = VALUES(title), slug = VALUES(slug), section = VALUES(section), filename = VALUES(filename), markdown = VALUES(markdown), fields_json = VALUES(fields_json), updated_at = NOW()';
    $stmt = mp_db()->prepare($sql);
    $stmt->execute([
        'user_id' => $userId,
        'draft_key' => $draftKey,
        'title' => $title,
        'slug' => $slug,
        'section' => $section,
        'filename' => $filename,
        'markdown' => $markdown,
        'fields_json' => $fieldsJson ?: '{}',
    ]);
}

function mp_get_autosave(string $draftKey): ?array
{
    if (!mp_is_installed()) {
        return null;
    }
    $draftKey = substr(hash('sha256', trim($draftKey) !== '' ? $draftKey : 'editor'), 0, 64);
    $stmt = mp_db()->prepare('SELECT * FROM ' . mp_table('autosaves') . ' WHERE draft_key = :draft_key AND user_id = :user_id LIMIT 1');
    $stmt->execute(['draft_key' => $draftKey, 'user_id' => mp_current_user_id()]);
    $row = $stmt->fetch();
    if (!is_array($row)) {
        return null;
    }
    $fields = json_decode((string)($row['fields_json'] ?? '{}'), true);
    if (!is_array($fields)) {
        $fields = [];
    }
    $row['fields'] = $fields;
    return $row;
}

function mp_delete_autosave(string $draftKey): void
{
    if (!mp_is_installed()) {
        return;
    }
    $draftKey = substr(hash('sha256', trim($draftKey) !== '' ? $draftKey : 'editor'), 0, 64);
    $stmt = mp_db()->prepare('DELETE FROM ' . mp_table('autosaves') . ' WHERE draft_key = :draft_key AND user_id = :user_id');
    $stmt->execute(['draft_key' => $draftKey, 'user_id' => mp_current_user_id()]);
}

function mp_restore_revision_over_current(int $id): array
{
    $revision = mp_get_revision($id);
    if (!$revision) {
        throw new RuntimeException('Revision not found.');
    }
    $revisionPage = mp_revision_page_from_row($revision);
    $slug = mp_slugify((string)($revision['slug'] ?? $revisionPage['slug'] ?? 'restored-revision'));
    $targetStatus = (string)($revision['status'] ?? '') === 'published' ? 'published' : 'draft';
    $section = $targetStatus === 'published' ? 'published' : 'drafts';
    $filename = basename((string)($revision['original_filename'] ?: ($slug . '.md')));
    $currentPage = mp_find_database_content_by_markdown_path($section, $filename) ?: mp_find_database_content_by_slug_status($slug, $targetStatus, 'stream');
    $targetAuthorId = null;
    if ($currentPage) {
        $targetAuthorId = (int)($currentPage['author_id'] ?? 0) ?: null;
        if (function_exists('mp_require_content_file_access')) {
            mp_require_content_file_access($section, $filename, 'edit_content', $currentPage);
        }
        if (function_exists('mp_record_revision_from_page')) {
            mp_record_revision_from_page($currentPage, $targetStatus, $filename, $targetAuthorId);
        }
    }
    $restored = mp_database_content_page_for_status(array_replace($revisionPage, ['slug' => $slug]), $targetStatus, 'stream');
    $filename = mp_database_content_filename_for_page($restored);
    mp_sync_stream_metadata($restored, $section, $filename, $targetAuthorId ?? mp_revision_original_author_id($revision));
    if ($section === 'published') {
    }
    return $restored + ['filename' => $filename, 'restored_status' => $targetStatus];
}


<?php
require_once __DIR__ . '/functions.php';

function bms_table_prefix(): string
{
    $config = bms_config();
    $prefix = (string)($config['database']['prefix'] ?? 'bms_');
    $prefix = preg_replace('/[^A-Za-z0-9_]/', '', $prefix) ?? 'bms_';
    return $prefix !== '' ? $prefix : 'bms_';
}

function bms_table(string $name): string
{
    $name = preg_replace('/[^A-Za-z0-9_]/', '', $name) ?? '';
    return bms_table_prefix() . $name;
}

function bms_db_config(): array
{
    $config = bms_config();
    $db = $config['database'] ?? [];
    return is_array($db) ? $db : [];
}

function bms_has_database_config(): bool
{
    $db = bms_db_config();
    return !empty($db['name']) && !empty($db['user']) && array_key_exists('password', $db) && !empty($db['host']);
}

function bms_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $db = bms_db_config();
    if (!bms_has_database_config()) {
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

    // All persisted system timestamps are canonical UTC. This removes any
    // dependence on the MySQL/MariaDB server's own session timezone for NOW().
    $pdo->exec("SET time_zone = '+00:00'");

    return $pdo;
}

function bms_db_test_connection(array $db): void
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

function bms_db_supports_mysql(): bool
{
    return extension_loaded('pdo') && in_array('mysql', PDO::getAvailableDrivers(), true);
}

function bms_setting(string $key, mixed $default = null): mixed
{
    static $cache = [];
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    if (!bms_is_installed() || !bms_has_database_config()) {
        $config = bms_config();
        return $config[$key] ?? $default;
    }

    try {
        $stmt = bms_db()->prepare('SELECT setting_value FROM ' . bms_table('settings') . ' WHERE setting_key = :setting_key LIMIT 1');
        $stmt->execute(['setting_key' => $key]);
        $value = $stmt->fetchColumn();
        if ($value === false) {
            $cache[$key] = $default;
            return $default;
        }
        $cache[$key] = $value;
        return $value;
    } catch (Throwable $e) {
        $config = bms_config();
        return $config[$key] ?? $default;
    }
}

function bms_set_setting(string $key, string $value): void
{
    $sql = 'INSERT INTO ' . bms_table('settings') . ' (setting_key, setting_value, updated_at) VALUES (:setting_key, :setting_value, NOW()) '
        . 'ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()';
    $stmt = bms_db()->prepare($sql);
    $stmt->execute(['setting_key' => $key, 'setting_value' => $value]);
}

function bms_migration_error_is_idempotent(Throwable $e, string $statement): bool
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

function bms_exec_migration_statement(PDO $pdo, string $statement, string $prefix): void
{
    $sql = str_replace('{{prefix}}', $prefix, $statement);
    if (preg_match('/{{[A-Za-z0-9_]+}}/', $sql, $matches)) {
        throw new RuntimeException('Migration contains an unsupported table placeholder: ' . $matches[0]);
    }
    try {
        $pdo->exec($sql);
    } catch (Throwable $e) {
        if (bms_migration_error_is_idempotent($e, $sql)) {
            return;
        }
        throw $e;
    }
}


/**
 * MySQL/MariaDB implicitly commit many DDL statements. Keep the migration
 * runner honest: DDL migrations are resumable, not transactional. A failed
 * migration is never recorded as complete, and its idempotent statements can
 * safely be retried on the next run.
 */
function bms_migration_statement_is_ddl(string $statement): bool
{
    return preg_match('/^\s*(?:ALTER|CREATE|DROP|RENAME|TRUNCATE)\b/i', $statement) === 1;
}

function bms_migration_contains_ddl(array $statements): bool
{
    foreach ($statements as $statement) {
        if (is_string($statement) && bms_migration_statement_is_ddl($statement)) {
            return true;
        }
    }
    return false;
}

function bms_normalize_utc_datetime(string $value): string
{
    $value = trim($value);
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) {
        return '';
    }
    try {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * Resolve the first UTC-published timestamp without rewriting posts.
 *
 * Valid cutovers are preserved. The legacy 1970 marker is repaired from the
 * earliest known 0.5.23/0.5.24 upgrade, a fresh UTC-era install marker, or the
 * start of a direct pre-0.5.23 upgrade. The last fallback is the current UTC
 * upgrade time, never 1970, so old local values are not silently reclassified.
 */
function bms_resolve_stream_published_at_utc_cutover(array $context): array
{
    $existing = bms_normalize_utc_datetime((string)($context['existing_cutover'] ?? ''));
    if ($existing !== '' && $existing !== '1970-01-01 00:00:00') {
        return ['cutover' => $existing, 'source' => 'preserved'];
    }

    $history = bms_normalize_utc_datetime((string)($context['history_cutover'] ?? ''));
    if ($history !== '') {
        return ['cutover' => $history, 'source' => 'upgrade_history'];
    }

    $freshBaseline = trim((string)($context['fresh_install_baseline'] ?? ''));
    $installedAt = bms_normalize_utc_datetime((string)($context['installed_at'] ?? ''));
    if ($freshBaseline !== '' && version_compare($freshBaseline, '0.5.23', '>=') && $installedAt !== '') {
        return ['cutover' => $installedAt, 'source' => 'fresh_install_lock'];
    }

    $fromVersion = trim((string)($context['from_version'] ?? ''));
    $now = bms_normalize_utc_datetime((string)($context['now'] ?? '')) ?: gmdate('Y-m-d H:i:s');
    if ($fromVersion !== '' && version_compare($fromVersion, '0.5.23', '<')) {
        return ['cutover' => $now, 'source' => 'direct_legacy_upgrade'];
    }

    if ($freshBaseline !== '' && version_compare($freshBaseline, '0.5.23', '<')) {
        return ['cutover' => $now, 'source' => 'legacy_baseline_upgrade'];
    }

    return ['cutover' => $now, 'source' => 'upgrade_time_fallback'];
}

function bms_database_table_exists(PDO $pdo, string $table): bool
{
    // MariaDB does not accept a bound parameter in SHOW TABLES LIKE.
    // Quote the literal explicitly instead of using a native prepared statement.
    $stmt = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table));
    return $stmt !== false && (bool)$stmt->fetchColumn();
}

function bms_database_setting_value(PDO $pdo, string $prefix, string $key): string
{
    $stmt = $pdo->prepare('SELECT setting_value FROM `' . $prefix . 'settings` WHERE setting_key = :setting_key LIMIT 1');
    $stmt->execute(['setting_key' => $key]);
    $value = $stmt->fetchColumn();
    return $value === false ? '' : (string)$value;
}

function bms_database_set_setting(PDO $pdo, string $prefix, string $key, string $value): void
{
    $stmt = $pdo->prepare('INSERT INTO `' . $prefix . 'settings` (setting_key, setting_value, updated_at) VALUES (:setting_key, :setting_value, NOW()) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()');
    $stmt->execute(['setting_key' => $key, 'setting_value' => $value]);
}

function bms_prepare_stream_published_at_utc_cutover(PDO $pdo, string $prefix, string $fromVersion = ''): array
{
    $existing = bms_database_setting_value($pdo, $prefix, 'stream_published_at_utc_cutover');
    $history = '';
    $upgradeHistoryTable = $prefix . 'upgrade_history';
    if (bms_database_table_exists($pdo, $upgradeHistoryTable)) {
        $historyStmt = $pdo->query("SELECT DATE_FORMAT(MIN(ran_at), '%Y-%m-%d %H:%i:%s') FROM `{$upgradeHistoryTable}` WHERE status = 'complete' AND to_version IN ('0.5.23', '0.5.24')");
        $history = $historyStmt ? (string)($historyStmt->fetchColumn() ?: '') : '';
    }
    $installedAt = is_file(bms_installed_lock_path()) ? gmdate('Y-m-d H:i:s', (int)filemtime(bms_installed_lock_path())) : '';

    $resolved = bms_resolve_stream_published_at_utc_cutover([
        'existing_cutover' => $existing,
        'history_cutover' => $history,
        'fresh_install_baseline' => bms_database_setting_value($pdo, $prefix, 'fresh_install_baseline'),
        'installed_at' => $installedAt,
        'from_version' => $fromVersion,
        'now' => gmdate('Y-m-d H:i:s'),
    ]);

    if (($resolved['source'] ?? '') === 'preserved') {
        return $resolved;
    }

    bms_database_set_setting($pdo, $prefix, 'stream_published_at_utc_cutover', (string)$resolved['cutover']);
    bms_database_set_setting($pdo, $prefix, 'stream_published_at_utc_cutover_source', (string)$resolved['source']);
    return $resolved;
}

function bms_install_schema(PDO $pdo, string $prefix): void
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
            bms_exec_migration_statement($pdo, (string)$statement, $prefix);
        }
        if ($name !== '0001_initial_schema') {
            $stmt = $pdo->prepare('INSERT IGNORE INTO `' . $prefix . 'migrations` (migration, ran_at) VALUES (:migration, NOW())');
            $stmt->execute(['migration' => $name]);
        }
    }

    $stmt = $pdo->prepare('INSERT IGNORE INTO `' . $prefix . 'migrations` (migration, ran_at) VALUES (:migration, NOW())');
    $stmt->execute(['migration' => '0001_initial_schema']);
}

function bms_run_migrations(string $fromVersion = ''): array
{
    if (!bms_has_database_config()) {
        return [];
    }

    $lockPath = bms_root_path('tmp/migration.lock');
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
        $pdo = bms_db();
        $prefix = bms_table_prefix();
        $migrationTable = $prefix . 'migrations';
        $pdo->exec('CREATE TABLE IF NOT EXISTS `' . $migrationTable . '` (migration VARCHAR(120) NOT NULL PRIMARY KEY, ran_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $doneStmt = $pdo->query('SELECT migration FROM `' . $migrationTable . '`');
        $done = [];
        foreach ($doneStmt->fetchAll() as $row) {
            $done[(string)$row['migration']] = true;
        }

        // Repair the old 1970 timestamp marker before migrations run. This is
        // intentionally upgrade-safe and leaves valid current cutovers untouched.
        bms_prepare_stream_published_at_utc_cutover($pdo, $prefix, $fromVersion);

        $ran = [];
        $files = glob(bms_root_path('migrations/*.php')) ?: [];
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

            $usesDdl = bms_migration_contains_ddl($statements);
            $startedTransaction = false;
            try {
                // MySQL/MariaDB auto-commit DDL, so only DML-only migrations use
                // transactions. DDL migrations remain idempotent and are marked
                // complete only after every statement has succeeded.
                if (!$usesDdl && !$pdo->inTransaction()) {
                    $pdo->beginTransaction();
                    $startedTransaction = true;
                }
                foreach ($statements as $index => $statement) {
                    if (!is_int($index) || !is_string($statement) || trim($statement) === '') {
                        throw new RuntimeException('Migration must return a numeric list of SQL statement strings: ' . $name);
                    }
                    bms_exec_migration_statement($pdo, $statement, $prefix);
                }
                $stmt = $pdo->prepare('INSERT IGNORE INTO `' . $migrationTable . '` (migration, ran_at) VALUES (:migration, NOW())');
                $stmt->execute(['migration' => $name]);
                if ($startedTransaction && $pdo->inTransaction()) {
                    $pdo->commit();
                }
            } catch (Throwable $e) {
                if ($startedTransaction && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
            $ran[] = $name;
        }
        return $ran;
    } finally {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}


/**
 * Forward-only upgrade recovery state.
 *
 * MySQL/MariaDB DDL auto-commits, so an upgrade that has entered the
 * migration phase must keep compatible software files in place. This
 * marker survives request boundaries and lets the upgrader resume safely.
 */
function bms_upgrade_recovery_marker_path(): string
{
    return bms_root_path('data/upgrade-recovery.json');
}

function bms_upgrade_recovery_state(): array
{
    $path = bms_upgrade_recovery_marker_path();
    if (!is_file($path)) {
        return [];
    }

    $state = json_decode((string)file_get_contents($path), true);
    if (!is_array($state)) {
        return [];
    }

    $status = (string)($state['status'] ?? '');
    if (!in_array($status, ['migration_in_progress', 'recovery_required'], true)) {
        return [];
    }

    return [
        'status' => $status,
        'phase' => (string)($state['phase'] ?? 'database_migration'),
        'from_version' => trim((string)($state['from_version'] ?? '')),
        'to_version' => trim((string)($state['to_version'] ?? '')),
        'backup_path' => trim((string)($state['backup_path'] ?? '')),
        'started_at' => trim((string)($state['started_at'] ?? '')),
        'updated_at' => trim((string)($state['updated_at'] ?? '')),
    ];
}

function bms_write_upgrade_recovery_state(array $state): void
{
    $status = (string)($state['status'] ?? '');
    if (!in_array($status, ['migration_in_progress', 'recovery_required'], true)) {
        throw new RuntimeException('Upgrade recovery state is invalid.');
    }

    $payload = [
        'status' => $status,
        'phase' => (string)($state['phase'] ?? 'database_migration'),
        'from_version' => trim((string)($state['from_version'] ?? '')),
        'to_version' => trim((string)($state['to_version'] ?? '')),
        'backup_path' => trim((string)($state['backup_path'] ?? '')),
        'started_at' => trim((string)($state['started_at'] ?? gmdate('c'))),
        'updated_at' => gmdate('c'),
    ];

    $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded)) {
        throw new RuntimeException('Could not encode the upgrade recovery state.');
    }

    bms_write_file(bms_upgrade_recovery_marker_path(), $encoded . "\n");
}

function bms_clear_upgrade_recovery_state(): void
{
    $path = bms_upgrade_recovery_marker_path();
    if (is_file($path) && !@unlink($path)) {
        throw new RuntimeException('Could not clear the completed upgrade recovery marker.');
    }
}

function bms_upgrade_recovery_matches_package(string $version): bool
{
    $state = bms_upgrade_recovery_state();
    return (string)($state['status'] ?? '') === 'recovery_required'
        && $version !== ''
        && hash_equals((string)($state['to_version'] ?? ''), $version);
}


function bms_db_insert_initial_data(array $site, array $admin): void
{
    $pdo = bms_db();
    $settings = [
        'site_name' => $site['site_name'] ?? 'Bonumark Stream',
        'site_tagline' => $site['site_tagline'] ?? 'A self-hosted microblog CMS for publishing short-form posts on a site you control.',
        'author_name' => $admin['display_name'] ?? 'Admin',
        'timezone' => $site['timezone'] ?? 'UTC',
        'base_path' => $site['base_path'] ?? '',
        'base_url' => $site['base_url'] ?? '',
        'public_path' => $site['public_path'] ?? '',
        'site_admin_email' => $admin['email'] ?? '',
        'default_editor_mode' => 'visual',
        'default_content_status' => 'draft',
        'media_upload_limit_mb' => '32',
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
        'registration_honeypot_enabled' => '1',
        'homepage_eyebrow' => 'Own your short-form publishing',
        'site_footer_text' => '',
        'show_powered_by' => '1',
        'site_favicon_media_id' => '0',
        'site_favicon_path' => '',
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
        'fresh_install_baseline' => bms_version(),
        'version' => bms_version(),
    ];
    foreach ($settings as $key => $value) {
        bms_set_setting($key, (string)$value);
    }

    $stmt = $pdo->prepare('INSERT INTO ' . bms_table('users') . ' (username, display_name, email, email_verified_at, password_hash, role, status, created_at, updated_at) VALUES (:username, :display_name, :email, :email_verified_at, :password_hash, :role, :status, NOW(), NOW())');
    $stmt->execute([
        'username' => bms_normalize_username((string)$admin['username']),
        'display_name' => trim((string)$admin['display_name']),
        'email' => trim((string)$admin['email']),
        'email_verified_at' => gmdate('Y-m-d H:i:s'),
        'password_hash' => password_hash((string)$admin['password'], PASSWORD_DEFAULT),
        'role' => 'admin',
        'status' => 'active',
    ]);
}

function bms_current_user_id(): ?int
{
    $id = $_SESSION['bms_user_id'] ?? null;
    return is_numeric($id) ? (int)$id : null;
}


function bms_database_content_enabled(): bool
{
    if (!bms_is_installed() || !bms_has_database_config()) {
        return false;
    }
    try {
        return (string)bms_setting('content_storage_mode', 'database') === 'database';
    } catch (Throwable $e) {
        return true;
    }
}

function bms_content_status_for_section(string $section): string
{
    $section = trim($section, '/');
    if ($section === 'scheduled') {
        return 'scheduled';
    }
    return str_contains($section, 'published') ? 'published' : 'draft';
}

function bms_content_type_for_section(string $section): string
{
    return str_starts_with(trim($section, '/'), 'pages/') ? 'page' : 'stream';
}

function bms_section_for_content(string $postType, string $status): string
{
    $postType = $postType === 'page' ? 'page' : 'stream';
    $status = in_array($status, ['draft', 'published', 'scheduled'], true) ? $status : 'draft';
    if ($postType === 'page') {
        return $status === 'published' ? 'pages/published' : 'pages/drafts';
    }
    if ($status === 'scheduled') {
        return 'scheduled';
    }
    return $status === 'published' ? 'published' : 'drafts';
}

function bms_database_content_columns_ready(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    if (!bms_is_installed() || !bms_has_database_config()) {
        $ready = false;
        return false;
    }
    try {
        $stmt = bms_db()->query('SHOW COLUMNS FROM ' . bms_table('posts') . " LIKE 'content_body'");
        $ready = (bool)$stmt->fetch();
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

function bms_content_front_matter_for_database(array $page): array
{
    $frontMatter = is_array($page['front_matter'] ?? null) ? $page['front_matter'] : [];
    foreach (['seo_title','robots','featured_media','stream_created_at','scheduled_at','link_preview_url','link_preview_title','link_preview_description','link_preview_image','link_preview_site_name'] as $key) {
        if (array_key_exists($key, $page) && !array_key_exists($key, $frontMatter)) {
            $frontMatter[$key] = $page[$key];
        }
    }
    return $frontMatter;
}

function bms_database_content_raw(array $page): string
{
    return bms_build_markdown_document([
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
        'scheduled_at' => (string)($page['scheduled_at'] ?? $page['front_matter']['scheduled_at'] ?? ''),
        'seo_title' => (string)($page['seo_title'] ?? $page['front_matter']['seo_title'] ?? ''),
        'robots' => (string)($page['robots'] ?? $page['front_matter']['robots'] ?? ''),
        'link_preview_url' => (string)($page['link_preview_url'] ?? $page['front_matter']['link_preview_url'] ?? ''),
        'link_preview_title' => (string)($page['link_preview_title'] ?? $page['front_matter']['link_preview_title'] ?? ''),
        'link_preview_description' => (string)($page['link_preview_description'] ?? $page['front_matter']['link_preview_description'] ?? ''),
        'link_preview_image' => (string)($page['link_preview_image'] ?? $page['front_matter']['link_preview_image'] ?? ''),
        'link_preview_site_name' => (string)($page['link_preview_site_name'] ?? $page['front_matter']['link_preview_site_name'] ?? ''),
    ], (string)($page['body'] ?? ''));
}

function bms_database_row_to_content_page(array $row): array
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
    $rawStatus = (string)($row['status'] ?? 'draft');
    $status = in_array($rawStatus, ['draft', 'published', 'scheduled'], true) ? $rawStatus : 'draft';
    $section = bms_section_for_content($postType, $status);
    $slug = bms_slugify((string)($row['slug'] ?? ''));
    $filename = basename((string)($row['markdown_path'] ?? ''));
    if ($filename === '' || $filename === '.') {
        $filename = ($slug !== '' ? $slug : 'content-' . (int)($row['id'] ?? 0)) . '.md';
    }
    $body = (string)($row['content_body'] ?? '');
    $path = trim((string)($row['markdown_path'] ?? ''));

    $date = (string)($row['date_published'] ?? '');
    if ($date === '' || $date === '0000-00-00') {
        $date = substr((string)($row['published_at'] ?? $row['created_at'] ?? date('Y-m-d')), 0, 10);
    }
    $streamCreatedAt = (string)($frontMatter['stream_created_at'] ?? ($row['published_at'] ?? $row['created_at'] ?? ''));
    if ($status === 'published' && trim((string)($row['scheduled_at'] ?? '')) !== '') {
        $streamCreatedAt = (string)$row['scheduled_at'];
    } elseif ($status === 'published' && trim((string)($row['published_at'] ?? '')) !== '') {
        $streamCreatedAt = (string)$row['published_at'];
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
        'scheduled_at' => (string)($row['scheduled_at'] ?? $frontMatter['scheduled_at'] ?? ''),
        'is_pinned' => $postType === 'stream' && $status === 'published' && !empty($row['is_pinned']),
        'pinned_at' => $postType === 'stream' && $status === 'published' ? (string)($row['pinned_at'] ?? '') : '',
        'date_published' => (string)($row['date_published'] ?? ''),
        'body' => $body,
        'front_matter' => $frontMatter,
        'featured_media' => (string)($frontMatter['featured_media'] ?? ''),
        'stream_created_at' => $streamCreatedAt,
        'seo_title' => (string)($frontMatter['seo_title'] ?? ''),
        'robots' => (string)($frontMatter['robots'] ?? ''),
        'link_preview_url' => (string)($frontMatter['link_preview_url'] ?? ''),
        'link_preview_title' => (string)($frontMatter['link_preview_title'] ?? ''),
        'link_preview_description' => (string)($frontMatter['link_preview_description'] ?? ''),
        'link_preview_image' => (string)($frontMatter['link_preview_image'] ?? ''),
        'link_preview_site_name' => (string)($frontMatter['link_preview_site_name'] ?? ''),
        'filename' => $filename,
        'path' => $path !== '' ? bms_root_path($path) : '',
        'markdown_path' => $path,
        'section' => $section,
        'content_status' => $status,
        'content_storage' => 'database',
    ];
    $page['raw'] = bms_database_content_raw($page);
    return $page;
}

function bms_database_slug_exists(string $slug, string $currentSlug = '', string $postType = ''): bool
{
    if (!bms_database_content_enabled() || !bms_database_content_columns_ready()) {
        return false;
    }
    $slug = bms_slugify($slug);
    $currentSlug = bms_slugify($currentSlug);
    if ($slug === '' || ($currentSlug !== '' && $slug === $currentSlug)) {
        return false;
    }
    try {
        $sql = 'SELECT COUNT(*) FROM ' . bms_table('posts') . ' WHERE slug = :slug';
        $params = ['slug' => $slug];
        if ($postType === 'stream' || $postType === 'page') {
            $sql .= ' AND post_type = :post_type';
            $params['post_type'] = $postType;
        }
        $stmt = bms_db()->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function bms_find_database_content_by_slug_status(string $slug, string $status = 'published', string $postType = 'stream'): ?array
{
    if (!bms_database_content_enabled() || !bms_database_content_columns_ready()) {
        return null;
    }
    try {
        $stmt = bms_db()->prepare('SELECT * FROM ' . bms_table('posts') . ' WHERE slug = :slug AND status = :status AND post_type = :post_type LIMIT 1');
        $stmt->execute(['slug' => bms_slugify($slug), 'status' => in_array($status, ['draft', 'published', 'scheduled'], true) ? $status : 'draft', 'post_type' => $postType === 'page' ? 'page' : 'stream']);
        $row = $stmt->fetch();
        return is_array($row) ? bms_database_row_to_content_page($row) : null;
    } catch (Throwable $e) {
        return null;
    }
}

function bms_find_database_content_by_markdown_path(string $section, string $filename): ?array
{
    if (!bms_database_content_enabled() || !bms_database_content_columns_ready()) {
        return null;
    }
    $section = trim(str_replace('\\', '/', $section), '/');
    $status = bms_content_status_for_section($section);
    $postType = bms_content_type_for_section($section);
    $path = 'content/' . $section . '/' . basename($filename);
    $slug = bms_slugify(pathinfo(basename($filename), PATHINFO_FILENAME));
    try {
        $stmt = bms_db()->prepare('SELECT * FROM ' . bms_table('posts') . ' WHERE ((markdown_path = :markdown_path) OR (slug = :slug)) AND status = :status AND post_type = :post_type LIMIT 1');
        $stmt->execute(['markdown_path' => $path, 'slug' => $slug, 'status' => $status, 'post_type' => $postType]);
        $row = $stmt->fetch();
        return is_array($row) ? bms_database_row_to_content_page($row) : null;
    } catch (Throwable $e) {
        return null;
    }
}

function bms_list_database_content_for_section(string $section): array
{
    if (!bms_database_content_enabled() || !bms_database_content_columns_ready()) {
        return [];
    }
    $section = trim(str_replace('\\', '/', $section), '/');
    $status = bms_content_status_for_section($section);
    $postType = bms_content_type_for_section($section);
    try {
        $stmt = bms_db()->prepare("SELECT * FROM " . bms_table('posts') . " WHERE status = :status AND post_type = :post_type ORDER BY CASE WHEN status = 'scheduled' THEN COALESCE(scheduled_at, created_at) ELSE COALESCE(published_at, created_at) END DESC, id DESC");
        $stmt->execute(['status' => $status, 'post_type' => $postType]);
        $rows = $stmt->fetchAll() ?: [];
        return array_map('bms_database_row_to_content_page', $rows);
    } catch (Throwable $e) {
        return [];
    }
}

function bms_is_pinned_stream_post(array $page): bool
{
    return (string)($page['post_type'] ?? $page['content_type'] ?? 'stream') === 'stream'
        && (string)($page['status'] ?? $page['content_status'] ?? '') === 'published'
        && !empty($page['is_pinned']);
}

function bms_list_pinned_stream_posts(): array
{
    if (!bms_database_content_enabled() || !bms_database_content_columns_ready()) {
        return [];
    }

    try {
        $stmt = bms_db()->prepare('SELECT * FROM ' . bms_table('posts') . ' WHERE status = :status AND post_type = :post_type AND is_pinned = 1 ORDER BY pinned_at DESC, id DESC');
        $stmt->execute(['status' => 'published', 'post_type' => 'stream']);
        $rows = $stmt->fetchAll() ?: [];
        return array_map('bms_database_row_to_content_page', $rows);
    } catch (Throwable $e) {
        return [];
    }
}

function bms_set_stream_post_pinned_state(string $filename, bool $pinned): array
{
    if (!bms_database_content_enabled() || !bms_database_content_columns_ready()) {
        throw new RuntimeException('Pinned posts are not available until the database upgrade has completed.');
    }

    $filename = basename($filename);
    if ($filename === '') {
        throw new RuntimeException('A published stream post is required.');
    }

    $page = bms_find_database_content_by_markdown_path('published', $filename);
    if (!is_array($page) || !bms_is_stream_post($page)) {
        throw new RuntimeException('Only published stream posts can be pinned.');
    }

    $postId = (int)($page['post_id'] ?? 0);
    if ($postId < 1) {
        throw new RuntimeException('The published stream post could not be found.');
    }

    $sql = $pinned
        ? 'UPDATE ' . bms_table('posts') . ' SET is_pinned = 1, pinned_at = NOW(), updated_at = NOW() WHERE id = :id AND status = :status AND post_type = :post_type'
        : 'UPDATE ' . bms_table('posts') . ' SET is_pinned = 0, pinned_at = NULL, updated_at = NOW() WHERE id = :id AND status = :status AND post_type = :post_type';
    $stmt = bms_db()->prepare($sql);
    $stmt->execute(['id' => $postId, 'status' => 'published', 'post_type' => 'stream']);
    if ($stmt->rowCount() < 1) {
        throw new RuntimeException('The published stream post could not be updated.');
    }

    $page['is_pinned'] = $pinned;
    $page['pinned_at'] = $pinned ? gmdate('Y-m-d H:i:s') : '';
    return $page;
}

function bms_database_content_record_from_page(array $page, string $section, string $filename, ?int $authorId = null): array
{
    $postType = bms_content_type_for_section($section);
    $status = bms_content_status_for_section($section);
    $sectionPath = trim($section, '/');
    $markdownPath = 'content/' . $sectionPath . '/' . basename($filename);
    $htmlPath = null;
    if ($status === 'published') {
        $htmlPath = $postType === 'page'
            ? trim(bms_page_relative_directory_for_page($page), '/') . '/index.html'
            : trim(bms_stream_relative_directory_for_post($page), '/') . '/index.html';
    }
    $frontMatter = bms_content_front_matter_for_database($page);
    $raw = (string)($page['raw'] ?? bms_database_content_raw($page));
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
        'category_slug' => $postType === 'page' ? 'page' : (string)($page['category_slug'] ?? bms_term_slug((string)($page['category'] ?? 'Stream'))),
        'markdown_path' => $markdownPath,
        'html_path' => $htmlPath,
        'date_published' => (string)($page['date'] ?? date('Y-m-d')),
        'content_hash' => $contentHash,
        'scheduled_at' => $status === 'scheduled' ? (string)($page['scheduled_at'] ?? $frontMatter['scheduled_at'] ?? '') : '',
        'raw' => $raw,
    ];
}

function bms_upsert_database_content(array $page, string $section, string $filename, ?int $authorId = null): int
{
    if (!bms_is_installed() || !bms_database_content_columns_ready()) {
        return 0;
    }
    $record = bms_database_content_record_from_page($page, $section, $filename, $authorId);
    $pdo = bms_db();
    $existing = null;
    try {
        $stmt = $pdo->prepare('SELECT * FROM ' . bms_table('posts') . ' WHERE slug = :slug AND status = :status AND post_type = :post_type LIMIT 1');
        $stmt->execute(['slug' => bms_slugify((string)$record['slug']), 'status' => $record['status'], 'post_type' => $record['post_type']]);
        $row = $stmt->fetch();
        $existing = is_array($row) ? $row : null;
    } catch (Throwable $e) {
        $existing = null;
    }

    if ($authorId === null && $existing) {
        $existingAuthor = (int)($existing['author_id'] ?? 0);
        $record['author_id'] = $existingAuthor > 0 ? $existingAuthor : null;
    } elseif ($record['author_id'] === null && !$existing) {
        $record['author_id'] = bms_current_user_id();
    }

    if ($existing) {
        // Keep this update positional. Some native MySQL/MariaDB PDO drivers have
        // reported HY093 for this long named-parameter statement during post edits.
        // Positional placeholders remove that driver-specific name-resolution path.
        $stmt = $pdo->prepare('UPDATE ' . bms_table('posts') . ' SET author_id = COALESCE(?, author_id), title = ?, slug = ?, status = ?, post_type = ?, description = ?, content_body = ?, content_front_matter = ?, content_source = ?, storage_mode = ?, category = ?, category_slug = ?, markdown_path = ?, html_path = ?, date_published = ?, scheduled_at = ?, content_hash = ?, is_pinned = CASE WHEN ? = 1 THEN is_pinned ELSE 0 END, pinned_at = CASE WHEN ? = 1 THEN pinned_at ELSE NULL END, updated_at = NOW(), published_at = CASE WHEN ? = \'published\' THEN COALESCE(published_at, NOW()) WHEN ? = \'scheduled\' THEN NULL ELSE published_at END WHERE id = ?');
        $pinEligible = ($record['status'] === 'published' && $record['post_type'] === 'stream') ? 1 : 0;
        $stmt->execute([
            $record['author_id'],
            $record['title'],
            bms_slugify((string)$record['slug']),
            $record['status'],
            $record['post_type'],
            $record['description'],
            $record['content_body'],
            $record['content_front_matter'],
            $record['content_source'],
            $record['storage_mode'],
            $record['category'],
            $record['category_slug'],
            $record['markdown_path'],
            $record['html_path'],
            $record['date_published'],
            ($record['scheduled_at'] !== '' ? $record['scheduled_at'] : null),
            $record['content_hash'],
            $pinEligible,
            $pinEligible,
            $record['status'],
            $record['status'],
            (int)$existing['id'],
        ]);
        $postId = (int)$existing['id'];
    } else {
        $stmt = $pdo->prepare('INSERT INTO ' . bms_table('posts') . ' (author_id, title, slug, status, post_type, description, content_body, content_front_matter, content_source, storage_mode, category, category_slug, markdown_path, html_path, date_published, scheduled_at, is_pinned, pinned_at, content_hash, created_at, updated_at, published_at) VALUES (:author_id, :title, :slug, :status, :post_type, :description, :content_body, :content_front_matter, :content_source, :storage_mode, :category, :category_slug, :markdown_path, :html_path, :date_published, :scheduled_at, 0, NULL, :content_hash, NOW(), NOW(), :published_at)');
        $stmt->execute([
            'author_id' => $record['author_id'], 'title' => $record['title'], 'slug' => bms_slugify((string)$record['slug']), 'status' => $record['status'], 'post_type' => $record['post_type'], 'description' => $record['description'],
            'content_body' => $record['content_body'], 'content_front_matter' => $record['content_front_matter'], 'content_source' => $record['content_source'], 'storage_mode' => $record['storage_mode'],
            'category' => $record['category'], 'category_slug' => $record['category_slug'], 'markdown_path' => $record['markdown_path'], 'html_path' => $record['html_path'], 'date_published' => $record['date_published'], 'scheduled_at' => ($record['scheduled_at'] !== '' ? $record['scheduled_at'] : null), 'content_hash' => $record['content_hash'], 'published_at' => $record['status'] === 'published' ? gmdate('Y-m-d H:i:s') : null,
        ]);
        $postId = (int)$pdo->lastInsertId();
    }
    bms_sync_post_terms($postId, $page);
    return $postId;
}

function bms_import_markdown_content_to_database(bool $force = false): int
{
    if (!bms_database_content_enabled() || !bms_database_content_columns_ready()) {
        return 0;
    }
    if (!$force && (string)bms_setting('database_first_import_complete', '0') === '1') {
        return 0;
    }
    if (!function_exists('bms_list_import_markdown_files')) {
        return 0;
    }
    $count = 0;
    foreach (['drafts', 'published', 'pages/drafts', 'pages/published'] as $section) {
        foreach (bms_list_import_markdown_files($section) as $page) {
            $filename = (string)($page['filename'] ?? ((string)($page['slug'] ?? 'content') . '.md'));
            bms_upsert_database_content($page, $section, $filename, null);
            $count++;
        }
    }
    bms_set_setting('database_first_import_complete', '1');
    bms_set_setting('content_storage_mode', 'database');
    return $count;
}


function bms_database_content_filename_for_page(array $page): string
{
    $slug = bms_slugify((string)($page['slug'] ?? ''));
    if ($slug === '') {
        $slug = bms_slugify((string)($page['title'] ?? 'content')) ?: 'content-' . date('Ymd-His');
    }
    return $slug . '.md';
}

function bms_database_content_page_for_status(array $page, string $status, string $postType = 'stream'): array
{
    $status = in_array($status, ['draft', 'published', 'scheduled'], true) ? $status : 'draft';
    $postType = $postType === 'page' ? 'page' : 'stream';
    $frontMatter = is_array($page['front_matter'] ?? null) ? $page['front_matter'] : [];
    $raw = bms_build_markdown_document([
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
        'scheduled_at' => $status === 'scheduled' ? (string)($page['scheduled_at'] ?? $frontMatter['scheduled_at'] ?? '') : '',
        'seo_title' => (string)($page['seo_title'] ?? $frontMatter['seo_title'] ?? ''),
        'robots' => (string)($page['robots'] ?? $frontMatter['robots'] ?? ''),
        'link_preview_url' => (string)($page['link_preview_url'] ?? $frontMatter['link_preview_url'] ?? ''),
        'link_preview_title' => (string)($page['link_preview_title'] ?? $frontMatter['link_preview_title'] ?? ''),
        'link_preview_description' => (string)($page['link_preview_description'] ?? $frontMatter['link_preview_description'] ?? ''),
        'link_preview_image' => (string)($page['link_preview_image'] ?? $frontMatter['link_preview_image'] ?? ''),
        'link_preview_site_name' => (string)($page['link_preview_site_name'] ?? $frontMatter['link_preview_site_name'] ?? ''),
    ], (string)($page['body'] ?? ''));
    $updated = bms_parse_markdown_string($raw);
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

function bms_delete_database_content_record(string $section, string $filename): void
{
    if (!bms_is_installed()) {
        return;
    }
    bms_delete_post_metadata_by_filename($section, $filename);
}

function bms_revision_page_from_row(array $revision): array
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

function bms_record_revision_from_page(array $page, string $status = 'published', string $originalFilename = '', ?int $authorId = null): void
{
    if (!bms_is_installed()) {
        return;
    }
    $status = in_array($status, ['draft', 'published'], true) ? $status : 'published';
    $slug = bms_slugify((string)($page['slug'] ?? ''));
    if ($slug === '') {
        return;
    }
    $post = bms_find_post_by_slug_status($slug, $status) ?: bms_find_post_by_slug_status($slug, 'published') ?: bms_find_post_by_slug_status($slug, 'draft');
    $frontMatter = bms_content_front_matter_for_database($page);
    $frontMatterJson = json_encode($frontMatter, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $body = (string)($page['body'] ?? '');
    $hash = hash('sha256', $body . "\n" . ($frontMatterJson ?: '{}'));
    $virtualPath = 'content/revisions/' . $slug . '/' . date('Ymd-His') . '-' . $status . '.md';
    try {
        $stmt = bms_db()->prepare('INSERT INTO ' . bms_table('revisions') . ' (post_id, slug, title, status, original_filename, markdown_path, content_body, content_front_matter, content_source, content_hash, author_id, created_at) VALUES (:post_id, :slug, :title, :status, :original_filename, :markdown_path, :content_body, :content_front_matter, :content_source, :content_hash, :author_id, NOW())');
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
            'author_id' => $authorId ?? bms_current_user_id(),
        ]);
    } catch (Throwable $e) {
        try {
            $stmt = bms_db()->prepare('INSERT INTO ' . bms_table('revisions') . ' (post_id, slug, title, status, original_filename, markdown_path, content_hash, author_id, created_at) VALUES (:post_id, :slug, :title, :status, :original_filename, :markdown_path, :content_hash, :author_id, NOW())');
            $stmt->execute([
                'post_id' => $post['id'] ?? null,
                'slug' => $slug,
                'title' => (string)($page['title'] ?? 'Untitled'),
                'status' => $status,
                'original_filename' => basename($originalFilename),
                'markdown_path' => $virtualPath,
                'content_hash' => $hash,
                'author_id' => $authorId ?? bms_current_user_id(),
            ]);
        } catch (Throwable $ignored) {
            // Revision recording should never block a content save.
        }
    }
}

function bms_find_post_by_slug_status(string $slug, string $status, string $postType = 'stream'): ?array
{
    if (!bms_is_installed()) {
        return null;
    }
    $postType = $postType === 'page' ? 'page' : 'stream';
    $stmt = bms_db()->prepare('SELECT * FROM ' . bms_table('posts') . ' WHERE slug = :slug AND status = :status AND post_type = :post_type LIMIT 1');
    $stmt->execute(['slug' => bms_slugify($slug), 'status' => $status, 'post_type' => $postType]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function bms_sync_stream_metadata(array $page, string $section, string $filename, ?int $authorId = null): void
{
    if (!bms_is_installed()) {
        return;
    }

    if (function_exists('bms_upsert_database_content') && bms_database_content_columns_ready()) {
        bms_upsert_database_content($page, $section, $filename, $authorId);
        return;
    }

    $status = $section === 'published' ? 'published' : 'draft';
    $markdownPath = 'content/' . $section . '/' . basename($filename);
    $htmlPath = $status === 'published' ? trim(bms_stream_relative_directory_for_post($page), '/') . '/index.html' : null;
    $contentHash = hash('sha256', (string)($page['raw'] ?? ''));

    $pdo = bms_db();
    $existing = bms_find_post_by_slug_status((string)$page['slug'], $status);

    if ($authorId === null && $existing) {
        $existingAuthorId = (int)($existing['author_id'] ?? 0);
        $authorId = $existingAuthorId > 0 ? $existingAuthorId : null;
    }

    if ($authorId === null) {
        $pathAuthorId = bms_content_author_id_for_file($section, $filename);
        $authorId = $pathAuthorId !== null ? $pathAuthorId : null;
    }

    if ($authorId === null && !$existing) {
        $authorId = bms_current_user_id();
    }

    if ($existing) {
        $stmt = $pdo->prepare('UPDATE ' . bms_table('posts') . ' SET author_id = COALESCE(:author_id, author_id), title = :title, post_type = :post_type, description = :description, category = :category, category_slug = :category_slug, markdown_path = :markdown_path, html_path = :html_path, date_published = :date_published, content_hash = :content_hash, updated_at = NOW(), published_at = CASE WHEN :published_status = \'published\' THEN COALESCE(published_at, NOW()) ELSE published_at END WHERE id = :id');
        $stmt->execute([
            'author_id' => $authorId,
            'title' => (string)$page['title'],
            'post_type' => 'stream',
            'description' => (string)($page['description'] ?? ''),
            'category' => (string)($page['category'] ?? 'Uncategorized'),
            'category_slug' => (string)($page['category_slug'] ?? bms_term_slug((string)($page['category'] ?? 'Uncategorized'))),
            'markdown_path' => $markdownPath,
            'html_path' => $htmlPath,
            'date_published' => (string)($page['date'] ?? date('Y-m-d')),
            'content_hash' => $contentHash,
            'published_status' => $status,
            'id' => (int)$existing['id'],
        ]);
        $postId = (int)$existing['id'];
    } else {
        $stmt = $pdo->prepare('INSERT INTO ' . bms_table('posts') . ' (author_id, title, slug, status, post_type, description, category, category_slug, markdown_path, html_path, date_published, content_hash, created_at, updated_at, published_at) VALUES (:author_id, :title, :slug, :status, :post_type, :description, :category, :category_slug, :markdown_path, :html_path, :date_published, :content_hash, NOW(), NOW(), :published_at)');
        $stmt->execute([
            'author_id' => $authorId,
            'title' => (string)$page['title'],
            'slug' => (string)$page['slug'],
            'status' => $status,
            'post_type' => 'stream',
            'description' => (string)($page['description'] ?? ''),
            'category' => (string)($page['category'] ?? 'Uncategorized'),
            'category_slug' => (string)($page['category_slug'] ?? bms_term_slug((string)($page['category'] ?? 'Uncategorized'))),
            'markdown_path' => $markdownPath,
            'html_path' => $htmlPath,
            'date_published' => (string)($page['date'] ?? date('Y-m-d')),
            'content_hash' => $contentHash,
            'published_at' => $status === 'published' ? gmdate('Y-m-d H:i:s') : null,
        ]);
        $postId = (int)$pdo->lastInsertId();
    }

    bms_sync_post_terms($postId, $page);
}


function bms_sync_page_metadata(array $page, string $section, string $filename, ?int $authorId = null): void
{
    if (!bms_is_installed()) {
        return;
    }

    if (function_exists('bms_upsert_database_content') && bms_database_content_columns_ready()) {
        bms_upsert_database_content($page, $section, $filename, $authorId);
        return;
    }

    $status = str_contains($section, 'published') ? 'published' : 'draft';
    $sectionPath = $status === 'published' ? 'pages/published' : 'pages/drafts';
    $markdownPath = 'content/' . $sectionPath . '/' . basename($filename);
    $htmlPath = $status === 'published' ? trim(bms_page_relative_directory_for_page($page), '/') . '/index.html' : null;
    $contentHash = hash('sha256', (string)($page['raw'] ?? ''));

    $pdo = bms_db();
    $stmt = $pdo->prepare('SELECT * FROM ' . bms_table('posts') . ' WHERE markdown_path = :markdown_path AND status = :status AND post_type = :post_type LIMIT 1');
    $stmt->execute(['markdown_path' => $markdownPath, 'status' => $status, 'post_type' => 'page']);
    $existing = $stmt->fetch();
    $existing = is_array($existing) ? $existing : null;

    if ($authorId === null && $existing) {
        $existingAuthorId = (int)($existing['author_id'] ?? 0);
        $authorId = $existingAuthorId > 0 ? $existingAuthorId : null;
    }

    if ($authorId === null) {
        $pathAuthorId = bms_content_author_id_for_file($sectionPath, $filename);
        $authorId = $pathAuthorId !== null ? $pathAuthorId : null;
    }

    if ($authorId === null && !$existing) {
        $authorId = bms_current_user_id();
    }

    if ($existing) {
        $stmt = $pdo->prepare('UPDATE ' . bms_table('posts') . ' SET author_id = COALESCE(:author_id, author_id), title = :title, slug = :slug, post_type = :post_type, description = :description, category = :category, category_slug = :category_slug, markdown_path = :markdown_path, html_path = :html_path, date_published = :date_published, content_hash = :content_hash, updated_at = NOW(), published_at = CASE WHEN :published_status = \'published\' THEN COALESCE(published_at, NOW()) ELSE published_at END WHERE id = :id');
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
        $stmt = $pdo->prepare('INSERT INTO ' . bms_table('posts') . ' (author_id, title, slug, status, post_type, description, category, category_slug, markdown_path, html_path, date_published, content_hash, created_at, updated_at, published_at) VALUES (:author_id, :title, :slug, :status, :post_type, :description, :category, :category_slug, :markdown_path, :html_path, :date_published, :content_hash, NOW(), NOW(), :published_at)');
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
            'published_at' => $status === 'published' ? gmdate('Y-m-d H:i:s') : null,
        ]);
        $postId = (int)$pdo->lastInsertId();
    }

    bms_sync_post_terms($postId, array_replace($page, ['category' => 'Page', 'tags' => []]));
}

function bms_sync_post_terms(int $postId, array $page): void
{
    $pdo = bms_db();
    $pdo->prepare('DELETE FROM ' . bms_table('post_terms') . ' WHERE post_id = :post_id')->execute(['post_id' => $postId]);

    $category = trim((string)($page['category'] ?? 'Uncategorized')) ?: 'Uncategorized';
    $categoryId = bms_get_or_create_term('category', $category);
    $stmt = $pdo->prepare('INSERT IGNORE INTO ' . bms_table('post_terms') . ' (post_id, term_id, is_primary) VALUES (:post_id, :term_id, 1)');
    $stmt->execute(['post_id' => $postId, 'term_id' => $categoryId]);

    foreach (($page['tags'] ?? []) as $tag) {
        $tag = trim((string)$tag);
        if ($tag === '') {
            continue;
        }
        $tagId = bms_get_or_create_term('tag', $tag);
        $stmt = $pdo->prepare('INSERT IGNORE INTO ' . bms_table('post_terms') . ' (post_id, term_id, is_primary) VALUES (:post_id, :term_id, 0)');
        $stmt->execute(['post_id' => $postId, 'term_id' => $tagId]);
    }
}

function bms_get_or_create_term(string $type, string $name): int
{
    $pdo = bms_db();
    $slug = bms_term_slug($name);
    $stmt = $pdo->prepare('SELECT id FROM ' . bms_table('terms') . ' WHERE term_type = :term_type AND slug = :slug LIMIT 1');
    $stmt->execute(['term_type' => $type, 'slug' => $slug]);
    $id = $stmt->fetchColumn();
    if ($id !== false) {
        return (int)$id;
    }

    $stmt = $pdo->prepare('INSERT INTO ' . bms_table('terms') . ' (term_type, name, slug, created_at, updated_at) VALUES (:term_type, :name, :slug, NOW(), NOW())');
    $stmt->execute(['term_type' => $type, 'name' => $name, 'slug' => $slug]);
    return (int)$pdo->lastInsertId();
}

function bms_delete_post_metadata(string $slug, string $status): void
{
    if (!bms_is_installed()) {
        return;
    }
    $post = bms_find_post_by_slug_status($slug, $status);
    if (!$post) {
        return;
    }
    $pdo = bms_db();
    $pdo->prepare('DELETE FROM ' . bms_table('post_terms') . ' WHERE post_id = :post_id')->execute(['post_id' => (int)$post['id']]);
    $pdo->prepare('DELETE FROM ' . bms_table('posts') . ' WHERE id = :id')->execute(['id' => (int)$post['id']]);
}

function bms_delete_post_metadata_by_filename(string $section, string $filename): void
{
    if (!bms_is_installed()) {
        return;
    }
    $status = trim($section, '/') === 'scheduled' ? 'scheduled' : (str_contains($section, 'published') ? 'published' : 'draft');
    $path = 'content/' . trim($section, '/') . '/' . basename($filename);
    $stmt = bms_db()->prepare('SELECT id FROM ' . bms_table('posts') . ' WHERE markdown_path = :markdown_path AND status = :status LIMIT 1');
    $stmt->execute(['markdown_path' => $path, 'status' => $status]);
    $id = $stmt->fetchColumn();
    if ($id === false) {
        return;
    }
    bms_db()->prepare('DELETE FROM ' . bms_table('post_terms') . ' WHERE post_id = :post_id')->execute(['post_id' => (int)$id]);
    bms_db()->prepare('DELETE FROM ' . bms_table('posts') . ' WHERE id = :id')->execute(['id' => (int)$id]);
}

function bms_list_revisions(?string $slug = null, int $limit = 100): array
{
    if (!bms_is_installed()) {
        return [];
    }
    $limit = max(1, min(250, $limit));
    $sql = 'SELECT r.*, u.display_name AS author_name FROM ' . bms_table('revisions') . ' r LEFT JOIN ' . bms_table('users') . ' u ON u.id = r.author_id';
    $params = [];
    if ($slug !== null && trim($slug) !== '') {
        $sql .= ' WHERE r.slug = :slug';
        $params['slug'] = bms_slugify($slug);
    }
    $sql .= ' ORDER BY r.created_at DESC, r.id DESC LIMIT ' . $limit;
    $stmt = bms_db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll() ?: [];
}

function bms_revision_count_for_slug(string $slug): int
{
    if (!bms_is_installed()) {
        return 0;
    }
    $stmt = bms_db()->prepare('SELECT COUNT(*) FROM ' . bms_table('revisions') . ' WHERE slug = :slug');
    $stmt->execute(['slug' => bms_slugify($slug)]);
    return (int)$stmt->fetchColumn();
}

function bms_get_revision(int $id): ?array
{
    if (!bms_is_installed()) {
        return null;
    }
    $stmt = bms_db()->prepare('SELECT r.*, u.display_name AS author_name FROM ' . bms_table('revisions') . ' r LEFT JOIN ' . bms_table('users') . ' u ON u.id = r.author_id WHERE r.id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function bms_restore_revision_as_draft(int $id): array
{
    $revision = bms_get_revision($id);
    if (!$revision) {
        throw new RuntimeException('Revision not found.');
    }
    $page = bms_revision_page_from_row($revision);
    $slug = bms_slugify((string)($page['slug'] ?? $revision['slug'] ?? 'restored-revision'));
    $baseSlug = $slug !== '' ? $slug : 'restored-revision';
    $slug = $baseSlug;
    $i = 1;
    while (bms_find_database_content_by_slug_status($slug, 'draft', 'stream') || bms_find_database_content_by_slug_status($slug, 'published', 'stream')) {
        $slug = $baseSlug . '-revision-' . date('Ymd-His') . ($i > 1 ? '-' . $i : '');
        $i++;
    }

    $page['slug'] = $slug;
    $restored = bms_database_content_page_for_status($page, 'draft', 'stream');
    $filename = bms_database_content_filename_for_page($restored);
    bms_sync_stream_metadata($restored, 'drafts', $filename, bms_revision_original_author_id($revision));
    return $restored + ['filename' => $filename];
}

function bms_record_trashed_content(array $page, string $originalStatus, string $originalFilename, string $trashFilename, string $trashPath = ''): void
{
    if (!bms_is_installed()) {
        return;
    }
    $normalizedStatus = $originalStatus === 'published' ? 'published' : 'draft';
    $originalSection = $normalizedStatus === 'published' ? 'published' : 'drafts';
    $originalAuthorId = bms_content_author_id_for_file($originalSection, $originalFilename);
    if ($originalAuthorId === null && (int)($page['author_id'] ?? 0) > 0) {
        $originalAuthorId = (int)$page['author_id'];
    }
    $frontMatter = bms_content_front_matter_for_database($page);
    $frontMatterJson = json_encode($frontMatter, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $body = (string)($page['body'] ?? '');
    $hash = hash('sha256', $body . "\n" . ($frontMatterJson ?: '{}'));
    $virtualPath = trim($trashPath) !== ''
        ? str_replace(rtrim(bms_root_path(), '/\\') . '/', '', $trashPath)
        : 'content/trash/' . basename($trashFilename ?: (date('Ymd-His') . '-' . $normalizedStatus . '-' . bms_database_content_filename_for_page($page)));

    try {
        $stmt = bms_db()->prepare('INSERT INTO ' . bms_table('trash') . ' (title, slug, original_status, original_filename, trash_filename, markdown_path, post_type, content_body, content_front_matter, content_source, content_hash, original_author_id, deleted_by, deleted_at) VALUES (:title, :slug, :original_status, :original_filename, :trash_filename, :markdown_path, :post_type, :content_body, :content_front_matter, :content_source, :content_hash, :original_author_id, :deleted_by, NOW())');
        $stmt->execute([
            'title' => (string)($page['title'] ?? 'Untitled'),
            'slug' => (string)($page['slug'] ?? bms_slugify((string)($page['title'] ?? 'untitled'))),
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
            'deleted_by' => bms_current_user_id(),
        ]);
    } catch (Throwable $e) {
        $stmt = bms_db()->prepare('INSERT INTO ' . bms_table('trash') . ' (title, slug, original_status, original_filename, trash_filename, markdown_path, content_hash, original_author_id, deleted_by, deleted_at) VALUES (:title, :slug, :original_status, :original_filename, :trash_filename, :markdown_path, :content_hash, :original_author_id, :deleted_by, NOW())');
        $stmt->execute([
            'title' => (string)($page['title'] ?? 'Untitled'),
            'slug' => (string)($page['slug'] ?? bms_slugify((string)($page['title'] ?? 'untitled'))),
            'original_status' => $normalizedStatus,
            'original_filename' => basename($originalFilename),
            'trash_filename' => basename($trashFilename),
            'markdown_path' => $virtualPath,
            'content_hash' => $hash,
            'original_author_id' => $originalAuthorId,
            'deleted_by' => bms_current_user_id(),
        ]);
    }
}

function bms_trash_row_to_content_page(array $row, string $postType = 'stream'): array
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

function bms_list_trash_items(): array
{
    if (!bms_is_installed()) {
        return [];
    }
    try {
        $stmt = bms_db()->query('SELECT t.*, u.display_name AS deleted_by_name FROM ' . bms_table('trash') . ' t LEFT JOIN ' . bms_table('users') . ' u ON u.id = t.deleted_by WHERE t.original_status NOT IN (\'page_draft\', \'page_published\') ORDER BY t.deleted_at DESC, t.id DESC');
        $rows = $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }

    $items = [];
    foreach ($rows as $row) {
        $parsed = bms_trash_row_to_content_page($row, 'stream');
        $items[] = array_replace($parsed, [
            'trash_id' => (int)$row['id'],
            'title' => (string)($parsed['title'] ?? $row['title'] ?? 'Untitled'),
            'slug' => (string)($parsed['slug'] ?? $row['slug'] ?? ''),
            'filename' => (string)$row['trash_filename'],
            'original_filename' => (string)$row['original_filename'],
            'original_status' => (string)$row['original_status'],
            'content_status' => 'trash',
            'section' => 'trash',
            'path' => bms_root_path((string)($row['markdown_path'] ?? '')),
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

function bms_get_trash_item(int $id): ?array
{
    if (!bms_is_installed()) {
        return null;
    }
    $stmt = bms_db()->prepare('SELECT * FROM ' . bms_table('trash') . ' WHERE id = :id AND original_status NOT IN (\'page_draft\', \'page_published\') LIMIT 1');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function bms_restore_trash_item(int $id): array
{
    $item = bms_get_trash_item($id);
    if (!$item) {
        throw new RuntimeException('Trash item not found.');
    }
    $page = bms_trash_row_to_content_page($item, 'stream');
    $originalStatus = (string)($item['original_status'] ?? 'draft');
    $status = in_array($originalStatus, ['published', 'scheduled'], true) ? $originalStatus : 'draft';
    $section = $status === 'published' ? 'published' : ($status === 'scheduled' ? 'scheduled' : 'drafts');
    $restored = bms_database_content_page_for_status($page, $status, 'stream');
    $filename = basename((string)($item['original_filename'] ?: bms_database_content_filename_for_page($restored)));
    $slug = bms_slugify((string)($restored['slug'] ?? pathinfo($filename, PATHINFO_FILENAME)));
    if (bms_find_database_content_by_slug_status($slug, $status, 'stream')) {
        throw new RuntimeException('A ' . ($status === 'published' ? 'published stream post' : ($status === 'scheduled' ? 'scheduled stream post' : 'draft')) . ' already uses this slug. Rename or remove it first.');
    }
    bms_db()->prepare('DELETE FROM ' . bms_table('trash') . ' WHERE id = :id')->execute(['id' => $id]);
    $originalAuthorId = (int)($item['original_author_id'] ?? 0);
    bms_sync_stream_metadata($restored, $section, $filename, $originalAuthorId > 0 ? $originalAuthorId : null);
    return $restored + ['filename' => $filename, 'restored_status' => $status];
}

function bms_delete_trash_item_permanently(int $id): ?array
{
    $item = bms_get_trash_item($id);
    if (!$item) {
        return null;
    }
    $path = bms_root_path((string)($item['markdown_path'] ?? ''));
    if (is_file($path)) {
        @unlink($path);
    }
    bms_db()->prepare('DELETE FROM ' . bms_table('trash') . ' WHERE id = :id')->execute(['id' => $id]);
    return $item;
}

function bms_empty_trash(): int
{
    $items = bms_list_trash_items();
    $count = 0;
    foreach ($items as $item) {
        $id = (int)($item['trash_id'] ?? 0);
        if ($id > 0) {
            bms_delete_trash_item_permanently($id);
            $count++;
        }
    }
    return $count;
}

function bms_sync_all_content_metadata(): void
{
    if (!bms_is_installed()) {
        return;
    }
    foreach (['drafts', 'scheduled', 'published'] as $section) {
        foreach (bms_list_content_records($section) as $page) {
            bms_sync_stream_metadata($page, $section, (string)$page['filename']);
        }
    }
    foreach (['pages/drafts', 'pages/published'] as $section) {
        foreach (bms_list_content_records($section) as $page) {
            bms_sync_page_metadata($page, $section, (string)$page['filename']);
        }
    }
}

function bms_find_post_by_markdown_path(string $section, string $filename): ?array
{
    if (!bms_is_installed()) {
        return null;
    }
    $section = trim(str_replace('\\', '/', $section), '/');
    if (!in_array($section, ['published', 'drafts', 'scheduled', 'pages/published', 'pages/drafts'], true)) {
        $section = $section === 'published' ? 'published' : 'drafts';
    }
    $status = trim($section, '/') === 'scheduled' ? 'scheduled' : (str_contains($section, 'published') ? 'published' : 'draft');
    $path = 'content/' . $section . '/' . basename($filename);
    $stmt = bms_db()->prepare('SELECT * FROM ' . bms_table('posts') . ' WHERE markdown_path = :markdown_path AND status = :status LIMIT 1');
    $stmt->execute(['markdown_path' => $path, 'status' => $status]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function bms_content_author_id_for_file(string $section, string $filename): ?int
{
    try {
        $post = bms_find_post_by_markdown_path($section, $filename);
    } catch (Throwable $e) {
        return null;
    }
    $authorId = (int)($post['author_id'] ?? 0);
    return $authorId > 0 ? $authorId : null;
}

function bms_post_author_id_by_id(int $postId): ?int
{
    if ($postId < 1 || !bms_is_installed()) {
        return null;
    }
    try {
        $stmt = bms_db()->prepare('SELECT author_id FROM ' . bms_table('posts') . ' WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $postId]);
        $authorId = (int)$stmt->fetchColumn();
        return $authorId > 0 ? $authorId : null;
    } catch (Throwable $e) {
        return null;
    }
}

function bms_revision_original_author_id(array $revision): ?int
{
    $postAuthorId = bms_post_author_id_by_id((int)($revision['post_id'] ?? 0));
    if ($postAuthorId !== null) {
        return $postAuthorId;
    }
    $revisionAuthorId = (int)($revision['author_id'] ?? 0);
    return $revisionAuthorId > 0 ? $revisionAuthorId : null;
}

function bms_content_subject_for_file(string $section, string $filename, array $page = []): array
{
    $section = trim(str_replace('\\', '/', $section), '/');
    if (!in_array($section, ['published', 'drafts', 'scheduled', 'pages/published', 'pages/drafts'], true)) {
        $section = $section === 'published' ? 'published' : 'drafts';
    }
    $post = null;
    try {
        $post = bms_find_post_by_markdown_path($section, $filename);
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



function bms_save_autosave(string $draftKey, array $fields, string $markdown): void
{
    if (!bms_is_installed()) {
        return;
    }
    $draftKey = substr(hash('sha256', trim($draftKey) !== '' ? $draftKey : 'editor'), 0, 64);
    $userId = bms_current_user_id();
    $title = trim((string)($fields['title'] ?? ''));
    $slug = bms_slugify((string)($fields['slug'] ?? $title ?: 'autosave'));
    $section = (string)($fields['section'] ?? 'drafts');
    $section = $section === 'published' ? 'published' : 'drafts';
    $filename = basename((string)($fields['filename'] ?? ''));
    $fieldsJson = json_encode($fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $sql = 'INSERT INTO ' . bms_table('autosaves') . ' (user_id, draft_key, title, slug, section, filename, markdown, fields_json, created_at, updated_at) '
        . 'VALUES (:user_id, :draft_key, :title, :slug, :section, :filename, :markdown, :fields_json, NOW(), NOW()) '
        . 'ON DUPLICATE KEY UPDATE title = VALUES(title), slug = VALUES(slug), section = VALUES(section), filename = VALUES(filename), markdown = VALUES(markdown), fields_json = VALUES(fields_json), updated_at = NOW()';
    $stmt = bms_db()->prepare($sql);
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

function bms_get_autosave(string $draftKey): ?array
{
    if (!bms_is_installed()) {
        return null;
    }
    $draftKey = substr(hash('sha256', trim($draftKey) !== '' ? $draftKey : 'editor'), 0, 64);
    $stmt = bms_db()->prepare('SELECT * FROM ' . bms_table('autosaves') . ' WHERE draft_key = :draft_key AND user_id = :user_id LIMIT 1');
    $stmt->execute(['draft_key' => $draftKey, 'user_id' => bms_current_user_id()]);
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

function bms_delete_autosave(string $draftKey): void
{
    if (!bms_is_installed()) {
        return;
    }
    $draftKey = substr(hash('sha256', trim($draftKey) !== '' ? $draftKey : 'editor'), 0, 64);
    $stmt = bms_db()->prepare('DELETE FROM ' . bms_table('autosaves') . ' WHERE draft_key = :draft_key AND user_id = :user_id');
    $stmt->execute(['draft_key' => $draftKey, 'user_id' => bms_current_user_id()]);
}

function bms_restore_revision_over_current(int $id): array
{
    $revision = bms_get_revision($id);
    if (!$revision) {
        throw new RuntimeException('Revision not found.');
    }
    $revisionPage = bms_revision_page_from_row($revision);
    $slug = bms_slugify((string)($revision['slug'] ?? $revisionPage['slug'] ?? 'restored-revision'));
    $targetStatus = (string)($revision['status'] ?? '') === 'published' ? 'published' : 'draft';
    $section = $targetStatus === 'published' ? 'published' : 'drafts';
    $filename = basename((string)($revision['original_filename'] ?: ($slug . '.md')));
    $currentPage = bms_find_database_content_by_markdown_path($section, $filename) ?: bms_find_database_content_by_slug_status($slug, $targetStatus, 'stream');
    $targetAuthorId = null;
    if ($currentPage) {
        $targetAuthorId = (int)($currentPage['author_id'] ?? 0) ?: null;
        if (function_exists('bms_require_content_file_access')) {
            bms_require_content_file_access($section, $filename, 'edit_content', $currentPage);
        }
        if (function_exists('bms_record_revision_from_page')) {
            bms_record_revision_from_page($currentPage, $targetStatus, $filename, $targetAuthorId);
        }
    }
    $restored = bms_database_content_page_for_status(array_replace($revisionPage, ['slug' => $slug]), $targetStatus, 'stream');
    $filename = bms_database_content_filename_for_page($restored);
    bms_sync_stream_metadata($restored, $section, $filename, $targetAuthorId ?? bms_revision_original_author_id($revision));
    if ($section === 'published') {
    }
    return $restored + ['filename' => $filename, 'restored_status' => $targetStatus];
}

// Config is only a bootstrap fallback after installation. The persisted General
// Settings timezone is the runtime source of truth for every normal request.
if (function_exists('bms_apply_site_timezone')) {
    try {
        bms_apply_site_timezone();
    } catch (Throwable $e) {
        // Keep config.php's timezone fallback if settings are unavailable during setup or repair.
    }
}


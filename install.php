<?php
require_once __DIR__ . '/_bonumark_stream/app/database.php';

mp_start_secure_session();
mp_send_security_headers();

if (mp_is_installed()) {
    mp_redirect(mp_admin_url('login.php'));
}

function bm_install_token(): string
{
    if (empty($_SESSION['bm_install_token'])) {
        $_SESSION['bm_install_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['bm_install_token'];
}

function bm_verify_install_token(): void
{
    $token = (string)($_POST['install_token'] ?? '');
    if ($token === '' || !hash_equals((string)($_SESSION['bm_install_token'] ?? ''), $token)) {
        bm_installer_error_page('Invalid installer request', 'The installer request token was missing or invalid.', 403);
    }
}

function bm_install_url(string $step = ''): string
{
    return mp_url_path('install.php' . ($step ? '?step=' . urlencode($step) : ''));
}


function bm_detect_base_path_from_request(): string
{
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $dir = rtrim(dirname($script), '/\\');
    if ($dir === '.' || $dir === '/') {
        return '';
    }
    return '/' . trim($dir, '/');
}

function bm_detect_base_url_from_request(): string
{
    return rtrim(mp_install_base_url_from_request(), '/');
}

function bm_valid_timezone(string $timezone): bool
{
    return in_array($timezone, DateTimeZone::listIdentifiers(), true);
}

function bm_timezone_select(string $name, string $label, string $selected = '', string $help = ''): void
{
    $id = 'field_' . preg_replace('/[^a-z0-9_]+/i', '_', $name);
    $zones = DateTimeZone::listIdentifiers();
    sort($zones, SORT_STRING);
    $selected = $selected !== '' && bm_valid_timezone($selected) ? $selected : 'UTC';

    echo '<label for="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</label>';
    echo '<select id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '" name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '" required>';

    $currentGroup = null;
    foreach ($zones as $zone) {
        $parts = explode('/', $zone, 2);
        $group = count($parts) === 2 ? $parts[0] : 'General';
        if ($group !== $currentGroup) {
            if ($currentGroup !== null) {
                echo '</optgroup>';
            }
            $currentGroup = $group;
            echo '<optgroup label="' . htmlspecialchars($group, ENT_QUOTES, 'UTF-8') . '">';
        }
        echo '<option value="' . htmlspecialchars($zone, ENT_QUOTES, 'UTF-8') . '"' . ($zone === $selected ? ' selected' : '') . '>' . htmlspecialchars($zone, ENT_QUOTES, 'UTF-8') . '</option>';
    }

    if ($currentGroup !== null) {
        echo '</optgroup>';
    }

    echo '</select>';
    if ($help !== '') {
        echo '<p class="field-help">' . htmlspecialchars($help, ENT_QUOTES, 'UTF-8') . '</p>';
    }
}

function bm_readonly_info(string $label, string $value, string $help = ''): void
{
    echo '<div class="readonly-field"><span>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span><strong>' . htmlspecialchars($value !== '' ? $value : 'Not detected', ENT_QUOTES, 'UTF-8') . '</strong></div>';
    if ($help !== '') {
        echo '<p class="field-help">' . htmlspecialchars($help, ENT_QUOTES, 'UTF-8') . '</p>';
    }
}

function bm_sanitize_prefix(string $prefix): string
{
    $prefix = preg_replace('/[^A-Za-z0-9_]/', '', $prefix) ?? '';
    return $prefix !== '' ? $prefix : 'bms_';
}

function bm_connect(array $db): PDO
{
    $host = (string)($db['host'] ?? 'localhost');
    $name = (string)($db['name'] ?? '');
    $user = (string)($db['user'] ?? '');
    $pass = (string)($db['password'] ?? '');
    $charset = (string)($db['charset'] ?? 'utf8mb4');
    $dsn = "mysql:host={$host};dbname={$name};charset={$charset}";
    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function bm_write_config(array $db, array $site): void
{
    $config = mp_default_config();
    $config['version'] = mp_version();
    $config['site_name'] = (string)$site['site_name'];
    $config['site_tagline'] = (string)$site['site_tagline'];
    $config['author_name'] = (string)$site['author_name'];
    $config['timezone'] = (string)$site['timezone'];
    $config['base_path'] = (string)$site['base_path'];
    $config['base_url'] = (string)$site['base_url'];
    $config['public_path'] = '';
    $config['homepage_mode'] = 'stream';
    $config['security_salt'] = bin2hex(random_bytes(32));
    $config['database'] = [
        'host' => (string)$db['host'],
        'name' => (string)$db['name'],
        'user' => (string)$db['user'],
        'password' => (string)$db['password'],
        'charset' => 'utf8mb4',
        'prefix' => bm_sanitize_prefix((string)$db['prefix']),
    ];

    $php = "<?php\nreturn " . var_export($config, true) . ";\n";
    mp_write_file(mp_config_path(), $php);
    @chmod(mp_config_path(), 0640);
    mp_config(true);
}

function bm_seed_database(PDO $pdo, string $prefix, array $site, array $admin): int
{
    $prefix = bm_sanitize_prefix($prefix);
    mp_install_schema($pdo, $prefix);

    $settings = [
        'site_name' => $site['site_name'],
        'site_tagline' => $site['site_tagline'],
        'author_name' => $admin['display_name'],
        'timezone' => $site['timezone'],
        'base_path' => $site['base_path'],
        'base_url' => $site['base_url'],
        'public_path' => '',
        'site_admin_email' => $admin['email'] ?? '',
        'default_editor_mode' => 'visual',
        'default_content_status' => 'draft',
        'homepage_mode' => 'stream',
        'active_public_theme' => 'default',
        'stream_composer_enabled' => '1',
        'stream_posts_per_page' => '20',
        'stream_show_dates' => '1',
        'stream_show_edit_links' => '0',
        'comments_enabled' => '1',
        'comment_registration_enabled' => '0',
        'comments_default_status' => 'approved',
        'registration_mode' => 'disabled',
        'registration_default_role' => 'commenter',
        'registration_require_email_verification' => '1',
        'registration_require_admin_approval' => '0',
        'registration_user_role_requires_approval' => '1',
        'registration_honeypot_enabled' => '1',
        'user_publish_mode' => 'draft_review',
        'media_limit_administrator_mb' => '32',
        'media_limit_user_mb' => '8',
        'media_limit_commenter_mb' => '2',
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
        'fresh_install_baseline' => mp_version(),
        'version' => mp_version(),
    ];

    $settingStmt = $pdo->prepare('INSERT INTO `' . $prefix . 'settings` (setting_key, setting_value, updated_at) VALUES (:setting_key, :setting_value, NOW()) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()');
    foreach ($settings as $key => $value) {
        $settingStmt->execute(['setting_key' => $key, 'setting_value' => (string)$value]);
    }

    $username = mp_normalize_username((string)$admin['username']);
    $email = trim((string)$admin['email']);
    $stmt = $pdo->prepare('INSERT INTO `' . $prefix . 'users` (username, display_name, email, email_verified_at, password_hash, role, status, created_at, updated_at) VALUES (:username, :display_name, :email, :email_verified_at, :password_hash, :role, :status, NOW(), NOW())');
    $stmt->execute([
        'username' => $username,
        'display_name' => trim((string)$admin['display_name']),
        'email' => $email,
        'email_verified_at' => date('Y-m-d H:i:s'),
        'password_hash' => password_hash((string)$admin['password'], PASSWORD_DEFAULT),
        'role' => 'administrator',
        'status' => 'active',
    ]);

    return (int)$pdo->lastInsertId();
}

function bm_installer_header(string $title): void
{
    mp_send_security_headers();
    $styleUrl = htmlspecialchars(mp_asset_url('assets/style.css'), ENT_QUOTES, 'UTF-8');
    $adminStyleUrl = htmlspecialchars(mp_asset_url('assets/admin.css'), ENT_QUOTES, 'UTF-8');
    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . $safeTitle . '</title><link rel="stylesheet" href="' . $styleUrl . '"><link rel="stylesheet" href="' . $adminStyleUrl . '"></head><body><main class="admin-wrap narrow install-wrap"><p class="eyebrow">Bonumark Stream install</p><h1>' . $safeTitle . '</h1>';
}

function bm_installer_footer(): void
{
    echo '<footer class="admin-footer">Bonumark Stream v' . htmlspecialchars(mp_version(), ENT_QUOTES, 'UTF-8') . '</footer></main></body></html>';
}


function bm_installer_error_page(string $title, string $message, int $status = 400): void
{
    http_response_code($status);
    bm_installer_header($title);
    echo '<section class="panel admin-error-panel"><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p><p><a class="button-link secondary" href="' . htmlspecialchars(bm_install_url(), ENT_QUOTES, 'UTF-8') . '">Return to installer</a></p></section>';
    bm_installer_footer();
    exit;
}

function bm_field(string $name, string $label, string $value = '', string $type = 'text', string $help = '', bool $required = true): void
{
    $id = 'field_' . preg_replace('/[^a-z0-9_]+/i', '_', $name);
    echo '<label for="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</label>';
    echo '<input id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '" type="' . htmlspecialchars($type, ENT_QUOTES, 'UTF-8') . '" name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '" ' . ($required ? 'required' : '') . '>';
    if ($help !== '') {
        echo '<p class="field-help">' . htmlspecialchars($help, ENT_QUOTES, 'UTF-8') . '</p>';
    }
}

$step = (string)($_GET['step'] ?? 'welcome');
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    bm_verify_install_token();

    if ($step === 'database') {
        $db = [
            'host' => trim((string)($_POST['db_host'] ?? 'localhost')),
            'name' => trim((string)($_POST['db_name'] ?? '')),
            'user' => trim((string)($_POST['db_user'] ?? '')),
            'password' => (string)($_POST['db_password'] ?? ''),
            'charset' => 'utf8mb4',
            'prefix' => bm_sanitize_prefix((string)($_POST['table_prefix'] ?? 'bms_')),
        ];

        try {
            if (!mp_db_supports_mysql()) {
                throw new RuntimeException('The PDO MySQL extension is not enabled on this server. Ask your host to enable pdo_mysql.');
            }
            mp_db_test_connection($db);
            $_SESSION['bm_install_db'] = $db;
            mp_redirect(bm_install_url('setup'));
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }

    if ($step === 'setup') {
        $db = $_SESSION['bm_install_db'] ?? null;
        if (!is_array($db)) {
            mp_redirect(bm_install_url('database'));
        }

        $site = [
            'site_name' => trim((string)($_POST['site_name'] ?? 'Bonumark Stream')),
            'site_tagline' => trim((string)($_POST['site_tagline'] ?? 'A self-hosted microblog stream for owning short-form publishing.')),
            'author_name' => trim((string)($_POST['display_name'] ?? 'Admin')),
            'timezone' => trim((string)($_POST['timezone'] ?? 'UTC')),
            'base_path' => bm_detect_base_path_from_request(),
            'base_url' => bm_detect_base_url_from_request(),
        ];
        $admin = [
            'username' => mp_normalize_username((string)($_POST['username'] ?? 'admin')),
            'display_name' => trim((string)($_POST['display_name'] ?? 'Admin')),
            'email' => trim((string)($_POST['email'] ?? '')),
            'password' => (string)($_POST['password'] ?? ''),
            'confirm_password' => (string)($_POST['confirm_password'] ?? ''),
        ];

        try {
            if ($site['site_name'] === '') {
                throw new RuntimeException('Site name is required.');
            }
            if ($site['timezone'] === '') {
                $site['timezone'] = 'UTC';
            }
            if (!bm_valid_timezone($site['timezone'])) {
                throw new RuntimeException('Choose a valid timezone.');
            }
            if ($admin['display_name'] === '') {
                throw new RuntimeException('Display name is required.');
            }
            if (strlen($admin['username']) < 3) {
                throw new RuntimeException('Username must be at least 3 characters.');
            }
            if ($admin['email'] !== '' && !filter_var($admin['email'], FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Enter a valid email address or leave it blank.');
            }
            mp_validate_password_policy($admin['password'], $admin['username'], $admin['email']);
            if ($admin['password'] !== $admin['confirm_password']) {
                throw new RuntimeException('Password and confirmation do not match.');
            }

            $baseUrlForProbe = $site['base_url'] !== '' ? $site['base_url'] : null;
            $probe = mp_probe_private_folder_exposure($baseUrlForProbe);
            if (($probe['status'] ?? '') === 'exposed') {
                throw new RuntimeException($probe['message']);
            }

            $pdo = bm_connect($db);
            bm_write_config($db, $site);
            $userId = bm_seed_database($pdo, (string)$db['prefix'], $site, $admin);

            foreach (['content/legacy-markdown', 'content/versions', 'backups/upgrades', 'tmp/upgrades', 'tmp/exports', 'tmp/static-site-exports', 'data'] as $dir) {
                $path = mp_root_path($dir);
                if (!is_dir($path)) {
                    mkdir($path, 0755, true);
                }
            }

            mp_write_file(mp_installed_lock_path(), "Installed: " . date('c') . "\nVersion: " . mp_version() . "\n");
            $_SESSION['mp_logged_in'] = true;
            $_SESSION['mp_user_id'] = $userId;
            unset($_SESSION['bm_install_db']);
            mp_redirect(mp_admin_url());
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

if ($step === 'welcome') {
    bm_installer_header('Welcome to Bonumark Stream');
    ?>
    <section class="panel">
      <p>Bonumark Stream needs a MySQL or MariaDB database before it can publish your stream. This setup follows the same basic flow most shared hosting users already know.</p>
      <ol>
        <li>Create a database and database user in your hosting control panel.</li>
        <li>Enter the database details.</li>
        <li>Create the site and first admin account.</li>
        <li>Start posting to your own stream.</li>
      </ol>
      <a class="button-link" href="<?= htmlspecialchars(bm_install_url('database'), ENT_QUOTES, 'UTF-8') ?>">Start setup</a>
    </section>
    <section class="panel">
      <h2>Server check</h2>
      <ul class="check-list">
        <li>PHP version: <?= htmlspecialchars(PHP_VERSION, ENT_QUOTES, 'UTF-8') ?> <?= version_compare(PHP_VERSION, '8.2.0', '>=') ? 'OK' : 'needs PHP 8.2+' ?></li>
        <li>PDO MySQL: <?= mp_db_supports_mysql() ? 'available' : 'not available' ?></li>
        <li>Config writable: <?= is_writable(mp_root_path()) ? 'yes' : 'no' ?></li>
        <?php $probe = mp_probe_private_folder_exposure(); ?>
        <li>Private folder exposure: <?= htmlspecialchars($probe['status'] . ' - ' . $probe['message'], ENT_QUOTES, 'UTF-8') ?></li>
      </ul>
    </section>
    <?php
    bm_installer_footer();
    exit;
}

if ($step === 'database') {
    $posted = $_POST ?: [];
    bm_installer_header('Database Connection');
    if ($error) {
        echo '<div class="flash error">' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</div>';
    }
    ?>
    <form method="post" class="panel settings-form">
      <input type="hidden" name="install_token" value="<?= htmlspecialchars(bm_install_token(), ENT_QUOTES, 'UTF-8') ?>">
      <?php bm_field('db_name', 'Database Name', (string)($posted['db_name'] ?? ''), 'text', 'The database you created in cPanel or your server panel.'); ?>
      <?php bm_field('db_user', 'Database Username', (string)($posted['db_user'] ?? ''), 'text', 'The database user that has privileges for this database.'); ?>
      <?php bm_field('db_password', 'Database Password', '', 'password', 'The password for the database user.', false); ?>
      <?php bm_field('db_host', 'Database Host', (string)($posted['db_host'] ?? 'localhost'), 'text', 'Usually localhost on shared hosting.'); ?>
      <?php bm_field('table_prefix', 'Table Prefix', (string)($posted['table_prefix'] ?? 'bms_'), 'text', 'Change this only if multiple Bonumark Stream installs share one database.'); ?>
      <button type="submit">Test database connection</button>
    </form>
    <?php
    bm_installer_footer();
    exit;
}

if ($step === 'setup') {
    if (empty($_SESSION['bm_install_db']) || !is_array($_SESSION['bm_install_db'])) {
        mp_redirect(bm_install_url('database'));
    }
    $posted = $_POST ?: [];
    bm_installer_header('Site Setup');
    if ($error) {
        echo '<div class="flash error">' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</div>';
    }
    ?>
    <form method="post" class="panel settings-form">
      <input type="hidden" name="install_token" value="<?= htmlspecialchars(bm_install_token(), ENT_QUOTES, 'UTF-8') ?>">
      <h2>Site</h2>
      <?php bm_field('site_name', 'Site Name', (string)($posted['site_name'] ?? 'Bonumark Stream')); ?>
      <?php bm_field('site_tagline', 'Tagline', (string)($posted['site_tagline'] ?? 'A self-hosted microblog stream for owning short-form publishing.'), 'text', '', false); ?>
      <?php bm_timezone_select('timezone', 'Timezone', (string)($posted['timezone'] ?? date_default_timezone_get()), 'Choose the timezone Bonumark Stream should use for dates and timestamps.'); ?>
      <?php bm_readonly_info('Detected Site URL', bm_detect_base_url_from_request(), 'Bonumark Stream detects this from the URL used to run the installer so most users do not have to type it manually.'); ?>
      <?php bm_readonly_info('Detected Base Path', bm_detect_base_path_from_request() !== '' ? bm_detect_base_path_from_request() : '/', 'Root installs use /. Subfolder installs are detected automatically.'); ?>

      <h2>Admin Account</h2>
      <?php bm_field('username', 'Username', (string)($posted['username'] ?? 'admin')); ?>
      <?php bm_field('display_name', 'Display Name', (string)($posted['display_name'] ?? ''), 'text', 'Shown inside the admin and used as the default public author name.'); ?>
      <?php bm_field('email', 'Email', (string)($posted['email'] ?? ''), 'text', 'Optional for now, useful for future recovery features.', false); ?>
      <?php bm_field('password', 'Password', '', 'password', 'Minimum 12 characters. Use a strong password or a long passphrase.'); ?>
      <?php bm_field('confirm_password', 'Confirm Password', '', 'password'); ?>
      <button type="submit">Install Bonumark Stream</button>
    </form>
    <?php
    bm_installer_footer();
    exit;
}

mp_redirect(bm_install_url());

<?php
/**
 * Export → fresh install → Import round trip for content-only portability.
 *
 * A source install is seeded with published, draft, scheduled (future and
 * past), pinned, and previously scheduled Stream Posts, Pages, a custom SEO
 * title and robots setting, a link preview, a Local Places location, a photo
 * gallery, and media library items no post uses. The owner downloads a Full
 * export over authenticated HTTP. A second, fresh install imports it through
 * Admin → Import with "Use detected status" and "Preserve dates", and every
 * carried field is compared with the source exactly.
 *
 * BMS_ROUNDTRIP_TEST_SOURCE optionally selects the application tree to test,
 * for example an older checkout; never an installed site.
 * BMS_ROUNDTRIP_REPORT optionally writes the field-by-field comparison as JSON.
 *
 * Uses BMS_DB_HOST, BMS_DB_NAME, BMS_DB_USER, BMS_DB_PASS and requires
 * BMS_DB_DANGER_RESET=1. Only random bms_rt_ci_* tables are created and
 * removed. Application files, sessions and loopback HTTP servers are temporary.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}
if ((string)getenv('BMS_DB_DANGER_RESET') !== '1') {
    fwrite(STDERR, "Refusing to run without BMS_DB_DANGER_RESET=1.\n");
    exit(1);
}
foreach (['BMS_DB_HOST', 'BMS_DB_NAME', 'BMS_DB_USER'] as $required) {
    if ((string)getenv($required) === '') {
        fwrite(STDERR, $required . " is required.\n");
        exit(1);
    }
}

$source = (string)getenv('BMS_ROUNDTRIP_TEST_SOURCE') ?: dirname(__DIR__);
$run = bin2hex(random_bytes(4));
$work = sys_get_temp_dir() . '/bms-roundtrip-' . $run;
$failures = [];
$checks = 0;
$report = ['fields' => [], 'media' => [], 'assertions' => []];
$servers = [];
$pdo = null;
$prefixes = ['source' => 'bms_rt_ci_' . $run . '_s_', 'target' => 'bms_rt_ci_' . $run . '_t_'];

function rt_expect(bool $condition, string $label): void
{
    $GLOBALS['report']['assertions'][] = ['label' => $label, 'passed' => $condition];
    if ($condition) {
        $GLOBALS['checks']++;
        fwrite(STDOUT, 'PASS ' . $label . "\n");
        return;
    }
    $GLOBALS['failures'][] = $label;
    fwrite(STDOUT, 'FAIL ' . $label . "\n");
}

function rt_remove_tree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($path);
}

function rt_copy_application(string $from, string $to): void
{
    $iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), static function (SplFileInfo $file) use ($from): bool {
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($from) + 1));
        return !$file->isLink()
            && !in_array($relative, ['.git', '_bonumark_stream/config.php', '_bonumark_stream/installed.lock', '_bonumark_stream/data', '_bonumark_stream/tmp', '_bonumark_stream/backups', '_bonumark_stream/import-staging', 'media'], true)
            && preg_match('/\.(?:log|sql|sqlite|zip|tar|tar\.gz|tgz)$/i', $relative) !== 1;
    }), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($iterator as $file) {
        $target = $to . substr($file->getPathname(), strlen($from));
        $file->isDir() ? mkdir($target, 0700, true) : copy($file->getPathname(), $target);
    }
}

/** Run a fixture command inside one install's own application code. */
function rt_fixture(string $tree, string $command, array $arguments = []): array
{
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', $tree . '/rt-fixture.php', $command, json_encode($arguments)], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $tree);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start the fixture command ' . $command . '.');
    }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    $decoded = json_decode((string)$output, true);
    if ($status !== 0 || !is_array($decoded)) {
        throw new RuntimeException('Fixture command ' . $command . ' failed: ' . trim((string)$errors . ' ' . (string)$output));
    }
    return $decoded;
}

function rt_request(string $base, string $path, array &$cookies, ?array $form = null, int $timeout = 60): array
{
    $headers = ['User-Agent: Bonumark Round Trip Regression', 'Connection: close'];
    if ($cookies !== []) {
        $headers[] = 'Cookie: ' . implode('; ', array_map(static fn(string $key, string $value): string => $key . '=' . $value, array_keys($cookies), $cookies));
    }
    $options = ['method' => $form === null ? 'GET' : 'POST', 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => $timeout];
    if ($form !== null) {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $options['content'] = http_build_query($form);
    }
    $options['header'] = implode("\r\n", $headers);
    $body = @file_get_contents($base . $path, false, stream_context_create(['http' => $options]));
    if ($body === false || !isset($http_response_header[0])) {
        throw new RuntimeException('HTTP request failed: ' . $path);
    }
    return rt_response($http_response_header, $body, $cookies);
}

function rt_response(array $headerLines, string $body, array &$cookies): array
{
    preg_match('/\s(\d{3})\s/', $headerLines[0], $status);
    $location = '';
    foreach ($headerLines as $header) {
        if (preg_match('/^Set-Cookie:\s*([^=;]+)=([^;]*)/i', $header, $match)) {
            $cookies[$match[1]] = $match[2];
        }
        if (stripos($header, 'Location:') === 0) {
            $location = trim(substr($header, 9));
        }
    }
    return ['status' => (int)($status[1] ?? 0), 'body' => $body, 'location' => $location, 'headers' => $headerLines];
}

function rt_upload(string $base, array &$cookies, string $token, string $filename, string $bytes): array
{
    $boundary = 'bms-rt-' . bin2hex(random_bytes(12));
    $body = '';
    foreach (['csrf_token' => $token, 'import_action' => 'preview'] as $key => $value) {
        $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"$key\"\r\n\r\n$value\r\n";
    }
    $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"import_file\"; filename=\"$filename\"\r\nContent-Type: application/zip\r\n\r\n$bytes\r\n--$boundary--\r\n";
    $headers = ['Content-Type: multipart/form-data; boundary=' . $boundary, 'Connection: close', 'Cookie: ' . implode('; ', array_map(static fn($key, $value) => "$key=$value", array_keys($cookies), $cookies))];
    $result = @file_get_contents($base . '/admin/import.php', false, stream_context_create(['http' => ['method' => 'POST', 'content' => $body, 'header' => implode("\r\n", $headers), 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 120]]));
    if ($result === false || !isset($http_response_header[0])) {
        throw new RuntimeException('Import upload failed.');
    }
    return rt_response($http_response_header, (string)$result, $cookies);
}

function rt_token(string $html): string
{
    if (!preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $match)) {
        throw new RuntimeException('Missing CSRF token in rendered form.');
    }
    return html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
}

function rt_flash_text(string $html): string
{
    return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function rt_login(string $base, string $username): array
{
    $cookies = [];
    $form = rt_request($base, '/admin/login.php', $cookies);
    $response = rt_request($base, '/admin/login.php', $cookies, ['csrf_token' => rt_token($form['body']), 'username' => $username, 'password' => 'Round-Trip-Owner-Password-48!']);
    if ($response['status'] !== 302) {
        throw new RuntimeException('Fixture owner could not sign in.');
    }
    return $cookies;
}

const RT_FIXTURE = <<<'PHP'
<?php
// Disposable round-trip fixture. Runs only inside a temporary install.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit; }
define('BMS_STATELESS_PROTOCOL_REQUEST', true);
require __DIR__ . '/_bonumark_stream/app/database.php';
require_once __DIR__ . '/_bonumark_stream/app/media.php';
require_once __DIR__ . '/_bonumark_stream/app/pages.php';
require_once __DIR__ . '/_bonumark_stream/app/scheduler.php';
require_once __DIR__ . '/_bonumark_stream/app/places.php';
bms_apply_site_timezone();
$arguments = json_decode((string)($argv[2] ?? '[]'), true) ?: [];
$pdo = bms_db();
$prefix = bms_table_prefix();

function rt_page(array $fields, string $body): array
{
    return bms_parse_markdown_string(bms_build_markdown_document($fields, $body));
}
function rt_post_id(string $slug, string $type): int
{
    $stmt = bms_db()->prepare('SELECT id FROM ' . bms_table('posts') . ' WHERE slug = :slug AND post_type = :type LIMIT 1');
    $stmt->execute(['slug' => $slug, 'type' => $type]);
    return (int)$stmt->fetchColumn();
}
function rt_image(string $path, int $width, int $height, string $type, array $rgb): void
{
    $image = imagecreatetruecolor($width, $height);
    $color = imagecolorallocate($image, $rgb[0], $rgb[1], $rgb[2]);
    imagefilledrectangle($image, 0, 0, $width, $height, $color);
    $accent = imagecolorallocate($image, 255 - $rgb[0], 255 - $rgb[1], 255 - $rgb[2]);
    imagefilledellipse($image, intdiv($width, 2), intdiv($height, 2), intdiv($width, 3), intdiv($height, 3), $accent);
    $type === 'png' ? imagepng($image, $path) : imagejpeg($image, $path, 88);
    imagedestroy($image);
}
function rt_upload_media(string $name, string $bytesPath, string $alt, string $caption, int $owner): array
{
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    return bms_media_upload(['name' => $name, 'type' => bms_media_expected_mime_for_extension($extension), 'tmp_name' => $bytesPath, 'error' => UPLOAD_ERR_OK, 'size' => filesize($bytesPath)], $alt, $caption, ['uploaded_by' => $owner, 'generate_derivatives' => true]);
}

switch ((string)($argv[1] ?? '')) {
    case 'install':
        bms_database_require_supported($pdo);
        bms_install_schema($pdo, $prefix);
        $insert = $pdo->prepare('INSERT INTO `' . $prefix . 'settings` (setting_key, setting_value, updated_at) VALUES (?, ?, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        foreach (bms_config() as $key => $value) {
            if (is_string($value) && $key !== 'mail_sendmail_path') {
                $insert->execute([$key, $value]);
            }
        }
        foreach ((array)($arguments['settings'] ?? []) as $key => $value) {
            $insert->execute([(string)$key, (string)$value]);
        }
        $pdo->prepare('INSERT INTO `' . $prefix . 'users` (username, display_name, email, email_verified_at, password_hash, role, status, created_at, updated_at) VALUES (?, ?, ?, UTC_TIMESTAMP(), ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())')
            ->execute(['owner', 'Owner', 'owner@example.test', password_hash('Round-Trip-Owner-Password-48!', PASSWORD_DEFAULT), 'admin', 'active']);
        echo json_encode(['owner_id' => (int)$pdo->lastInsertId(), 'cutover' => (string)bms_database_setting_value($pdo, $prefix, 'stream_published_at_utc_cutover')]);
        break;

    case 'seed':
        $owner = (int)$pdo->query('SELECT id FROM `' . $prefix . "users` WHERE username = 'owner'")->fetchColumn();
        $scratch = __DIR__ . '/fixture-media';
        @mkdir($scratch, 0700, true);
        rt_image($scratch . '/hero.jpg', 1600, 900, 'jpg', [200, 40, 40]);
        rt_image($scratch . '/one.png', 1200, 800, 'png', [30, 120, 200]);
        rt_image($scratch . '/two.jpg', 1000, 750, 'jpg', [40, 160, 60]);
        rt_image($scratch . '/unused.jpg', 640, 480, 'jpg', [90, 90, 90]);
        rt_image($scratch . '/legacy.jpg', 700, 500, 'jpg', [150, 110, 30]);
        file_put_contents($scratch . '/brochure.pdf', "%PDF-1.4\n1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n2 0 obj << /Type /Pages /Kids [] /Count 0 >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");
        $hero = rt_upload_media('Launch Day Hero.jpg', $scratch . '/hero.jpg', 'Crowd at the launch', 'Launch day, main hall', $owner);
        $one = rt_upload_media('Gallery One.png', $scratch . '/one.png', 'Bridge at dusk', 'First stop', $owner);
        $two = rt_upload_media('Gallery Two.jpg', $scratch . '/two.jpg', 'River path', 'Second stop', $owner);
        $brochure = rt_upload_media('Unused Brochure "2026".pdf', $scratch . '/brochure.pdf', 'Brochure', 'Never attached to a post', $owner);
        $unused = rt_upload_media('Unused Photo.jpg', $scratch . '/unused.jpg', 'Spare photo', '', $owner);
        $pdo->prepare('UPDATE `' . $prefix . 'media` SET trashed_at = :at, trashed_by = :by WHERE id = :id')->execute(['at' => '2026-02-03 04:05:06', 'by' => $owner, 'id' => (int)$unused['id']]);
        // A file from before media privacy checks: Import cleans it again.
        @mkdir(bms_public_path('media/2024/01'), 0755, true);
        copy($scratch . '/legacy.jpg', bms_public_path('media/2024/01/legacy-photo.jpg'));
        $pdo->prepare('INSERT INTO `' . $prefix . "media` (filename, original_filename, public_path, mime_type, file_size, width, height, alt_text, caption, uploaded_by, file_hash, privacy_status, privacy_note, created_at, updated_at) VALUES ('legacy-photo.jpg', 'IMG_0001.JPG', 'media/2024/01/legacy-photo.jpg', 'image/jpeg', ?, 700, 500, 'Old photo', 'From the first year', ?, ?, 'legacy_unchecked', '', '2024-01-15 10:00:00', '2024-01-15 10:00:00')")
            ->execute([filesize($scratch . '/legacy.jpg'), $owner, hash_file('sha256', $scratch . '/legacy.jpg')]);
        $pdo->exec('INSERT INTO `' . $prefix . "places` (id, name, category, area_label, locality, region, country, latitude, longitude, default_display_mode, created_at, updated_at) VALUES (7, 'Riverside Café', 'cafe', 'Downtown', 'Columbus', 'Indiana', 'US', 39.2014405, -85.9213796, 'exact', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $location = bms_place_snapshot_fields(bms_place_get(7), 'area');

        // A post that was scheduled and then published by the scheduler.
        $page = rt_page(['title' => 'Scheduled then live', 'slug' => 'scheduled-then-live', 'status' => 'scheduled', 'content_type' => 'stream', 'date' => '2025-09-01', 'description' => 'Went live on schedule.', 'stream_created_at' => '2025-08-30 09:00:00', 'scheduled_at' => '2099-03-04 05:06:07'], 'This post went live on schedule.');
        bms_schedule_post_page($page, 'scheduled', 'scheduled-then-live.md', $owner, '2099-03-04 05:06:07');
        $pdo->prepare('UPDATE `' . $prefix . 'posts` SET scheduled_at = :at WHERE id = :id')->execute(['at' => '2025-09-01 12:00:00', 'id' => rt_post_id('scheduled-then-live', 'stream')]);
        bms_publish_due_scheduled_posts(10);

        $posts = [
            ['launch-day', 'published', 'stream', ['title' => 'Launch day', 'description' => 'We launched.', 'date' => '2025-03-04', 'stream_created_at' => '2025-03-04 00:06:07', 'tags' => ['news', 'launch'], 'seo_title' => 'Launch "Day" \\ notes', 'robots' => 'noindex, nofollow',
                'link_preview_url' => 'https://example.org/article?x=1&y=2', 'link_preview_title' => "Example's \"article\"", 'link_preview_description' => 'A description, with commas', 'link_preview_image' => 'https://example.org/og.png', 'link_preview_site_name' => 'Example'],
                "Launch notes with \"quotes\" and a path C:\\\\launch.\n\n" . bms_media_markdown($hero)],
            ['gallery-walk', 'published', 'stream', ['title' => 'Gallery walk', 'description' => 'Two stops.', 'date' => '2025-06-07', 'stream_created_at' => '2025-06-07 04:09:10', 'featured_media' => (string)$one['public_path'], 'media_gallery' => [(string)$one['public_path'], (string)$two['public_path']]] + $location,
                'Walking the riverside.'],
            ['legacy-local', 'published', 'stream', ['title' => 'Old local time', 'description' => 'Stored before UTC storage.', 'date' => '2019-06-01', 'stream_created_at' => '2019-06-01 12:00:00'], 'From the old days.'],
            ['owners-draft', 'draft', 'stream', ['title' => 'Owner\'s "draft" \\ path', 'description' => 'Draft description.', 'date' => '2026-01-05', 'stream_created_at' => '2026-01-05 08:00:00', 'seo_title' => 'Draft SEO', 'robots' => 'noindex'], 'Draft body.'],
            ['unpublished-note', 'draft', 'stream', ['title' => 'Unpublished note', 'description' => 'Was public once.', 'date' => '2024-12-25', 'stream_created_at' => '2024-12-25 05:00:00'], 'Taken down for edits.'],
            ['future-plan', 'scheduled', 'stream', ['title' => 'Future plan', 'description' => 'Not yet.', 'date' => '2026-01-06', 'stream_created_at' => '2026-01-06 09:00:00'], 'Coming later.'],
            ['missed-schedule', 'scheduled', 'stream', ['title' => 'Missed schedule', 'description' => 'Its time passed.', 'date' => '2026-01-07', 'stream_created_at' => '2026-01-07 09:00:00'], 'The scheduler never ran.'],
            ['unscheduled', 'scheduled', 'stream', ['title' => 'No schedule time', 'description' => 'Scheduled without a time.', 'date' => '2026-01-08', 'stream_created_at' => '2026-01-08 09:00:00'], 'Scheduled without a time.'],
            ['about', 'published', 'page', ['title' => 'About this site', 'description' => 'About page.', 'date' => '2025-01-10', 'seo_title' => 'About "Bonumark"', 'robots' => 'index, follow'], 'About this site.'],
            ['colophon', 'draft', 'page', ['title' => 'Colophon', 'description' => 'How it is made.', 'date' => '2025-02-11'], 'Made with care.'],
        ];
        foreach ($posts as [$slug, $status, $type, $fields, $body]) {
            $fields += ['slug' => $slug, 'status' => $status, 'content_type' => $type];
            if ($slug === 'future-plan') {
                bms_schedule_post_page(rt_page($fields + ['scheduled_at' => '2099-01-02 12:00:00'], $body), 'scheduled', $slug . '.md', $owner, '2099-01-02 12:00:00');
                continue;
            }
            if ($slug === 'missed-schedule') {
                // Still ahead at export time; the test moves the exported time
                // into the past, as if the import happened after it.
                bms_schedule_post_page(rt_page($fields + ['scheduled_at' => '2099-01-03 12:00:00'], $body), 'scheduled', $slug . '.md', $owner, '2099-01-03 12:00:00');
                continue;
            }
            $section = $type === 'page' ? 'pages/' . ($status === 'published' ? 'published' : 'drafts') : ($status === 'published' ? 'published' : ($status === 'scheduled' ? 'scheduled' : 'drafts'));
            bms_upsert_database_content(rt_page($fields, $body), $section, $slug . '.md', $owner);
        }
        $times = [
            ['launch-day', 'stream', '2025-03-04 05:06:07'],
            ['gallery-walk', 'stream', '2025-06-07 08:09:10'],
            ['legacy-local', 'stream', '2019-06-01 12:00:00'],
            ['unpublished-note', 'stream', '2024-12-25 10:00:00'],
            ['about', 'page', '2025-01-10 09:00:00'],
        ];
        foreach ($times as [$slug, $type, $at]) {
            $pdo->prepare('UPDATE `' . $prefix . 'posts` SET published_at = :at WHERE id = :id')->execute(['at' => $at, 'id' => rt_post_id($slug, $type)]);
        }
        $pdo->prepare('UPDATE `' . $prefix . 'posts` SET is_pinned = 1, pinned_at = :at WHERE id = :id')->execute(['at' => '2025-03-05 06:07:08', 'id' => rt_post_id('launch-day', 'stream')]);
        // Every seeded time is UTC-era except legacy-local, which predates the cutover.
        bms_set_setting('stream_published_at_utc_cutover', '2020-01-01 00:00:00');
        // A saved SMTP password, as stored by earlier versions. No export may contain it.
        bms_set_setting('mail_smtp_password', (string)($arguments['smtp_password'] ?? ''));
        echo json_encode(['media' => [(string)$hero['public_path'], (string)$one['public_path'], (string)$two['public_path'], (string)$brochure['public_path'], (string)$unused['public_path'], 'media/2024/01/legacy-photo.jpg']]);
        break;

    case 'instants':
        // The instant each published record shows publicly on this install.
        $instants = [];
        foreach (['published', 'pages/published'] as $section) {
            foreach (bms_list_content_records($section) as $page) {
                $timestamp = bms_stream_public_datetime_timestamp($page);
                $instants[(string)$page['content_type'] . ':' . (string)$page['slug']] = $timestamp === null ? '' : gmdate('Y-m-d\TH:i:s\Z', $timestamp);
            }
        }
        echo json_encode($instants);
        break;

    default:
        fwrite(STDERR, "Unknown fixture command.\n");
        exit(1);
}
PHP;

try {
    mkdir($work, 0700, true);
    $pdo = new PDO('mysql:host=' . getenv('BMS_DB_HOST') . ';dbname=' . getenv('BMS_DB_NAME') . ';charset=utf8mb4', (string)getenv('BMS_DB_USER'), (string)getenv('BMS_DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec("SET time_zone = '+00:00'");
    $smtpSecret = 'rt-smtp-' . bin2hex(random_bytes(10));
    $trees = [];
    $bases = [];
    foreach (['source', 'target'] as $role) {
        $tree = $work . '/' . $role;
        mkdir($tree, 0700, true);
        rt_copy_application($source, $tree);
        mkdir($tree . '/sessions', 0700);
        mkdir($tree . '/media', 0755);
        file_put_contents($tree . '/rt-fixture.php', RT_FIXTURE);
        $trees[$role] = $tree;
    }
    foreach (['source', 'target'] as $role) {
        $tree = $trees[$role];
        // The server address must be known before config.php is written.
        $listener = stream_socket_server('tcp://127.0.0.1:0');
        $address = stream_socket_get_name($listener, false);
        fclose($listener);
        $base = 'http://' . $address;
        $config = [
            'site_name' => 'Round Trip ' . ucfirst($role), 'site_tagline' => 'Fixture', 'site_admin_email' => 'owner@example.test',
            'timezone' => 'America/Indiana/Indianapolis', 'base_url' => $base, 'base_path' => '', 'security_salt' => bin2hex(random_bytes(32)),
            'scheduled_tasks_public_traffic_enabled' => '0', 'scheduled_tasks_heartbeat_enabled' => '0', 'activitypub_enabled' => '0',
            'comments_enabled' => '0', 'pwa_enabled' => '0', 'mail_transport' => 'disabled',
            'database' => ['host' => (string)getenv('BMS_DB_HOST'), 'name' => (string)getenv('BMS_DB_NAME'), 'user' => (string)getenv('BMS_DB_USER'), 'password' => (string)getenv('BMS_DB_PASS'), 'charset' => 'utf8mb4', 'prefix' => $prefixes[$role]],
        ];
        file_put_contents($tree . '/_bonumark_stream/config.php', "<?php\nreturn " . var_export($config, true) . ";\n");
        touch($tree . '/_bonumark_stream/installed.lock');
        rt_fixture($tree, 'install');
        // Start the server on the reserved address.
        $process = proc_open([PHP_BINARY, '-d', 'opcache.enable=0', '-d', 'display_errors=1', '-d', 'session.save_path=' . $tree . '/sessions', '-d', 'upload_max_filesize=64M', '-d', 'post_max_size=64M', '-S', $address, '-t', $tree], [0 => ['pipe', 'r'], 1 => ['file', $tree . '/http.log', 'a'], 2 => ['file', $tree . '/http.log', 'a']], $pipes, $tree);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start an isolated PHP HTTP server.');
        }
        fclose($pipes[0]);
        $servers[] = $process;
        $ready = false;
        for ($attempt = 0; $attempt < 50 && !$ready; $attempt++) {
            $socket = @stream_socket_client('tcp://' . $address, $error, $errorMessage, 0.1);
            if ($socket !== false) {
                fclose($socket);
                $ready = true;
            } else {
                usleep(100000);
            }
        }
        rt_expect($ready, ucfirst($role) . ' install serves HTTP');
        $bases[$role] = $base;
    }
    fwrite(STDOUT, 'Runtime: PHP ' . PHP_VERSION . ' / ' . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . "\n");
    $report['runtime'] = ['php' => PHP_VERSION, 'database' => $pdo->getAttribute(PDO::ATTR_SERVER_VERSION), 'source' => $source];

    $seeded = rt_fixture($trees['source'], 'seed', ['smtp_password' => $smtpSecret]);
    $s = $prefixes['source'];
    $t = $prefixes['target'];
    rt_expect((string)$pdo->query("SELECT status FROM `{$s}posts` WHERE slug = 'scheduled-then-live'")->fetchColumn() === 'published', 'Source: the scheduler published the previously scheduled post');

    // Full export from the source over authenticated HTTP.
    $sourceOwner = rt_login($bases['source'], 'owner');
    $exportForm = rt_request($bases['source'], '/admin/export.php', $sourceOwner);
    $exportText = rt_flash_text($exportForm['body']);
    rt_expect(str_contains($exportText, 'content-only') && str_contains($exportText, 'Comments, commenter accounts, settings, and analytics') && str_contains($exportText, 'Full recovery'), 'Export screen says it is content-only, names what it leaves out, and points to server backups');
    $export = rt_request($bases['source'], '/admin/export.php', $sourceOwner, ['csrf_token' => rt_token($exportForm['body']), 'export_kind' => 'full'], 120);
    rt_expect($export['status'] === 200 && str_starts_with($export['body'], 'PK'), 'Source: Full export downloads as a ZIP');
    $zipPath = $work . '/full-export.zip';
    file_put_contents($zipPath, $export['body']);
    $zip = new ZipArchive();
    rt_expect($zip->open($zipPath) === true, 'Full export opens as a ZIP');
    $sql = (string)$zip->getFromName('database/bonumark.sql');
    rt_expect($sql !== '' && !str_contains($sql, $smtpSecret) && !str_contains($sql, "'mail_smtp_password'"), 'The database SQL in the Full export carries no SMTP password row or value');
    $zipText = '';
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $zipText .= (string)$zip->getFromIndex($i);
    }
    rt_expect(!str_contains($zipText, $smtpSecret), 'No file in the Full export contains the SMTP password');
    // The import happens after one exported post's scheduled time has passed.
    $missedEntry = 'markdown/posts/scheduled/missed-schedule.md';
    $missed = (string)$zip->getFromName($missedEntry);
    rt_expect(str_contains($missed, 'scheduled_at: "2099-01-03 12:00:00"'), 'The export carries the scheduled time of a scheduled post');
    $zip->addFromString($missedEntry, str_replace('scheduled_at: "2099-01-03 12:00:00"', 'scheduled_at: "2020-01-01 00:00:00"', $missed));
    $zip->close();
    $export['body'] = (string)file_get_contents($zipPath);

    // Fresh target install imports the export through Admin → Import.
    $targetCutoverBefore = (string)$pdo->query("SELECT setting_value FROM `{$t}settings` WHERE setting_key = 'stream_published_at_utc_cutover'")->fetchColumn();
    $targetOwner = rt_login($bases['target'], 'owner');
    $importForm = rt_request($bases['target'], '/admin/import.php', $targetOwner);
    $importToken = rt_token($importForm['body']);
    $preview = rt_upload($bases['target'], $targetOwner, $importToken, 'full-export.zip', $export['body']);
    rt_expect($preview['status'] === 200 && preg_match('/name="preview_token"\s+value="([^"]+)"/', $preview['body'], $previewMatch) === 1, 'Target: Full export creates an import preview');
    $previewText = rt_flash_text($preview['body']);
    rt_expect(str_contains($previewText, 'no post uses'), 'Preview reports media library files no post uses');
    rt_expect(str_contains($preview['body'], '<option value="original" selected>Use detected status</option>') && preg_match('/<input type="checkbox" name="preserve_dates" value="1" checked>/', $preview['body']) === 1, 'A Bonumark export preview preselects Use detected status and Preserve dates');
    rt_expect((int)$pdo->query("SELECT COUNT(*) FROM `{$t}posts`")->fetchColumn() === 0 && (int)$pdo->query("SELECT COUNT(*) FROM `{$t}media`")->fetchColumn() === 0, 'Preview writes no content or media records');
    $confirm = rt_request($bases['target'], '/admin/import.php', $targetOwner, [
        'csrf_token' => $importToken, 'import_action' => 'import', 'preview_token' => html_entity_decode((string)($previewMatch[1] ?? ''), ENT_QUOTES, 'UTF-8'),
        'target_status' => 'original', 'duplicate_policy' => 'skip', 'media_policy' => 'import', 'preserve_dates' => '1', 'range_start' => '1', 'batch_size' => '0',
    ], 180);
    rt_expect($confirm['status'] === 302, 'Target: import confirmation completes');
    $landing = rt_request($bases['target'], parse_url($confirm['location'], PHP_URL_PATH) . (($query = parse_url($confirm['location'], PHP_URL_QUERY)) ? '?' . $query : ''), $targetOwner);
    $flash = rt_flash_text($landing['body']);
    rt_expect(str_contains($flash, 'Import complete'), 'Import reports completion');
    rt_expect(str_contains($flash, '2 scheduled item(s) were imported as drafts') && str_contains($flash, 'Missed schedule') && str_contains($flash, 'No schedule time'), 'Import warns which scheduled items became drafts');
    rt_expect(str_contains($flash, 'Restored 6 media library file(s)'), 'Import reports the media library restore');

    // Field-by-field comparison of every content record.
    $rows = static function (string $prefix) use ($pdo): array {
        $records = [];
        foreach ($pdo->query("SELECT * FROM `{$prefix}posts` ORDER BY id")->fetchAll() as $row) {
            $tags = $pdo->prepare("SELECT t.name FROM `{$prefix}post_terms` pt JOIN `{$prefix}terms` t ON t.id = pt.term_id WHERE pt.post_id = ? AND t.term_type = 'tag' ORDER BY t.name");
            $tags->execute([(int)$row['id']]);
            $row['tags'] = implode(', ', $tags->fetchAll(PDO::FETCH_COLUMN));
            $row['front'] = json_decode((string)$row['content_front_matter'], true) ?: [];
            $records[$row['post_type'] . ':' . $row['slug']] = $row;
        }
        return $records;
    };
    $sourceRows = $rows($s);
    $targetRows = $rows($t);
    rt_expect(count($sourceRows) === 11 && count($targetRows) === 11, 'All 11 source records exist on the target');
    $sourceInstants = rt_fixture($trees['source'], 'instants');
    $targetInstants = rt_fixture($trees['target'], 'instants');
    $expectedStatus = ['stream:missed-schedule' => 'draft', 'stream:unscheduled' => 'draft'];
    $frontKeys = ['seo_title', 'robots', 'link_preview_url', 'link_preview_title', 'link_preview_description', 'link_preview_image', 'link_preview_site_name', 'location_place_id', 'location_name', 'location_category', 'location_area', 'location_locality', 'location_region', 'location_country', 'location_display_mode', 'featured_media', 'media_gallery', 'stream_created_at', 'scheduled_at'];
    foreach ($sourceRows as $key => $before) {
        $after = $targetRows[$key] ?? null;
        if (!is_array($after)) {
            rt_expect(false, $key . ': imported');
            continue;
        }
        $fields = [
            'status' => [$expectedStatus[$key] ?? $before['status'], $after['status']],
            'title' => [$before['title'], $after['title']],
            'description' => [$before['description'], $after['description']],
            'body' => [$before['content_body'], $after['content_body']],
            'date' => [$before['date_published'], $after['date_published']],
            'tags' => [$before['tags'], $after['tags']],
            'published_at' => [$before['published_at'], $after['published_at']],
            'scheduled_at' => [isset($expectedStatus[$key]) ? null : $before['scheduled_at'], $after['scheduled_at']],
            'is_pinned' => [(int)$before['is_pinned'], (int)$after['is_pinned']],
            'pinned_at' => [$before['pinned_at'], $after['pinned_at']],
        ];
        foreach ($frontKeys as $frontKey) {
            $expected = $before['front'][$frontKey] ?? null;
            if (isset($expectedStatus[$key]) && $frontKey === 'scheduled_at') {
                $expected = null;
            }
            $fields['front:' . $frontKey] = [$expected, $after['front'][$frontKey] ?? null];
        }
        if ($key === 'stream:legacy-local') {
            // Stored before UTC storage: the raw value is site-local on the
            // source and UTC on the target. The public instant must match.
            $fields['published_at'] = [$sourceInstants[$key] ?? 'missing', gmdate('Y-m-d\TH:i:s\Z', (new DateTimeImmutable((string)$after['published_at'], new DateTimeZone('UTC')))->getTimestamp())];
        }
        if (isset($sourceInstants[$key]) || isset($targetInstants[$key])) {
            $fields['public_time'] = [$sourceInstants[$key] ?? 'missing', $targetInstants[$key] ?? 'missing'];
        }
        foreach ($fields as $field => [$expected, $actual]) {
            $match = $expected === $actual;
            $report['fields'][] = ['record' => $key, 'field' => $field, 'source' => $expected, 'target' => $actual, 'match' => $match];
            rt_expect($match, $key . ' ' . $field . ' matches' . ($match ? '' : ' (source ' . json_encode($expected, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ', target ' . json_encode($actual, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ')'));
        }
    }

    // The media library, including files no post uses.
    $media = static function (string $prefix) use ($pdo): array {
        $records = [];
        foreach ($pdo->query("SELECT * FROM `{$prefix}media` ORDER BY id")->fetchAll() as $row) {
            $records[$row['public_path']] = $row;
        }
        return $records;
    };
    $sourceMedia = $media($s);
    $targetMedia = $media($t);
    rt_expect(count($targetMedia) === count($sourceMedia) && count($sourceMedia) === 6, 'Every media library item, including unused and trashed ones, is restored');
    foreach ($sourceMedia as $path => $before) {
        $after = $targetMedia[$path] ?? null;
        if (!is_array($after)) {
            rt_expect(false, $path . ': restored at its original path');
            continue;
        }
        $legacy = $path === 'media/2024/01/legacy-photo.jpg';
        $compare = ['original_filename', 'alt_text', 'caption', 'mime_type', 'width', 'height', 'created_at', 'updated_at', 'trashed_at'];
        if (!$legacy) {
            $compare = array_merge($compare, ['file_size', 'file_hash', 'privacy_status', 'privacy_note']);
        }
        foreach ($compare as $column) {
            $match = $before[$column] === $after[$column];
            $report['media'][] = ['path' => $path, 'field' => $column, 'source' => $before[$column], 'target' => $after[$column], 'match' => $match];
            rt_expect($match, $path . ' ' . $column . ' matches' . ($match ? '' : ' (source ' . json_encode($before[$column]) . ', target ' . json_encode($after[$column]) . ')'));
        }
        $sourceVariants = json_decode((string)$before['image_variants_json'], true) ?: [];
        $targetVariants = json_decode((string)$after['image_variants_json'], true) ?: [];
        if (!$legacy) {
            $match = $sourceVariants === $targetVariants;
            $report['media'][] = ['path' => $path, 'field' => 'image_variants_json', 'source' => count($sourceVariants) . ' variant(s)', 'target' => count($targetVariants) . ' variant(s)', 'match' => $match];
            rt_expect($match, $path . ' responsive variants match');
            $bytes = hash_file('sha256', $trees['source'] . '/' . $path) === hash_file('sha256', $trees['target'] . '/' . $path);
            rt_expect($bytes, $path . ' file bytes match');
            foreach ($targetVariants as $variant) {
                rt_expect(is_file($trees['target'] . '/' . $variant['path']) && hash_file('sha256', $trees['source'] . '/' . $variant['path']) === hash_file('sha256', $trees['target'] . '/' . $variant['path']), $path . ' variant ' . basename((string)$variant['path']) . ' bytes match');
            }
        } else {
            rt_expect($after['privacy_status'] === 'cleaned' && is_file($trees['target'] . '/' . $path), 'An image the export does not mark as cleaned is cleaned again at its original path');
        }
    }
    rt_expect((int)$pdo->query("SELECT COUNT(*) FROM `{$t}media` WHERE uploaded_by IS NOT NULL")->fetchColumn() === 6, 'Restored media belongs to the importing owner');

    // Local Places: the reference survives, and a new place never takes its id.
    $placeRow = $pdo->query("SELECT COUNT(*) FROM `{$t}places` WHERE id = 7")->fetchColumn();
    rt_expect((int)$placeRow === 0, 'The private Local Places directory is not exported');
    $pdo->exec("INSERT INTO `{$t}places` (name, category, area_label, locality, region, country, latitude, longitude, default_display_mode, created_at, updated_at) VALUES ('New place', 'other', '', 'Elsewhere', '', '', 1, 1, 'exact', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    rt_expect((int)$pdo->lastInsertId() > 7, 'A place created after import never reuses an imported place id');

    // A second import of the same export changes nothing.
    $previewAgain = rt_upload($bases['target'], $targetOwner, $importToken, 'full-export.zip', $export['body']);
    preg_match('/name="preview_token"\s+value="([^"]+)"/', $previewAgain['body'], $againMatch);
    rt_request($bases['target'], '/admin/import.php', $targetOwner, [
        'csrf_token' => $importToken, 'import_action' => 'import', 'preview_token' => html_entity_decode((string)($againMatch[1] ?? ''), ENT_QUOTES, 'UTF-8'),
        'target_status' => 'original', 'duplicate_policy' => 'skip', 'media_policy' => 'import', 'preserve_dates' => '1', 'range_start' => '1', 'batch_size' => '0',
    ], 180);
    rt_expect((int)$pdo->query("SELECT COUNT(*) FROM `{$t}posts`")->fetchColumn() === 11 && (int)$pdo->query("SELECT COUNT(*) FROM `{$t}media`")->fetchColumn() === 6, 'Importing the same export again duplicates no posts or media');
    rt_expect((glob($trees['target'] . '/_bonumark_stream/import-staging/*/*') ?: []) === [] || (glob($trees['target'] . '/_bonumark_stream/import-staging/previews/*') ?: []) === [], 'Confirmed imports clean their private preview files');
    rt_expect($targetCutoverBefore !== '' && (string)$pdo->query("SELECT setting_value FROM `{$t}settings` WHERE setting_key = 'stream_published_at_utc_cutover'")->fetchColumn() <= '2019-06-01 16:00:00', 'The fresh install reads every imported publication time as UTC');

    // Other formats keep the Draft default.
    $markdownPreview = rt_upload($bases['target'], $targetOwner, $importToken, 'note.md', "---\ntitle: Imported note\n---\nA note to import.\n");
    rt_expect($markdownPreview['status'] === 200 && str_contains($markdownPreview['body'], '<option value="draft" selected>Import as drafts</option>') && !str_contains($markdownPreview['body'], '<option value="original" selected>'), 'A Markdown preview keeps Import as drafts preselected');
    rt_request($bases['target'], '/admin/import.php', $targetOwner, ['csrf_token' => $importToken, 'import_action' => 'clear']);
    $report['completed'] = true;
} catch (Throwable $e) {
    rt_expect(false, 'Round trip ran without error (' . get_class($e) . ': ' . $e->getMessage() . ')');
} finally {
    foreach ($servers as $process) {
        if (is_resource($process)) {
            proc_terminate($process);
            proc_close($process);
        }
    }
    if ($pdo instanceof PDO) {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
            if (str_starts_with((string)$table, 'bms_rt_ci_' . $run . '_')) {
                $pdo->exec('DROP TABLE `' . str_replace('`', '``', (string)$table) . '`');
            }
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }
    $logs = [];
    foreach (['source', 'target'] as $role) {
        $log = $work . '/' . $role . '/http.log';
        if (is_file($log) && $failures !== []) {
            $logs[$role] = (string)file_get_contents($log);
        }
    }
    rt_remove_tree($work);
    $report['passed'] = $checks;
    $report['failed'] = $failures;
    $reportPath = (string)getenv('BMS_ROUNDTRIP_REPORT');
    if ($reportPath !== '') {
        file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
    foreach ($logs as $role => $log) {
        if (preg_match_all('/(PHP (?:Fatal|Warning|Notice|Deprecated)[^\n]*)/', $log, $matches)) {
            fwrite(STDOUT, ucfirst($role) . " server log:\n" . implode("\n", array_slice(array_unique($matches[1]), 0, 20)) . "\n");
        }
    }
}

fwrite(STDOUT, 'Export/import round trip: ' . $checks . ' passed, ' . count($failures) . " failed.\n");
exit($failures === [] ? 0 : 1);

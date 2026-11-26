<?php
/**
 * Security hardening regression test. Needs no database.
 *
 * Covers theme SVG refusal and the passive-file server rules, Markdown
 * placeholder escaping, the shared outbound address guard, Sendmail
 * hardening, the media PHP denial, the minimal API status payload, the
 * dot-directory and WebFinger rules, and the related public fixes.
 *
 * Every check is reported, so a run against an older tree shows exactly which
 * protections are missing. Theme installation and config-dependent checks run
 * in a child process inside a disposable copy of the application, so this test
 * never writes to the tree under test.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    if (!headers_sent()) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
    }
    exit('CLI only.');
}

$root = dirname(__DIR__);

// Child mode: run one isolated check inside a disposable copy of the tree.
if (($argv[1] ?? '') === '--child') {
    bms_sec_child((string)($argv[2] ?? ''), (string)($argv[3] ?? ''));
    exit(0);
}

require_once $root . '/_bonumark_stream/app/functions.php';
require_once $root . '/_bonumark_stream/app/markdown.php';
require_once $root . '/_bonumark_stream/app/link-preview.php';
require_once $root . '/_bonumark_stream/app/import-media.php';
require_once $root . '/_bonumark_stream/app/activitypub-inbox.php';
require_once $root . '/_bonumark_stream/app/mail.php';
require_once $root . '/_bonumark_stream/app/api.php';
require_once $root . '/_bonumark_stream/app/upgrader.php';

$results = ['pass' => 0, 'fail' => 0, 'failed' => []];

function bms_sec_check(string $section, string $label, bool $condition): void
{
    global $results;
    if ($condition) {
        $results['pass']++;
        fwrite(STDOUT, 'PASS [' . $section . '] ' . $label . "\n");
        return;
    }
    $results['fail']++;
    $results['failed'][] = '[' . $section . '] ' . $label;
    fwrite(STDOUT, 'FAIL [' . $section . '] ' . $label . "\n");
}

/** Run a section, reporting an unexpected exception as one failure. */
function bms_sec_section(string $section, callable $callback): void
{
    try {
        $callback();
    } catch (Throwable $e) {
        bms_sec_check($section, 'section completed without an unexpected ' . get_class($e) . ': ' . $e->getMessage(), false);
    }
}

function bms_sec_file(string $relative): string
{
    global $root;
    $path = $root . '/' . $relative;
    return is_file($path) ? (string)file_get_contents($path) : '';
}

function bms_sec_temp_dir(string $label): string
{
    $path = sys_get_temp_dir() . '/bms-security-' . $label . '-' . bin2hex(random_bytes(6));
    if (!mkdir($path, 0700, true)) {
        throw new RuntimeException('Could not create a temporary directory.');
    }
    return $path;
}

function bms_sec_remove_tree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) {
        $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($path);
}

function bms_sec_copy_tree(string $source, string $destination): void
{
    if (!is_dir($source)) {
        return;
    }
    if (!is_dir($destination)) {
        mkdir($destination, 0700, true);
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($iterator as $item) {
        $target = $destination . substr($item->getPathname(), strlen($source));
        if ($item->isDir()) {
            if (!is_dir($target)) {
                mkdir($target, 0700, true);
            }
        } else {
            copy($item->getPathname(), $target);
        }
    }
}

/** Build a ZIP from a map of relative path => bytes. */
function bms_sec_zip(string $path, array $files): void
{
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Could not create a test ZIP.');
    }
    foreach ($files as $name => $bytes) {
        $zip->addFromString($name, $bytes);
    }
    $zip->close();
}

function bms_sec_theme_manifest(string $slug, string $screenshot, array $images): string
{
    return (string)json_encode([
        'name' => 'Security Fixture ' . $slug,
        'slug' => $slug,
        'version' => '1.0.0',
        'author' => 'Bonumark test',
        'description' => 'Disposable theme for the security regression test.',
        'screenshot' => $screenshot,
        'assets' => ['css' => ['assets/css/theme.css'], 'images' => $images, 'fonts' => []],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

function bms_sec_png(): string
{
    return (string)base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jP1sAAAAASUVORK5CYII=');
}

/** Attributes of every element in an HTML fragment, as [tag, name, value] rows. */
function bms_sec_html_attributes(string $html): array
{
    $document = new DOMDocument('1.0', 'UTF-8');
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="UTF-8"><div id="bms-security-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $rows = [];
    foreach ($document->getElementsByTagName('*') as $element) {
        if ($element instanceof DOMElement && $element->hasAttributes()) {
            foreach ($element->attributes as $attribute) {
                $rows[] = [strtolower($element->tagName), strtolower($attribute->name), (string)$attribute->value];
            }
        }
    }
    return $rows;
}

/** Attribute names core Markdown may emit. Anything else was injected. */
function bms_sec_markdown_attribute_allowed(string $name): bool
{
    return in_array($name, ['id', 'href', 'src', 'alt', 'title', 'loading', 'decoding', 'fetchpriority', 'sizes', 'srcset', 'width', 'height', 'controls', 'preload', 'class'], true);
}

/** Every address of a CIDR range worth sampling: first, an inner one, and last. */
function bms_sec_cidr_samples(string $cidr): array
{
    [$network, $bits] = explode('/', $cidr, 2);
    $packed = inet_pton($network);
    $bits = (int)$bits;
    $length = strlen($packed);
    $first = '';
    $last = '';
    $inner = '';
    for ($i = 0; $i < $length; $i++) {
        $hostBits = max(0, min(8, ($i + 1) * 8 - $bits));
        $mask = $hostBits >= 8 ? 0x00 : ((0xFF << $hostBits) & 0xFF);
        $byte = ord($packed[$i]) & $mask;
        $hostMask = (~$mask) & 0xFF;
        $first .= chr($byte);
        $last .= chr($byte | $hostMask);
        $inner .= chr($byte | ($hostMask & ($i === $length - 1 ? 0x05 : 0x5A)));
    }
    return array_values(array_unique([inet_ntop($first), inet_ntop($inner), inet_ntop($last)]));
}

/** Embed an IPv4 address in the three IPv6 forms that carry one. */
function bms_sec_ipv4_wrapped_forms(string $ipv4): array
{
    $octets = array_map('intval', explode('.', $ipv4));
    $high = sprintf('%x', ($octets[0] << 8) | $octets[1]);
    $low = sprintf('%x', ($octets[2] << 8) | $octets[3]);
    return [
        'IPv4-mapped' => '::ffff:' . $ipv4,
        'NAT64' => '64:ff9b::' . $high . ':' . $low,
        '6to4' => '2002:' . $high . ':' . $low . '::1',
    ];
}

/** Run one isolated child check and decode its JSON report. */
function bms_sec_run_child(string $tree, string $check): array
{
    global $root;
    $command = [PHP_BINARY, $root . '/scripts/security-hardening-test.php', '--child', $tree, $check];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start a child check.');
    }
    fclose($pipes[0]);
    $stdout = (string)stream_get_contents($pipes[1]);
    $stderr = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    $decoded = json_decode(trim($stdout), true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Child check ' . $check . ' returned no report. ' . trim($stdout . ' ' . $stderr));
    }
    return $decoded;
}

/**
 * Child process body. Loads the application from the disposable tree, so any
 * file the code writes stays inside that tree.
 */
function bms_sec_child(string $tree, string $check): void
{
    $report = [];
    try {
        require_once $tree . '/_bonumark_stream/app/functions.php';
        require_once $tree . '/_bonumark_stream/app/theme-installer.php';
        require_once $tree . '/_bonumark_stream/app/registration.php';
        $fixtures = $tree . '/fixtures';

        if ($check === 'theme-install-svg') {
            // A ZIP that ships an SVG must be refused before extraction.
            $zipPath = $fixtures . '/svg-theme.zip';
            $zip = new ZipArchive();
            $zip->open($zipPath);
            $message = '';
            try {
                bms_theme_installer_safe_extract($zip, $fixtures . '/extract-svg');
            } catch (Throwable $e) {
                $message = $e->getMessage();
            }
            $zip->close();
            $report['zip_message'] = $message;
            $report['zip_extracted_svg'] = is_file($fixtures . '/extract-svg/svg-theme/assets/images/evil.svg');

            // A manifest that declares an SVG screenshot must be refused even
            // when no SVG file is shipped.
            $message = '';
            try {
                bms_theme_installer_install_candidate([
                    'manifest' => $fixtures . '/declared-svg/theme.json',
                    'private_root' => $fixtures . '/declared-svg',
                    'public_root' => $fixtures . '/declared-svg',
                    'package_root' => $fixtures . '/declared-svg',
                ]);
            } catch (Throwable $e) {
                $message = $e->getMessage();
            }
            $report['manifest_message'] = $message;
            $report['declared_svg_installed'] = is_dir(bms_themes_path('declared-svg')) || is_dir(bms_public_theme_asset_path('declared-svg'));
            $report['allowed_svg'] = bms_theme_installer_is_allowed_file('assets/images/screenshot.svg');
            $report['allowed_png'] = bms_theme_installer_is_allowed_file('assets/images/screenshot.png');
        } elseif ($check === 'theme-legacy-svg') {
            // A theme installed before SVG was refused keeps working and active.
            $manifest = bms_read_theme_manifest('legacy-svg');
            $health = is_array($manifest) ? bms_public_theme_package_health($manifest) : [];
            $report['manifest_read'] = is_array($manifest);
            $report['manifest_errors'] = is_array($manifest) ? array_values((array)($manifest['manifest_errors'] ?? [])) : ['unreadable'];
            $report['valid'] = !empty($health['valid']);
            $report['warnings'] = array_values((array)($health['warnings'] ?? []));
            $report['active_slug'] = bms_active_public_theme_slug();
            $report['screenshot_url'] = bms_public_theme_screenshot_url('legacy-svg');
        } elseif ($check === 'bundled-theme') {
            $manifest = bms_read_theme_manifest('default');
            $health = is_array($manifest) ? bms_public_theme_package_health($manifest) : [];
            $report['screenshot'] = (string)($manifest['screenshot'] ?? '');
            $report['images'] = array_values((array)($manifest['assets']['images'] ?? []));
            $report['valid'] = !empty($health['valid']);
            $report['warnings'] = array_values((array)($health['warnings'] ?? []));
        } elseif ($check === 'verification-url') {
            $report['url'] = bms_registration_verification_url('token123');
        } else {
            $report['error'] = 'unknown check';
        }
    } catch (Throwable $e) {
        $report['exception'] = get_class($e) . ': ' . $e->getMessage();
    }
    fwrite(STDOUT, (string)json_encode($report, JSON_UNESCAPED_SLASHES) . "\n");
}

// ---------------------------------------------------------------------------
// H1: themes cannot ship SVG; installed SVG keeps working; passive-file rules.
// ---------------------------------------------------------------------------
$tree = bms_sec_temp_dir('tree');
try {
    bms_sec_copy_tree($root . '/_bonumark_stream/app', $tree . '/_bonumark_stream/app');
    bms_sec_copy_tree($root . '/_bonumark_stream/themes', $tree . '/_bonumark_stream/themes');
    bms_sec_copy_tree($root . '/assets/themes', $tree . '/assets/themes');
    copy($root . '/_bonumark_stream/VERSION', $tree . '/_bonumark_stream/VERSION');
    mkdir($tree . '/_bonumark_stream/tmp', 0700, true);
    mkdir($tree . '/fixtures/declared-svg/assets/css', 0700, true);

    // Config only, no installed.lock: settings resolve from config.php.
    file_put_contents($tree . '/_bonumark_stream/config.php', "<?php\nreturn " . var_export([
        'active_public_theme' => 'legacy-svg',
        'base_url' => 'https://example.test',
        'base_path' => '',
        'security_salt' => bin2hex(random_bytes(16)),
    ], true) . ";\n");

    $evilSvg = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><script>document.title = "x";</script></svg>';
    bms_sec_zip($tree . '/fixtures/svg-theme.zip', [
        'svg-theme/theme.json' => bms_sec_theme_manifest('svg-theme', 'assets/images/evil.svg', ['assets/images/evil.svg']),
        'svg-theme/assets/css/theme.css' => "body { color: #000; }\n",
        'svg-theme/assets/images/evil.svg' => $evilSvg,
    ]);
    file_put_contents($tree . '/fixtures/declared-svg/theme.json', bms_sec_theme_manifest('declared-svg', 'assets/images/screenshot.svg', []));
    file_put_contents($tree . '/fixtures/declared-svg/assets/css/theme.css', "body { color: #000; }\n");

    // A theme installed before the SVG rule, as Microblog Stream 2.1.4 is.
    mkdir($tree . '/_bonumark_stream/themes/legacy-svg', 0700, true);
    mkdir($tree . '/assets/themes/legacy-svg/assets/css', 0700, true);
    mkdir($tree . '/assets/themes/legacy-svg/assets/images', 0700, true);
    file_put_contents($tree . '/_bonumark_stream/themes/legacy-svg/theme.json', bms_sec_theme_manifest('legacy-svg', 'assets/images/screenshot.svg', ['assets/images/screenshot.svg']));
    file_put_contents($tree . '/assets/themes/legacy-svg/assets/css/theme.css', "body { color: #000; }\n");
    file_put_contents($tree . '/assets/themes/legacy-svg/assets/images/screenshot.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"/>');

    bms_sec_section('H1', static function () use ($tree): void {
        $install = bms_sec_run_child($tree, 'theme-install-svg');
        bms_sec_check('H1', 'Theme ZIP containing an SVG is refused', str_contains((string)($install['zip_message'] ?? ''), 'SVG'));
        bms_sec_check('H1', 'Refused SVG theme ZIP extracts nothing', empty($install['zip_extracted_svg']));
        bms_sec_check('H1', 'Refusal names the SVG file and the allowed formats', str_contains((string)($install['zip_message'] ?? ''), 'evil.svg') && str_contains((string)($install['zip_message'] ?? ''), 'PNG'));
        bms_sec_check('H1', 'Theme manifest declaring an SVG screenshot is refused', str_contains((string)($install['manifest_message'] ?? ''), 'SVG'));
        bms_sec_check('H1', 'Refused SVG manifest installs nothing', empty($install['declared_svg_installed']));
        bms_sec_check('H1', 'SVG is not an allowed theme package file', ($install['allowed_svg'] ?? true) === false);
        bms_sec_check('H1', 'PNG remains an allowed theme package file', ($install['allowed_png'] ?? false) === true);

        $legacy = bms_sec_run_child($tree, 'theme-legacy-svg');
        bms_sec_check('H1', 'Installed theme with an SVG screenshot still parses without manifest errors', !empty($legacy['manifest_read']) && ($legacy['manifest_errors'] ?? ['x']) === []);
        bms_sec_check('H1', 'Installed theme with an SVG screenshot stays safe to activate', !empty($legacy['valid']));
        bms_sec_check('H1', 'Installed theme with an SVG screenshot stays the active theme', ($legacy['active_slug'] ?? '') === 'legacy-svg');
        bms_sec_check('H1', 'Installed theme with an SVG screenshot keeps its screenshot URL', str_contains((string)($legacy['screenshot_url'] ?? ''), 'assets/images/screenshot.svg'));
        $svgWarnings = array_filter((array)($legacy['warnings'] ?? []), static fn($warning): bool => str_contains((string)$warning, 'SVG') && str_contains((string)$warning, 'screenshot.svg'));
        bms_sec_check('H1', 'Theme Health warns about the installed SVG file', $svgWarnings !== []);

        $bundled = bms_sec_run_child($tree, 'bundled-theme');
        bms_sec_check('H1', 'Midnight Ledger declares a PNG screenshot', ($bundled['screenshot'] ?? '') === 'assets/images/screenshot.png' && ($bundled['images'] ?? []) === ['assets/images/screenshot.png']);
        bms_sec_check('H1', 'Midnight Ledger passes Theme Health with no SVG warning', !empty($bundled['valid']) && !array_filter((array)($bundled['warnings'] ?? []), static fn($w): bool => str_contains((string)$w, 'SVG')));
    });
} finally {
    bms_sec_remove_tree($tree);
}

bms_sec_section('H1', static function () use ($root): void {
    $svgFiles = [];
    foreach (['_bonumark_stream/themes/default', 'assets/themes/default'] as $themeRoot) {
        if (!is_dir($root . '/' . $themeRoot)) {
            continue;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $themeRoot, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (strtolower($file->getExtension()) === 'svg') {
                $svgFiles[] = $file->getPathname();
            }
        }
    }
    bms_sec_check('H1', 'Both Midnight Ledger copies ship no SVG files', $svgFiles === []);
    bms_sec_check('H1', 'Both Midnight Ledger copies ship the PNG screenshot', is_file($root . '/_bonumark_stream/themes/default/assets/images/screenshot.png') && is_file($root . '/assets/themes/default/assets/images/screenshot.png'));

    $themePolicy = "Header always set Content-Security-Policy \"default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox\"";
    $mediaPolicy = "Header always set Content-Security-Policy \"default-src 'none'; img-src 'self' data:; media-src 'self'; style-src 'unsafe-inline'; sandbox\"";
    $nosniff = 'Header always set X-Content-Type-Options "nosniff"';
    foreach (['assets/themes/.htaccess' => $themePolicy, 'media/.htaccess' => $mediaPolicy] as $rules => $policy) {
        $source = bms_sec_file($rules);
        bms_sec_check('H1', $rules . ' sets the passive-file Content-Security-Policy', str_contains($source, $policy));
        bms_sec_check('H1', $rules . ' sets X-Content-Type-Options nosniff', str_contains($source, $nosniff));
    }

    $nginx = bms_sec_file('docs/server/bonumark-stream-nginx.conf');
    $nginxThemePolicy = "add_header Content-Security-Policy \"default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox\" always;";
    $nginxMediaPolicy = "add_header Content-Security-Policy \"default-src 'none'; img-src 'self' data:; media-src 'self'; style-src 'unsafe-inline'; sandbox\" always;";
    $themes = strpos($nginx, 'location ~ ^/assets/themes/ {');
    $media = strpos($nginx, 'location ~ ^/media/ {');
    $dotfiles = strpos($nginx, 'location ~ /\.(?!well-known(?:/|$)) {');
    $mediaPhp = strpos($nginx, 'location ~* ^/media/.*\.(?:php\d?|phtml|phar)(?:/|$) {');
    $php = strpos($nginx, 'location ~ \.php$ {');
    $cache = strpos($nginx, 'location ~* \.(?:css|js|mjs|jpg|jpeg|png|gif|webp|svg|ico|avif|woff|woff2)$ {');
    bms_sec_check('H1', 'Nginx example has passive-file locations for /assets/themes/ and /media/', $themes !== false && $media !== false);
    bms_sec_check('H1', 'Nginx passive-file locations send the policy and nosniff', substr_count($nginx, $nginxThemePolicy) >= 1 && substr_count($nginx, $nginxMediaPolicy) >= 2 && substr_count($nginx, 'add_header X-Content-Type-Options "nosniff" always;') >= 3);
    bms_sec_check('H1', 'Nginx passive-file locations follow the dotfile and media PHP denials', $themes !== false && $media !== false && $dotfiles !== false && $mediaPhp !== false && $dotfiles < $themes && $mediaPhp < $themes && $mediaPhp < $media);
    bms_sec_check('H1', 'Nginx passive-file locations precede the PHP and cache locations', $themes !== false && $media !== false && $php !== false && $cache !== false && $themes < $php && $media < $php && $themes < $cache && $media < $cache);
});

// ---------------------------------------------------------------------------
// M1: Markdown placeholders cannot reach an attribute.
// ---------------------------------------------------------------------------
bms_sec_section('M1', static function (): void {
    $payloads = [
        '`" onmouseover="x` [a](/x%%CODE0%%)',
        '`" onmouseover="x` https://example.com/%%CODE0%%',
        '`" autofocus onfocus="y` ![alt](/m%%CODE0%%.png)',
        '[a](/x`" onmouseover="y`)',
        '[a](/x "t`" onclick="z`")',
        '![a`" onerror="q`](/i.png)',
        '<img src="/x`" onerror="w`.png">',
        'https://example.com/`" data-stream-composer-mount data-stream-composer-endpoint="https://evil.example/`',
        '`" data-x="1` %%RAWIMAGE0%% %%IMAGE0%% %%CODE0%%',
        '`" onmouseover="x` [`a`](/x%%CODE1%% "%%CODE0%%")',
    ];
    foreach ($payloads as $index => $payload) {
        foreach (['inline' => bms_inline_markdown($payload), 'block' => bms_markdown_to_html($payload, false)] as $mode => $html) {
            $injected = array_filter(bms_sec_html_attributes($html), static fn(array $row): bool => !bms_sec_markdown_attribute_allowed($row[1]));
            // Authored text may spell %%CODE0%% inside a URL. Substituted
            // markup, quotes, or the placeholder marker must never get there.
            $brokenUrls = array_filter(bms_sec_html_attributes($html), static fn(array $row): bool => in_array($row[1], ['href', 'src'], true) && preg_match('/["<>\x1A]/', $row[2]) === 1);
            bms_sec_check('M1', 'Payload ' . ($index + 1) . ' (' . $mode . ') produces no injected attribute', $injected === []);
            bms_sec_check('M1', 'Payload ' . ($index + 1) . ' (' . $mode . ') keeps substituted markup out of URLs', $brokenUrls === []);
        }
    }

    $literal = bms_inline_markdown('`<b>` and %%CODE0%% literal');
    bms_sec_check('M1', 'Authored text that spells an old placeholder key stays literal', str_contains($literal, '%%CODE0%%') && substr_count($literal, '<code>') === 1);
    $code = bms_inline_markdown('`a"b\'c`');
    bms_sec_check('M1', 'Code span content escapes quotes', str_contains($code, '<code>a&quot;b&#039;c</code>'));

    // Ordinary Markdown is unchanged.
    bms_sec_check('M1', 'Code inside link text still renders', bms_inline_markdown('[`code` label](/x)') === '<a href="/x"><code>code</code> label</a>');
    bms_sec_check('M1', 'Image inside a link still renders', bms_inline_markdown('[![alt](/i.png)](https://e.example)') === '<a href="https://e.example"><img src="/i.png" alt="alt" loading="lazy" decoding="async"></a>');
    bms_sec_check('M1', 'Autolinks next to code spans still render', bms_inline_markdown('Visit https://example.com/path and `x`') === 'Visit <a href="https://example.com/path">https://example.com/path</a> and <code>x</code>');
    bms_sec_check('M1', 'Emphasis still renders', bms_inline_markdown('**bold** and _em_') === '<strong>bold</strong> and <em>em</em>');
});

// ---------------------------------------------------------------------------
// M4: one outbound address guard with an explicit denylist on every fetcher.
// ---------------------------------------------------------------------------
bms_sec_section('M4', static function (): void {
    $guards = [
        'link preview' => 'bms_link_preview_ip_is_public',
        'remote media import' => 'bms_import_remote_image_ip_is_public',
        'ActivityPub' => 'bms_activitypub_ip_is_public',
    ];
    if (function_exists('bms_network_ip_is_public')) {
        $guards['shared guard'] = 'bms_network_ip_is_public';
    }
    bms_sec_check('M4', 'A shared outbound address guard exists', function_exists('bms_network_ip_is_public') && function_exists('bms_network_public_host_ips'));

    // This table is independent of the implementation's own denylist.
    $denied = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
        '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15',
        '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
        '::/128', '::1/128', '::/96', '::ffff:0:0:0/96', '64:ff9b:1::/48', '100::/64', '2001::/23', '2001::/32',
        '2001:2::/48', '2001:db8::/32', '3fff::/20', '5f00::/16', 'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8',
        '::/8', '400::/6', '4000::/2', '8000::/1',
    ];
    $deniedIpv4Samples = [];
    foreach ($denied as $cidr) {
        foreach (bms_sec_cidr_samples($cidr) as $sample) {
            if (str_contains($sample, '.') && !str_contains($sample, ':')) {
                $deniedIpv4Samples[] = $sample;
            }
            foreach ($guards as $name => $guard) {
                bms_sec_check('M4', $name . ' refuses ' . $sample . ' (' . $cidr . ')', $guard($sample) === false);
            }
        }
    }

    // IPv6 forms that carry an IPv4 destination are unwrapped and rechecked.
    foreach (array_values(array_unique($deniedIpv4Samples)) as $ipv4) {
        foreach (bms_sec_ipv4_wrapped_forms($ipv4) as $form => $wrapped) {
            foreach ($guards as $name => $guard) {
                bms_sec_check('M4', $name . ' refuses ' . $form . ' ' . $wrapped . ' (' . $ipv4 . ')', $guard($wrapped) === false);
            }
        }
    }

    $public = ['8.8.8.8', '1.1.1.1', '93.184.216.34', '192.31.196.1', '2606:4700:4700::1111', '2001:4860:4860::8888', '2620:0:2d0:200::7'];
    foreach (bms_sec_ipv4_wrapped_forms('8.8.8.8') as $wrapped) {
        $public[] = $wrapped;
    }
    foreach ($public as $address) {
        foreach ($guards as $name => $guard) {
            bms_sec_check('M4', $name . ' accepts public address ' . $address, $guard($address) === true);
        }
    }

    foreach (['localhost', 'printer.local', 'db.internal', 'nas.home.arpa', 'svc.localhost', 'fe80::1%eth0', '127.1', ''] as $notAddress) {
        foreach ($guards as $name => $guard) {
            bms_sec_check('M4', $name . ' refuses non-address input ' . json_encode($notAddress), $guard($notAddress) === false);
        }
    }

    // URL-level entry points refuse the same destinations before any request.
    foreach (['http://[::ffff:127.0.0.1]/', 'http://[::ffff:169.254.169.254]/latest/meta-data/', 'http://100.64.0.1/', 'http://[64:ff9b::7f00:1]/', 'http://198.18.0.1/', 'http://[2002:7f00:1::1]/'] as $url) {
        bms_sec_check('M4', 'Link preview refuses ' . $url, bms_link_preview_fetch_target($url) === null);
        bms_sec_check('M4', 'Remote media import refuses ' . $url, bms_import_remote_image_fetch_target($url . 'image.png') === null);
        $httpsUrl = 'https://' . substr($url, strlen('http://'));
        $refused = false;
        try {
            bms_activitypub_validate_remote_url($httpsUrl . 'actor', static fn(string $host): array => ['93.184.216.34']);
        } catch (BmsActivityPubSecurityException $e) {
            $refused = true;
        }
        bms_sec_check('M4', 'ActivityPub refuses ' . $httpsUrl, $refused);
    }
    $refused = false;
    try {
        bms_activitypub_validate_remote_url('https://remote.example/actor', static fn(string $host): array => ['93.184.216.34', '::ffff:10.0.0.1']);
    } catch (BmsActivityPubSecurityException $e) {
        $refused = true;
    }
    bms_sec_check('M4', 'ActivityPub refuses a host when any resolved address is not public', $refused);
    bms_sec_check('M4', 'ActivityPub accepts a host that resolves only to public addresses', is_array(bms_activitypub_validate_remote_url('https://remote.example/actor', static fn(string $host): array => ['93.184.216.34', '2606:4700:4700::1111'])));
    bms_sec_check('M4', 'The guard does not depend on FILTER_FLAG_GLOBAL_RANGE', !str_contains(bms_sec_file('_bonumark_stream/app/network-guard.php') . bms_sec_file('_bonumark_stream/app/activitypub-security.php'), 'FILTER_FLAG_GLOBAL_RANGE'));
    fwrite(STDOUT, 'M4 runtime: PHP ' . PHP_VERSION . ', FILTER_FLAG_GLOBAL_RANGE ' . (defined('FILTER_FLAG_GLOBAL_RANGE') ? 'defined' : 'not defined') . "\n");
});

// ---------------------------------------------------------------------------
// L2: Sendmail path from config.php only; correct pipes; safe recipients.
// ---------------------------------------------------------------------------
bms_sec_section('L2', static function (): void {
    $work = bms_sec_temp_dir('sendmail');
    try {
        $capture = $work . '/stdin.txt';
        $argvFile = $work . '/argv.txt';
        $fake = $work . '/fake-sendmail';
        file_put_contents($fake, "#!/bin/sh\nprintf '%s\\n' \"\$@\" > " . escapeshellarg($argvFile) . "\ncat > " . escapeshellarg($capture) . "\nexit 0\n");
        chmod($fake, 0700);

        $settings = ['mail_sendmail_path' => $fake, 'mail_from_email' => 'sender@example.test', 'mail_from_name' => 'Fixture', 'mail_reply_to' => ''];
        $message = bms_mail_message_from_settings($settings, 'rcpt@example.test', 'Security fixture subject', "Fixture body line\n", 'plain_text');
        $sent = bms_mail_send_sendmail($settings, $message);
        $stdin = is_file($capture) ? (string)file_get_contents($capture) : '';
        bms_sec_check('L2', 'Sendmail receives the message on standard input', str_contains($stdin, 'Subject: Security fixture subject') && str_contains($stdin, 'Fixture body line'));
        bms_sec_check('L2', 'Sendmail receives -i and the recipient as separate arguments', is_file($argvFile) && trim((string)file_get_contents($argvFile)) === "-i\nrcpt@example.test");
        bms_sec_check('L2', 'Sendmail success is reported', ($sent['transport'] ?? '') === 'sendmail');

        foreach (['a/b@example.test', '-oQ/tmp/x@example.test', '-be@example.test', '|cmd@example.test'] as $badRecipient) {
            @unlink($argvFile);
            $unsafe = $message;
            $unsafe['to'] = [$badRecipient];
            $refused = false;
            try {
                bms_mail_send_sendmail($settings, $unsafe);
            } catch (RuntimeException $e) {
                $refused = true;
            }
            bms_sec_check('L2', 'Sendmail refuses recipient ' . $badRecipient . ' without starting the program', $refused && !is_file($argvFile));
        }

        $relative = false;
        try {
            bms_mail_send_sendmail(['mail_sendmail_path' => 'fake-sendmail'] + $settings, $message);
        } catch (RuntimeException $e) {
            $relative = true;
        }
        bms_sec_check('L2', 'Sendmail refuses a relative program path', $relative);
    } finally {
        bms_sec_remove_tree($work);
    }

    $admin = bms_sec_file('admin/mail.php');
    bms_sec_check('L2', 'Admin Mail has no Sendmail path input', !str_contains($admin, 'name="mail_sendmail_path"'));
    bms_sec_check('L2', 'Admin Mail never saves a Sendmail path', !str_contains($admin, "bms_set_setting('mail_sendmail_path'"));
    bms_sec_check('L2', 'Mail settings take the Sendmail path from config.php', function_exists('bms_mail_sendmail_path') && (bms_mail_settings()['mail_sendmail_path'] ?? '') === bms_mail_sendmail_path());
    bms_sec_check('L2', 'Fresh installs no longer store a Sendmail path setting', !str_contains(bms_sec_file('install.php'), "'mail_sendmail_path' =>") && !str_contains(bms_sec_file('_bonumark_stream/app/database.php'), "'mail_sendmail_path' =>"));
    bms_sec_check('L2', 'config.php keeps a safe default Sendmail path', str_contains(bms_sec_file('_bonumark_stream/config.sample.php'), "'mail_sendmail_path' => '/usr/sbin/sendmail'") && (bms_default_config()['mail_sendmail_path'] ?? '') === '/usr/sbin/sendmail');
});

// ---------------------------------------------------------------------------
// L3: PHP never runs from media/, and upgrades install media/.htaccess.
// ---------------------------------------------------------------------------
bms_sec_section('L3', static function () use ($root): void {
    $rules = bms_sec_file('media/.htaccess');
    bms_sec_check('L3', 'media/.htaccess denies PHP-like files', str_contains($rules, '<FilesMatch "(?i)\.(php\d?|phtml|phar)$">') && str_contains($rules, 'Require all denied') && str_contains($rules, 'Deny from all'));
    bms_sec_check('L3', 'media/.htaccess turns the PHP engine off where mod_php allows it', str_contains($rules, 'php_flag engine off'));
    if (preg_match('/<FilesMatch "([^"]+)">/', $rules, $match) === 1) {
        $pattern = '#' . $match[1] . '#';
        foreach (['shell.php', 'shell.PHP', 'shell.php5', 'shell.php8', 'shell.phtml', 'shell.phar'] as $name) {
            bms_sec_check('L3', 'media/.htaccess pattern denies ' . $name, preg_match($pattern, $name) === 1);
        }
        foreach (['photo.jpg', 'clip.mp4', 'notes.txt', 'paper.pdf', 'php.png'] as $name) {
            bms_sec_check('L3', 'media/.htaccess pattern allows ' . $name, preg_match($pattern, $name) !== 1);
        }
    } else {
        bms_sec_check('L3', 'media/.htaccess declares a FilesMatch pattern', false);
    }
    bms_sec_check('L3', 'media/.htaccess is package-managed', bms_package_managed_software_path('media/.htaccess'));
    bms_sec_check('L3', 'Other media files stay owner data', !bms_package_managed_software_path('media/photo.jpg') && !bms_package_managed_software_path('media/.gitkeep'));

    $package = bms_sec_temp_dir('package');
    try {
        mkdir($package . '/_bonumark_stream', 0700, true);
        file_put_contents($package . '/_bonumark_stream/RELEASE-MANIFEST.json', (string)json_encode(['name' => 'bonumark-stream', 'version' => '99.0.0', 'files' => [
            ['path' => 'index.php', 'sha256' => str_repeat('a', 64)],
            ['path' => 'media/.gitkeep', 'sha256' => str_repeat('b', 64)],
            ['path' => 'media/.htaccess', 'sha256' => str_repeat('c', 64)],
        ]]));
        $items = bms_upgrade_software_items($package);
        bms_sec_check('L3', 'Upgrades install media/.htaccess', in_array('media/.htaccess', $items, true));
        bms_sec_check('L3', 'Upgrades never replace the media folder itself', !in_array('media', $items, true) && !in_array('media/.gitkeep', $items, true));
    } finally {
        bms_sec_remove_tree($package);
    }
});

// ---------------------------------------------------------------------------
// #54, #55: minimal API status; dot-directories denied except .well-known.
// ---------------------------------------------------------------------------
bms_sec_section('#54', static function (): void {
    $payload = bms_api_status_payload(null);
    bms_sec_check('#54', 'Unauthenticated status returns only ok, api, and authenticated', array_keys($payload) === ['ok', 'api', 'authenticated']);
    bms_sec_check('#54', 'Unauthenticated status keeps the System Check probe marker', ($payload['ok'] ?? false) === true && ($payload['api'] ?? '') === 'bonumark-stream' && bms_public_url_probe_response(200, (string)json_encode($payload))['status'] === 'pass');
    bms_sec_check('#54', 'Unauthenticated status omits the version', !array_key_exists('version', $payload) && !str_contains((string)json_encode($payload), bms_version()));
    $authenticated = bms_api_status_payload(['id' => 7, 'token_name' => 'Fixture', 'scopes_json' => '["status:read"]', 'expires_at' => '']);
    bms_sec_check('#54', 'Authenticated status still reports the version and API settings', ($authenticated['version'] ?? '') === bms_version() && array_key_exists('remote_posting_enabled', $authenticated) && ($authenticated['authenticated'] ?? false) === true);

    $attributes = bms_sec_file('.gitattributes');
    foreach (['/.gitattributes', '/.github/', '/CLAUDE.md', '/docs/development/'] as $path) {
        bms_sec_check('#54', $path . ' is export-ignored from release ZIPs', preg_match('#^' . preg_quote($path, '#') . '\s+export-ignore\s*$#m', $attributes) === 1);
    }
});

bms_sec_section('#55', static function (): void {
    $htaccess = bms_sec_file('.htaccess');
    $dotPattern = '';
    if (preg_match('/<FilesMatch "(\^\\\\\.[^"]*)">/', $htaccess, $match) === 1) {
        $dotPattern = '#' . $match[1] . '#';
    }
    bms_sec_check('#55', 'Root .htaccess has a dotfile FilesMatch rule', $dotPattern !== '');
    if ($dotPattern !== '') {
        bms_sec_check('#55', 'Dotfile rule does not block .well-known', preg_match($dotPattern, '.well-known') !== 1);
        foreach (['.htaccess', '.env', '.git', '.github', '.well-knownx', '.gitattributes'] as $name) {
            bms_sec_check('#55', 'Dotfile rule still denies ' . $name, preg_match($dotPattern, $name) === 1);
        }
    }
    $rewritePattern = '';
    if (preg_match('/^\s*RewriteRule\s+(\S+)\s+-\s+\[F\]\s*$/m', $htaccess, $match) === 1) {
        $rewritePattern = '#' . $match[1] . '#';
    }
    bms_sec_check('#54', 'Root .htaccess denies dot-directories', $rewritePattern !== '');
    if ($rewritePattern !== '') {
        foreach (['.github/workflows/compatibility.yml', '.git/config', 'assets/.hidden/x.css', '.env'] as $path) {
            bms_sec_check('#54', 'Dot-directory rule denies ' . $path, preg_match($rewritePattern, $path) === 1);
        }
        foreach (['.well-known/webfinger', '.well-known/acme-challenge/token', 'stream/post-slug', 'assets/stream.js'] as $path) {
            bms_sec_check('#55', 'Dot-directory rule allows ' . $path, preg_match($rewritePattern, $path) !== 1);
        }
        $denyPosition = strpos($htaccess, 'RewriteRule ' . trim($rewritePattern, '#') . ' - [F]');
        $webfingerPosition = strpos($htaccess, 'RewriteRule ^\.well-known/webfinger/?$');
        bms_sec_check('#54', 'Dot-directory denial runs before the route rules', $denyPosition !== false && $webfingerPosition !== false && $denyPosition < $webfingerPosition);
    }
});

// ---------------------------------------------------------------------------
// #57, #51, #44: absolute verification link, honest backup wording, tokens.
// ---------------------------------------------------------------------------
$tree = bms_sec_temp_dir('verify');
try {
    bms_sec_copy_tree($root . '/_bonumark_stream/app', $tree . '/_bonumark_stream/app');
    copy($root . '/_bonumark_stream/VERSION', $tree . '/_bonumark_stream/VERSION');
    file_put_contents($tree . '/_bonumark_stream/config.php', "<?php\nreturn " . var_export(['base_url' => 'https://example.test', 'base_path' => '', 'security_salt' => bin2hex(random_bytes(16))], true) . ";\n");
    bms_sec_section('#57', static function () use ($tree): void {
        $report = bms_sec_run_child($tree, 'verification-url');
        bms_sec_check('#57', 'Verification email link is absolute from the stored base URL', ($report['url'] ?? '') === 'https://example.test/account.php?action=verify&token=token123');
    });
} finally {
    bms_sec_remove_tree($tree);
}

bms_sec_section('#51', static function (): void {
    $upgrade = bms_sec_file('admin/upgrade.php');
    bms_sec_check('#51', 'Upgrade completion says Software backup created', str_contains($upgrade, 'Software backup created'));
    bms_sec_check('#51', 'Upgrade completion says the database is not backed up', str_contains($upgrade, 'The upgrader does not back up the database.'));
    bms_sec_check('#51', 'Upgrade no longer reports a bare Backup created', !str_contains($upgrade, ". Backup created and "));
});

bms_sec_section('#44', static function (): void {
    $css = bms_sec_file('assets/admin.css');
    $rule = '';
    if (preg_match('/body\.bonumark-admin \.upgrade-details > div \{([^}]*)\}/', $css, $match) === 1) {
        $rule = $match[1];
    }
    bms_sec_check('#44', 'Upgrade details divider uses the Admin border token', str_contains($rule, 'border-top: 1px solid var(--admin-border);') && !str_contains($rule, 'rgba('));
});

fwrite(STDOUT, sprintf("\nSecurity hardening test: %d passed, %d failed.\n", $results['pass'], $results['fail']));
if ($results['fail'] > 0) {
    foreach ($results['failed'] as $failed) {
        fwrite(STDERR, 'FAILED ' . $failed . "\n");
    }
    exit(1);
}

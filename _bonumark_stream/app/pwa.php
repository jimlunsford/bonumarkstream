<?php
require_once __DIR__ . '/functions.php';


function bms_pwa_limit_text(string $value, int $limit): string
{
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($value, 'UTF-8') > $limit ? mb_substr($value, 0, $limit, 'UTF-8') : $value;
    }
    return strlen($value) > $limit ? substr($value, 0, $limit) : $value;
}

function bms_pwa_enabled(): bool
{
    return (string)bms_setting_or_config('pwa_enabled', '1') === '1';
}

function bms_pwa_share_target_enabled(): bool
{
    return bms_pwa_enabled() && (string)bms_setting_or_config('pwa_share_target_enabled', '1') === '1';
}

function bms_pwa_theme_color(): string
{
    $color = trim((string)bms_setting_or_config('pwa_theme_color', '#111827'));
    return preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1 ? $color : '#111827';
}

function bms_pwa_background_color(): string
{
    $color = trim((string)bms_setting_or_config('pwa_background_color', '#0f172a'));
    return preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1 ? $color : '#0f172a';
}

function bms_pwa_app_name(): string
{
    $name = trim(bms_plain_text((string)bms_setting_or_config('site_name', 'Bonumark Stream')));
    return $name !== '' ? bms_pwa_limit_text($name, 80) : 'Bonumark Stream';
}

function bms_pwa_short_name(): string
{
    $name = trim(bms_plain_text((string)bms_setting_or_config('site_name', 'Bonumark')));
    if ($name === '') {
        return 'Bonumark';
    }
    return bms_pwa_limit_text($name, 24);
}

function bms_pwa_description(): string
{
    $description = trim(bms_plain_text((string)bms_setting_or_config('site_tagline', 'A self-hosted microblog CMS for publishing short-form posts on a site you control.')));
    if ($description === '') {
        $description = 'A self-hosted microblog CMS for publishing short-form posts on a site you control.';
    }
    return bms_pwa_limit_text($description, 220);
}

function bms_pwa_manifest_url(): string
{
    return bms_url_path('manifest.php');
}

function bms_pwa_service_worker_url(): string
{
    return bms_url_path('sw.js');
}

function bms_pwa_scope_url(): string
{
    return bms_url_path();
}

function bms_pwa_script_url(): string
{
    return bms_asset_url('assets/pwa.js');
}


function bms_pwa_fallback_icon_url(int $size): string
{
    $fallbackSize = $size >= 512 ? 512 : 192;
    return bms_url_path('assets/icons/bonumark-icon-' . $fallbackSize . '.png');
}

function bms_pwa_icon_source(): array
{
    if (!function_exists('bms_site_favicon_view_data')) {
        return [];
    }

    $favicon = bms_site_favicon_view_data();
    $path = trim(str_replace('\\', '/', (string)($favicon['path'] ?? '')), '/');
    if ($path === '' || !str_starts_with($path, 'media/') || str_contains($path, "\0") || preg_match('#(^|/)\\.\\.(/|$)#', $path) === 1) {
        return [];
    }

    $file = bms_public_path($path);
    if (!is_file($file) || !is_readable($file)) {
        return [];
    }

    $info = @getimagesize($file);
    $width = is_array($info) ? (int)($info[0] ?? 0) : 0;
    $height = is_array($info) ? (int)($info[1] ?? 0) : 0;
    $mime = strtolower(trim((string)(is_array($info) ? ($info['mime'] ?? '') : '')));
    if ($width < 1 || $height < 1 || !in_array($mime, ['image/jpeg', 'image/pjpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
        return [];
    }

    return [
        'path' => $path,
        'file' => $file,
        'mime' => $mime,
        'width' => $width,
        'height' => $height,
    ];
}

function bms_pwa_icon_gd_loader(string $mime): string
{
    return match (strtolower($mime)) {
        'image/jpeg', 'image/pjpeg' => 'imagecreatefromjpeg',
        'image/png' => 'imagecreatefrompng',
        'image/gif' => 'imagecreatefromgif',
        'image/webp' => 'imagecreatefromwebp',
        default => '',
    };
}

function bms_pwa_can_render_site_icon(array $source = []): bool
{
    if (!$source) {
        return false;
    }

    $loader = bms_pwa_icon_gd_loader((string)($source['mime'] ?? ''));
    $gdReady = $loader !== ''
        && function_exists($loader)
        && function_exists('imagecreatetruecolor')
        && function_exists('imagecopyresampled')
        && function_exists('imagepng');

    return $gdReady || class_exists('Imagick');
}

function bms_pwa_site_icon_version(array $source): string
{
    $path = (string)($source['path'] ?? '');
    $file = (string)($source['file'] ?? '');
    $stamp = is_file($file) ? (string)@filemtime($file) : '0';
    $bytes = is_file($file) ? (string)@filesize($file) : '0';
    return substr(hash('sha256', $path . '|' . $stamp . '|' . $bytes), 0, 16);
}

function bms_pwa_site_icon_direct_url(array $source): string
{
    $path = trim(str_replace('\\', '/', (string)($source['path'] ?? '')), '/');
    if ($path === '' || !str_starts_with($path, 'media/')) {
        return '';
    }

    $url = bms_url_path($path);
    $separator = str_contains($url, '?') ? '&' : '?';
    return $url . $separator . 'v=' . rawurlencode(bms_pwa_site_icon_version($source));
}

function bms_pwa_site_icon_native_size(array $source): string
{
    $width = max(0, (int)($source['width'] ?? 0));
    $height = max(0, (int)($source['height'] ?? 0));
    return $width > 0 && $height > 0 ? $width . 'x' . $height : 'any';
}

function bms_pwa_icon_url(int $size): string
{
    $size = max(1, $size);
    $source = bms_pwa_icon_source();
    if ($source && bms_pwa_can_render_site_icon($source)) {
        return bms_url_path('pwa-icon.php?size=' . $size . '&v=' . rawurlencode(bms_pwa_site_icon_version($source)));
    }

    // A selected Site Identity favicon must still be the installed-app icon on
    // minimal shared-hosting PHP builds that do not provide GD or Imagick.
    $directUrl = $source ? bms_pwa_site_icon_direct_url($source) : '';
    if ($directUrl !== '') {
        return $directUrl;
    }

    return bms_pwa_fallback_icon_url($size);
}

function bms_pwa_apple_touch_icon_url(): string
{
    return bms_pwa_icon_url(180);
}

function bms_pwa_manifest_icons(): array
{
    $source = bms_pwa_icon_source();
    if ($source && !bms_pwa_can_render_site_icon($source)) {
        $directUrl = bms_pwa_site_icon_direct_url($source);
        if ($directUrl !== '') {
            return [
                [
                    'src' => $directUrl,
                    'sizes' => bms_pwa_site_icon_native_size($source),
                    'type' => (string)($source['mime'] ?? 'image/png'),
                    // Do not claim maskable safe-zone support for an original
                    // favicon that Bonumark could not crop and pad itself.
                    'purpose' => 'any',
                ],
            ];
        }
    }

    return [
        [
            'src' => bms_pwa_icon_url(192),
            'sizes' => '192x192',
            'type' => 'image/png',
            'purpose' => 'any',
        ],
        [
            'src' => bms_pwa_icon_url(512),
            'sizes' => '512x512',
            'type' => 'image/png',
            'purpose' => 'any maskable',
        ],
    ];
}

function bms_pwa_icon_size_from_request(): int
{
    $size = (int)($_GET['size'] ?? 192);
    return in_array($size, [180, 192, 512], true) ? $size : 192;
}

function bms_pwa_icon_background_rgb(): array
{
    $color = ltrim(bms_pwa_background_color(), '#');
    if (!preg_match('/^[0-9a-fA-F]{6}$/', $color)) {
        $color = '0f172a';
    }
    return [
        hexdec(substr($color, 0, 2)),
        hexdec(substr($color, 2, 2)),
        hexdec(substr($color, 4, 2)),
    ];
}

function bms_pwa_render_site_icon_with_gd(array $source, int $size): bool
{
    $loader = bms_pwa_icon_gd_loader((string)($source['mime'] ?? ''));
    $file = (string)($source['file'] ?? '');
    $sourceWidth = (int)($source['width'] ?? 0);
    $sourceHeight = (int)($source['height'] ?? 0);
    if ($loader === '' || !function_exists($loader) || !function_exists('imagecreatetruecolor') || !function_exists('imagecopyresampled') || !function_exists('imagepng') || !is_file($file) || $sourceWidth < 1 || $sourceHeight < 1) {
        return false;
    }

    $image = @$loader($file);
    if (!$image) {
        return false;
    }

    $canvas = imagecreatetruecolor($size, $size);
    if (!$canvas) {
        imagedestroy($image);
        return false;
    }

    [$red, $green, $blue] = bms_pwa_icon_background_rgb();
    $background = imagecolorallocate($canvas, $red, $green, $blue);
    if ($background !== false) {
        imagefill($canvas, 0, 0, $background);
    }
    imagealphablending($canvas, true);

    $scale = max($size / $sourceWidth, $size / $sourceHeight);
    $cropWidth = max(1, (int)round($size / $scale));
    $cropHeight = max(1, (int)round($size / $scale));
    $sourceX = max(0, (int)floor(($sourceWidth - $cropWidth) / 2));
    $sourceY = max(0, (int)floor(($sourceHeight - $cropHeight) / 2));

    $copied = imagecopyresampled($canvas, $image, 0, 0, $sourceX, $sourceY, $size, $size, $cropWidth, $cropHeight);
    imagedestroy($image);
    if (!$copied) {
        imagedestroy($canvas);
        return false;
    }

    $written = imagepng($canvas, null, 6);
    imagedestroy($canvas);
    return (bool)$written;
}

function bms_pwa_render_site_icon_with_imagick(array $source, int $size): bool
{
    if (!class_exists('Imagick') || !is_file((string)($source['file'] ?? ''))) {
        return false;
    }

    try {
        $image = new Imagick((string)$source['file']);
        if ($image->getNumberImages() > 1) {
            $image->setIteratorIndex(0);
        }
        $image->setImageBackgroundColor(bms_pwa_background_color());
        $image->cropThumbnailImage($size, $size);
        $image->setImageFormat('png');
        echo $image->getImagesBlob();
        $image->clear();
        $image->destroy();
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function bms_pwa_output_fallback_icon(int $size): void
{
    $fallback = bms_public_path('assets/icons/bonumark-icon-' . ($size >= 512 ? 512 : 192) . '.png');
    if (is_file($fallback) && is_readable($fallback)) {
        readfile($fallback);
        return;
    }
    http_response_code(404);
}

function bms_pwa_output_icon(int $size): void
{
    $size = in_array($size, [180, 192, 512], true) ? $size : 192;
    header('Content-Type: image/png');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: public, max-age=31536000, immutable');

    $source = bms_pwa_icon_source();
    if ($source && bms_pwa_can_render_site_icon($source)) {
        if (bms_pwa_render_site_icon_with_gd($source, $size)) {
            return;
        }
        if (bms_pwa_render_site_icon_with_imagick($source, $size)) {
            return;
        }
    }

    bms_pwa_output_fallback_icon($size);
}

function bms_pwa_meta_tags(): string
{
    if (!bms_pwa_enabled()) {
        if (isset($_GET['bms-pwa-clear'])) {
            $scriptUrl = htmlspecialchars(bms_pwa_script_url(), ENT_QUOTES, 'UTF-8');
            return '  <script src="' . $scriptUrl . '" defer></script>' . "\n";
        }
        return '';
    }

    $manifest = htmlspecialchars(bms_pwa_manifest_url(), ENT_QUOTES, 'UTF-8');
    $themeColor = htmlspecialchars(bms_pwa_theme_color(), ENT_QUOTES, 'UTF-8');
    $name = htmlspecialchars(bms_pwa_app_name(), ENT_QUOTES, 'UTF-8');
    $swUrl = htmlspecialchars(bms_pwa_service_worker_url(), ENT_QUOTES, 'UTF-8');
    $scopeUrl = htmlspecialchars(bms_pwa_scope_url(), ENT_QUOTES, 'UTF-8');
    $scriptUrl = htmlspecialchars(bms_pwa_script_url(), ENT_QUOTES, 'UTF-8');
    $iconUrl = htmlspecialchars(bms_pwa_apple_touch_icon_url(), ENT_QUOTES, 'UTF-8');

    return '  <link rel="manifest" href="' . $manifest . '">' . "\n"
        . '  <meta name="theme-color" content="' . $themeColor . '">' . "\n"
        . '  <meta name="application-name" content="' . $name . '">' . "\n"
        . '  <meta name="apple-mobile-web-app-capable" content="yes">' . "\n"
        . '  <meta name="apple-mobile-web-app-title" content="' . $name . '">' . "\n"
        . '  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">' . "\n"
        . '  <meta name="mobile-web-app-capable" content="yes">' . "\n"
        . '  <meta name="bonumark-service-worker" content="' . $swUrl . '">' . "\n"
        . '  <meta name="bonumark-service-worker-scope" content="' . $scopeUrl . '">' . "\n"
        . '  <link rel="apple-touch-icon" href="' . $iconUrl . '">' . "\n"
        . '  <script src="' . $scriptUrl . '" defer></script>' . "\n";
}

function bms_public_head_has_pwa_tags(string $headHtml): bool
{
    return preg_match('/<link\s+[^>]*rel=["\']?manifest(?:["\']|\s|>)/i', $headHtml) === 1;
}

function bms_inject_public_pwa_tags(string $html): string
{
    if ($html === '' || !bms_pwa_enabled()) {
        return $html;
    }
    if (preg_match('/<head\b[^>]*>(.*?)<\/head>/is', $html, $matches) !== 1) {
        return $html;
    }
    $headHtml = (string)($matches[1] ?? '');
    if (bms_public_head_has_pwa_tags($headHtml)) {
        return $html;
    }
    $tags = bms_pwa_meta_tags();
    if (trim($tags) === '') {
        return $html;
    }
    return preg_replace('/<\/head>/i', rtrim($tags) . "\n</head>", $html, 1) ?? $html;
}

function bms_pwa_manifest_data(): array
{
    $manifest = [
        'name' => bms_pwa_app_name(),
        'short_name' => bms_pwa_short_name(),
        'description' => bms_pwa_description(),
        'id' => bms_url_path(),
        'start_url' => bms_url_path(),
        'scope' => bms_url_path(),
        'display' => 'standalone',
        'display_override' => ['standalone', 'minimal-ui', 'browser'],
        'theme_color' => bms_pwa_theme_color(),
        'background_color' => bms_pwa_background_color(),
        'orientation' => 'any',
        'icons' => bms_pwa_manifest_icons(),
    ];

    if (bms_pwa_share_target_enabled()) {
        $manifest['share_target'] = [
            'action' => bms_admin_url('share-target.php'),
            'method' => 'POST',
            'enctype' => 'application/x-www-form-urlencoded',
            'params' => [
                'title' => 'title',
                'text' => 'text',
                'url' => 'url',
            ],
        ];
    }

    return $manifest;
}

function bms_share_target_clean_text(string $value, int $limit): string
{
    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = str_replace(["\r\n", "\r"], "\n", $value);
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? $value;
    $value = trim($value);
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($value, 'UTF-8') > $limit) {
            $value = mb_substr($value, 0, $limit, 'UTF-8');
        }
    } elseif (strlen($value) > $limit) {
        $value = substr($value, 0, $limit);
    }
    return $value;
}

function bms_share_target_clean_url(string $url): string
{
    $url = bms_share_target_clean_text($url, 2048);
    if ($url === '') {
        return '';
    }
    if (!preg_match('#^https?://#i', $url)) {
        return '';
    }
    return filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
}

function bms_share_target_payload_from_array(array $source): array
{
    $title = bms_share_target_clean_text((string)($source['title'] ?? ''), 300);
    $text = bms_share_target_clean_text((string)($source['text'] ?? ''), 10000);
    $url = bms_share_target_clean_url((string)($source['url'] ?? ''));
    return [
        'title' => $title,
        'text' => $text,
        'url' => $url,
    ];
}

function bms_share_target_payload_is_empty(array $payload): bool
{
    return trim((string)($payload['title'] ?? '')) === ''
        && trim((string)($payload['text'] ?? '')) === ''
        && trim((string)($payload['url'] ?? '')) === '';
}

function bms_share_target_body_from_payload(array $payload): string
{
    $title = trim((string)($payload['title'] ?? ''));
    $text = trim((string)($payload['text'] ?? ''));
    $url = trim((string)($payload['url'] ?? ''));
    $parts = [];

    if ($title !== '' && ($text === '' || !str_contains($text, $title))) {
        $parts[] = $title;
    }
    if ($text !== '') {
        $parts[] = $text;
    }
    if ($url !== '' && !str_contains($text, $url)) {
        $parts[] = $url;
    }

    return trim(implode("\n\n", $parts));
}

function bms_share_target_store_pending(array $payload): void
{
    $_SESSION['bms_share_target_pending'] = [
        'title' => (string)($payload['title'] ?? ''),
        'text' => (string)($payload['text'] ?? ''),
        'url' => (string)($payload['url'] ?? ''),
        'created_at' => time(),
    ];
}

function bms_share_target_pending_payload(): array
{
    $payload = $_SESSION['bms_share_target_pending'] ?? [];
    if (!is_array($payload)) {
        return [];
    }
    if ((int)($payload['created_at'] ?? 0) < time() - 900) {
        unset($_SESSION['bms_share_target_pending']);
        return [];
    }
    return bms_share_target_payload_from_array($payload);
}

function bms_share_target_clear_pending(): void
{
    unset($_SESSION['bms_share_target_pending']);
}

function bms_share_target_front_composer_url(): string
{
    return bms_url_path('?bms-shared=1');
}

function bms_share_target_take_pending_payload(): array
{
    $payload = bms_share_target_pending_payload();
    if (!bms_share_target_payload_is_empty($payload)) {
        bms_share_target_clear_pending();
    }
    return $payload;
}

function bms_share_target_rate_limit_path(): string
{
    return bms_root_path('tmp/share-target-rate-limits.json');
}

function bms_share_target_client_hash(): string
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    return hash('sha256', $ip . '|share-target|' . (string)(bms_config()['security_salt'] ?? 'bonumark'));
}

function bms_share_target_rate_limited(): bool
{
    $windowSeconds = 300;
    $limit = 30;
    $now = time();
    $path = bms_share_target_rate_limit_path();
    $directory = dirname($path);

    if (!is_dir($directory) && !@mkdir($directory, 0755, true)) {
        error_log('Bonumark Stream share target rate limit storage is unavailable.');
        return true;
    }

    $handle = @fopen($path, 'c+');
    if (!$handle) {
        error_log('Bonumark Stream share target rate limit storage is unavailable.');
        return true;
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            error_log('Bonumark Stream share target rate limit lock is unavailable.');
            return true;
        }

        rewind($handle);
        $raw = stream_get_contents($handle);
        $stored = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : [];
        $stored = is_array($stored) ? $stored : [];
        $cutoff = $now - $windowSeconds;
        $clean = [];

        foreach ($stored as $clientHash => $timestamps) {
            if (!is_string($clientHash) || !preg_match('/^[a-f0-9]{64}$/', $clientHash) || !is_array($timestamps)) {
                continue;
            }
            $timestamps = array_values(array_filter(
                $timestamps,
                static fn($timestamp): bool => is_numeric($timestamp) && (int)$timestamp > $cutoff
            ));
            if ($timestamps) {
                $clean[$clientHash] = $timestamps;
            }
        }

        // Keep the private throttle store bounded during distributed abuse.
        // The state contains only salted client hashes and recent timestamps.
        if (count($clean) > 1000) {
            uasort($clean, static fn(array $left, array $right): int => max($right) <=> max($left));
            $clean = array_slice($clean, 0, 1000, true);
        }

        $clientHash = bms_share_target_client_hash();
        $attempts = $clean[$clientHash] ?? [];
        $attempts[] = $now;
        $clean[$clientHash] = $attempts;
        $limited = count($attempts) > $limit;

        $encoded = json_encode($clean, JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            error_log('Bonumark Stream could not encode share target rate limit state.');
            return true;
        }

        rewind($handle);
        if (!ftruncate($handle, 0) || fwrite($handle, $encoded) === false || !fflush($handle)) {
            error_log('Bonumark Stream could not persist share target rate limit state.');
            return true;
        }

        return $limited;
    } finally {
        @flock($handle, LOCK_UN);
        fclose($handle);
    }
}

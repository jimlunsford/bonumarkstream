<?php

function bms_set_public_preview_mode(bool $enabled): void
{
    $GLOBALS['bms_public_preview_mode'] = $enabled;
}

function bms_public_preview_mode(): bool
{
    return !empty($GLOBALS['bms_public_preview_mode']);
}

function bms_with_public_preview_mode(callable $callback): mixed
{
    $previous = !empty($GLOBALS['bms_public_preview_mode']);
    $GLOBALS['bms_public_preview_mode'] = true;
    try {
        return $callback();
    } finally {
        $GLOBALS['bms_public_preview_mode'] = $previous;
    }
}

function bms_config_path(): string
{
    return dirname(__DIR__) . '/config.php';
}

function bms_installed_lock_path(): string
{
    return dirname(__DIR__) . '/installed.lock';
}

function bms_default_config(): array
{
    return [
        'site_name' => 'Bonumark Stream',
        'site_tagline' => 'A self-hosted microblog CMS for publishing short-form posts on a site you control.',
        'active_public_theme' => 'default',
        'show_powered_by' => '1',
        'site_favicon_media_id' => '0',
        'site_favicon_path' => '',
        'public_navigation_account_links_enabled' => '1',
        'remote_posting_enabled' => '0',
        'remote_posting_direct_publish_enabled' => '0',
        'remote_posting_default_status' => 'draft',
        'remote_posting_publish_confirmation_required' => '1',
        'remote_posting_rate_limit_per_minute' => '60',
        'remote_media_upload_enabled' => '0',
        'activitypub_enabled' => '0',
        'pwa_enabled' => '1',
        'pwa_share_target_enabled' => '1',
        'pwa_theme_color' => '#111827',
        'pwa_background_color' => '#0f172a',
        'remember_login_enabled' => '1',
        'remember_login_days' => '30',
        'scheduled_tasks_expected_interval_minutes' => '5',
        'scheduled_tasks_public_traffic_enabled' => '1',
        'scheduled_tasks_heartbeat_enabled' => '1',
        'scheduled_tasks_web_cron_enabled' => '0',
        'analytics_enabled' => '0',
        'analytics_retention_days' => '90',
        'analytics_last_cleanup_date' => '',
        'version' => '0.7.2',
        'author_name' => 'Admin',
        'base_path' => '',
        'base_url' => '',
        'public_path' => '',
        'stream_composer_enabled' => '1',
        'stream_posts_per_page' => '20',
        'stream_show_dates' => '1',
        'stream_show_edit_links' => '0',
        'stream_index_policy' => 'smart',
        'sitemap_enabled' => '1',
        'sitemap_include_stream_posts' => '1',
        'sitemap_include_pages' => '1',
        'sitemap_include_profiles' => '0',
        'content_storage_mode' => 'database',
                'comments_enabled' => '1',
        'comment_registration_enabled' => '0',
        'comments_default_status' => 'approved',
        'registration_mode' => 'disabled',
        'registration_default_role' => 'commenter',
        'registration_require_email_verification' => '1',
        'registration_require_admin_approval' => '0',
        'registration_honeypot_enabled' => '1',
        'media_upload_limit_mb' => '32',
        'media_privacy_mode' => 'best_effort',
        'mail_transport' => 'disabled',
        'mail_from_name' => 'Bonumark Stream',
        'mail_from_email' => '',
        'mail_reply_to' => '',
        'mail_smtp_host' => '',
        'mail_smtp_port' => '587',
        'mail_smtp_encryption' => 'tls',
        'mail_smtp_username' => '',
        'mail_smtp_password' => '',
        'mail_sendmail_path' => '/usr/sbin/sendmail',
        'timezone' => 'UTC',
        'security_salt' => '',
        'database' => [
            'host' => '',
            'name' => '',
            'user' => '',
            'password' => '',
            'charset' => 'utf8mb4',
            'prefix' => 'bms_',
        ],
    ];
}

function bms_config_exists(): bool
{
    return is_file(bms_config_path());
}

function bms_config(bool $reload = false): array
{
    static $config = null;
    if ($reload) {
        $config = null;
    }
    if ($config === null) {
        $config = bms_default_config();
        if (bms_config_exists()) {
            $loaded = require bms_config_path();
            if (is_array($loaded)) {
                $config = array_replace_recursive($config, $loaded);
            }
        }
        date_default_timezone_set($config['timezone'] ?? 'UTC');
    }
    return $config;
}

function bms_is_installed(): bool
{
    $config = bms_config();
    $db = $config['database'] ?? [];
    return is_file(bms_installed_lock_path()) && is_array($db) && !empty($db['host']) && !empty($db['name']) && !empty($db['user']);
}

function bms_require_installed(): void
{
    if (!bms_is_installed()) {
        bms_redirect(bms_url_path('install.php'));
    }
}

function bms_setting_or_config(string $key, mixed $default = ''): mixed
{
    if (function_exists('bms_setting') && bms_is_installed()) {
        try {
            return bms_setting($key, bms_config()[$key] ?? $default);
        } catch (Throwable $e) {
            return bms_config()[$key] ?? $default;
        }
    }
    return bms_config()[$key] ?? $default;
}

function bms_site_timezone_name(): string
{
    $timezone = trim((string)bms_setting_or_config('timezone', date_default_timezone_get() ?: 'UTC'));
    if ($timezone === '') {
        $timezone = 'UTC';
    }
    try {
        new DateTimeZone($timezone);
        return $timezone;
    } catch (Throwable $e) {
        return 'UTC';
    }
}

/**
 * Make PHP's runtime clock match the persisted site timezone.
 *
 * The database remains on UTC for canonical timestamps. This only controls
 * local authoring defaults and legacy date()/strtotime() call sites.
 */
function bms_apply_site_timezone(?string $timezone = null): string
{
    $timezone = trim($timezone ?? bms_site_timezone_name());
    if ($timezone === '') {
        $timezone = 'UTC';
    }
    try {
        new DateTimeZone($timezone);
    } catch (Throwable $e) {
        $timezone = 'UTC';
    }
    date_default_timezone_set($timezone);
    return $timezone;
}

/**
 * Timestamp storage changed in v0.5.23. Earlier published_at values were
 * written as site-local clock values, while v0.5.23+ values are canonical UTC.
 * The one-time migration records the upgrade boundary without rewriting posts.
 */
function bms_stream_published_at_utc_cutover(): string
{
    $value = trim((string)bms_setting_or_config('stream_published_at_utc_cutover', '1970-01-01 00:00:00'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) {
        return '1970-01-01 00:00:00';
    }
    try {
        return (new DateTimeImmutable($value, bms_utc_timezone()))->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return '1970-01-01 00:00:00';
    }
}

function bms_stream_published_at_is_utc(array $page): bool
{
    $publishedAt = trim((string)($page['published_at'] ?? ''));
    if ($publishedAt === '') {
        return false;
    }
    try {
        $published = new DateTimeImmutable($publishedAt, bms_utc_timezone());
        $cutover = new DateTimeImmutable(bms_stream_published_at_utc_cutover(), bms_utc_timezone());
        return $published->getTimestamp() >= $cutover->getTimestamp();
    } catch (Throwable $e) {
        // Preserve the historical local-time behavior for malformed legacy values.
        return false;
    }
}

function bms_site_timezone(): DateTimeZone
{
    return new DateTimeZone(bms_site_timezone_name());
}

function bms_utc_timezone(): DateTimeZone
{
    return new DateTimeZone('UTC');
}

function bms_scheduled_input_to_utc(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    $value = str_replace('T', ' ', $value);
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(?::\d{2})?$/', $value)) {
        throw new RuntimeException('Enter a valid schedule date and time.');
    }
    try {
        $local = new DateTimeImmutable($value, bms_site_timezone());
    } catch (Throwable $e) {
        throw new RuntimeException('Enter a valid schedule date and time.');
    }
    $utc = $local->setTimezone(bms_utc_timezone());
    if ($utc->getTimestamp() <= time() + 60) {
        throw new RuntimeException('Scheduled time must be in the future.');
    }
    return $utc->format('Y-m-d H:i:s');
}

function bms_utc_to_scheduled_input(string $utc): string
{
    $utc = trim($utc);
    if ($utc === '' || $utc === '0000-00-00 00:00:00') {
        return '';
    }
    try {
        $date = new DateTimeImmutable($utc, bms_utc_timezone());
        return $date->setTimezone(bms_site_timezone())->format('Y-m-d\TH:i');
    } catch (Throwable $e) {
        return '';
    }
}

function bms_format_scheduled_datetime(string $utc): string
{
    $utc = trim($utc);
    if ($utc === '' || $utc === '0000-00-00 00:00:00') {
        return '';
    }
    try {
        $date = new DateTimeImmutable($utc, bms_utc_timezone());
        return $date->setTimezone(bms_site_timezone())->format('M j, Y g:i A') . ' ' . bms_site_timezone_name();
    } catch (Throwable $e) {
        return $utc . ' UTC';
    }
}

function bms_scheduled_status_label(array $page): string
{
    $scheduledAt = (string)($page['scheduled_at'] ?? ($page['front_matter']['scheduled_at'] ?? ''));
    return $scheduledAt !== '' ? bms_format_scheduled_datetime($scheduledAt) : '';
}



function bms_plain_text(string $text): string
{
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/', ' ', $text) ?? $text;
    return trim($text);
}


function bms_site_identity_text_segment(string $text): string
{
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/', ' ', $text) ?? $text;
    return $text;
}

function bms_site_identity_allowed_link_url(string $url): string
{
    $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($url === '') {
        return '';
    }

    if (str_starts_with($url, '/')) {
        return preg_match('#^/[A-Za-z0-9._~!$&\'()*+,;=:@%/-]*(?:\?[A-Za-z0-9._~!$&\'()*+,;=:@%/?-]*)?(?:\#[A-Za-z0-9._~!$&\'()*+,;=:@%/?-]*)?$#', $url) === 1 ? $url : '';
    }

    $parts = parse_url($url);
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], true)) {
        return '';
    }

    return filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
}

function bms_site_identity_anchor_attributes(string $rawAttributes): array
{
    $attributes = [];
    if (preg_match_all('/([a-zA-Z][a-zA-Z0-9:-]*)\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))/', $rawAttributes, $matches, PREG_SET_ORDER) !== false) {
        foreach ($matches as $match) {
            $name = strtolower((string)$match[1]);
            $value = (string)($match[3] ?? $match[4] ?? $match[5] ?? '');
            $attributes[$name] = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
    }
    return $attributes;
}

function bms_sanitize_site_identity_html(string $html): string
{
    $html = trim($html);
    if ($html === '') {
        return '';
    }

    $output = '';
    $offset = 0;
    $pattern = '/<a\s+([^>]*)>(.*?)<\/a>/is';
    if (preg_match_all($pattern, $html, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) === false) {
        return htmlspecialchars(bms_plain_text($html), ENT_QUOTES, 'UTF-8');
    }

    foreach ($matches as $match) {
        $start = (int)$match[0][1];
        $length = strlen((string)$match[0][0]);
        $before = substr($html, $offset, $start - $offset);
        $output .= htmlspecialchars(bms_site_identity_text_segment($before), ENT_QUOTES, 'UTF-8');

        $attributes = bms_site_identity_anchor_attributes((string)$match[1][0]);
        $href = bms_site_identity_allowed_link_url((string)($attributes['href'] ?? ''));
        $label = bms_plain_text((string)$match[2][0]);
        if ($href !== '' && $label !== '') {
            $title = bms_plain_text((string)($attributes['title'] ?? ''));
            $target = (string)($attributes['target'] ?? '');
            $target = in_array($target, ['_blank', '_self'], true) ? $target : '';
            $rel = bms_plain_text((string)($attributes['rel'] ?? ''));
            $relParts = preg_split('/\s+/', strtolower($rel), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if ($target === '_blank') {
                $relParts = array_merge($relParts, ['noopener', 'noreferrer']);
            }
            $relParts = array_values(array_unique(array_filter($relParts, static fn($part) => preg_match('/^[a-z0-9_-]+$/', $part) === 1)));

            $output .= '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '"';
            if ($title !== '') {
                $output .= ' title="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '"';
            }
            if ($target !== '') {
                $output .= ' target="' . htmlspecialchars($target, ENT_QUOTES, 'UTF-8') . '"';
            }
            if ($relParts) {
                $output .= ' rel="' . htmlspecialchars(implode(' ', $relParts), ENT_QUOTES, 'UTF-8') . '"';
            }
            $output .= '>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';
        } else {
            $output .= htmlspecialchars($label !== '' ? $label : bms_plain_text((string)$match[0][0]), ENT_QUOTES, 'UTF-8');
        }
        $offset = $start + $length;
    }

    $remaining = substr($html, $offset);
    $output .= htmlspecialchars(bms_site_identity_text_segment($remaining), ENT_QUOTES, 'UTF-8');
    $output = preg_replace('/\s+/', ' ', $output) ?? $output;
    return trim($output);
}

function bms_site_identity_plain_text(string $html): string
{
    return bms_plain_text($html);
}

function bms_root_path(string $path = ''): string
{
    return dirname(__DIR__) . ($path ? '/' . ltrim($path, '/') : '');
}

function bms_public_path(string $path = ''): string
{
    $configured = trim((string)bms_setting_or_config('public_path', ''));
    $publicRoot = $configured !== '' ? rtrim($configured, '/\\') : dirname(dirname(__DIR__));
    return $publicRoot . ($path ? '/' . ltrim($path, '/') : '');
}

function bms_content_path(string $path = ''): string
{
    return bms_root_path('content' . ($path ? '/' . ltrim($path, '/') : ''));
}

/**
 * Return the runtime directories Bonumark Stream expects the PHP process to
 * be able to create and write during normal operation.
 *
 * Installer provisioning and System Check diagnostics intentionally share
 * this definition so hosting requirements cannot silently drift apart.
 *
 * @return array<string,array{label:string,relative_path:string,path:string,purpose:string}>
 */
function bms_runtime_directory_definitions(): array
{
    return [
        'private_data' => [
            'label' => 'Private data writable',
            'relative_path' => '_bonumark_stream/data',
            'path' => bms_root_path('data'),
            'purpose' => 'Private application runtime data.',
        ],
        'private_tmp' => [
            'label' => 'Private temporary storage writable',
            'relative_path' => '_bonumark_stream/tmp',
            'path' => bms_root_path('tmp'),
            'purpose' => 'Locks, rate limits, theme staging, profile exports, and other temporary runtime files.',
        ],
        'temporary_exports' => [
            'label' => 'Temporary export storage writable',
            'relative_path' => '_bonumark_stream/tmp/exports',
            'path' => bms_root_path('tmp/exports'),
            'purpose' => 'Temporary package export files.',
        ],
        'static_site_exports' => [
            'label' => 'Static site export temp writable',
            'relative_path' => '_bonumark_stream/tmp/static-site-exports',
            'path' => bms_root_path('tmp/static-site-exports'),
            'purpose' => 'Optional static-site export staging.',
        ],
        'upgrade_temp' => [
            'label' => 'Upgrade temp writable',
            'relative_path' => '_bonumark_stream/tmp/upgrades',
            'path' => bms_root_path('tmp/upgrades'),
            'purpose' => 'Admin ZIP upgrade extraction and validation.',
        ],
        'upgrade_backups' => [
            'label' => 'Upgrade backups writable',
            'relative_path' => '_bonumark_stream/backups/upgrades',
            'path' => bms_root_path('backups/upgrades'),
            'purpose' => 'Pre-upgrade software backups.',
        ],
        'markdown_imports' => [
            'label' => 'Markdown import folder writable',
            'relative_path' => '_bonumark_stream/content/import-markdown',
            'path' => bms_content_path('import-markdown'),
            'purpose' => 'Private Markdown import staging.',
        ],
        'content_versions' => [
            'label' => 'Content versions writable',
            'relative_path' => '_bonumark_stream/content/versions',
            'path' => bms_content_path('versions'),
            'purpose' => 'Private content version files used by portability and editing workflows.',
        ],
        'import_staging' => [
            'label' => 'Import staging writable',
            'relative_path' => '_bonumark_stream/import-staging',
            'path' => bms_root_path('import-staging'),
            'purpose' => 'Private media and archive staging during imports.',
        ],
        'import_previews' => [
            'label' => 'Import preview staging writable',
            'relative_path' => '_bonumark_stream/import-staging/previews',
            'path' => bms_root_path('import-staging/previews'),
            'purpose' => 'Private serialized import previews.',
        ],
        'public_media' => [
            'label' => 'Public media writable',
            'relative_path' => 'media',
            'path' => bms_public_path('media'),
            'purpose' => 'Validated public uploads and generated media variants.',
        ],
    ];
}

/**
 * Report runtime-directory state without changing the filesystem.
 *
 * @return array<string,array{label:string,relative_path:string,path:string,purpose:string,exists:bool,writable:bool}>
 */
function bms_runtime_directory_status(): array
{
    $results = [];
    foreach (bms_runtime_directory_definitions() as $key => $definition) {
        $path = (string)$definition['path'];
        $exists = is_dir($path);
        $results[$key] = $definition + [
            'exists' => $exists,
            'writable' => $exists && is_writable($path),
        ];
    }
    return $results;
}

/**
 * Create missing runtime directories and report their current write state.
 *
 * @return array<string,array{label:string,relative_path:string,path:string,purpose:string,exists:bool,writable:bool,created:bool}>
 */
function bms_ensure_runtime_directories(): array
{
    $created = [];
    foreach (bms_runtime_directory_definitions() as $key => $definition) {
        $path = (string)$definition['path'];
        $created[$key] = false;
        if (!is_dir($path)) {
            $created[$key] = @mkdir($path, 0755, true);
        }
    }

    $results = [];
    foreach (bms_runtime_directory_status() as $key => $status) {
        $results[$key] = $status + ['created' => !empty($created[$key])];
    }
    return $results;
}

/**
 * Return true when a relative path is package-managed application software.
 * Runtime/owner data and public uploads are intentionally outside this set.
 */
function bms_package_managed_software_path(string $relative): bool
{
    $relative = str_replace('\\', '/', ltrim(trim($relative), '/'));
    if ($relative === '') {
        return false;
    }

    foreach (['admin/', 'api/', 'assets/', 'docs/', 'scripts/', '_bonumark_stream/app/', '_bonumark_stream/migrations/', '_bonumark_stream/themes/', '_bonumark_stream/tools/'] as $prefix) {
        if (str_starts_with($relative, $prefix)) {
            return true;
        }
    }

    $managedExact = [
        '.htaccess' => true,
        '.gitignore' => true,
        'CHANGELOG.md' => true,
        'CONTRIBUTING.md' => true,
        'LICENSE' => true,
        'README.md' => true,
        'SECURITY.md' => true,
        'VERSION' => true,
        'account.php' => true,
        'analytics.php' => true,
        'comments.php' => true,
        'index.php' => true,
        'install.php' => true,
        'manifest.php' => true,
        'page.php' => true,
        'pwa-icon.php' => true,
        'profile.php' => true,
        'profile-export.php' => true,
        'search.php' => true,
        'stream-like.php' => true,
        'sw.js' => true,
        '_bonumark_stream/.htaccess' => true,
        '_bonumark_stream/CHANGELOG.md' => true,
        '_bonumark_stream/PACKAGE.json' => true,
        '_bonumark_stream/RELEASE-MANIFEST.json' => true,
        '_bonumark_stream/VERSION' => true,
        '_bonumark_stream/config.sample.php' => true,
        '_bonumark_stream/migrations/README.md' => true,
        '_bonumark_stream/themes/README.md' => true,
    ];

    return isset($managedExact[$relative]);
}

/**
 * Return package-managed file paths recorded by the installed release manifest.
 *
 * @return list<string>
 */
function bms_installed_release_manifest_paths(): array
{
    $manifestPath = bms_root_path('RELEASE-MANIFEST.json');
    if (!is_file($manifestPath)) {
        return [];
    }

    $manifest = json_decode((string)@file_get_contents($manifestPath), true);
    if (!is_array($manifest) || !is_array($manifest['files'] ?? null)) {
        return [];
    }

    $paths = [];
    foreach ($manifest['files'] as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $relative = str_replace('\\', '/', ltrim(trim((string)($entry['path'] ?? '')), '/'));
        if ($relative !== '' && bms_package_managed_software_path($relative)) {
            $paths[$relative] = true;
        }
    }

    // The manifest validates the package but is intentionally not self-listed.
    $paths['_bonumark_stream/RELEASE-MANIFEST.json'] = true;
    $paths = array_keys($paths);
    sort($paths);
    return $paths;
}

function bms_nearest_existing_parent_directory(string $path): string
{
    $candidate = dirname($path);
    while ($candidate !== '' && $candidate !== '.' && !is_dir($candidate)) {
        $parent = dirname($candidate);
        if ($parent === $candidate) {
            break;
        }
        $candidate = $parent;
    }
    return is_dir($candidate) ? $candidate : '';
}

/**
 * Report whether the PHP process can safely perform automatic software replacement.
 *
 * Automatic upgrades need more than writable runtime storage: existing managed
 * files must be writable and their containing directories must allow replacement,
 * cleanup, and rollback. Locked-down software trees are valid installations; they
 * simply require an owner-run or hosting-layer deployment workflow.
 *
 * @param list<string>|null $relativePaths Candidate current/target package paths.
 * @return array{status:string,available:bool,checked:int,blocked:list<array{relative_path:string,reason:string}>}
 */
function bms_automatic_upgrade_capability(?array $relativePaths = null): array
{
    $relativePaths = $relativePaths ?? bms_installed_release_manifest_paths();
    if ($relativePaths === []) {
        return [
            'status' => 'unknown',
            'available' => false,
            'checked' => 0,
            'blocked' => [],
        ];
    }

    $paths = [];
    foreach ($relativePaths as $relative) {
        $relative = str_replace('\\', '/', ltrim(trim((string)$relative), '/'));
        if ($relative !== '' && bms_package_managed_software_path($relative)) {
            $paths[$relative] = true;
        }
    }
    $paths = array_keys($paths);
    sort($paths);

    $blocked = [];
    $blockedIndex = [];
    $addBlocked = static function (string $relative, string $reason) use (&$blocked, &$blockedIndex): void {
        if (isset($blockedIndex[$relative])) {
            $index = $blockedIndex[$relative];
            if (!str_contains((string)$blocked[$index]['reason'], $reason)) {
                $blocked[$index]['reason'] .= ' ' . $reason;
            }
            return;
        }
        $blockedIndex[$relative] = count($blocked);
        $blocked[] = ['relative_path' => $relative, 'reason' => $reason];
    };

    $publicRoot = rtrim(bms_public_path(), '/\\');
    foreach ($paths as $relative) {
        $destination = $publicRoot . '/' . $relative;
        $parent = dirname($destination);

        clearstatcache(true, $destination);
        clearstatcache(true, $parent);

        if (is_link($destination)) {
            $addBlocked($relative, 'Package-managed software path is a symbolic link.');
            continue;
        }

        if (file_exists($destination)) {
            if (!is_file($destination)) {
                $addBlocked($relative, 'Package-managed file path is not a regular file.');
                continue;
            }
            if (!is_writable($destination)) {
                $addBlocked($relative, 'Existing package-managed file is not writable by PHP.');
            }
            if (!is_dir($parent) || !is_writable($parent)) {
                $addBlocked($relative, 'Containing directory is not writable for software replacement, cleanup, and rollback.');
            }
            continue;
        }

        $existingParent = bms_nearest_existing_parent_directory($destination);
        if ($existingParent === '' || !is_writable($existingParent)) {
            $addBlocked($relative, 'Nearest existing parent directory is not writable for a new package-managed file.');
        }
    }

    return [
        'status' => $blocked === [] ? 'available' : 'unavailable',
        'available' => $blocked === [],
        'checked' => count($paths),
        'blocked' => $blocked,
    ];
}

/**
 * Identify the web server family exposed to PHP.
 *
 * @return array{family:string,label:string,software:string,status:string,message:string}
 */
function bms_web_server_capability(): array
{
    $software = trim((string)($_SERVER['SERVER_SOFTWARE'] ?? ''));
    $lower = strtolower($software);

    if ($software === '') {
        return [
            'family' => 'unknown',
            'label' => 'Unknown',
            'software' => '',
            'status' => 'warning',
            'message' => 'Web server software could not be detected. Confirm that Bonumark routing and private-path protections are configured for this host.',
        ];
    }

    if (str_contains($lower, 'litespeed')) {
        return [
            'family' => 'litespeed',
            'label' => 'LiteSpeed',
            'software' => $software,
            'status' => 'pass',
            'message' => 'LiteSpeed detected. Bonumark ships Apache-compatible .htaccess routing and private-path protections.',
        ];
    }

    if (str_contains($lower, 'apache')) {
        return [
            'family' => 'apache',
            'label' => 'Apache',
            'software' => $software,
            'status' => 'pass',
            'message' => 'Apache detected. Bonumark ships .htaccess routing and private-path protections.',
        ];
    }

    if (str_contains($lower, 'nginx')) {
        return [
            'family' => 'nginx',
            'label' => 'Nginx',
            'software' => $software,
            'status' => 'pass',
            'message' => 'Nginx detected. Bonumark includes a tested configuration example under docs/server/. Verify the separate Public URL mode and Private folder exposure checks on this installation.',
        ];
    }

    return [
        'family' => 'other',
        'label' => $software,
        'software' => $software,
        'status' => 'warning',
        'message' => 'Web server detected as ' . $software . '. Configure routing and private-path protections equivalent to the shipped Apache/LiteSpeed and Nginx examples.',
    ];
}

/**
 * Parse a PHP shorthand byte value such as 2M, 128M, or 1G.
 * Returns null when the value cannot be interpreted.
 */
function bms_php_ini_bytes(string $value): ?int
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    if (!preg_match('/^(-?\d+(?:\.\d+)?)\s*([kmgtpe]?)(?:b)?$/i', $value, $matches)) {
        return null;
    }

    $number = (float)$matches[1];
    $unit = strtolower((string)($matches[2] ?? ''));
    $powers = ['' => 0, 'k' => 1, 'm' => 2, 'g' => 3, 't' => 4, 'p' => 5, 'e' => 6];
    if (!array_key_exists($unit, $powers)) {
        return null;
    }

    $bytes = $number * (1024 ** $powers[$unit]);
    if (!is_finite($bytes) || $bytes > PHP_INT_MAX || $bytes < PHP_INT_MIN) {
        return null;
    }
    return (int)floor($bytes);
}

function bms_format_bytes_compact(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    $units = ['KB', 'MB', 'GB', 'TB', 'PB'];
    $value = (float)$bytes;
    foreach ($units as $unit) {
        $value /= 1024;
        if ($value < 1024 || $unit === 'PB') {
            $rounded = $value >= 10 ? round($value, 0) : round($value, 1);
            return rtrim(rtrim(number_format($rounded, 1, '.', ''), '0'), '.') . ' ' . $unit;
        }
    }
    return $bytes . ' B';
}

/**
 * Report the effective media upload ceiling imposed by Bonumark and PHP.
 * post_max_size=0 is treated as unlimited per PHP behavior.
 *
 * @return array{status:string,effective_bytes:int,bonumark_bytes:int,upload_max_bytes:?int,post_max_bytes:?int,message:string}
 */
function bms_upload_limit_capability(): array
{
    $bonumarkMb = max(1, min(128, (int)bms_setting_or_config('media_upload_limit_mb', '32')));
    $bonumarkBytes = $bonumarkMb * 1024 * 1024;

    $uploadRaw = (string)ini_get('upload_max_filesize');
    $postRaw = (string)ini_get('post_max_size');
    $uploadBytes = bms_php_ini_bytes($uploadRaw);
    $postBytesParsed = bms_php_ini_bytes($postRaw);
    $postBytes = $postBytesParsed === 0 ? null : $postBytesParsed;

    $limits = [$bonumarkBytes];
    if ($uploadBytes !== null && $uploadBytes >= 0) {
        $limits[] = $uploadBytes;
    }
    if ($postBytes !== null && $postBytes >= 0) {
        $limits[] = $postBytes;
    }
    $effective = min($limits);

    $status = $effective >= $bonumarkBytes ? 'pass' : 'warning';
    $parts = [
        'Bonumark limit ' . $bonumarkMb . ' MB',
        'PHP upload_max_filesize ' . ($uploadRaw !== '' ? $uploadRaw : 'unknown'),
        'PHP post_max_size ' . ($postRaw !== '' ? $postRaw : 'unknown'),
    ];
    $message = implode('; ', $parts) . '. Effective PHP/Bonumark file ceiling is no higher than ' . bms_format_bytes_compact($effective) . '. A web server or reverse proxy can impose a lower request limit.';
    if ($status !== 'pass') {
        $message .= ' The hosting PHP limits are lower than the configured Bonumark media limit.';
    }

    return [
        'status' => $status,
        'effective_bytes' => $effective,
        'bonumark_bytes' => $bonumarkBytes,
        'upload_max_bytes' => $uploadBytes,
        'post_max_bytes' => $postBytes,
        'message' => $message,
    ];
}

/**
 * Report whether Admin can install/update theme ZIPs directly.
 * Core theme use does not require these directories to be writable.
 *
 * @return array{status:string,available:bool,blocked:list<string>,message:string}
 */
function bms_theme_zip_install_capability(): array
{
    $blocked = [];
    if (!class_exists('ZipArchive')) {
        $blocked[] = 'ZipArchive is unavailable.';
    }

    $targets = [
        '_bonumark_stream/tmp' => bms_root_path('tmp'),
        '_bonumark_stream/themes' => bms_root_path('themes'),
        'assets/themes' => bms_public_path('assets/themes'),
    ];
    foreach ($targets as $relative => $path) {
        $candidate = is_dir($path) ? $path : bms_nearest_existing_parent_directory($path);
        if ($candidate === '' || !is_writable($candidate)) {
            $blocked[] = $relative . ' is not writable by PHP.';
        }
    }

    $available = $blocked === [];
    return [
        'status' => $available ? 'available' : 'unavailable',
        'available' => $available,
        'blocked' => $blocked,
        'message' => $available
            ? 'Admin theme ZIP installation is available.'
            : 'Core theme operation is supported, but Admin theme ZIP installation is unavailable. ' . implode(' ', $blocked) . ' Install or update optional themes through an external/manual deployment workflow.',
    ];
}

function bms_base_path(): string
{
    $basePath = trim((string)bms_setting_or_config('base_path', ''));
    if ($basePath === '' || $basePath === '/') {
        return '';
    }
    return '/' . trim($basePath, '/');
}

function bms_url_path(string $path = ''): string
{
    $base = bms_base_path();
    $path = str_replace('\\', '/', trim($path));
    if ($path === '' || $path === '/') {
        return $base !== '' ? $base . '/' : '/';
    }

    $fragment = '';
    $query = '';
    $pathOnly = $path;

    $fragmentPosition = strpos($pathOnly, '#');
    if ($fragmentPosition !== false) {
        $fragment = substr($pathOnly, $fragmentPosition);
        $pathOnly = substr($pathOnly, 0, $fragmentPosition);
    }

    $queryPosition = strpos($pathOnly, '?');
    if ($queryPosition !== false) {
        $query = substr($pathOnly, $queryPosition);
        $pathOnly = substr($pathOnly, 0, $queryPosition);
    }

    $hasTrailingSlash = str_ends_with($pathOnly, '/');
    $pathOnly = trim($pathOnly, '/');

    if ($pathOnly === '') {
        $url = $base !== '' ? $base . '/' : '/';
        return $url . $query . $fragment;
    }

    $url = ($base !== '' ? $base : '') . '/' . $pathOnly;
    if ($hasTrailingSlash) {
        $url .= '/';
    }

    return $url . $query . $fragment;
}

function bms_admin_url(string $path = ''): string
{
    return bms_url_path('admin' . ($path ? '/' . ltrim($path, '/') : ''));
}

function bms_stream_safe_return_url(string $returnTo = ''): string
{
    $fallback = bms_url_path();
    $returnTo = trim(str_replace('\\', '/', $returnTo));
    if ($returnTo === '') {
        return $fallback;
    }

    if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $returnTo) === 1 || str_starts_with($returnTo, '//')) {
        return $fallback;
    }

    $base = bms_base_path();
    $path = parse_url($returnTo, PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
        return $fallback;
    }

    if ($base !== '' && !str_starts_with($path, $base . '/') && $path !== $base) {
        return $fallback;
    }

    $query = parse_url($returnTo, PHP_URL_QUERY);
    return $path . (is_string($query) && $query !== '' ? '?' . $query : '');
}

function bms_asset_url(string $path): string
{
    $url = bms_url_path($path);
    $version = rawurlencode(bms_version());
    return $url . (str_contains($url, '?') ? '&' : '?') . 'v=' . $version;
}


function bms_stream_home_url(): string
{
    return bms_url_path();
}

function bms_stream_composer_url(): string
{
    return bms_url_path('?compose=1#stream-composer');
}

function bms_stream_relative_directory(string $slug, string $category = ''): string
{
    $slug = bms_slugify($slug);
    return $slug !== '' ? 'stream/' . $slug : 'stream';
}

function bms_stream_url(string $slug, string $category = ''): string
{
    $slug = bms_slugify($slug);
    if ($slug === '') {
        return bms_stream_home_url();
    }
    return bms_url_path(bms_stream_relative_directory($slug, $category) . '/');
}

function bms_stream_relative_directory_for_post(array $page): string
{
    return 'stream/' . bms_slugify((string)($page['slug'] ?? ''));
}

function bms_stream_url_for_post(array $page): string
{
    return bms_url_path(bms_stream_relative_directory_for_post($page) . '/');
}


function bms_page_relative_directory(string $slug): string
{
    return 'pages/' . bms_slugify($slug);
}

function bms_page_relative_directory_for_page(array $page): string
{
    return bms_page_relative_directory((string)($page['slug'] ?? ''));
}

function bms_page_url(string $slug): string
{
    return bms_url_path(bms_page_relative_directory($slug) . '/');
}

function bms_page_url_for_page(array $page): string
{
    return bms_page_url((string)($page['slug'] ?? ''));
}




function bms_static_site_export_root(string $name = ''): string
{
    $root = bms_root_path('tmp/static-site-exports');
    if ($name !== '') {
        $name = preg_replace('/[^A-Za-z0-9_.-]+/', '-', $name) ?? 'export';
        $root .= '/' . trim($name, '-');
    }
    return $root;
}

function bms_static_site_export_path(string $path = '', ?string $targetRoot = null): string
{
    $root = $targetRoot !== null && trim($targetRoot) !== ''
        ? rtrim($targetRoot, '/\\')
        : bms_static_site_export_root('current');
    return $root . ($path !== '' ? '/' . ltrim($path, '/\\') : '');
}

function bms_stream_export_index_path_for_post(array $page, ?string $targetRoot = null): string
{
    return bms_static_site_export_path(bms_stream_relative_directory_for_post($page) . '/index.html', $targetRoot);
}

function bms_page_export_index_path_for_page(array $page, ?string $targetRoot = null): string
{
    return bms_static_site_export_path(bms_page_relative_directory_for_page($page) . '/index.html', $targetRoot);
}

function bms_site_url(string $path = ''): string
{
    $base = rtrim((string)bms_setting_or_config('base_url', ''), '/');
    $urlPath = bms_url_path($path);
    return $base !== '' ? $base . $urlPath : $urlPath;
}


function bms_text_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function bms_text_substr(string $value, int $start, ?int $length = null): string
{
    if (function_exists('mb_substr')) {
        return $length === null
            ? (string)mb_substr($value, $start, null, 'UTF-8')
            : (string)mb_substr($value, $start, $length, 'UTF-8');
    }
    return $length === null ? (string)substr($value, $start) : (string)substr($value, $start, $length);
}

function bms_text_lower(string $value): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function bms_normalize_username(string $username): string
{
    $username = strtolower(trim($username));
    $username = preg_replace('/[^a-z0-9._-]+/', '-', $username) ?? '';
    $username = trim($username, '.-_');
    return $username !== '' ? substr($username, 0, 64) : 'admin';
}

function bms_slugify(string $text): string
{
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/i', '-', $text);
    $text = trim($text ?? '', '-');
    return $text !== '' ? $text : 'untitled-' . date('Ymd-His');
}

function bms_term_slug(string $text): string
{
    return bms_slugify($text);
}

function bms_stream_clean_text_for_seo(string $text): string
{
    $text = preg_replace('/```.*?```/s', ' ', $text) ?? $text;
    $text = preg_replace('/~~~.*?~~~/s', ' ', $text) ?? $text;
    $text = preg_replace('/^\s{0,3}#{1,6}\s+/m', '', $text) ?? $text;
    $text = preg_replace('/!\[[^\]]*\]\([^\)]*\)/', ' ', $text) ?? $text;
    $text = preg_replace('/\[([^\]]+)\]\([^\)]*\)/', '$1', $text) ?? $text;
    $text = preg_replace('/`([^`]+)`/', '$1', $text) ?? $text;
    $text = preg_replace('/^[\s>*_`#~\-+=|]+/m', ' ', $text) ?? $text;
    $text = preg_replace('/[`*_>#~|]+/', ' ', $text) ?? $text;
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/', ' ', $text) ?? $text;
    return trim($text);
}

function bms_stream_first_heading_text(string $body): string
{
    if (preg_match('/^\s{0,3}#\s+(.+)$/m', $body, $match) !== 1) {
        return '';
    }

    return bms_stream_clean_text_for_seo((string)$match[1]);
}

function bms_stream_slug_candidate_from_text(string $text, int $maxWords = 10, int $maxLength = 72): string
{
    $text = bms_stream_clean_text_for_seo($text);
    if ($text === '') {
        return '';
    }

    $words = preg_split('/\s+/', $text) ?: [];
    $selected = [];
    foreach ($words as $word) {
        $word = trim((string)$word);
        if ($word === '') {
            continue;
        }

        $selected[] = $word;
        if (count($selected) >= $maxWords) {
            break;
        }
    }

    $candidate = bms_slugify(implode(' ', $selected));
    if ($candidate === '' || str_starts_with($candidate, 'untitled-')) {
        return '';
    }

    if (strlen($candidate) <= $maxLength) {
        return $candidate;
    }

    $candidate = substr($candidate, 0, $maxLength);
    $candidate = preg_replace('/-[^-]*$/', '', $candidate) ?: $candidate;
    return trim($candidate, '-');
}

function bms_stream_limit_text(string $text, int $limit, string $suffix = '…'): string
{
    $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    if ($text === '' || $limit < 1) {
        return '';
    }

    $length = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
    if ($length <= $limit) {
        return $text;
    }

    $cut = function_exists('mb_substr') ? mb_substr($text, 0, max(1, $limit - 1)) : substr($text, 0, max(1, $limit - 1));
    $cut = preg_replace('/\s+\S*$/u', '', $cut) ?: $cut;
    return rtrim($cut, " \t\n\r\0\x0B.,;:!?") . $suffix;
}


function bms_seo_clean_title(string $title): string
{
    $title = html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $title = trim(strip_tags($title));
    $title = preg_replace('/\s+/u', ' ', $title) ?? $title;
    return trim($title);
}

function bms_seo_site_title(): string
{
    $siteTitle = bms_seo_clean_title((string)bms_setting_or_config('site_name', 'Bonumark Stream'));
    return $siteTitle !== '' ? $siteTitle : 'Bonumark Stream';
}

function bms_seo_site_tagline(): string
{
    if (function_exists('bms_site_identity_plain_text')) {
        return bms_seo_clean_title(bms_site_identity_plain_text((string)bms_setting_or_config('site_tagline', '')));
    }
    return bms_seo_clean_title((string)bms_setting_or_config('site_tagline', ''));
}

function bms_seo_title_lower(string $title): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($title, 'UTF-8') : strtolower($title);
}

function bms_seo_strip_site_title(string $title, ?string $siteTitle = null): string
{
    $title = bms_seo_clean_title($title);
    $siteTitle = bms_seo_clean_title($siteTitle ?? bms_seo_site_title());
    if ($title === '' || $siteTitle === '') {
        return $title;
    }

    $separators = [' | ', ' - ', ' – ', ' — ', ' · ', ' • ', ': '];
    $changed = true;
    while ($changed && $title !== '') {
        $changed = false;
        foreach ($separators as $separator) {
            $suffix = $separator . $siteTitle;
            $prefix = $siteTitle . $separator;
            $titleLower = bms_seo_title_lower($title);
            $suffixLower = bms_seo_title_lower($suffix);
            $prefixLower = bms_seo_title_lower($prefix);

            if (str_ends_with($titleLower, $suffixLower)) {
                $title = trim((string)(function_exists('mb_substr') ? mb_substr($title, 0, -1 * (function_exists('mb_strlen') ? mb_strlen($suffix) : strlen($suffix)), 'UTF-8') : substr($title, 0, -1 * strlen($suffix))));
                $changed = true;
                break;
            }
            if (str_starts_with($titleLower, $prefixLower)) {
                $title = trim((string)(function_exists('mb_substr') ? mb_substr($title, (function_exists('mb_strlen') ? mb_strlen($prefix) : strlen($prefix)), null, 'UTF-8') : substr($title, strlen($prefix))));
                $changed = true;
                break;
            }
        }
    }

    return bms_seo_title_lower($title) === bms_seo_title_lower($siteTitle) ? '' : $title;
}

function bms_seo_join_title_parts(array $parts, int $limit = 70): string
{
    $clean = [];
    foreach ($parts as $part) {
        $part = bms_seo_clean_title((string)$part);
        if ($part !== '') {
            $clean[] = $part;
        }
    }
    if (!$clean) {
        return '';
    }

    $title = implode(' | ', $clean);
    return bms_stream_limit_text($title, $limit, '…');
}

function bms_seo_document_title(string $primaryTitle = '', int $limit = 70): string
{
    $siteTitle = bms_seo_site_title();
    $primaryTitle = bms_seo_strip_site_title($primaryTitle, $siteTitle);

    if ($primaryTitle === '') {
        return bms_stream_limit_text($siteTitle, $limit, '…');
    }

    $separator = ' | ';
    $siteLength = function_exists('mb_strlen') ? mb_strlen($siteTitle, 'UTF-8') : strlen($siteTitle);
    $separatorLength = function_exists('mb_strlen') ? mb_strlen($separator, 'UTF-8') : strlen($separator);
    $availableForPrimary = $limit - $siteLength - $separatorLength;

    if ($siteTitle !== '' && $availableForPrimary >= 18) {
        return bms_stream_limit_text($primaryTitle, $availableForPrimary, '…') . $separator . $siteTitle;
    }

    return bms_stream_limit_text($primaryTitle, $limit, '…');
}

function bms_seo_home_title(int $limit = 70): string
{
    $siteTitle = bms_seo_site_title();
    $tagline = bms_seo_strip_site_title(bms_seo_site_tagline(), $siteTitle);

    if ($tagline === '') {
        return bms_stream_limit_text($siteTitle, $limit, '…');
    }

    $separator = ' | ';
    $siteLength = function_exists('mb_strlen') ? mb_strlen($siteTitle, 'UTF-8') : strlen($siteTitle);
    $separatorLength = function_exists('mb_strlen') ? mb_strlen($separator, 'UTF-8') : strlen($separator);
    $availableForTagline = $limit - $siteLength - $separatorLength;

    if ($availableForTagline >= 18) {
        return $siteTitle . $separator . bms_stream_limit_text($tagline, $availableForTagline, '…');
    }

    return bms_stream_limit_text($siteTitle, $limit, '…');
}

function bms_public_seo_view_data(string $template, array $data): array
{
    $template = strtolower(trim($template));

    /*
     * Only complete public documents own document SEO.
     *
     * Fragment templates such as link-preview, card, media, location,
     * composer, comments, pagination, and empty states receive domain data
     * that can legitimately contain a `title` key. Running those fragments
     * through document-title SEO rewrites that domain title and can append
     * the local Bonumark site name. Keep fragment payloads untouched.
     */
    $documentTemplates = ['layout', 'home', 'archive', 'single', 'page', 'profile', 'account', 'search'];
    if (!in_array($template, $documentTemplates, true)) {
        return $data;
    }

    $siteTitle = bms_seo_site_title();
    $primary = '';
    $documentTitle = '';
    $socialTitle = '';

    $providedPrimary = bms_seo_strip_site_title((string)($data['seo_title_primary'] ?? ''), $siteTitle);

    if ($template === 'home') {
        $documentTitle = bms_seo_home_title();
        $socialTitle = $siteTitle;
        $primary = $siteTitle;
    } elseif ($providedPrimary !== '') {
        $primary = $providedPrimary;
    } elseif ($template === 'archive') {
        $pageNumber = max(1, (int)($data['page_number'] ?? 1));
        $primary = $pageNumber > 1 ? 'Stream, Page ' . $pageNumber : 'Stream';
    } elseif ($template === 'single') {
        $primary = bms_seo_strip_site_title((string)($data['page_title'] ?? $data['title'] ?? 'Stream post'), $siteTitle);
    } elseif ($template === 'page') {
        $primary = bms_seo_strip_site_title((string)($data['page_title'] ?? $data['title'] ?? 'Page'), $siteTitle);
    } elseif ($template === 'search') {
        $query = trim((string)($data['query'] ?? ''));
        $primary = $query !== '' ? 'Search results for ' . $query : 'Search';
    } elseif ($template === 'profile') {
        $primary = bms_seo_strip_site_title((string)($data['display_name'] ?? $data['title'] ?? 'Profile'), $siteTitle);
        if ($primary === '') {
            $primary = 'Profile';
        }
    } elseif ($template === 'account') {
        $primary = 'Account';
    } else {
        $primary = bms_seo_strip_site_title((string)($data['page_title'] ?? $data['title'] ?? ''), $siteTitle);
    }

    if ($documentTitle === '') {
        $documentTitle = bms_seo_document_title($primary);
    }
    if ($socialTitle === '') {
        $socialTitle = $primary !== '' ? $primary : $documentTitle;
    }

    $data['seo_title_primary'] = $data['seo_title_primary'] ?? $primary;
    $data['seo_document_title'] = $data['seo_document_title'] ?? $documentTitle;
    $data['seo_social_title'] = $data['seo_social_title'] ?? $socialTitle;
    $data['title'] = $data['seo_document_title'];

    return $data;
}

function bms_stream_first_sentence(string $body): string
{
    $text = bms_stream_clean_text_for_seo($body);
    if ($text === '') {
        return '';
    }

    if (preg_match('/^(.{20,110}?[.!?])\s/u', $text, $match) === 1) {
        return trim($match[1]);
    }

    return bms_stream_limit_text($text, 90, '');
}

function bms_stream_title_case(string $text): string
{
    $text = trim($text);
    if ($text === '') {
        return '';
    }

    $small = ['a', 'an', 'and', 'as', 'at', 'but', 'by', 'for', 'from', 'in', 'into', 'nor', 'of', 'on', 'or', 'over', 'the', 'to', 'with'];
    $words = preg_split('/\s+/', strtolower($text)) ?: [];
    $out = [];
    $last = count($words) - 1;
    foreach ($words as $i => $word) {
        $word = trim($word);
        if ($word === '') {
            continue;
        }
        if ($i !== 0 && $i !== $last && in_array($word, $small, true)) {
            $out[] = $word;
            continue;
        }
        $out[] = function_exists('mb_convert_case') ? mb_convert_case($word, MB_CASE_TITLE, 'UTF-8') : ucfirst($word);
    }
    return implode(' ', $out);
}

function bms_stream_generated_post_title(string $body, string $createdAt = '', string $featuredMedia = '', array $media = [], int $limit = 70): string
{
    $candidate = bms_stream_first_sentence($body);
    if ($candidate !== '') {
        $candidate = trim($candidate, " \t\n\r\0\x0B.,;:!?");
        return bms_stream_limit_text($candidate, $limit, '…');
    }

    $mediaName = trim((string)($media['original_filename'] ?? $media['filename'] ?? ''));
    if ($mediaName === '' && $featuredMedia !== '') {
        $mediaName = basename($featuredMedia);
    }
    if ($mediaName !== '') {
        $mediaName = preg_replace('/\.[A-Za-z0-9]{1,8}$/', '', $mediaName) ?? $mediaName;
        $mediaName = str_replace(['-', '_'], ' ', $mediaName);
        $mediaName = trim(preg_replace('/\s+/', ' ', $mediaName) ?? $mediaName);
        if ($mediaName !== '') {
            return bms_stream_limit_text($mediaName, $limit, '…');
        }
    }

    $time = strtotime($createdAt) ?: time();
    return 'Media post from ' . date('M j, Y', $time);
}

function bms_stream_generated_seo_title(string $body, string $createdAt = '', string $featuredMedia = '', array $media = []): string
{
    $postTitle = trim(bms_stream_generated_post_title($body, $createdAt, $featuredMedia, $media, 70));
    if ($postTitle === '') {
        $postTitle = 'Stream update';
    }

    return bms_stream_limit_text(bms_seo_strip_site_title($postTitle), 70, '…');
}

function bms_stream_generated_description(string $body, string $createdAt = '', string $featuredMedia = '', int $limit = 160): string
{
    $text = bms_stream_clean_text_for_seo($body);
    if ($text !== '') {
        return bms_stream_limit_text($text, $limit, '…');
    }

    $site = (string)bms_setting_or_config('site_name', 'Bonumark Stream');
    $time = strtotime($createdAt) ?: time();
    if (trim($featuredMedia) !== '') {
        return 'Media post from ' . $site . ' on ' . date('F j, Y', $time) . '.';
    }
    return 'Short-form stream post from ' . $site . ' on ' . date('F j, Y', $time) . '.';
}

function bms_stream_slug_base(string $body, string $createdAt = '', array $media = [], string $title = ''): string
{
    $titleCandidate = trim($title);
    if ($titleCandidate !== '' && !bms_stream_title_needs_generation($titleCandidate)) {
        $candidate = bms_stream_slug_candidate_from_text($titleCandidate, 10, 72);
        if ($candidate !== '') {
            return $candidate;
        }
    }

    $headingCandidate = bms_stream_first_heading_text($body);
    if ($headingCandidate !== '') {
        $candidate = bms_stream_slug_candidate_from_text($headingCandidate, 10, 72);
        if ($candidate !== '') {
            return $candidate;
        }
    }

    $sentenceCandidate = bms_stream_first_sentence($body);
    if ($sentenceCandidate !== '') {
        $candidate = bms_stream_slug_candidate_from_text($sentenceCandidate, 10, 72);
        if ($candidate !== '') {
            return $candidate;
        }
    }

    $mediaName = trim((string)($media['original_filename'] ?? $media['filename'] ?? ''));
    if ($mediaName !== '') {
        $mediaName = preg_replace('/\.[A-Za-z0-9]{1,8}$/', '', $mediaName) ?? $mediaName;
        $mediaName = str_replace(['-', '_'], ' ', $mediaName);
        $candidate = bms_stream_slug_candidate_from_text($mediaName, 10, 72);
        if ($candidate !== '') {
            return $candidate;
        }
    }

    return 'stream-post';
}

function bms_stream_unique_slug(string $baseSlug, string $currentSlug = ''): string
{
    $baseSlug = bms_slugify($baseSlug);
    if ($baseSlug === '') {
        $baseSlug = 'stream-post-' . date('Ymd');
    }

    $currentSlug = bms_slugify($currentSlug);
    $slug = $baseSlug;
    $counter = 2;
    while (true) {
        $published = bms_content_path('published/' . $slug . '.md');
        $draft = bms_content_path('drafts/' . $slug . '.md');
        $databaseConflict = function_exists('bms_database_slug_exists') && bms_database_slug_exists($slug, $currentSlug, 'stream');
        $conflicts = ($currentSlug === '' || $slug !== $currentSlug) && ($databaseConflict || is_file($published) || is_file($draft));
        if (!$conflicts) {
            return $slug;
        }
        $slug = $baseSlug . '-' . $counter;
        $counter++;
    }
}

function bms_page_clean_slug_candidate(string $slug): string
{
    $slug = strtolower(trim($slug));
    $slug = preg_replace('/[^a-z0-9]+/i', '-', $slug) ?? '';
    return trim($slug, '-');
}

function bms_page_generated_seo_title(string $title): string
{
    $pageTitle = trim($title);
    if ($pageTitle === '') {
        $pageTitle = 'Untitled Page';
    }

    return bms_stream_limit_text(bms_seo_strip_site_title($pageTitle), 70, '…');
}

function bms_page_unique_slug(string $baseSlug, string $currentSlug = ''): string
{
    $baseSlug = bms_page_clean_slug_candidate($baseSlug);
    if ($baseSlug === '') {
        $baseSlug = 'page';
    }

    $reserved = ['admin', 'assets', 'install', 'stream', 'media', 'feed', 'account', 'profile', 'author', 'comments', 'search', 'page'];
    if (in_array($baseSlug, $reserved, true)) {
        $baseSlug = 'page-' . $baseSlug;
    }

    $currentSlug = bms_slugify($currentSlug);
    $slug = $baseSlug;
    $counter = 2;
    while (true) {
        $published = bms_content_path('pages/published/' . $slug . '.md');
        $draft = bms_content_path('pages/drafts/' . $slug . '.md');
        $streamPublished = bms_content_path('published/' . $slug . '.md');
        $streamDraft = bms_content_path('drafts/' . $slug . '.md');
        $databaseConflict = function_exists('bms_database_slug_exists') && (bms_database_slug_exists($slug, $currentSlug, 'page') || bms_database_slug_exists($slug, $currentSlug, 'stream'));
        $conflicts = ($currentSlug === '' || $slug !== $currentSlug) && ($databaseConflict || is_file($published) || is_file($draft) || is_file($streamPublished) || is_file($streamDraft));
        if (!$conflicts) {
            return $slug;
        }
        $slug = $baseSlug . '-' . $counter;
        $counter++;
    }
}

function bms_page_status_section(string $status): string
{
    return $status === 'published' ? 'pages/published' : 'pages/drafts';
}

function bms_page_slug_needs_generation(string $slug): bool
{
    $rawSlug = trim($slug);
    if ($rawSlug === '') {
        return true;
    }

    $cleanSlug = bms_page_clean_slug_candidate($rawSlug);
    if ($cleanSlug === '' || in_array($cleanSlug, ['untitled', 'generated-on-save'], true)) {
        return true;
    }

    return preg_match('/^untitled-\d{8}(?:-\d{6})?$/', $cleanSlug) === 1;
}

function bms_page_prepare_metadata_fields(array $fields, string $body, string $currentSlug = ''): array
{
    $title = trim((string)($fields['title'] ?? ''));
    if ($title === '') {
        $title = bms_first_heading($body) ?: 'Untitled Page';
    }

    $slugInput = trim((string)($fields['slug'] ?? ''));
    $slug = bms_page_slug_needs_generation($slugInput) ? bms_page_unique_slug($title, $currentSlug) : bms_page_unique_slug($slugInput, $currentSlug);

    $seoTitle = trim((string)($fields['seo_title'] ?? ''));
    if ($seoTitle === bms_page_generated_seo_title($title) || $seoTitle === $title) {
        $seoTitle = '';
    }

    $description = trim((string)($fields['description'] ?? ''));
    if ($description === '') {
        $description = bms_plain_excerpt($body, 160);
    }

    $fields['title'] = $title;
    $fields['slug'] = $slug;
    $fields['seo_title'] = $seoTitle;
    $fields['description'] = $description;
    $fields['content_type'] = 'page';
    $fields['category'] = 'Page';
    $fields['tags'] = '';

    return $fields;
}

function bms_build_page_markdown_from_request(string $forcedStatus = 'draft', string $currentSlug = ''): string
{
    $body = (string)($_POST['body_markdown'] ?? '');
    $fields = [
        'title' => (string)($_POST['page_title'] ?? ''),
        'slug' => (string)($_POST['page_slug'] ?? ''),
        'status' => $forcedStatus,
        'date' => (string)($_POST['page_date'] ?? date('Y-m-d')),
        'content_type' => 'page',
        'description' => (string)($_POST['page_description'] ?? ''),
        'category' => 'Page',
        'tags' => '',
        'seo_title' => (string)($_POST['page_seo_title'] ?? ''),
        'robots' => (string)($_POST['page_robots'] ?? ''),
    ];
    $fields = bms_page_prepare_metadata_fields($fields, $body, $currentSlug);
    return bms_build_markdown_document($fields, $body);
}

function bms_list_page_records(string $status = 'published'): array
{
    $section = $status === 'published' ? 'pages/published' : 'pages/drafts';
    return array_values(array_filter(bms_list_content_records($section), function (array $page): bool {
        return bms_normalize_content_type((string)($page['content_type'] ?? $page['post_type'] ?? 'page')) === 'page';
    }));
}




function bms_stream_slug_needs_generation(string $slug): bool
{
    $slug = bms_slugify($slug);
    if ($slug === '') {
        return true;
    }

    if (in_array($slug, ['untitled', 'generated-on-save'], true)) {
        return true;
    }

    return preg_match('/^(stream|stream-post|untitled)-\d{8}(?:-\d{6})?$/', $slug) === 1;
}

function bms_stream_title_needs_generation(string $title): bool
{
    $title = trim($title);
    if ($title === '' || strtolower($title) === 'untitled') {
        return true;
    }

    return preg_match('/^Stream Post:\s+/i', $title) === 1;
}

function bms_stream_media_context_from_path(string $featuredMedia): array
{
    $featuredMedia = trim($featuredMedia);
    if ($featuredMedia === '') {
        return [];
    }

    return ['filename' => basename($featuredMedia)];
}

function bms_stream_prepare_metadata_fields(array $fields, string $body, string $currentSlug = ''): array
{
    $createdAt = trim((string)($fields['stream_created_at'] ?? $fields['created_at'] ?? $fields['date'] ?? date('Y-m-d H:i:s')));
    $featuredMedia = trim((string)($fields['featured_media'] ?? ''));
    $mediaGallery = bms_normalize_media_gallery($fields['media_gallery'] ?? [], $featuredMedia);
    if ($mediaGallery) {
        $featuredMedia = (string)$mediaGallery[0];
        $fields['featured_media'] = $featuredMedia;
        $fields['media_gallery'] = $mediaGallery;
    }
    $mediaContext = bms_stream_media_context_from_path($featuredMedia);
    $metadataBody = $body;
    if (trim($metadataBody) === '' && $featuredMedia === '') {
        $locationLabel = trim((string)($fields['location_name'] ?? $fields['location_area'] ?? $fields['location_locality'] ?? ''));
        if ($locationLabel !== '') {
            $metadataBody = 'Checked in at ' . $locationLabel . '.';
        }
    }

    $manualTitle = trim((string)($fields['title'] ?? ''));
    $title = $manualTitle;
    if (bms_stream_title_needs_generation($title)) {
        $title = bms_stream_admin_title_from_body($metadataBody, $createdAt, $featuredMedia, $mediaContext);
    }

    $slugInput = trim((string)($fields['slug'] ?? ''));
    if (bms_stream_slug_needs_generation($slugInput)) {
        $slug = bms_stream_unique_slug(bms_stream_slug_base($metadataBody, $createdAt, $mediaContext, $manualTitle), $currentSlug);
    } else {
        $slug = bms_slugify($slugInput);
    }

    $seoTitle = trim((string)($fields['seo_title'] ?? ''));
    if ($seoTitle === '') {
        $seoTitle = bms_stream_generated_seo_title($metadataBody, $createdAt, $featuredMedia, $mediaContext);
    }

    $description = trim((string)($fields['description'] ?? ''));
    if ($description === '') {
        $description = bms_stream_generated_description($metadataBody, $createdAt, $featuredMedia);
    }

    $fields['title'] = $title;
    $fields['slug'] = $slug;
    $fields['seo_title'] = $seoTitle;
    $fields['description'] = $description;
    $fields['stream_created_at'] = $createdAt;

    return $fields;
}



function bms_parse_markdown_file(string $file): array
{
    $raw = file_get_contents($file);
    if ($raw === false) {
        throw new RuntimeException('Could not read Markdown file.');
    }
    return bms_parse_markdown_string($raw);
}

function bms_parse_markdown_string(string $raw): array
{
    $frontMatter = [];
    $body = $raw;

    if (preg_match('/\A---\R(.*?)\R---\R?(.*)\z/s', $raw, $matches)) {
        $frontMatterRaw = trim($matches[1]);
        $body = $matches[2];
        $frontMatter = bms_parse_front_matter($frontMatterRaw);
    }

    $title = $frontMatter['title'] ?? bms_first_heading($body) ?? 'Untitled';
    $slug = $frontMatter['slug'] ?? bms_slugify($title);
    $description = $frontMatter['description'] ?? '';
    $date = $frontMatter['date'] ?? date('Y-m-d');
    $category = $frontMatter['category'] ?? 'Stream';
    $status = $frontMatter['status'] ?? 'draft';
    $contentType = bms_normalize_content_type((string)($frontMatter['content_type'] ?? $frontMatter['post_type'] ?? 'stream'));
    $tags = bms_normalize_terms($frontMatter['tags'] ?? []);
    $featuredMedia = trim((string)($frontMatter['featured_media'] ?? ''));
    $mediaGallery = bms_normalize_media_gallery($frontMatter['media_gallery'] ?? [], $featuredMedia);
    if ($featuredMedia === '' && $mediaGallery) {
        $featuredMedia = (string)$mediaGallery[0];
    }
    $streamCreatedAt = trim((string)($frontMatter['stream_created_at'] ?? $frontMatter['created_at'] ?? ''));
    $seoTitle = trim((string)($frontMatter['seo_title'] ?? ''));
    $robots = trim((string)($frontMatter['robots'] ?? ''));
    $linkPreviewUrl = trim((string)($frontMatter['link_preview_url'] ?? ''));
    $linkPreviewTitle = trim((string)($frontMatter['link_preview_title'] ?? ''));
    $linkPreviewDescription = trim((string)($frontMatter['link_preview_description'] ?? ''));
    $linkPreviewImage = trim((string)($frontMatter['link_preview_image'] ?? ''));
    $linkPreviewSiteName = trim((string)($frontMatter['link_preview_site_name'] ?? ''));
    $locationPlaceId = trim((string)($frontMatter['location_place_id'] ?? ''));
    $locationName = trim((string)($frontMatter['location_name'] ?? ''));
    $locationCategory = trim((string)($frontMatter['location_category'] ?? ''));
    $locationArea = trim((string)($frontMatter['location_area'] ?? ''));
    $locationLocality = trim((string)($frontMatter['location_locality'] ?? ''));
    $locationRegion = trim((string)($frontMatter['location_region'] ?? ''));
    $locationCountry = trim((string)($frontMatter['location_country'] ?? ''));
    $locationDisplayMode = trim((string)($frontMatter['location_display_mode'] ?? ''));

    $category = trim((string)(is_array($category) ? reset($category) : $category));
    if ($category === '') {
        $category = 'Stream';
    }

    return [
        'front_matter' => $frontMatter,
        'body' => $body,
        'title' => trim((string)$title),
        'slug' => bms_slugify((string)$slug),
        'description' => trim((string)$description),
        'date' => trim((string)$date),
        'category' => $category,
        'category_slug' => bms_term_slug($category),
        'tags' => $tags,
        'tag_slugs' => array_map('bms_term_slug', $tags),
        'status' => trim((string)$status),
        'content_type' => $contentType,
        'post_type' => $contentType,
        'featured_media' => $featuredMedia,
        'media_gallery' => $mediaGallery,
        'stream_created_at' => $streamCreatedAt,
        'seo_title' => $seoTitle,
        'robots' => $robots,
        'link_preview_url' => $linkPreviewUrl,
        'link_preview_title' => $linkPreviewTitle,
        'link_preview_description' => $linkPreviewDescription,
        'link_preview_image' => $linkPreviewImage,
        'link_preview_site_name' => $linkPreviewSiteName,
        'location_place_id' => $locationPlaceId,
        'location_name' => $locationName,
        'location_category' => $locationCategory,
        'location_area' => $locationArea,
        'location_locality' => $locationLocality,
        'location_region' => $locationRegion,
        'location_country' => $locationCountry,
        'location_display_mode' => $locationDisplayMode,
        'raw' => $raw,
    ];
}

function bms_parse_front_matter(string $raw): array
{
    $data = [];
    $lines = preg_split('/\R/', $raw) ?: [];
    $currentKey = null;

    foreach ($lines as $line) {
        if (preg_match('/^([A-Za-z0-9_-]+):\s*(.*)$/', $line, $m)) {
            $currentKey = $m[1];
            $value = trim($m[2]);
            $value = trim($value, '"\'');
            $data[$currentKey] = $value;
            continue;
        }

        if ($currentKey && preg_match('/^\s*-\s*(.+)$/', $line, $m)) {
            if (!is_array($data[$currentKey])) {
                $data[$currentKey] = $data[$currentKey] === '' ? [] : [$data[$currentKey]];
            }
            $data[$currentKey][] = trim($m[1], '"\'');
        }
    }

    return $data;
}

function bms_normalize_terms(mixed $value): array
{
    if (is_string($value)) {
        $value = trim($value);
        if ($value === '') {
            return [];
        }
        $terms = preg_split('/\s*,\s*/', $value) ?: [];
    } elseif (is_array($value)) {
        $terms = $value;
    } else {
        $terms = [];
    }

    $clean = [];
    foreach ($terms as $term) {
        if (is_array($term)) {
            continue;
        }
        $term = trim((string)$term);
        $term = trim($term, '"\'');
        if ($term === '') {
            continue;
        }
        $key = strtolower($term);
        if (!isset($clean[$key])) {
            $clean[$key] = $term;
        }
    }

    return array_values($clean);
}


function bms_normalize_media_gallery(mixed $value, string $featuredMedia = ''): array
{
    if (is_string($value)) {
        $value = trim($value);
        if ($value === '') {
            $items = [];
        } elseif (str_starts_with($value, '[')) {
            $decoded = json_decode($value, true);
            $items = is_array($decoded) ? $decoded : [$value];
        } else {
            $items = [$value];
        }
    } elseif (is_array($value)) {
        $items = $value;
    } else {
        $items = [];
    }

    $featuredMedia = trim(str_replace('\\', '/', $featuredMedia));
    if ($featuredMedia !== '') {
        array_unshift($items, $featuredMedia);
    }

    $clean = [];
    foreach ($items as $item) {
        if (is_array($item) || is_object($item)) {
            continue;
        }
        $item = trim(str_replace('\\', '/', (string)$item));
        $item = trim($item, "\r\n\t ");
        if ($item === '' || str_contains($item, "\0") || preg_match('/[\r\n]/', $item) === 1) {
            continue;
        }
        $key = strtolower($item);
        if (!isset($clean[$key])) {
            $clean[$key] = $item;
        }
        if (count($clean) >= 4) {
            break;
        }
    }

    return array_values($clean);
}


function bms_front_matter_quote(string $value): string
{
    $value = str_replace(["\r\n", "\r", "\n"], ' ', trim($value));
    $value = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    return '"' . $value . '"';
}

function bms_build_markdown_document(array $fields, string $body): string
{
    $title = trim((string)($fields['title'] ?? 'Untitled'));
    if ($title === '') {
        $title = 'Untitled';
    }

    $slug = bms_slugify((string)($fields['slug'] ?? $title));
    $status = trim((string)($fields['status'] ?? 'draft'));
    if (!in_array($status, ['draft', 'published', 'scheduled'], true)) {
        $status = 'draft';
    }

    $date = trim((string)($fields['date'] ?? date('Y-m-d')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $date = date('Y-m-d');
    }

    $description = trim((string)($fields['description'] ?? ''));
    $contentType = bms_normalize_content_type((string)($fields['content_type'] ?? $fields['post_type'] ?? 'stream'));
    $category = $contentType === 'page' ? 'Page' : 'Stream';

    $tags = bms_normalize_terms($fields['tags'] ?? []);
    $featuredMedia = trim((string)($fields['featured_media'] ?? ''));
    $mediaGallery = bms_normalize_media_gallery($fields['media_gallery'] ?? [], $featuredMedia);
    if ($mediaGallery) {
        $featuredMedia = (string)$mediaGallery[0];
        $fields['featured_media'] = $featuredMedia;
    }

    $lines = [
        '---',
        'title: ' . bms_front_matter_quote($title),
        'slug: ' . bms_front_matter_quote($slug),
        'status: ' . bms_front_matter_quote($status),
        'content_type: ' . bms_front_matter_quote($contentType),
        'date: ' . $date,
        'description: ' . bms_front_matter_quote($description),
        'category: ' . bms_front_matter_quote($category),
    ];

    if ($tags) {
        $lines[] = 'tags:';
        foreach ($tags as $tag) {
            $lines[] = '  - ' . bms_front_matter_quote($tag);
        }
    } else {
        $lines[] = 'tags: ""';
    }

    foreach (['featured_media', 'stream_created_at', 'scheduled_at', 'seo_title', 'robots', 'link_preview_url', 'link_preview_title', 'link_preview_description', 'link_preview_image', 'link_preview_site_name', 'location_place_id', 'location_name', 'location_category', 'location_area', 'location_locality', 'location_region', 'location_country', 'location_display_mode'] as $streamKey) {
        $streamValue = trim((string)($fields[$streamKey] ?? ''));
        if ($streamValue !== '') {
            $lines[] = $streamKey . ': ' . bms_front_matter_quote($streamValue);
        }
    }

    if (count($mediaGallery) > 1) {
        $lines[] = 'media_gallery:';
        foreach ($mediaGallery as $galleryItem) {
            $lines[] = '  - ' . bms_front_matter_quote((string)$galleryItem);
        }
    }

    $lines[] = '---';

    $body = str_replace(["\r\n", "\r"], "\n", trim($body));
    if ($body === '') {
        $hasStreamMedia = $contentType === 'stream' && trim((string)($fields['featured_media'] ?? '')) !== '';
        $hasStreamLocation = $contentType === 'stream' && (int)($fields['location_place_id'] ?? 0) > 0;
        if (!$hasStreamMedia && !$hasStreamLocation) {
            $body = '# ' . $title . "\n\nStart writing here.";
        }
    }

    return implode("\n", $lines) . "\n\n" . $body . ($body !== '' ? "\n" : '');
}

function bms_existing_stream_front_matter_for_slug(string $slug): array
{
    $slug = bms_slugify($slug);
    if ($slug === '' || !function_exists('bms_find_database_content_by_slug_status')) {
        return [];
    }
    foreach (['published', 'scheduled', 'draft'] as $status) {
        try {
            $page = bms_find_database_content_by_slug_status($slug, $status, 'stream');
            if ($page && is_array($page['front_matter'] ?? null)) {
                return $page['front_matter'];
            }
        } catch (Throwable $e) {
            return [];
        }
    }
    return [];
}

function bms_stream_link_preview_request_fields(string $currentSlug = ''): array
{
    $keys = ['link_preview_url', 'link_preview_title', 'link_preview_description', 'link_preview_image', 'link_preview_site_name'];
    if (array_key_exists('link_preview_enabled', $_POST)) {
        if ((string)($_POST['link_preview_enabled'] ?? '0') !== '1') {
            return [];
        }
        $fields = [];
        foreach ($keys as $key) {
            $fields[$key] = trim((string)($_POST[$key] ?? ''));
        }
        return trim((string)$fields['link_preview_url']) !== '' ? $fields : [];
    }

    $existing = bms_existing_stream_front_matter_for_slug($currentSlug);
    $fields = [];
    foreach ($keys as $key) {
        $fields[$key] = trim((string)($existing[$key] ?? ''));
    }
    return trim((string)$fields['link_preview_url']) !== '' ? $fields : [];
}

function bms_build_markdown_from_request(string $forcedStatus = 'draft', string $currentSlug = ''): string
{
    $body = (string)($_POST['body_markdown'] ?? '');
    $fields = [
        'title' => (string)($_POST['stream_title'] ?? ''),
        'slug' => (string)($_POST['stream_slug'] ?? ''),
        'status' => $forcedStatus,
        'date' => (string)($_POST['stream_date'] ?? date('Y-m-d')),
        'content_type' => 'stream',
        'description' => (string)($_POST['stream_description'] ?? ''),
        'category' => 'Stream',
        'tags' => '',
        'featured_media' => (string)($_POST['featured_media'] ?? ''),
        'media_gallery' => bms_normalize_media_gallery($_POST['media_gallery'] ?? [], (string)($_POST['featured_media'] ?? '')),
        'stream_created_at' => (string)($_POST['stream_created_at'] ?? ($_POST['stream_date'] ?? date('Y-m-d H:i:s'))),
        'scheduled_at' => (string)($_POST['stream_scheduled_at_utc'] ?? ''),
        'seo_title' => (string)($_POST['stream_seo_title'] ?? ''),
        'robots' => (string)($_POST['stream_robots'] ?? ''),
    ];
    $fields = array_merge($fields, bms_stream_link_preview_request_fields($currentSlug));
    if (function_exists('bms_place_request_fields')) {
        $fields = array_merge($fields, bms_place_request_fields($currentSlug));
    }
    $fields = bms_stream_prepare_metadata_fields($fields, $body, $currentSlug);

    return bms_build_markdown_document($fields, $body);
}

function bms_first_heading(string $body): ?string
{
    if (preg_match('/^#\s+(.+)$/m', $body, $m)) {
        return trim($m[1]);
    }
    return null;
}

function bms_list_import_markdown_files(string $section): array
{
    $dir = bms_content_path($section);
    if (!is_dir($dir)) {
        return [];
    }

    $files = glob($dir . '/*.md') ?: [];
    $items = [];
    foreach ($files as $file) {
        try {
            $parsed = bms_parse_markdown_file($file);
            $parsed['filename'] = basename($file);
            $parsed['path'] = $file;
            $parsed['section'] = $section;
            $parsed['content_storage'] = 'import-markdown';
            $items[] = $parsed;
        } catch (Throwable $e) {
            continue;
        }
    }

    usort($items, function ($a, $b) {
        return strcmp($b['date'] ?? '', $a['date'] ?? '');
    });

    return $items;
}

function bms_list_content_records(string $section): array
{
    if (function_exists('bms_database_content_enabled') && function_exists('bms_database_content_columns_ready') && bms_database_content_enabled() && bms_database_content_columns_ready()) {
        try {
            return bms_list_database_content_for_section($section);
        } catch (Throwable $e) {
            return [];
        }
    }

    return [];
}


function bms_write_file(string $path, string $contents): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        throw new RuntimeException('Could not create directory: ' . $dir);
    }
    if (file_put_contents($path, $contents) === false) {
        throw new RuntimeException('Could not write file: ' . $path);
    }
}

function bms_delete_directory(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = array_diff(scandir($dir) ?: [], ['.', '..']);
    foreach ($items as $item) {
        $path = $dir . '/' . $item;
        is_dir($path) ? bms_delete_directory($path) : unlink($path);
    }
    rmdir($dir);
}


function bms_normalize_content_type(string $type): string
{
    $type = strtolower(trim($type));
    if ($type === 'page') {
        return 'page';
    }
    // Legacy content-type values are tolerated and treated as stream posts.
    return 'stream';
}


function bms_homepage_mode(): string
{
    return 'stream';
}

function bms_stream_composer_enabled(): bool
{
    // The front-end composer is the canonical Stream Post creation surface.
    // Keep this helper for theme and extension compatibility, but do not allow
    // an old saved setting to remove the only supported creation workflow.
    return true;
}

function bms_stream_show_dates(): bool
{
    return (string)bms_setting_or_config('stream_show_dates', '1') === '1';
}

function bms_stream_show_edit_links(): bool
{
    return (string)bms_setting_or_config('stream_show_edit_links', '0') === '1';
}


function bms_stream_index_policy(): string
{
    $policy = (string)bms_setting_or_config('stream_index_policy', 'smart');
    return in_array($policy, ['all', 'smart', 'noindex'], true) ? $policy : 'smart';
}



function bms_stream_posts_per_page(): int
{
    $count = (int)bms_setting_or_config('stream_posts_per_page', '20');
    if ($count < 1) {
        return 1;
    }
    if ($count > 100) {
        return 100;
    }
    return $count;
}

function bms_is_stream_post(array $page): bool
{
    return bms_normalize_content_type((string)($page['content_type'] ?? $page['post_type'] ?? 'stream')) === 'stream';
}

function bms_filter_stream_posts(array $pages): array
{
    return array_values(array_filter($pages, 'bms_is_stream_post'));
}

function bms_datetime_sort_timestamp(string $raw, ?DateTimeZone $timezone = null): int
{
    $raw = trim($raw);
    if ($raw === '') {
        return 0;
    }

    try {
        $date = $timezone instanceof DateTimeZone ? new DateTimeImmutable($raw, $timezone) : new DateTimeImmutable($raw);
        return $date->getTimestamp();
    } catch (Throwable $e) {
        $time = strtotime($raw);
        return $time === false ? 0 : (int)$time;
    }
}

function bms_stream_sort_timestamp(array $page): int
{
    $status = (string)($page['content_status'] ?? $page['status'] ?? '');
    $scheduledAt = trim((string)($page['scheduled_at'] ?? ($page['front_matter']['scheduled_at'] ?? '')));
    $publishedAt = trim((string)($page['published_at'] ?? ''));

    if (($status === 'scheduled' || $status === 'published') && $scheduledAt !== '') {
        return bms_datetime_sort_timestamp($scheduledAt, bms_utc_timezone());
    }

    if ($status === 'published' && $publishedAt !== '') {
        return bms_datetime_sort_timestamp(
            $publishedAt,
            bms_stream_published_at_is_utc($page) ? bms_utc_timezone() : bms_site_timezone()
        );
    }

    $raw = trim((string)($page['stream_created_at'] ?? $page['front_matter']['stream_created_at'] ?? ''));
    if ($raw === '') {
        $raw = $publishedAt !== '' ? $publishedAt : (string)($page['date'] ?? '');
    }
    return bms_datetime_sort_timestamp($raw);
}

function bms_sort_stream_posts(array $pages): array
{
    usort($pages, function (array $a, array $b): int {
        $aTime = bms_stream_sort_timestamp($a);
        $bTime = bms_stream_sort_timestamp($b);
        if ($aTime !== $bTime) {
            return $bTime <=> $aTime;
        }
        return strcmp((string)($b['filename'] ?? ''), (string)($a['filename'] ?? ''));
    });
    return $pages;
}

function bms_apply_stream_reading_settings(array $pages): array
{
    return array_slice(bms_sort_stream_posts($pages), 0, bms_stream_posts_per_page());
}

function bms_stream_preview_text(array $page, int $limit = 90): string
{
    $body = bms_stream_clean_text_for_seo((string)($page['body'] ?? ''));
    if ($body === '') {
        return trim((string)($page['description'] ?? '')) ?: 'Media post';
    }
    return bms_stream_limit_text($body, $limit, '…');
}

function bms_stream_admin_title_from_body(string $body, string $createdAt = '', string $featuredMedia = '', array $media = []): string
{
    return bms_stream_generated_post_title($body, $createdAt, $featuredMedia, $media, 70);
}


function bms_autosave_enabled(): bool
{
    return (string)bms_setting_or_config('autosave_enabled', '1') === '1';
}


function bms_default_editor_mode(): string
{
    $mode = (string)bms_setting_or_config('default_editor_mode', 'visual');
    return in_array($mode, ['visual', 'markdown'], true) ? $mode : 'visual';
}


function bms_default_content_status(): string
{
    $status = (string)bms_setting_or_config('default_content_status', 'draft');
    return in_array($status, ['draft', 'published'], true) ? $status : 'draft';
}


function bms_query_string(array $params): string
{
    $clean = [];
    foreach ($params as $key => $value) {
        if ($value === null || $value === '') {
            continue;
        }
        $clean[$key] = $value;
    }
    $query = http_build_query($clean);
    return $query !== '' ? '?' . $query : '';
}

function bms_version(): string
{
    $versionFile = bms_root_path('VERSION');
    if (is_file($versionFile)) {
        $version = trim((string)file_get_contents($versionFile));
        if ($version !== '') {
            return $version;
        }
    }

    $configured = trim((string)(bms_config()['version'] ?? ''));
    if ($configured !== '') {
        return $configured;
    }

    return 'unknown';
}


function bms_abort_request(string $message, int $status = 400): void
{
    http_response_code($status);
    if (!headers_sent()) {
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo $message;
    exit;
}

function bms_flash(string $message, string $type = 'info'): void
{
    $_SESSION['flash'][] = ['message' => $message, 'type' => $type];
}

function bms_get_flash(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}


function bms_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
        return true;
    }
    return false;
}

function bms_session_cookie_path(): string
{
    $base = bms_base_path();
    return $base !== '' ? rtrim($base, '/') . '/' : '/';
}

function bms_session_cookie_name(): string
{
    $base = bms_base_path();
    if ($base === '') {
        return 'bms_session_root';
    }
    return 'bms_session_' . substr(hash('sha256', $base), 0, 16);
}

/**
 * Accept same-site browser requests and browser/OS handoffs with no Origin.
 * A hostile cross-site form supplies an untrusted Origin or Sec-Fetch-Site.
 */
function bms_request_origin_is_same_site_or_absent(): bool
{
    $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($origin === '') {
        $fetchSite = strtolower(trim((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')));
        return $fetchSite !== 'cross-site';
    }

    $host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
    if ($host === '') {
        return false;
    }
    $scheme = bms_is_https() ? 'https' : 'http';
    $expected = $scheme . '://' . $host;
    return hash_equals($expected, rtrim($origin, '/'));
}

function bms_log_sanitized_exception(string $context, Throwable $e): void
{
    $context = preg_replace('/[^a-z0-9._-]+/i', '-', trim($context)) ?: 'application';
    $message = str_replace(["\r", "\n"], ' ', trim($e->getMessage()));
    $message = preg_replace('/\b(password|pass|token|secret|api[_ -]?key|cron[_ -]?key|authorization|cookie|session(?:[_ -]?id)?)\s*[:=]\s*(?:"[^"]*"|\'[^\']*\'|[^\s,;]+)/i', '$1=[redacted]', $message) ?? $message;
    $message = preg_replace('/\b(?:bms[a-z0-9_-]{10,}|[a-f0-9]{48,})\b/i', '[redacted]', $message) ?? $message;
    if (strlen($message) > 900) {
        $message = substr($message, 0, 900) . '…';
    }
    error_log('Bonumark Stream admin error [' . $context . ']: ' . $message);
}

function bms_log_admin_exception(string $context, Throwable $e): void
{
    bms_log_sanitized_exception($context, $e);
}

function bms_start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');

    $secure = bms_is_https();
    session_name(bms_session_cookie_name());
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => bms_session_cookie_path(),
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function bms_send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: geolocation=(self), microphone=(), camera=()');
    header("Content-Security-Policy: default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'; img-src 'self' data: blob: https: http:; style-src 'self'; script-src 'self'");
}

function bms_password_policy_error(string $password, string $username = '', string $email = ''): ?string
{
    $passwordLength = strlen($password);
    if ($passwordLength < 12) {
        return 'Password must be at least 12 characters.';
    }

    if ($passwordLength > 128) {
        return 'Password must be 128 characters or fewer.';
    }

    $lowerPassword = strtolower($password);
    $common = [
        'password', 'password1', 'password12', 'password123', 'admin123456',
        'changeme', 'change-this-password', 'qwerty123456', 'letmein123456',
        'bonumark123', '123456789012', '111111111111', 'aaaaaaaaaaaa'
    ];
    foreach ($common as $bad) {
        if ($lowerPassword === $bad || str_contains($lowerPassword, $bad)) {
            return 'Password is too common. Use a stronger unique password.';
        }
    }

    $normalizedUsername = strtolower(trim($username));
    if ($normalizedUsername !== '' && $lowerPassword === $normalizedUsername) {
        return 'Password cannot match the username.';
    }

    $normalizedEmail = strtolower(trim($email));
    if ($normalizedEmail !== '' && $lowerPassword === $normalizedEmail) {
        return 'Password cannot match the email address.';
    }

    if ($passwordLength < 20) {
        $classes = 0;
        $classes += preg_match('/[a-z]/', $password) ? 1 : 0;
        $classes += preg_match('/[A-Z]/', $password) ? 1 : 0;
        $classes += preg_match('/[0-9]/', $password) ? 1 : 0;
        $classes += preg_match('/[^A-Za-z0-9]/', $password) ? 1 : 0;
        if ($classes < 3) {
            return 'Password must use at least three of these: lowercase letters, uppercase letters, numbers, and symbols. A 20+ character passphrase is also accepted.';
        }
    }

    return null;
}

function bms_validate_password_policy(string $password, string $username = '', string $email = ''): void
{
    $error = bms_password_policy_error($password, $username, $email);
    if ($error !== null) {
        throw new RuntimeException($error);
    }
}

function bms_request_origin(): string
{
    $scheme = bms_is_https() ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    return $host !== '' ? $scheme . '://' . $host : '';
}

function bms_install_base_url_from_request(): string
{
    $origin = bms_request_origin();
    if ($origin === '') {
        return '';
    }
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $dir = rtrim(dirname($script), '/\\');
    if ($dir === '.' || $dir === '/') {
        $dir = '';
    }
    return $origin . $dir;
}

function bms_private_folder_probe_response(int $httpStatus, string $body, string $expectedMarker): array
{
    if (in_array($httpStatus, [401, 403, 404, 410], true)) {
        return [
            'status' => 'protected',
            'message' => 'The _bonumark_stream private folder rejected direct HTTP access (HTTP ' . $httpStatus . ').',
        ];
    }

    if ($httpStatus >= 200 && $httpStatus < 300) {
        if ($expectedMarker !== '' && trim($body) === trim($expectedMarker)) {
            return [
                'status' => 'exposed',
                'message' => 'The _bonumark_stream private folder is publicly reachable. Installation should not continue until server rules block it.',
            ];
        }
        return [
            'status' => 'unknown',
            'message' => 'The private-path request returned HTTP ' . $httpStatus . ' without the expected private-file contents. Verify that the web server explicitly blocks _bonumark_stream.',
        ];
    }

    if ($httpStatus >= 300 && $httpStatus < 400) {
        return [
            'status' => 'unknown',
            'message' => 'The private-path request was redirected (HTTP ' . $httpStatus . '). Verify that the web server explicitly blocks _bonumark_stream.',
        ];
    }

    if ($httpStatus >= 400 && $httpStatus < 500) {
        return [
            'status' => 'unknown',
            'message' => 'The private-path request returned HTTP ' . $httpStatus . '. Verify that the web server explicitly blocks _bonumark_stream.',
        ];
    }

    return [
        'status' => 'unknown',
        'message' => $httpStatus > 0
            ? 'The private-path request returned HTTP ' . $httpStatus . '. Verify that the web server explicitly blocks _bonumark_stream.'
            : 'Bonumark Stream could not obtain an HTTP response while testing private-folder exposure.',
    ];
}

function bms_readonly_http_probe(string $url): array
{
    if (function_exists('curl_init')) {
        $handle = curl_init($url);
        if ($handle !== false) {
            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 4,
                CURLOPT_USERAGENT => 'BonumarkStreamSecurityProbe/2.0',
                CURLOPT_HEADER => false,
            ]);
            $body = curl_exec($handle);
            $error = curl_error($handle);
            $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            curl_close($handle);
            if ($body !== false) {
                return ['ok' => true, 'status' => $status, 'body' => (string)$body, 'transport' => 'curl', 'error' => ''];
            }
            return ['ok' => false, 'status' => $status, 'body' => '', 'transport' => 'curl', 'error' => $error !== '' ? $error : 'cURL request failed.'];
        }
    }

    if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
        return ['ok' => false, 'status' => 0, 'body' => '', 'transport' => 'none', 'error' => 'Neither PHP cURL nor allow_url_fopen is available for the read-only HTTP probe.'];
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 4,
            'ignore_errors' => true,
            'follow_location' => 0,
            'max_redirects' => 0,
            'header' => "User-Agent: BonumarkStreamSecurityProbe/2.0\r\n",
        ],
    ]);
    $body = @file_get_contents($url, false, $context);
    $headers = is_array($http_response_header ?? null) ? $http_response_header : [];
    $status = 0;
    foreach ($headers as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#i', (string)$header, $matches) === 1) {
            $status = (int)$matches[1];
        }
    }
    if ($body === false && $status === 0) {
        $last = error_get_last();
        return ['ok' => false, 'status' => 0, 'body' => '', 'transport' => 'stream', 'error' => (string)($last['message'] ?? 'HTTP probe failed.')];
    }
    return ['ok' => true, 'status' => $status, 'body' => is_string($body) ? $body : '', 'transport' => 'stream', 'error' => ''];
}

function bms_probe_private_folder_exposure(?string $baseUrl = null): array
{
    $baseUrl = $baseUrl !== null && trim($baseUrl) !== '' ? rtrim(trim($baseUrl), '/') : bms_install_base_url_from_request();
    if ($baseUrl === '') {
        return ['status' => 'unknown', 'message' => 'Could not determine the site URL to test private folder exposure.'];
    }

    $markerPath = bms_root_path('VERSION');
    $expectedMarker = is_file($markerPath) ? trim((string)@file_get_contents($markerPath)) : '';
    if ($expectedMarker === '') {
        return ['status' => 'unknown', 'message' => 'Could not read the installed private VERSION marker for the read-only exposure test.'];
    }

    $probeUrl = $baseUrl . '/_bonumark_stream/VERSION';
    $response = bms_readonly_http_probe($probeUrl);
    if (empty($response['ok'])) {
        $error = trim((string)($response['error'] ?? ''));
        return [
            'status' => 'unknown',
            'message' => 'Could not complete the read-only private folder exposure test.' . ($error !== '' ? ' ' . $error : ''),
        ];
    }

    return bms_private_folder_probe_response(
        (int)($response['status'] ?? 0),
        (string)($response['body'] ?? ''),
        $expectedMarker
    );
}

function bms_security_status(): array
{
    $items = [];
    $items[] = [
        'label' => 'PHP version',
        'status' => version_compare(PHP_VERSION, '8.1.0', '>=') ? 'pass' : 'fail',
        'message' => PHP_VERSION . (version_compare(PHP_VERSION, '8.2.0', '>=') ? ' is supported.' : (version_compare(PHP_VERSION, '8.1.0', '>=') ? ' is supported. PHP 8.2 or newer is recommended.' : ' is below the PHP 8.1 minimum target.')),
    ];
    $items[] = [
        'label' => 'HTTPS',
        'status' => bms_is_https() ? 'pass' : 'warn',
        'message' => bms_is_https() ? 'Admin requests appear to be using HTTPS.' : 'HTTPS was not detected. Use HTTPS for real sites.',
    ];
    $webServer = bms_web_server_capability();
    $items[] = [
        'label' => 'Web server',
        'status' => (string)($webServer['status'] ?? 'warning'),
        'message' => (string)($webServer['message'] ?? 'Web server capability could not be determined.'),
    ];
    $items[] = [
        'label' => 'PDO MySQL',
        'status' => function_exists('bms_db_supports_mysql') && bms_db_supports_mysql() ? 'pass' : 'fail',
        'message' => function_exists('bms_db_supports_mysql') && bms_db_supports_mysql() ? 'PDO MySQL is available.' : 'PDO MySQL is not available.',
    ];

    $dbStatus = 'warn';
    $dbMessage = 'Database configuration has not been verified.';
    if (function_exists('bms_has_database_config') && bms_has_database_config()) {
        try {
            if (function_exists('bms_db')) {
                bms_db()->query('SELECT 1');
                $dbStatus = 'pass';
                $dbMessage = 'Database connection is working.';
            }
        } catch (Throwable $e) {
            $dbStatus = 'fail';
            $dbMessage = 'Database connection failed: ' . $e->getMessage();
        }
    }
    $items[] = ['label' => 'Database connection', 'status' => $dbStatus, 'message' => $dbMessage];

    $databaseCompatibilityStatus = 'warn';
    $databaseCompatibilityMessage = 'Database server version has not been verified.';
    if ($dbStatus === 'pass' && function_exists('bms_database_server_compatibility')) {
        try {
            $databaseCompatibility = bms_database_server_compatibility(bms_db());
            $databaseCompatibilityStatus = !empty($databaseCompatibility['supported']) ? 'pass' : 'fail';
            $databaseCompatibilityMessage = (string)($databaseCompatibility['message'] ?? 'Database compatibility could not be determined.');
            if (!empty($databaseCompatibility['supported'])) {
                $databaseCompatibilityMessage .= ' For production, use a database release that is still receiving vendor security updates.';
            }
        } catch (Throwable $e) {
            $databaseCompatibilityStatus = 'warn';
            $databaseCompatibilityMessage = 'Database server version could not be determined: ' . $e->getMessage();
        }
    }
    $items[] = [
        'label' => 'Database server compatibility',
        'status' => $databaseCompatibilityStatus,
        'message' => $databaseCompatibilityMessage,
    ];

    $migrationStatus = 'warn';
    $migrationMessage = 'Database migration state could not be verified.';
    if ($dbStatus === 'pass' && function_exists('bms_pending_migration_names')) {
        try {
            $recoveryState = function_exists('bms_upgrade_recovery_state') ? bms_upgrade_recovery_state() : [];
            if ($recoveryState !== []) {
                $migrationStatus = 'fail';
                $migrationMessage = 'Database migration recovery is required for v' . (string)($recoveryState['to_version'] ?? 'unknown') . '. Complete the matching upgrade or owner-run migration workflow before normal operation.';
            } else {
                $pendingMigrations = bms_pending_migration_names(bms_db());
                if ($pendingMigrations === []) {
                    $migrationStatus = 'pass';
                    $migrationMessage = 'No database migrations are pending.';
                } else {
                    $migrationStatus = 'fail';
                    $migrationMessage = 'Pending database migrations: ' . implode(', ', $pendingMigrations) . '. Locked-down/manual deployments should use scripts/run-migrations.php after taking a database backup.';
                }
            }
        } catch (Throwable $e) {
            $migrationStatus = 'warn';
            $migrationMessage = 'Database migration state could not be verified: ' . $e->getMessage();
        }
    }
    $items[] = [
        'label' => 'Database migration state',
        'status' => $migrationStatus,
        'message' => $migrationMessage,
    ];

    $items[] = [
        'label' => 'Config file',
        'status' => is_file(bms_config_path()) ? 'pass' : 'warn',
        'message' => is_file(bms_config_path()) ? '_bonumark_stream/config.php exists.' : 'Config file has not been created yet.',
    ];
    $probe = bms_probe_private_folder_exposure();
    $items[] = [
        'label' => 'Private folder exposure',
        'status' => $probe['status'] === 'protected' ? 'pass' : ($probe['status'] === 'exposed' ? 'fail' : 'warn'),
        'message' => $probe['message'],
    ];

    foreach (bms_runtime_directory_status() as $directory) {
        $writable = !empty($directory['writable']);
        $relativePath = (string)($directory['relative_path'] ?? 'runtime storage');
        $purpose = trim((string)($directory['purpose'] ?? ''));
        $items[] = [
            'label' => (string)($directory['label'] ?? 'Runtime storage writable'),
            'status' => $writable ? 'pass' : 'fail',
            'message' => $writable
                ? $relativePath . ' is writable.' . ($purpose !== '' ? ' ' . $purpose : '')
                : $relativePath . ' is not writable by the PHP process.' . ($purpose !== '' ? ' ' . $purpose : ''),
        ];
    }

    $automaticUpgrade = bms_automatic_upgrade_capability();
    if (($automaticUpgrade['status'] ?? '') === 'available') {
        $upgradeMessage = 'Package-managed application files are writable by the web/PHP process. Admin ZIP software upgrades are available.';
        $upgradeStatus = 'pass';
    } elseif (($automaticUpgrade['status'] ?? '') === 'unknown') {
        $upgradeMessage = 'Web-based software upgrade capability could not be determined because the installed release manifest is unavailable or invalid.';
        $upgradeStatus = 'warn';
    } else {
        $blocked = $automaticUpgrade['blocked'] ?? [];
        $firstPath = (string)($blocked[0]['relative_path'] ?? 'package-managed application files');
        $extra = max(0, count($blocked) - 1);
        $upgradeMessage = 'Core operation is supported, but web-based software upgrades are unavailable because PHP cannot safely replace package-managed application files. First blocked path: ' . $firstPath . ($extra > 0 ? ' (+' . $extra . ' more).' : '.') . ' Keep the application tree locked and run php scripts/deploy-update.php as the application owner when shell access is available; otherwise use the documented manual/hosting-layer deployment workflow.';
        $upgradeStatus = 'warn';
    }
    $items[] = [
        'label' => 'Web-based software upgrades',
        'status' => $upgradeStatus,
        'message' => $upgradeMessage,
    ];

    $items[] = [
        'label' => 'ZipArchive',
        'status' => class_exists('ZipArchive') ? 'pass' : 'warn',
        'message' => class_exists('ZipArchive')
            ? 'ZipArchive is available for ZIP-based management and export features.'
            : 'ZipArchive is unavailable. Core publishing still works, but Admin ZIP upgrades, theme ZIP installation, and ZIP export features are unavailable.',
    ];
    $items[] = [
        'label' => 'cURL features',
        'status' => function_exists('curl_init') ? 'pass' : 'warn',
        'message' => function_exists('curl_init')
            ? 'PHP cURL is available for safe link previews, remote media import, and the preferred read-only HTTP diagnostic transport.'
            : 'PHP cURL is unavailable. Core publishing still works, but safe link previews and remote media import are unavailable; HTTP diagnostics fall back to allow_url_fopen when enabled.',
    ];

    $themeInstall = bms_theme_zip_install_capability();
    $items[] = [
        'label' => 'Theme ZIP installation',
        'status' => !empty($themeInstall['available']) ? 'pass' : 'warn',
        'message' => (string)($themeInstall['message'] ?? 'Theme ZIP installation capability could not be determined.'),
    ];

    $uploadCapability = bms_upload_limit_capability();
    $items[] = [
        'label' => 'Media upload ceiling',
        'status' => (string)($uploadCapability['status'] ?? 'warning'),
        'message' => (string)($uploadCapability['message'] ?? 'Media upload limits could not be determined.'),
    ];
    $items[] = [
        'label' => 'Image validation',
        'status' => function_exists('getimagesize') ? 'pass' : 'fail',
        'message' => function_exists('getimagesize') ? 'getimagesize is available for validating uploaded images.' : 'getimagesize is missing. Media uploads cannot be safely validated.',
    ];
    $imageProcessingAvailable = class_exists('Imagick') || function_exists('imagecreatetruecolor');
    $items[] = [
        'label' => 'Image processing',
        'status' => $imageProcessingAvailable ? 'pass' : 'warn',
        'message' => $imageProcessingAvailable
            ? (class_exists('Imagick') ? 'Imagick is available for image processing and generated variants.' : 'GD is available for image processing and generated variants.')
            : 'GD and Imagick are unavailable. Core publishing still works, but image metadata cleaning, generated variants, and generated PWA icons are limited.',
    ];
    $items[] = [
        'label' => 'File info',
        'status' => function_exists('finfo_open') ? 'pass' : 'warn',
        'message' => function_exists('finfo_open') ? 'Fileinfo is available for MIME checking uploads.' : 'Fileinfo is missing. Bonumark Stream will rely on image validation fallback checks.',
    ];
    $publicUrlProbe = bms_probe_public_url_mode();
    $items[] = [
        'label' => 'Public URL mode',
        'status' => (string)($publicUrlProbe['status'] ?? 'warn'),
        'message' => (string)($publicUrlProbe['message'] ?? 'Public URL routing could not be verified.'),
    ];
    if (function_exists('bms_activitypub_system_check_items')) {
        foreach (bms_activitypub_system_check_items() as $activityPubItem) {
            if (is_array($activityPubItem)) {
                $items[] = $activityPubItem;
            }
        }
    }
    return $items;
}

/**
 * Classify a read-only clean-route probe response.
 */
function bms_public_url_probe_response(int $httpStatus, string $body): array
{
    if ($httpStatus === 200) {
        $payload = json_decode($body, true);
        if (is_array($payload)
            && !empty($payload['ok'])
            && (string)($payload['api'] ?? '') === 'bonumark-stream') {
            return [
                'status' => 'pass',
                'message' => 'Bonumark clean URL routing reached the public API status endpoint successfully.',
            ];
        }
        return [
            'status' => 'fail',
            'message' => 'The clean-route probe returned HTTP 200 but did not return the Bonumark API status marker. Verify web-server routing rules.',
        ];
    }

    if ($httpStatus >= 300 && $httpStatus < 400) {
        return [
            'status' => 'warn',
            'message' => 'The clean-route probe was redirected (HTTP ' . $httpStatus . '). Verify the configured base URL and web-server routing rules.',
        ];
    }

    if ($httpStatus > 0) {
        return [
            'status' => 'fail',
            'message' => 'The Bonumark clean-route probe returned HTTP ' . $httpStatus . '. Verify Apache/LiteSpeed rewrite processing or the shipped Nginx route configuration.',
        ];
    }

    return [
        'status' => 'warn',
        'message' => 'Bonumark could not obtain an HTTP response while verifying clean URL routing.',
    ];
}

/**
 * Verify that a known Bonumark clean URL is reaching the application.
 */
function bms_probe_public_url_mode(?string $baseUrl = null): array
{
    $baseUrl = $baseUrl !== null && trim($baseUrl) !== ''
        ? rtrim(trim($baseUrl), '/')
        : rtrim((string)bms_setting_or_config('base_url', ''), '/');
    if ($baseUrl === '') {
        return [
            'status' => 'warn',
            'message' => 'Could not determine the configured site URL for the clean-route probe.',
        ];
    }

    $url = $baseUrl . bms_url_path('api/v1/status');
    $response = bms_readonly_http_probe($url);
    if (empty($response['ok'])) {
        $error = trim((string)($response['error'] ?? ''));
        return [
            'status' => 'warn',
            'message' => 'Could not complete the read-only clean-route probe.' . ($error !== '' ? ' ' . $error : ''),
        ];
    }

    return bms_public_url_probe_response(
        (int)($response['status'] ?? 0),
        (string)($response['body'] ?? '')
    );
}

function bms_redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

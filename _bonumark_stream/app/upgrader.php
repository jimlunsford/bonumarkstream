<?php
/**
 * Shared Bonumark Stream software-upgrade engine.
 *
 * Both Admin > Upgrade and the owner-run CLI deployment helper use this file.
 * The engine never elevates operating-system privileges. All filesystem changes
 * run with the permissions of the PHP process that invoked it.
 */

declare(strict_types=1);

if (!function_exists('bms_db')) {
    require_once __DIR__ . '/database.php';
}

function bms_upgrade_zip_entry_is_symlink(ZipArchive $zip, int $index): bool
{
    if (!method_exists($zip, 'getExternalAttributesIndex')) {
        return false;
    }

    $opsys = 0;
    $attr = 0;
    if (!$zip->getExternalAttributesIndex($index, $opsys, $attr)) {
        return false;
    }

    if (defined('ZipArchive::OPSYS_UNIX') && $opsys === ZipArchive::OPSYS_UNIX) {
        $mode = ($attr >> 16) & 0170000;
        return $mode === 0120000;
    }

    return false;
}

function bms_upgrade_safe_extract(ZipArchive $zip, string $destination): void
{
    $maxFiles = 700;
    $maxTotalBytes = 50 * 1024 * 1024;
    $maxSingleBytes = 10 * 1024 * 1024;
    $totalBytes = 0;

    if ($zip->numFiles < 1 || $zip->numFiles > $maxFiles) {
        throw new RuntimeException('Upgrade package has an unsafe number of files.');
    }

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string)$zip->getNameIndex($i);
        $normalized = str_replace('\\', '/', $name);
        $stat = $zip->statIndex($i) ?: [];
        $size = (int)($stat['size'] ?? 0);
        $depth = substr_count(trim($normalized, '/'), '/');

        if ($normalized === '' || str_contains($normalized, "\0") || str_starts_with($normalized, '/') || preg_match('#(^|/)\.\.(/|$)#', $normalized) || preg_match('#^[A-Za-z]:#', $normalized)) {
            throw new RuntimeException('Unsafe ZIP package path detected.');
        }
        if (strlen($normalized) > 240 || $depth > 12) {
            throw new RuntimeException('Upgrade package contains paths that are too deep or too long.');
        }
        if (bms_upgrade_zip_entry_is_symlink($zip, $i)) {
            throw new RuntimeException('Upgrade package contains a symbolic link, which is not allowed.');
        }
        if ($size > $maxSingleBytes) {
            throw new RuntimeException('Upgrade package contains a file larger than the allowed limit.');
        }
        $totalBytes += $size;
        if ($totalBytes > $maxTotalBytes) {
            throw new RuntimeException('Upgrade package expands beyond the allowed size limit.');
        }
    }

    if (!$zip->extractTo($destination)) {
        throw new RuntimeException('Could not extract the upgrade package.');
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($destination, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isLink()) {
            throw new RuntimeException('Upgrade package extracted a symbolic link, which is not allowed.');
        }
    }
}

function bms_upgrade_find_package_root(string $directory): string
{
    $directory = rtrim($directory, '/\\');
    if (is_file($directory . '/_bonumark_stream/VERSION') && is_file($directory . '/admin/index.php')) {
        return $directory;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        if (!$item->isDir()) {
            continue;
        }
        $path = $item->getPathname();
        if (is_file($path . '/_bonumark_stream/VERSION') && is_file($path . '/admin/index.php')) {
            return $path;
        }
    }

    throw new RuntimeException('This does not look like a Bonumark Stream release package.');
}

function bms_upgrade_normalize_package_name(string $name): string
{
    $name = strtolower(trim($name));
    $name = preg_replace('/[^a-z0-9]+/', '-', $name) ?: '';
    return trim($name, '-');
}

function bms_upgrade_allowed_package_names(): array
{
    return ['bonumark-stream'];
}

function bms_upgrade_package_version(string $packageRoot): string
{
    $manifest = $packageRoot . '/_bonumark_stream/PACKAGE.json';
    if (is_file($manifest)) {
        $data = json_decode((string)file_get_contents($manifest), true);
        if (is_array($data) && in_array(bms_upgrade_normalize_package_name((string)($data['name'] ?? '')), bms_upgrade_allowed_package_names(), true) && !empty($data['version'])) {
            return trim((string)$data['version']);
        }
    }

    $versionFile = $packageRoot . '/_bonumark_stream/VERSION';
    if (is_file($versionFile)) {
        $version = trim((string)file_get_contents($versionFile));
        if ($version !== '') {
            return $version;
        }
    }

    throw new RuntimeException('The release package does not include a version marker.');
}


function bms_upgrade_package_file_paths(string $packageRoot): array
{
    $paths = [];
    $root = rtrim($packageRoot, '/\\');
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($iterator as $item) {
        if (!$item->isFile()) {
            continue;
        }
        $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($root) + 1));
        if ($relative === '_bonumark_stream/RELEASE-MANIFEST.json') {
            continue;
        }
        $paths[$relative] = true;
    }

    ksort($paths);
    return $paths;
}

function bms_upgrade_verify_manifest(string $packageRoot): void
{
    $manifestPath = $packageRoot . '/_bonumark_stream/RELEASE-MANIFEST.json';
    if (!is_file($manifestPath)) {
        throw new RuntimeException('Release package is missing _bonumark_stream/RELEASE-MANIFEST.json. Upgrade refused.');
    }

    $manifest = json_decode((string)file_get_contents($manifestPath), true);
    if (!is_array($manifest) || !in_array(bms_upgrade_normalize_package_name((string)($manifest['name'] ?? '')), bms_upgrade_allowed_package_names(), true) || empty($manifest['files']) || !is_array($manifest['files'])) {
        throw new RuntimeException('Release manifest is invalid. Upgrade refused.');
    }

    $manifestFiles = [];
    foreach ($manifest['files'] as $entry) {
        if (!is_array($entry)) {
            throw new RuntimeException('Release manifest contains an invalid file entry.');
        }
        $relative = str_replace('\\', '/', (string)($entry['path'] ?? ''));
        $hash = strtolower(trim((string)($entry['sha256'] ?? '')));
        if ($relative === '' || $relative === '_bonumark_stream/RELEASE-MANIFEST.json' || str_starts_with($relative, '/') || preg_match('#(^|/)\.\.(/|$)#', $relative) || !preg_match('/^[a-f0-9]{64}$/', $hash)) {
            throw new RuntimeException('Release manifest contains an unsafe file entry.');
        }
        if (isset($manifestFiles[$relative])) {
            throw new RuntimeException('Release manifest contains a duplicate file entry: ' . $relative);
        }

        $path = $packageRoot . '/' . $relative;
        if (!is_file($path)) {
            throw new RuntimeException('Release manifest references a missing file: ' . $relative);
        }
        if (!hash_equals($hash, hash_file('sha256', $path))) {
            throw new RuntimeException('Release manifest hash mismatch: ' . $relative);
        }
        $manifestFiles[$relative] = true;
    }

    $packageFiles = bms_upgrade_package_file_paths($packageRoot);
    foreach ($packageFiles as $relative => $_) {
        if (!isset($manifestFiles[$relative])) {
            throw new RuntimeException('Release package contains an unlisted file. Upgrade refused: ' . $relative);
        }
    }
}


function bms_upgrade_manifest_file_set(string $packageRoot): array
{
    $manifestPath = $packageRoot . '/_bonumark_stream/RELEASE-MANIFEST.json';
    $manifest = json_decode((string)file_get_contents($manifestPath), true);
    $files = [];
    if (!is_array($manifest) || !is_array($manifest['files'] ?? null)) {
        return $files;
    }

    foreach ($manifest['files'] as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $relative = str_replace('\\', '/', (string)($entry['path'] ?? ''));
        if ($relative !== '') {
            $files[$relative] = true;
        }
    }

    $files['_bonumark_stream/RELEASE-MANIFEST.json'] = true;
    return $files;
}


function bms_upgrade_retired_bundled_theme_slugs(): array
{
    return [
        'microblog-stream' => true,
        'profile-editorial' => true,
        'profile-split' => true,
    ];
}

function bms_upgrade_package_theme_slugs(array $manifestFiles, string $prefix): array
{
    $slugs = [];
    foreach (array_keys($manifestFiles) as $relative) {
        if (preg_match('#^' . preg_quote($prefix, '#') . '/([^/]+)/#', $relative, $matches)) {
            $slug = (string)$matches[1];
            if ($slug !== '') {
                $slugs[$slug] = true;
            }
        }
    }
    return $slugs;
}

function bms_upgrade_installed_theme_manifest(string $publicRoot, string $slug): array
{
    $slug = trim($slug);
    if ($slug === '' || str_contains($slug, '/') || str_contains($slug, '\\')) {
        return [];
    }

    $path = rtrim(str_replace('\\', '/', $publicRoot), '/') . '/_bonumark_stream/themes/' . $slug . '/theme.json';
    if (!is_file($path)) {
        return [];
    }

    $data = json_decode((string)file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function bms_upgrade_theme_marked_as_bundled(array $manifest): bool
{
    $package = strtolower(trim((string)($manifest['package'] ?? '')));
    if (in_array($package, ['bundled-theme', 'bundled-declarative-proof-theme', 'bundled', 'core'], true)) {
        return true;
    }

    return !empty($manifest['bundled']) || !empty($manifest['core_theme']);
}

function bms_upgrade_retired_bundled_theme_leftover(string $publicRoot, string $slug): bool
{
    static $cache = [];

    $publicRoot = rtrim(str_replace('\\', '/', $publicRoot), '/');
    $cacheKey = $publicRoot . '|' . $slug;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    $retiredThemeSlugs = bms_upgrade_retired_bundled_theme_slugs();
    if (!isset($retiredThemeSlugs[$slug])) {
        $cache[$cacheKey] = false;
        return false;
    }

    $manifest = bms_upgrade_installed_theme_manifest($publicRoot, $slug);
    if (!$manifest) {
        $cache[$cacheKey] = false;
        return false;
    }

    $cache[$cacheKey] = bms_upgrade_theme_marked_as_bundled($manifest);
    return $cache[$cacheKey];
}

function bms_upgrade_theme_path_preserved(string $publicRoot, string $slug, array $packageThemeSlugs): bool
{
    if ($slug === '') {
        return false;
    }

    if (isset($packageThemeSlugs[$slug])) {
        return false;
    }

    if (bms_upgrade_retired_bundled_theme_leftover($publicRoot, $slug)) {
        return false;
    }

    return true;
}

function bms_upgrade_cleanup_preserved_path(string $publicRoot, string $relative, array $privateThemeSlugs, array $publicThemeSlugs): bool
{
    $relative = str_replace('\\', '/', ltrim($relative, '/'));

    $preserveExact = [
        '_bonumark_stream/config.php' => true,
        '_bonumark_stream/installed.lock' => true,
        '_bonumark_stream/RELEASE-MANIFEST.json' => true,
    ];
    if (isset($preserveExact[$relative])) {
        return true;
    }

    foreach (['_bonumark_stream/data/', '_bonumark_stream/backups/', '_bonumark_stream/tmp/', 'media/', 'uploads/'] as $prefix) {
        if (str_starts_with($relative, $prefix)) {
            return true;
        }
    }

    if (preg_match('#^_bonumark_stream/themes/([^/]+)/#', $relative, $matches)) {
        return bms_upgrade_theme_path_preserved($publicRoot, (string)$matches[1], $privateThemeSlugs);
    }

    if (preg_match('#^assets/themes/([^/]+)/#', $relative, $matches)) {
        return bms_upgrade_theme_path_preserved($publicRoot, (string)$matches[1], $publicThemeSlugs);
    }

    return false;
}

function bms_upgrade_cleanup_managed_path(string $relative): bool
{
    return bms_package_managed_software_path($relative);
}

function bms_upgrade_remove_empty_directories(string $root, array $privateThemeSlugs, array $publicThemeSlugs): void
{
    if (!is_dir($root)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        if (!$item->isDir() || $item->isLink()) {
            continue;
        }
        $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($root) + 1));
        if ($relative === '' || bms_upgrade_cleanup_preserved_path($root, $relative . '/', $privateThemeSlugs, $publicThemeSlugs)) {
            continue;
        }
        if (!bms_upgrade_cleanup_managed_path($relative . '/') && !bms_upgrade_cleanup_managed_path($relative)) {
            continue;
        }
        $contents = array_diff(scandir($item->getPathname()) ?: [], ['.', '..']);
        if (!$contents) {
            @rmdir($item->getPathname());
        }
    }
}

function bms_upgrade_cleanup_obsolete_files(string $publicRoot, array $manifestFiles, string $backupRoot = '', ?array &$removedTracker = null): array
{
    $privateThemeSlugs = bms_upgrade_package_theme_slugs($manifestFiles, '_bonumark_stream/themes');
    $publicThemeSlugs = bms_upgrade_package_theme_slugs($manifestFiles, 'assets/themes');
    $removed = [];

    // Warm retired bundled-theme detection before obsolete-file removal starts.
    // Once a bundled manifest is removed, public asset cleanup still needs to
    // know that the matching retired theme was package-owned. Custom themes
    // using the same slug remain preserved because they do not carry a bundled
    // package marker.
    foreach (array_keys(bms_upgrade_retired_bundled_theme_slugs()) as $retiredThemeSlug) {
        bms_upgrade_retired_bundled_theme_leftover($publicRoot, (string)$retiredThemeSlug);
    }

    if (!is_dir($publicRoot)) {
        return $removed;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($publicRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($iterator as $item) {
        if (!$item->isFile() || $item->isLink()) {
            continue;
        }
        $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($publicRoot) + 1));
        if (isset($manifestFiles[$relative]) || bms_upgrade_cleanup_preserved_path($publicRoot, $relative, $privateThemeSlugs, $publicThemeSlugs) || !bms_upgrade_cleanup_managed_path($relative)) {
            continue;
        }
        if ($backupRoot !== '') {
            bms_upgrade_copy_recursive($item->getPathname(), rtrim($backupRoot, '/\\') . '/_removed-software/' . $relative);
        }
        if (@unlink($item->getPathname())) {
            $removed[] = $relative;
            if ($removedTracker !== null) {
                $removedTracker[$relative] = true;
            }
        } else {
            throw new RuntimeException('Could not remove obsolete software file: ' . $relative);
        }
    }

    bms_upgrade_remove_empty_directories($publicRoot, $privateThemeSlugs, $publicThemeSlugs);
    sort($removed);
    return $removed;
}

function bms_upgrade_record_history(string $fromVersion, string $toVersion, array $ran, array $removed): void
{
    if (!function_exists('bms_db')) {
        return;
    }

    $migrationNotes = $ran ? implode(', ', $ran) : 'none';
    $cleanupNotes = $removed ? implode(', ', array_slice($removed, 0, 25)) . (count($removed) > 25 ? ' +' . (count($removed) - 25) . ' more' : '') : 'none';
    $stmt = bms_db()->prepare('INSERT INTO ' . bms_table('upgrade_history') . ' (from_version, to_version, status, notes, ran_at) VALUES (:from_version, :to_version, :status, :notes, NOW())');
    $stmt->execute([
        'from_version' => $fromVersion,
        'to_version' => $toVersion,
        'status' => 'complete',
        'notes' => 'Migrations: ' . $migrationNotes . '; obsolete software files removed: ' . $cleanupNotes,
    ]);
}

function bms_upgrade_recovery_state_current(): array
{
    return function_exists('bms_upgrade_recovery_state') ? bms_upgrade_recovery_state() : [];
}

function bms_upgrade_recovery_can_resume_package(string $packageVersion): bool
{
    return function_exists('bms_upgrade_recovery_matches_package')
        && bms_upgrade_recovery_matches_package($packageVersion);
}

function bms_upgrade_assert_recovery_allows_package(string $packageVersion): bool
{
    $recovery = bms_upgrade_recovery_state_current();
    if (!$recovery) {
        return false;
    }

    if ((string)($recovery['status'] ?? '') === 'migration_in_progress') {
        throw new RuntimeException('A previous upgrade entered the database migration phase and did not report completion. The installation is protected from an unsafe rollback. Review the private upgrade backup and server error log before starting another upgrade.');
    }

    if (bms_upgrade_recovery_can_resume_package($packageVersion)) {
        return true;
    }

    throw new RuntimeException('A previous upgrade requires recovery for v' . (string)($recovery['to_version'] ?? 'unknown') . '. Retry that exact release package before starting another upgrade.');
}

function bms_upgrade_record_recovery_required(string $fromVersion, string $toVersion, string $backupRoot): void
{
    if (!function_exists('bms_db')) {
        return;
    }

    try {
        $stmt = bms_db()->prepare('INSERT INTO ' . bms_table('upgrade_history') . ' (from_version, to_version, status, notes, ran_at) VALUES (:from_version, :to_version, :status, :notes, NOW())');
        $stmt->execute([
            'from_version' => $fromVersion,
            'to_version' => $toVersion,
            'status' => 'recovery_required',
            'notes' => 'Database migration phase began. New software files were retained because MySQL/MariaDB DDL may already be committed. Retry this exact release package to resume safely. Backup: ' . basename($backupRoot),
        ]);
    } catch (Throwable $e) {
        bms_log_admin_exception('upgrade-recovery-history', $e);
    }
}

function bms_upgrade_recovery_message(array $recovery): string
{
    $toVersion = trim((string)($recovery['to_version'] ?? ''));
    if ((string)($recovery['status'] ?? '') === 'migration_in_progress') {
        return 'An upgrade entered the database migration phase and did not report completion. Bonumark will not restore older software over a possibly migrated database. Review the private upgrade backup and server error log before starting another upgrade.';
    }

    return 'A previous upgrade stopped after the database migration phase began. Bonumark kept the newer software files so they remain compatible with the database. Upload and retry the same v' . ($toVersion !== '' ? $toVersion : 'release') . ' package to resume safely.';
}


function bms_upgrade_software_items(string $packageRoot): array
{
    $manifestFiles = bms_upgrade_manifest_file_set($packageRoot);
    $items = [];

    if ($manifestFiles) {
        $skipPrivate = ['config.php' => true, 'installed.lock' => true, 'data' => true, 'backups' => true, 'tmp' => true];
        $skipPublic = ['media' => true, 'uploads' => true];
        foreach (array_keys($manifestFiles) as $relative) {
            $relative = str_replace('\\', '/', ltrim((string)$relative, '/'));
            if ($relative === '' || !bms_package_managed_software_path($relative)) {
                continue;
            }
            if (str_starts_with($relative, '_bonumark_stream/')) {
                $parts = explode('/', $relative, 3);
                $privateItem = (string)($parts[1] ?? '');
                if ($privateItem === '' || isset($skipPrivate[$privateItem])) {
                    continue;
                }
                $items[] = '_bonumark_stream/' . $privateItem;
                continue;
            }
            $topLevel = explode('/', $relative, 2)[0];
            if ($topLevel !== '' && !isset($skipPublic[$topLevel])) {
                $items[] = $topLevel;
            }
        }
    }

    if (!$items) {
        $items = [
            'admin',
            'api',
            'assets',
            '.htaccess',
            '.gitignore',
            'LICENSE',
            'README.md',
            'CONTRIBUTING.md',
            'SECURITY.md',
            'VERSION',
            'docs',
            'scripts',
            'install.php',
            'index.php',
            'page.php',
            'account.php',
            'profile.php',
            'profile-export.php',
            'comments.php',
            'search.php',
            'stream-like.php',
        ];

        $privateRoot = $packageRoot . '/_bonumark_stream';
        $skipPrivate = ['config.php' => true, 'installed.lock' => true, 'data' => true, 'backups' => true, 'tmp' => true];
        foreach (array_diff(scandir($privateRoot) ?: [], ['.', '..']) as $item) {
            if (isset($skipPrivate[$item])) {
                continue;
            }
            $items[] = '_bonumark_stream/' . $item;
        }
    }

    $items = array_values(array_unique($items));
    sort($items);
    return $items;
}

function bms_upgrade_runtime_cache_available(): bool
{
    if (!function_exists('opcache_invalidate')) {
        return false;
    }

    $enabled = ini_get('opcache.enable');
    return $enabled !== false && !in_array(strtolower(trim((string)$enabled)), ['', '0', 'off', 'false', 'no'], true);
}

function bms_upgrade_invalidate_php_runtime_file(string $path): bool
{
    if (!bms_upgrade_runtime_cache_available() || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'php') {
        return false;
    }

    clearstatcache(true, $path);
    try {
        return @opcache_invalidate($path, true);
    } catch (Throwable $e) {
        bms_log_admin_exception('upgrade-opcache-invalidate', $e);
        return false;
    }
}

function bms_upgrade_reset_php_runtime_cache(): bool
{
    if (!function_exists('opcache_reset')) {
        return false;
    }

    $enabled = ini_get('opcache.enable');
    if ($enabled === false || in_array(strtolower(trim((string)$enabled)), ['', '0', 'off', 'false', 'no'], true)) {
        return false;
    }

    try {
        return @opcache_reset();
    } catch (Throwable $e) {
        bms_log_admin_exception('upgrade-opcache-reset', $e);
        return false;
    }
}

function bms_upgrade_copy_recursive(string $source, string $destination, ?array &$copiedFiles = null, string $trackingRoot = ''): void
{
    if (is_file($source)) {
        $dir = dirname($destination);
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new RuntimeException('Could not create directory: ' . $dir);
        }
        if (!copy($source, $destination)) {
            throw new RuntimeException('Could not copy file: ' . $source);
        }
        clearstatcache(true, $destination);
        bms_upgrade_invalidate_php_runtime_file($destination);
        if ($copiedFiles !== null) {
            $tracked = str_replace('\\', '/', $destination);
            $root = str_replace('\\', '/', rtrim($trackingRoot, '/\\'));
            if ($root !== '' && str_starts_with($tracked, $root . '/')) {
                $tracked = substr($tracked, strlen($root) + 1);
            }
            $copiedFiles[$tracked] = true;
        }
        return;
    }

    if (!is_dir($source)) {
        return;
    }

    if (!is_dir($destination) && !mkdir($destination, 0755, true)) {
        throw new RuntimeException('Could not create directory: ' . $destination);
    }

    $items = array_diff(scandir($source) ?: [], ['.', '..']);
    foreach ($items as $item) {
        bms_upgrade_copy_recursive($source . '/' . $item, $destination . '/' . $item, $copiedFiles, $trackingRoot);
    }
}

function bms_upgrade_backup_existing(array $items, string $backupRoot, string $publicRoot): void
{
    // Public media and upload directories are owner data preserved in place.
    // The release package contains media/.gitkeep, but that must not cause a
    // full media library copy inside every private upgrade backup.
    $preservedRuntimeItems = ['media' => true, 'uploads' => true];

    foreach ($items as $item) {
        if (isset($preservedRuntimeItems[$item])) {
            continue;
        }
        $source = $publicRoot . '/' . $item;
        if (!file_exists($source)) {
            continue;
        }
        bms_upgrade_copy_recursive($source, $backupRoot . '/' . $item);
    }

    $config = $publicRoot . '/_bonumark_stream/config.php';
    if (is_file($config)) {
        bms_upgrade_copy_recursive($config, $backupRoot . '/_bonumark_stream/config.php');
    }
}

function bms_upgrade_existing_manifest_file_set(string $publicRoot, array $manifestFiles): array
{
    $existing = [];
    foreach (array_keys($manifestFiles) as $relative) {
        $relative = str_replace('\\', '/', ltrim((string)$relative, '/'));
        if ($relative === '') {
            continue;
        }
        $existing[$relative] = is_file($publicRoot . '/' . $relative);
    }
    return $existing;
}

function bms_upgrade_remove_new_package_files(string $publicRoot, array $manifestFiles, array $existingBefore): array
{
    $privateThemeSlugs = bms_upgrade_package_theme_slugs($manifestFiles, '_bonumark_stream/themes');
    $publicThemeSlugs = bms_upgrade_package_theme_slugs($manifestFiles, 'assets/themes');
    $removed = [];

    $paths = array_keys($manifestFiles);
    usort($paths, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));

    foreach ($paths as $relative) {
        $relative = str_replace('\\', '/', ltrim((string)$relative, '/'));
        if ($relative === '' || !empty($existingBefore[$relative])) {
            continue;
        }
        if (bms_upgrade_cleanup_preserved_path($publicRoot, $relative, $privateThemeSlugs, $publicThemeSlugs) || !bms_upgrade_cleanup_managed_path($relative)) {
            continue;
        }

        $path = $publicRoot . '/' . $relative;
        if (!is_file($path) || is_link($path)) {
            continue;
        }
        if (!@unlink($path)) {
            throw new RuntimeException('Could not remove newly copied upgrade file during rollback: ' . $relative);
        }
        $removed[] = $relative;
    }

    bms_upgrade_remove_empty_directories($publicRoot, $privateThemeSlugs, $publicThemeSlugs);
    sort($removed);
    return $removed;
}

function bms_upgrade_restore_changed_software(array $changedFiles, array $removedObsolete, string $backupRoot, string $publicRoot, array $existingBefore, array $manifestFiles): array
{
    $restored = [];
    $removedNew = [];
    $restoredObsolete = [];

    $changedPaths = array_keys($changedFiles);
    usort($changedPaths, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));

    foreach ($changedPaths as $relative) {
        $relative = str_replace('\\', '/', ltrim($relative, '/'));
        if ($relative === '') {
            continue;
        }
        $destination = rtrim($publicRoot, '/\\') . '/' . $relative;
        if (!empty($existingBefore[$relative])) {
            $backup = rtrim($backupRoot, '/\\') . '/' . $relative;
            if (!is_file($backup)) {
                throw new RuntimeException('Rollback backup is missing for changed software file: ' . $relative);
            }
            bms_upgrade_copy_recursive($backup, $destination);
            $restored[] = $relative;
            continue;
        }
        if (is_file($destination) && !@unlink($destination)) {
            throw new RuntimeException('Could not remove newly copied upgrade file during rollback: ' . $relative);
        }
        if (!file_exists($destination)) {
            $removedNew[] = $relative;
        }
    }

    foreach (array_keys($removedObsolete) as $relative) {
        $relative = str_replace('\\', '/', ltrim($relative, '/'));
        $backup = rtrim($backupRoot, '/\\') . '/_removed-software/' . $relative;
        if (!is_file($backup)) {
            throw new RuntimeException('Rollback backup is missing for removed obsolete software file: ' . $relative);
        }
        bms_upgrade_copy_recursive($backup, rtrim($publicRoot, '/\\') . '/' . $relative);
        $restoredObsolete[] = $relative;
    }

    $privateThemeSlugs = bms_upgrade_package_theme_slugs($manifestFiles, '_bonumark_stream/themes');
    $publicThemeSlugs = bms_upgrade_package_theme_slugs($manifestFiles, 'assets/themes');
    bms_upgrade_remove_empty_directories($publicRoot, $privateThemeSlugs, $publicThemeSlugs);

    sort($restored);
    sort($removedNew);
    sort($restoredObsolete);
    return [
        'restored' => $restored,
        'removed_new' => $removedNew,
        'restored_obsolete' => $restoredObsolete,
    ];
}

function bms_upgrade_remove_temp(string $path): void
{
    if (is_dir($path)) {
        bms_delete_directory($path);
    }
}


function bms_upgrade_pending_migrations_from_package(string $packageRoot): array
{
    $packageFiles = glob($packageRoot . '/_bonumark_stream/migrations/*.php') ?: [];
    $packageMigrations = array_map(fn($file) => basename($file, '.php'), $packageFiles);
    sort($packageMigrations);

    $done = [];
    try {
        if (function_exists('bms_has_database_config') && bms_has_database_config()) {
            $table = bms_table_prefix() . 'migrations';
            $stmt = bms_db()->query('SELECT migration FROM `' . $table . '`');
            foreach ($stmt->fetchAll() as $row) {
                $done[(string)$row['migration']] = true;
            }
        }
    } catch (Throwable $e) {
        bms_log_admin_exception('upgrade-pending-migrations', $e);
        return ['Could not check pending migrations safely.'];
    }

    return array_values(array_filter($packageMigrations, fn($migration) => !isset($done[$migration])));
}


function bms_upgrade_assert_supported_current_version(string $currentVersion): void
{
    if ($currentVersion !== 'unknown' && version_compare($currentVersion, '0.4.0', '<')) {
        throw new RuntimeException('Bonumark Stream supports software upgrades from v0.4.0 and newer only.');
    }
}

/**
 * Inspect and validate a release ZIP without changing the installed software.
 *
 * This is used by the owner-run CLI workflow. The package is extracted into a
 * temporary directory outside the live application tree, validated against its
 * release manifest, then removed before returning the plan.
 *
 * @return array<string,mixed>
 */
function bms_upgrade_inspect_package(string $zipPath): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('The PHP ZipArchive extension is required for ZIP-based upgrades.');
    }
    if (!is_file($zipPath) || !is_readable($zipPath)) {
        throw new RuntimeException('Upgrade ZIP is missing or not readable: ' . $zipPath);
    }

    $currentVersion = bms_version();
    bms_upgrade_assert_supported_current_version($currentVersion);
    $token = bin2hex(random_bytes(12));
    $base = rtrim((string)sys_get_temp_dir(), '/\\');
    if ($base === '' || !is_dir($base) || !is_writable($base)) {
        throw new RuntimeException('The system temporary directory is not writable by the current CLI user.');
    }
    $extractRoot = $base . '/bonumark-upgrade-precheck-' . $token;
    if (!mkdir($extractRoot, 0700, true)) {
        throw new RuntimeException('Could not create the temporary upgrade precheck directory.');
    }

    $zip = new ZipArchive();
    $opened = $zip->open($zipPath);
    if ($opened !== true) {
        bms_upgrade_remove_temp($extractRoot);
        throw new RuntimeException('Could not open the upgrade ZIP package.');
    }

    try {
        bms_upgrade_safe_extract($zip, $extractRoot);
    } finally {
        $zip->close();
    }

    try {
        $packageRoot = bms_upgrade_find_package_root($extractRoot);
        $packageVersion = bms_upgrade_package_version($packageRoot);
        bms_upgrade_verify_manifest($packageRoot);
        $manifestFiles = bms_upgrade_manifest_file_set($packageRoot);
        $recoveryResume = bms_upgrade_assert_recovery_allows_package($packageVersion);
        if ($currentVersion !== 'unknown' && version_compare($packageVersion, $currentVersion, '<=') && !$recoveryResume) {
            throw new RuntimeException('This package is not newer than the installed version. Installed: ' . $currentVersion . '. Package: ' . $packageVersion . '.');
        }

        $packageMeta = is_file($packageRoot . '/_bonumark_stream/PACKAGE.json')
            ? json_decode((string)file_get_contents($packageRoot . '/_bonumark_stream/PACKAGE.json'), true)
            : [];
        $releaseNotes = is_array($packageMeta)
            ? trim((string)($packageMeta['release_name'] ?? $packageMeta['description'] ?? ''))
            : '';

        $candidateSoftwarePaths = array_values(array_unique(array_merge(
            array_keys($manifestFiles),
            bms_installed_release_manifest_paths()
        )));
        $deploymentCapability = bms_automatic_upgrade_capability($candidateSoftwarePaths);
        $pendingMigrations = bms_upgrade_pending_migrations_from_package($packageRoot);
        if ($pendingMigrations === ['Could not check pending migrations safely.']) {
            throw new RuntimeException('Could not check pending database migrations safely.');
        }

        $managedManifestFiles = array_filter(
            $manifestFiles,
            static fn(bool $_, string $relative): bool => bms_package_managed_software_path($relative),
            ARRAY_FILTER_USE_BOTH
        );
        $existingBefore = bms_upgrade_existing_manifest_file_set(bms_public_path(), $managedManifestFiles);
        $newFiles = 0;
        foreach ($existingBefore as $exists) {
            if (!$exists) {
                $newFiles++;
            }
        }

        $backupBase = bms_root_path('backups/upgrades');
        $backupReady = is_dir($backupBase)
            ? is_writable($backupBase)
            : (($parent = bms_nearest_existing_parent_directory($backupBase)) !== '' && is_writable($parent));

        return [
            'current_version' => $currentVersion,
            'package_version' => $packageVersion,
            'release_notes' => $releaseNotes,
            'manifest_file_count' => count($manifestFiles),
            'managed_file_count' => count($managedManifestFiles),
            'new_file_count' => $newFiles,
            'pending_migrations' => $pendingMigrations,
            'deployment_capability' => $deploymentCapability,
            'backup_ready' => $backupReady,
            'recovery_resume' => $recoveryResume,
            'zip_sha256' => hash_file('sha256', $zipPath) ?: '',
        ];
    } finally {
        bms_upgrade_remove_temp($extractRoot);
    }
}

function bms_upgrade_precheck_package(string $uploadedPath, string $uploadedName): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('The PHP ZipArchive extension is not available on this server. Ask the host to enable it before using admin ZIP upgrades.');
    }

    $currentVersion = bms_version();
    bms_upgrade_assert_supported_current_version($currentVersion);
    $token = bin2hex(random_bytes(16));
    $pendingDir = bms_root_path('tmp/upgrades/pending');
    $extractRoot = bms_root_path('tmp/upgrades/precheck-' . $token);
    if (!is_dir($pendingDir) && !mkdir($pendingDir, 0755, true)) {
        throw new RuntimeException('Could not create the pending upgrade folder.');
    }
    if (!is_dir($extractRoot) && !mkdir($extractRoot, 0755, true)) {
        throw new RuntimeException('Could not create the upgrade precheck folder.');
    }

    $pendingZip = $pendingDir . '/' . $token . '.zip';
    $copied = is_uploaded_file($uploadedPath) ? move_uploaded_file($uploadedPath, $pendingZip) : copy($uploadedPath, $pendingZip);
    if (!$copied) {
        bms_upgrade_remove_temp($extractRoot);
        throw new RuntimeException('Could not store the uploaded upgrade package for checking.');
    }

    $zip = new ZipArchive();
    $opened = $zip->open($pendingZip);
    if ($opened !== true) {
        @unlink($pendingZip);
        bms_upgrade_remove_temp($extractRoot);
        throw new RuntimeException('Could not open the uploaded ZIP package.');
    }

    try {
        bms_upgrade_safe_extract($zip, $extractRoot);
    } finally {
        $zip->close();
    }

    try {
        $packageRoot = bms_upgrade_find_package_root($extractRoot);
        $packageVersion = bms_upgrade_package_version($packageRoot);
        bms_upgrade_verify_manifest($packageRoot);
        $packageMeta = is_file($packageRoot . '/_bonumark_stream/PACKAGE.json') ? json_decode((string)file_get_contents($packageRoot . '/_bonumark_stream/PACKAGE.json'), true) : [];
        $releaseNotes = is_array($packageMeta) ? trim((string)($packageMeta['release_name'] ?? $packageMeta['description'] ?? '')) : '';

        $recoveryResume = bms_upgrade_assert_recovery_allows_package($packageVersion);
        if ($currentVersion !== 'unknown' && version_compare($packageVersion, $currentVersion, '<=') && !$recoveryResume) {
            throw new RuntimeException('This package is not newer than the installed version. Installed: ' . $currentVersion . '. Package: ' . $packageVersion . '.');
        }

        $backupBase = bms_root_path('backups/upgrades');
        if (!is_dir($backupBase)) {
            @mkdir($backupBase, 0755, true);
        }
        $backupReady = is_dir($backupBase) && is_writable($backupBase);
        $candidateSoftwarePaths = array_values(array_unique(array_merge(
            array_keys(bms_upgrade_manifest_file_set($packageRoot)),
            bms_installed_release_manifest_paths()
        )));
        $automaticUpgrade = bms_automatic_upgrade_capability($candidateSoftwarePaths);
        $pendingMigrations = bms_upgrade_pending_migrations_from_package($packageRoot);
        $publishedCount = count(bms_list_content_records('published'));

        $precheck = [
            'token' => $token,
            'zip_path' => $pendingZip,
            'uploaded_name' => basename($uploadedName),
            'current_version' => $currentVersion,
            'package_version' => $packageVersion,
            'backup_ready' => $backupReady,
            'automatic_upgrade' => $automaticUpgrade,
            'pending_migrations' => $pendingMigrations,
            'published_count' => $publishedCount,
            'release_notes' => $releaseNotes,
            'recovery_resume' => $recoveryResume,
            'checked_at' => gmdate('c'),
        ];
        $_SESSION['pending_upgrade'] = $precheck;
        return $precheck;
    } catch (Throwable $e) {
        @unlink($pendingZip);
        throw $e;
    } finally {
        bms_upgrade_remove_temp($extractRoot);
    }
}

function bms_upgrade_clear_pending(): void
{
    $pending = $_SESSION['pending_upgrade'] ?? null;
    if (is_array($pending) && !empty($pending['zip_path']) && is_file((string)$pending['zip_path'])) {
        @unlink((string)$pending['zip_path']);
    }
    unset($_SESSION['pending_upgrade']);
}

function bms_upgrade_install(string $zipPath): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('The PHP ZipArchive extension is not available on this server. Ask the host to enable it before using admin ZIP upgrades.');
    }

    $currentVersion = bms_version();
    bms_upgrade_assert_supported_current_version($currentVersion);
    $publicRoot = bms_public_path();
    $timestamp = date('Ymd-His');
    $tmpRoot = bms_root_path('tmp/upgrades/' . $timestamp . '-' . bin2hex(random_bytes(4)));
    $backupRoot = bms_root_path('backups/upgrades/' . $timestamp);

    if (!is_dir($tmpRoot) && !mkdir($tmpRoot, 0755, true)) {
        throw new RuntimeException('Could not create upgrade temp directory.');
    }

    $zip = new ZipArchive();
    $opened = $zip->open($zipPath);
    if ($opened !== true) {
        bms_upgrade_remove_temp($tmpRoot);
        throw new RuntimeException('Could not open the uploaded ZIP package.');
    }

    try {
        bms_upgrade_safe_extract($zip, $tmpRoot);
    } finally {
        $zip->close();
    }

    $packageRoot = bms_upgrade_find_package_root($tmpRoot);
    $packageVersion = bms_upgrade_package_version($packageRoot);
    bms_upgrade_verify_manifest($packageRoot);
    $manifestFiles = bms_upgrade_manifest_file_set($packageRoot);
    $recovery = bms_upgrade_recovery_state_current();
    $recoveryResume = bms_upgrade_assert_recovery_allows_package($packageVersion);
    $historyFromVersion = $recoveryResume && trim((string)($recovery['from_version'] ?? '')) !== ''
        ? trim((string)$recovery['from_version'])
        : $currentVersion;

    if ($currentVersion !== 'unknown' && version_compare($packageVersion, $currentVersion, '<=') && !$recoveryResume) {
        bms_upgrade_remove_temp($tmpRoot);
        throw new RuntimeException('This package is not newer than the installed version. Installed: ' . $currentVersion . '. Package: ' . $packageVersion . '.');
    }

    $softwareItems = bms_upgrade_software_items($packageRoot);
    $existingManifestFiles = bms_upgrade_existing_manifest_file_set($publicRoot, $manifestFiles);
    $candidateSoftwarePaths = array_values(array_unique(array_merge(
        array_keys($manifestFiles),
        bms_installed_release_manifest_paths()
    )));
    $automaticUpgrade = bms_automatic_upgrade_capability($candidateSoftwarePaths);
    if (empty($automaticUpgrade['available'])) {
        $blocked = $automaticUpgrade['blocked'] ?? [];
        $firstPath = (string)($blocked[0]['relative_path'] ?? 'package-managed application files');
        bms_upgrade_remove_temp($tmpRoot);
        throw new RuntimeException('This PHP process cannot safely replace package-managed application files. First blocked path: ' . $firstPath . '. If this is the web/PHP-FPM process, keep the application tree locked and use php scripts/deploy-update.php as the application owner.');
    }

    if (!is_dir($backupRoot) && !mkdir($backupRoot, 0755, true)) {
        bms_upgrade_remove_temp($tmpRoot);
        throw new RuntimeException('Could not create upgrade backup directory.');
    }

    bms_upgrade_backup_existing($softwareItems, $backupRoot, $publicRoot);

    $ran = [];
    $removed = [];
    $changedPackageFiles = [];
    $removedDuringInstall = [];
    $migrationPhaseStarted = false;
    $migrationStartedAt = '';

    try {
        foreach ($softwareItems as $item) {
            $source = $packageRoot . '/' . $item;
            if (!file_exists($source)) {
                continue;
            }
            bms_upgrade_copy_recursive($source, $publicRoot . '/' . $item, $changedPackageFiles, $publicRoot);
        }

        $removed = bms_upgrade_cleanup_obsolete_files($publicRoot, $manifestFiles, $backupRoot, $removedDuringInstall);

        // A host may keep old compiled PHP in OPcache after files on disk are
        // replaced. Invalidate individual copied PHP files during copy, then
        // reset the shared opcode cache once software replacement is complete.
        // The current request continues safely; subsequent requests compile
        // from the upgraded files now present on disk.
        $runtimeCacheReset = bms_upgrade_reset_php_runtime_cache();

        $log = "Bonumark Stream upgrade\n" .
            "From: {$historyFromVersion}\n" .
            "To: {$packageVersion}\n" .
            "Date: " . gmdate('c') . "\n" .
            "Preserved: _bonumark_stream/config.php, _bonumark_stream/installed.lock, _bonumark_stream/data/, _bonumark_stream/backups/, _bonumark_stream/tmp/, media/, uploads/, and future code-free theme assets. Upgrade support starts at v0.4.0.\n" .
            "Obsolete package-managed files removed: " . count($removed) . "\n" .
            "PHP runtime cache reset requested: " . ($runtimeCacheReset ? "yes" : "no or unavailable") . "\n";
        bms_write_file($backupRoot . '/UPGRADE.txt', $log);

        if (function_exists('bms_run_migrations')) {
            $migrationStartedAt = gmdate('c');
            bms_write_upgrade_recovery_state([
                'status' => 'migration_in_progress',
                'phase' => 'database_migration',
                'from_version' => $historyFromVersion,
                'to_version' => $packageVersion,
                'backup_path' => $backupRoot,
                'started_at' => $migrationStartedAt,
            ]);
            $migrationPhaseStarted = true;
            $ran = bms_run_migrations($historyFromVersion);
        }

        bms_upgrade_record_history($historyFromVersion, $packageVersion, $ran, $removed);
        if (function_exists('bms_clear_upgrade_recovery_state')) {
            bms_clear_upgrade_recovery_state();
        }
    } catch (Throwable $e) {
        if ($migrationPhaseStarted) {
            try {
                bms_write_upgrade_recovery_state([
                    'status' => 'recovery_required',
                    'phase' => 'database_migration',
                    'from_version' => $historyFromVersion,
                    'to_version' => $packageVersion,
                    'backup_path' => $backupRoot,
                    'started_at' => $migrationStartedAt !== '' ? $migrationStartedAt : gmdate('c'),
                ]);
                bms_upgrade_record_recovery_required($historyFromVersion, $packageVersion, $backupRoot);
            } catch (Throwable $recoveryError) {
                bms_log_admin_exception('upgrade-recovery-marker', $recoveryError);
            }

            bms_upgrade_remove_temp($tmpRoot);
            bms_log_admin_exception('upgrade-install', $e);
            throw new RuntimeException('Upgrade stopped after the database migration phase began. New software files were kept so they remain compatible with the database. Retry this exact release package using the same upgrade workflow to resume safely.');
        }

        if ($changedPackageFiles === [] && $removedDuringInstall === []) {
            bms_upgrade_remove_temp($tmpRoot);
            bms_log_admin_exception('upgrade-install', $e);
            throw new RuntimeException('Upgrade stopped before any package-managed software files were changed. No rollback was necessary. Review System Check. On a locked application tree, run php scripts/deploy-update.php as the application owner instead of broadening web/PHP write access.');
        }

        try {
            $rollback = bms_upgrade_restore_changed_software(
                $changedPackageFiles,
                $removedDuringInstall,
                $backupRoot,
                $publicRoot,
                $existingManifestFiles,
                $manifestFiles
            );
        } catch (Throwable $rollbackError) {
            bms_upgrade_remove_temp($tmpRoot);
            bms_log_admin_exception('upgrade-install', $e);
            bms_log_admin_exception('upgrade-rollback', $rollbackError);
            throw new RuntimeException('Upgrade failed before database migration began and recovery of changed software could not complete. Restore the upgrade backup before trying again.');
        }
        bms_upgrade_remove_temp($tmpRoot);
        bms_log_admin_exception('upgrade-install', $e);
        throw new RuntimeException(
            'Upgrade failed before database migration began. Bonumark Stream restored only the software changed during this attempt: '
            . count($rollback['restored'] ?? []) . ' previous file(s), '
            . count($rollback['removed_new'] ?? []) . ' newly copied file(s) removed, and '
            . count($rollback['restored_obsolete'] ?? []) . ' obsolete file(s) restored.'
        );
    }

    bms_upgrade_remove_temp($tmpRoot);

    return [
        'from' => $historyFromVersion,
        'to' => $packageVersion,
        'backup' => $backupRoot,
        'migrations' => $ran,
        'removed' => $removed,
    ];
}

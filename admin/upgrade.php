<?php
require_once __DIR__ . '/../_bonumark_stream/app/auth.php';
require_once __DIR__ . '/../_bonumark_stream/app/renderer.php';
require_once __DIR__ . '/_layout.php';
mp_require_login();

function mp_upgrade_zip_entry_is_symlink(ZipArchive $zip, int $index): bool
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

function mp_upgrade_safe_extract(ZipArchive $zip, string $destination): void
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
        if (mp_upgrade_zip_entry_is_symlink($zip, $i)) {
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

function mp_upgrade_find_package_root(string $directory): string
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

function mp_upgrade_normalize_package_name(string $name): string
{
    $name = strtolower(trim($name));
    $name = preg_replace('/[^a-z0-9]+/', '-', $name) ?: '';
    return trim($name, '-');
}

function mp_upgrade_allowed_package_names(): array
{
    return ['bonumark-stream'];
}

function mp_upgrade_package_version(string $packageRoot): string
{
    $manifest = $packageRoot . '/_bonumark_stream/PACKAGE.json';
    if (is_file($manifest)) {
        $data = json_decode((string)file_get_contents($manifest), true);
        if (is_array($data) && in_array(mp_upgrade_normalize_package_name((string)($data['name'] ?? '')), mp_upgrade_allowed_package_names(), true) && !empty($data['version'])) {
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


function mp_upgrade_package_file_paths(string $packageRoot): array
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

function mp_upgrade_verify_manifest(string $packageRoot): void
{
    $manifestPath = $packageRoot . '/_bonumark_stream/RELEASE-MANIFEST.json';
    if (!is_file($manifestPath)) {
        throw new RuntimeException('Release package is missing _bonumark_stream/RELEASE-MANIFEST.json. Upgrade refused.');
    }

    $manifest = json_decode((string)file_get_contents($manifestPath), true);
    if (!is_array($manifest) || !in_array(mp_upgrade_normalize_package_name((string)($manifest['name'] ?? '')), mp_upgrade_allowed_package_names(), true) || empty($manifest['files']) || !is_array($manifest['files'])) {
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

    $packageFiles = mp_upgrade_package_file_paths($packageRoot);
    foreach ($packageFiles as $relative => $_) {
        if (!isset($manifestFiles[$relative])) {
            throw new RuntimeException('Release package contains an unlisted file. Upgrade refused: ' . $relative);
        }
    }
}


function mp_upgrade_manifest_file_set(string $packageRoot): array
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


function mp_upgrade_retired_bundled_theme_slugs(): array
{
    return [
        'microblog-stream' => true,
    ];
}

function mp_upgrade_package_theme_slugs(array $manifestFiles, string $prefix): array
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

function mp_upgrade_installed_theme_manifest(string $publicRoot, string $slug): array
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

function mp_upgrade_theme_marked_as_bundled(array $manifest): bool
{
    $package = strtolower(trim((string)($manifest['package'] ?? '')));
    if (in_array($package, ['bundled-theme', 'bundled', 'core'], true)) {
        return true;
    }

    return !empty($manifest['bundled']) || !empty($manifest['core_theme']);
}

function mp_upgrade_retired_bundled_theme_leftover(string $publicRoot, string $slug): bool
{
    static $cache = [];

    $publicRoot = rtrim(str_replace('\\', '/', $publicRoot), '/');
    $cacheKey = $publicRoot . '|' . $slug;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    $retiredThemeSlugs = mp_upgrade_retired_bundled_theme_slugs();
    if (!isset($retiredThemeSlugs[$slug])) {
        $cache[$cacheKey] = false;
        return false;
    }

    $manifest = mp_upgrade_installed_theme_manifest($publicRoot, $slug);
    if (!$manifest) {
        $cache[$cacheKey] = false;
        return false;
    }

    $cache[$cacheKey] = mp_upgrade_theme_marked_as_bundled($manifest);
    return $cache[$cacheKey];
}

function mp_upgrade_theme_path_preserved(string $publicRoot, string $slug, array $packageThemeSlugs): bool
{
    if ($slug === '') {
        return false;
    }

    if (isset($packageThemeSlugs[$slug])) {
        return false;
    }

    if (mp_upgrade_retired_bundled_theme_leftover($publicRoot, $slug)) {
        return false;
    }

    return true;
}

function mp_upgrade_cleanup_preserved_path(string $publicRoot, string $relative, array $privateThemeSlugs, array $publicThemeSlugs): bool
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

    foreach (['_bonumark_stream/content/', '_bonumark_stream/data/', '_bonumark_stream/backups/', '_bonumark_stream/tmp/', 'media/', 'uploads/'] as $prefix) {
        if (str_starts_with($relative, $prefix)) {
            return true;
        }
    }

    if (preg_match('#^_bonumark_stream/themes/([^/]+)/#', $relative, $matches)) {
        return mp_upgrade_theme_path_preserved($publicRoot, (string)$matches[1], $privateThemeSlugs);
    }

    if (preg_match('#^assets/themes/([^/]+)/#', $relative, $matches)) {
        return mp_upgrade_theme_path_preserved($publicRoot, (string)$matches[1], $publicThemeSlugs);
    }

    return false;
}

function mp_upgrade_cleanup_managed_path(string $relative): bool
{
    $relative = str_replace('\\', '/', ltrim($relative, '/'));

    foreach (['admin/', 'assets/', 'docs/', 'scripts/', '_bonumark_stream/app/', '_bonumark_stream/migrations/', '_bonumark_stream/themes/', '_bonumark_stream/tools/'] as $prefix) {
        if (str_starts_with($relative, $prefix)) {
            return true;
        }
    }

    $managedExact = [
        '.htaccess' => true,
        '.gitignore' => true,
        'CONTRIBUTING.md' => true,
        'LICENSE' => true,
        'README.md' => true,
        'SECURITY.md' => true,
        'VERSION' => true,
        'account.php' => true,
        'author.php' => true,
        'comments.php' => true,
        'index.php' => true,
        'install.php' => true,
        'page.php' => true,
        'profile.php' => true,
        'search.php' => true,
        'stream-like.php' => true,
        'stream-page.php' => true,
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

function mp_upgrade_remove_empty_directories(string $root, array $privateThemeSlugs, array $publicThemeSlugs): void
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
        if ($relative === '' || mp_upgrade_cleanup_preserved_path($root, $relative . '/', $privateThemeSlugs, $publicThemeSlugs)) {
            continue;
        }
        if (!mp_upgrade_cleanup_managed_path($relative . '/') && !mp_upgrade_cleanup_managed_path($relative)) {
            continue;
        }
        $contents = array_diff(scandir($item->getPathname()) ?: [], ['.', '..']);
        if (!$contents) {
            @rmdir($item->getPathname());
        }
    }
}

function mp_upgrade_cleanup_obsolete_files(string $publicRoot, array $manifestFiles): array
{
    $privateThemeSlugs = mp_upgrade_package_theme_slugs($manifestFiles, '_bonumark_stream/themes');
    $publicThemeSlugs = mp_upgrade_package_theme_slugs($manifestFiles, 'assets/themes');
    $removed = [];

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
        if (isset($manifestFiles[$relative]) || mp_upgrade_cleanup_preserved_path($publicRoot, $relative, $privateThemeSlugs, $publicThemeSlugs) || !mp_upgrade_cleanup_managed_path($relative)) {
            continue;
        }
        if (@unlink($item->getPathname())) {
            $removed[] = $relative;
        } else {
            throw new RuntimeException('Could not remove obsolete software file: ' . $relative);
        }
    }

    mp_upgrade_remove_empty_directories($publicRoot, $privateThemeSlugs, $publicThemeSlugs);
    sort($removed);
    return $removed;
}

function mp_upgrade_record_history(string $fromVersion, string $toVersion, array $ran, array $removed): void
{
    if (!function_exists('mp_db')) {
        return;
    }

    $migrationNotes = $ran ? implode(', ', $ran) : 'none';
    $cleanupNotes = $removed ? implode(', ', array_slice($removed, 0, 25)) . (count($removed) > 25 ? ' +' . (count($removed) - 25) . ' more' : '') : 'none';
    $stmt = mp_db()->prepare('INSERT INTO ' . mp_table('upgrade_history') . ' (from_version, to_version, status, notes, ran_at) VALUES (:from_version, :to_version, :status, :notes, NOW())');
    $stmt->execute([
        'from_version' => $fromVersion,
        'to_version' => $toVersion,
        'status' => 'complete',
        'notes' => 'Migrations: ' . $migrationNotes . '; obsolete software files removed: ' . $cleanupNotes,
    ]);
}

function mp_upgrade_software_items(string $packageRoot): array
{
    $items = [
        'admin',
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
        'author.php',
        'comments.php',
        'search.php',
        'stream-like.php',
    ];

    $privateRoot = $packageRoot . '/_bonumark_stream';
    $skipPrivate = ['config.php' => true, 'installed.lock' => true, 'content' => true, 'data' => true, 'backups' => true, 'tmp' => true];
    foreach (array_diff(scandir($privateRoot) ?: [], ['.', '..']) as $item) {
        if (isset($skipPrivate[$item])) {
            continue;
        }
        $items[] = '_bonumark_stream/' . $item;
    }

    $items = array_values(array_unique($items));
    sort($items);
    return $items;
}

function mp_upgrade_copy_recursive(string $source, string $destination): void
{
    if (is_file($source)) {
        $dir = dirname($destination);
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new RuntimeException('Could not create directory: ' . $dir);
        }
        if (!copy($source, $destination)) {
            throw new RuntimeException('Could not copy file: ' . $source);
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
        mp_upgrade_copy_recursive($source . '/' . $item, $destination . '/' . $item);
    }
}

function mp_upgrade_backup_existing(array $items, string $backupRoot, string $publicRoot): void
{
    foreach ($items as $item) {
        $source = $publicRoot . '/' . $item;
        if (!file_exists($source)) {
            continue;
        }
        mp_upgrade_copy_recursive($source, $backupRoot . '/' . $item);
    }

    $config = $publicRoot . '/_bonumark_stream/config.php';
    if (is_file($config)) {
        mp_upgrade_copy_recursive($config, $backupRoot . '/_bonumark_stream/config.php');
    }
}

function mp_upgrade_existing_manifest_file_set(string $publicRoot, array $manifestFiles): array
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

function mp_upgrade_remove_new_package_files(string $publicRoot, array $manifestFiles, array $existingBefore): array
{
    $privateThemeSlugs = mp_upgrade_package_theme_slugs($manifestFiles, '_bonumark_stream/themes');
    $publicThemeSlugs = mp_upgrade_package_theme_slugs($manifestFiles, 'assets/themes');
    $removed = [];

    $paths = array_keys($manifestFiles);
    usort($paths, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));

    foreach ($paths as $relative) {
        $relative = str_replace('\\', '/', ltrim((string)$relative, '/'));
        if ($relative === '' || !empty($existingBefore[$relative])) {
            continue;
        }
        if (mp_upgrade_cleanup_preserved_path($publicRoot, $relative, $privateThemeSlugs, $publicThemeSlugs) || !mp_upgrade_cleanup_managed_path($relative)) {
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

    mp_upgrade_remove_empty_directories($publicRoot, $privateThemeSlugs, $publicThemeSlugs);
    sort($removed);
    return $removed;
}

function mp_upgrade_restore_backup(array $items, string $backupRoot, string $publicRoot): void
{
    foreach ($items as $item) {
        $backup = $backupRoot . '/' . $item;
        if (!file_exists($backup)) {
            continue;
        }
        mp_upgrade_copy_recursive($backup, $publicRoot . '/' . $item);
    }

    $config = $backupRoot . '/_bonumark_stream/config.php';
    if (is_file($config)) {
        mp_upgrade_copy_recursive($config, $publicRoot . '/_bonumark_stream/config.php');
    }
}

function mp_upgrade_remove_temp(string $path): void
{
    if (is_dir($path)) {
        mp_delete_directory($path);
    }
}


function mp_upgrade_pending_migrations_from_package(string $packageRoot): array
{
    $packageFiles = glob($packageRoot . '/_bonumark_stream/migrations/*.php') ?: [];
    $packageMigrations = array_map(fn($file) => basename($file, '.php'), $packageFiles);
    sort($packageMigrations);

    $done = [];
    try {
        if (function_exists('mp_has_database_config') && mp_has_database_config()) {
            $table = mp_table_prefix() . 'migrations';
            $stmt = mp_db()->query('SELECT migration FROM `' . $table . '`');
            foreach ($stmt->fetchAll() as $row) {
                $done[(string)$row['migration']] = true;
            }
        }
    } catch (Throwable $e) {
        return ['Could not check migrations: ' . $e->getMessage()];
    }

    return array_values(array_filter($packageMigrations, fn($migration) => !isset($done[$migration])));
}

function mp_upgrade_precheck_package(string $uploadedPath, string $uploadedName): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('The PHP ZipArchive extension is not available on this server. Ask the host to enable it before using admin ZIP upgrades.');
    }

    $currentVersion = mp_version();
    $token = bin2hex(random_bytes(16));
    $pendingDir = mp_root_path('tmp/upgrades/pending');
    $extractRoot = mp_root_path('tmp/upgrades/precheck-' . $token);
    if (!is_dir($pendingDir) && !mkdir($pendingDir, 0755, true)) {
        throw new RuntimeException('Could not create the pending upgrade folder.');
    }
    if (!is_dir($extractRoot) && !mkdir($extractRoot, 0755, true)) {
        throw new RuntimeException('Could not create the upgrade precheck folder.');
    }

    $pendingZip = $pendingDir . '/' . $token . '.zip';
    $copied = is_uploaded_file($uploadedPath) ? move_uploaded_file($uploadedPath, $pendingZip) : copy($uploadedPath, $pendingZip);
    if (!$copied) {
        mp_upgrade_remove_temp($extractRoot);
        throw new RuntimeException('Could not store the uploaded upgrade package for checking.');
    }

    $zip = new ZipArchive();
    $opened = $zip->open($pendingZip);
    if ($opened !== true) {
        @unlink($pendingZip);
        mp_upgrade_remove_temp($extractRoot);
        throw new RuntimeException('Could not open the uploaded ZIP package.');
    }

    try {
        mp_upgrade_safe_extract($zip, $extractRoot);
    } finally {
        $zip->close();
    }

    try {
        $packageRoot = mp_upgrade_find_package_root($extractRoot);
        $packageVersion = mp_upgrade_package_version($packageRoot);
        mp_upgrade_verify_manifest($packageRoot);
        $packageMeta = is_file($packageRoot . '/_bonumark_stream/PACKAGE.json') ? json_decode((string)file_get_contents($packageRoot . '/_bonumark_stream/PACKAGE.json'), true) : [];
        $releaseNotes = is_array($packageMeta) ? trim((string)($packageMeta['release_name'] ?? $packageMeta['description'] ?? '')) : '';

        if ($currentVersion !== 'unknown' && version_compare($packageVersion, $currentVersion, '<=')) {
            throw new RuntimeException('This package is not newer than the installed version. Installed: ' . $currentVersion . '. Package: ' . $packageVersion . '.');
        }

        $backupBase = mp_root_path('backups/upgrades');
        if (!is_dir($backupBase)) {
            @mkdir($backupBase, 0755, true);
        }
        $backupReady = is_dir($backupBase) && is_writable($backupBase);
        $pendingMigrations = mp_upgrade_pending_migrations_from_package($packageRoot);
        $publishedCount = count(mp_list_content_records('published'));

        $precheck = [
            'token' => $token,
            'zip_path' => $pendingZip,
            'uploaded_name' => basename($uploadedName),
            'current_version' => $currentVersion,
            'package_version' => $packageVersion,
            'backup_ready' => $backupReady,
            'pending_migrations' => $pendingMigrations,
            'published_count' => $publishedCount,
            'release_notes' => $releaseNotes,
            'checked_at' => date('c'),
        ];
        $_SESSION['pending_upgrade'] = $precheck;
        return $precheck;
    } catch (Throwable $e) {
        @unlink($pendingZip);
        throw $e;
    } finally {
        mp_upgrade_remove_temp($extractRoot);
    }
}

function mp_upgrade_clear_pending(): void
{
    $pending = $_SESSION['pending_upgrade'] ?? null;
    if (is_array($pending) && !empty($pending['zip_path']) && is_file((string)$pending['zip_path'])) {
        @unlink((string)$pending['zip_path']);
    }
    unset($_SESSION['pending_upgrade']);
}

function mp_upgrade_install(string $zipPath): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('The PHP ZipArchive extension is not available on this server. Ask the host to enable it before using admin ZIP upgrades.');
    }

    $currentVersion = mp_version();
    $publicRoot = mp_public_path();
    $timestamp = date('Ymd-His');
    $tmpRoot = mp_root_path('tmp/upgrades/' . $timestamp . '-' . bin2hex(random_bytes(4)));
    $backupRoot = mp_root_path('backups/upgrades/' . $timestamp);

    if (!is_dir($tmpRoot) && !mkdir($tmpRoot, 0755, true)) {
        throw new RuntimeException('Could not create upgrade temp directory.');
    }

    $zip = new ZipArchive();
    $opened = $zip->open($zipPath);
    if ($opened !== true) {
        mp_upgrade_remove_temp($tmpRoot);
        throw new RuntimeException('Could not open the uploaded ZIP package.');
    }

    try {
        mp_upgrade_safe_extract($zip, $tmpRoot);
    } finally {
        $zip->close();
    }

    $packageRoot = mp_upgrade_find_package_root($tmpRoot);
    $packageVersion = mp_upgrade_package_version($packageRoot);
    mp_upgrade_verify_manifest($packageRoot);
    $manifestFiles = mp_upgrade_manifest_file_set($packageRoot);

    if ($currentVersion !== 'unknown' && version_compare($packageVersion, $currentVersion, '<=')) {
        mp_upgrade_remove_temp($tmpRoot);
        throw new RuntimeException('This package is not newer than the installed version. Installed: ' . $currentVersion . '. Package: ' . $packageVersion . '.');
    }

    $softwareItems = mp_upgrade_software_items($packageRoot);
    $existingManifestFiles = mp_upgrade_existing_manifest_file_set($publicRoot, $manifestFiles);

    if (!is_dir($backupRoot) && !mkdir($backupRoot, 0755, true)) {
        mp_upgrade_remove_temp($tmpRoot);
        throw new RuntimeException('Could not create upgrade backup directory.');
    }

    mp_upgrade_backup_existing($softwareItems, $backupRoot, $publicRoot);

    $ran = [];
    $removed = [];

    try {
        foreach ($softwareItems as $item) {
            $source = $packageRoot . '/' . $item;
            if (!file_exists($source)) {
                continue;
            }
            mp_upgrade_copy_recursive($source, $publicRoot . '/' . $item);
        }

        $removed = mp_upgrade_cleanup_obsolete_files($publicRoot, $manifestFiles);

        $log = "Bonumark Stream upgrade\n" .
            "From: {$currentVersion}\n" .
            "To: {$packageVersion}\n" .
            "Date: " . date('c') . "\n" .
            "Preserved: _bonumark_stream/config.php, _bonumark_stream/installed.lock, _bonumark_stream/content/, _bonumark_stream/data/, _bonumark_stream/backups/, _bonumark_stream/tmp/, media/, uploads/, and custom installed themes, including external themes that reuse retired bundled slugs unless their theme manifest clearly identifies them as bundled leftovers\n" .
            "Obsolete package-managed files removed: " . count($removed) . "\n";
        mp_write_file($backupRoot . '/UPGRADE.txt', $log);

        if (function_exists('mp_run_migrations')) {
            $ran = mp_run_migrations();
        }
        mp_upgrade_record_history($currentVersion, $packageVersion, $ran, $removed);
    } catch (Throwable $e) {
        $rollbackRemoved = [];
        try {
            mp_upgrade_restore_backup($softwareItems, $backupRoot, $publicRoot);
            $rollbackRemoved = mp_upgrade_remove_new_package_files($publicRoot, $manifestFiles, $existingManifestFiles);
        } catch (Throwable $rollbackError) {
            mp_upgrade_remove_temp($tmpRoot);
            throw new RuntimeException('Upgrade failed. Rollback also failed: ' . $rollbackError->getMessage() . '. Original error: ' . $e->getMessage());
        }
        mp_upgrade_remove_temp($tmpRoot);
        $rollbackNote = $rollbackRemoved ? ' Newly copied package files removed during rollback: ' . count($rollbackRemoved) . '.' : ' No newly copied package files remained after rollback.';
        throw new RuntimeException('Upgrade failed and Bonumark Stream restored the previous software files.' . $rollbackNote . ' Original error: ' . $e->getMessage());
    }

    mp_upgrade_remove_temp($tmpRoot);

    return [
        'from' => $currentVersion,
        'to' => $packageVersion,
        'backup' => $backupRoot,
        'migrations' => $ran,
        'removed' => $removed,
    ];
}

$precheck = null;

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !empty($_GET['post_upgrade']) && !empty($_SESSION['completed_upgrade']) && is_array($_SESSION['completed_upgrade'])) {
    $completedUpgrade = $_SESSION['completed_upgrade'];
    unset($_SESSION['completed_upgrade']);

    $migrationCount = count($completedUpgrade['migrations'] ?? []);
    mp_flash('Upgrade complete. Bonumark Stream moved from v' . (string)($completedUpgrade['from'] ?? 'unknown') . ' to v' . (string)($completedUpgrade['to'] ?? mp_version()) . '. Backup created and ' . $migrationCount . ' migration(s) ran. Dynamic public routes now use the upgraded code.', 'success');

    mp_redirect(mp_admin_url());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    mp_verify_csrf();

    if (!empty($_POST['cancel_upgrade'])) {
        mp_upgrade_clear_pending();
        mp_flash('Pending upgrade canceled. No software files were changed.', 'info');
        mp_redirect(mp_admin_url('upgrade.php'));
    }

    if (!empty($_POST['confirm_upgrade'])) {
        $pending = $_SESSION['pending_upgrade'] ?? null;
        if (!is_array($pending) || empty($pending['zip_path']) || !is_file((string)$pending['zip_path'])) {
            mp_flash('Upgrade confirmation expired. Upload the Bonumark Stream release ZIP again.', 'warning');
            mp_redirect(mp_admin_url('upgrade.php'));
        }

        try {
            $result = mp_upgrade_install((string)$pending['zip_path']);
            $_SESSION['completed_upgrade'] = $result;
            mp_upgrade_clear_pending();
            mp_redirect(mp_admin_url('upgrade.php?post_upgrade=1'));
        } catch (Throwable $e) {
            mp_flash('Upgrade failed. ' . $e->getMessage(), 'error');
            mp_redirect(mp_admin_url('upgrade.php'));
        }
    }

    if (empty($_FILES['upgrade_zip']) || $_FILES['upgrade_zip']['error'] !== UPLOAD_ERR_OK) {
        mp_flash('Upload failed. Choose a Bonumark Stream release ZIP and try again.', 'error');
        mp_redirect(mp_admin_url('upgrade.php'));
    }

    $file = $_FILES['upgrade_zip'];
    $extension = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    if ($extension !== 'zip') {
        mp_flash('Only Bonumark Stream .zip release packages are allowed.', 'error');
        mp_redirect(mp_admin_url('upgrade.php'));
    }

    if (($file['size'] ?? 0) > 1024 * 1024 * 20) {
        mp_flash('Upgrade package is too large. Keep ZIP uploads under 20 MB.', 'error');
        mp_redirect(mp_admin_url('upgrade.php'));
    }

    try {
        mp_upgrade_clear_pending();
        $precheck = mp_upgrade_precheck_package((string)$file['tmp_name'], (string)$file['name']);
        mp_flash('Upgrade package checked. Review the status below before running the upgrade.', 'info');
    } catch (Throwable $e) {
        mp_upgrade_clear_pending();
        mp_flash('Upgrade check failed. ' . $e->getMessage(), 'error');
        mp_redirect(mp_admin_url('upgrade.php'));
    }
} elseif (!empty($_SESSION['pending_upgrade']) && is_array($_SESSION['pending_upgrade'])) {
    $pending = $_SESSION['pending_upgrade'];
    if (!empty($pending['zip_path']) && is_file((string)$pending['zip_path'])) {
        $precheck = $pending;
    } else {
        unset($_SESSION['pending_upgrade']);
    }
}

$upgradeHistory = [];
try {
    $upgradeHistory = mp_db()->query('SELECT * FROM ' . mp_table('upgrade_history') . ' ORDER BY ran_at DESC LIMIT 8')->fetchAll() ?: [];
} catch (Throwable $e) {
    $upgradeHistory = [];
}

mp_admin_header('Upgrade Bonumark Stream', [
    ['label' => 'System Check', 'href' => mp_admin_url('system-check.php'), 'style' => 'secondary'],
]);
?>
<section class="panel upgrade-upload-panel">
  <h2>Install a Bonumark Stream release ZIP</h2>
  <p>Upload a release ZIP. Bonumark Stream will verify it before you can run the upgrade.</p>

  <div class="upgrade-current-version">
    <span>Installed version</span>
    <strong>v<?= htmlspecialchars(mp_version(), ENT_QUOTES, 'UTF-8') ?></strong>
  </div>

  <form method="post" enctype="multipart/form-data" class="upgrade-form">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(mp_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <label for="upgrade_zip">Bonumark Stream release ZIP</label>
    <input id="upgrade_zip" type="file" name="upgrade_zip" accept=".zip,application/zip" required>
    <button type="submit">Upload and check package</button>
  </form>

  <details class="upgrade-details">
    <summary>What happens during upgrade?</summary>
    <div>
      <p><strong>Protected:</strong> <code>_bonumark_stream/config.php</code>, <code>_bonumark_stream/installed.lock</code>, runtime content, data, backups, media, uploads, and custom installed themes, including external themes that reuse retired bundled slugs.</p>
      <p><strong>Updated:</strong> admin files, Stream app files, tools, assets, documentation, changelog, migrations, bundled themes, and version markers.</p>
      <p>Bonumark Stream validates the release manifest, rejects unsafe ZIP paths, blocks symlinks, refuses older versions, creates a backup, copies software files, runs migrations, and restores the previous software files if the upgrade fails.</p>
    </div>
  </details>
</section>

<?php if ($precheck): ?>
<section class="panel upgrade-check-panel">
  <div class="section-header-row">
    <div>
      <p class="eyebrow">Upgrade check</p>
      <h2>Ready to upgrade from v<?= htmlspecialchars((string)$precheck['current_version'], ENT_QUOTES, 'UTF-8') ?> to v<?= htmlspecialchars((string)$precheck['package_version'], ENT_QUOTES, 'UTF-8') ?></h2>
      <p class="meta">Package checked and ready. Review the status, then run the upgrade.</p>
      <?php if (!empty($precheck['release_notes'])): ?><p class="meta"><strong>Release notes:</strong> <?= htmlspecialchars((string)$precheck['release_notes'], ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    </div>
  </div>

  <div class="upgrade-status-grid">
    <div class="upgrade-status-card pass">
      <span>Current Version</span>
      <strong>v<?= htmlspecialchars((string)$precheck['current_version'], ENT_QUOTES, 'UTF-8') ?></strong>
    </div>
    <div class="upgrade-status-card pass">
      <span>Uploaded Version</span>
      <strong>v<?= htmlspecialchars((string)$precheck['package_version'], ENT_QUOTES, 'UTF-8') ?></strong>
    </div>
    <div class="upgrade-status-card <?= !empty($precheck['backup_ready']) ? 'pass' : 'fail' ?>">
      <span>Backup Status</span>
      <strong><?= !empty($precheck['backup_ready']) ? 'Ready' : 'Not writable' ?></strong>
    </div>
    <div class="upgrade-status-card pass">
      <span>Migration Status</span>
      <strong><?= count($precheck['pending_migrations'] ?? []) ?> pending</strong>
    </div>
    <div class="upgrade-status-card pass">
      <span>Public Output</span>
      <strong><?= (int)($precheck['published_count'] ?? 0) ?> stream post(s) to rebuild later</strong>
    </div>
  </div>

  <details class="upgrade-details upgrade-precheck-details">
    <summary>Advanced upgrade details</summary>
    <div>
      <p><strong>Uploaded package:</strong> <?= htmlspecialchars((string)$precheck['uploaded_name'], ENT_QUOTES, 'UTF-8') ?></p>
      <?php if (!empty($precheck['pending_migrations'])): ?>
        <div class="upgrade-migrations-list">
          <h3>Pending migrations</h3>
          <ul>
            <?php foreach ($precheck['pending_migrations'] as $migration): ?>
              <li><code><?= htmlspecialchars((string)$migration, ENT_QUOTES, 'UTF-8') ?></code></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php else: ?>
        <p class="meta">No database migrations appear to be pending for this package.</p>
      <?php endif; ?>
      <p>Running the upgrade creates a backup, replaces software files, removes obsolete package-managed files, preserves custom installed themes, and runs pending migrations. Dynamic public routes use the upgraded code immediately. Static Site Export remains an optional Export-screen artifact.</p>
    </div>
  </details>

  <form method="post" class="form-actions-row upgrade-confirm-actions">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(mp_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <button type="submit" name="confirm_upgrade" value="1" <?= empty($precheck['backup_ready']) ? 'disabled' : '' ?>>Run Upgrade</button>
    <button type="submit" name="cancel_upgrade" value="1" class="secondary-button">Cancel</button>
  </form>
  <?php if (empty($precheck['backup_ready'])): ?>
    <p class="field-help warning-text">Upgrade is blocked until the upgrade backup folder is writable.</p>
  <?php else: ?>
    <p class="field-help">A backup will be created before software files are replaced.</p>
  <?php endif; ?>
</section>
<?php endif; ?>

<section class="panel">
  <div class="section-header-row"><div><h2>Upgrade history</h2><p class="meta">Recent software updates recorded by Bonumark Stream.</p></div></div>
  <?php if (!$upgradeHistory): ?>
    <p class="meta">No upgrade history recorded yet.</p>
  <?php else: ?>
    <table class="admin-table compact-table"><thead><tr><th>From</th><th>To</th><th>Status</th><th>Ran</th></tr></thead><tbody>
    <?php foreach ($upgradeHistory as $row): ?><tr><td><?= htmlspecialchars((string)$row['from_version'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars((string)$row['to_version'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars((string)$row['status'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars((string)$row['ran_at'], ENT_QUOTES, 'UTF-8') ?></td></tr><?php endforeach; ?>
    </tbody></table>
  <?php endif; ?>
</section>

<section class="panel upgrade-rule-panel">
  <h2>Upgrade rule</h2>
  <p>Only upload Bonumark Stream release ZIP files you created or trust.</p>
  <details class="upgrade-details">
    <summary>Why this matters</summary>
    <div>
      <p>A ZIP upgrade replaces PHP software files. Bonumark Stream validates the release manifest and rejects unsafe package paths, symlinks, older versions, and oversized packages, but a malicious trusted-admin upload can still compromise a site.</p>
    </div>
  </details>
</section>
<?php mp_admin_footer(); ?>

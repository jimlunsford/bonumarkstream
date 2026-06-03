<?php
require_once __DIR__ . '/themes.php';

function bms_theme_installer_remove_directory(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isDir() && !$item->isLink()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }
    @rmdir($path);
}

function bms_theme_installer_zip_entry_is_symlink(ZipArchive $zip, int $index): bool
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

function bms_theme_installer_is_allowed_file(string $relative): bool
{
    $relative = str_replace('\\', '/', trim($relative));
    $basename = strtolower(basename($relative));
    if ($relative === '' || $basename === '') {
        return false;
    }

    if (str_starts_with($basename, '.') || $basename === '.htaccess' || str_starts_with($basename, '.user') || str_starts_with($basename, 'php.ini')) {
        return false;
    }

    if (preg_match('#(^|/)(templates?|views?|app|admin|vendor|node_modules|\.git)(/|$)#i', $relative) === 1) {
        return false;
    }

    if (in_array($basename, ['license', 'copying', 'notice'], true)) {
        return true;
    }

    $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
    $allowed = ['json', 'md', 'txt', 'css', 'svg', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'woff', 'woff2', 'ico'];
    return in_array($extension, $allowed, true);
}

function bms_theme_installer_safe_extract(ZipArchive $zip, string $destination): void
{
    $maxFiles = 250;
    $maxTotalBytes = 15 * 1024 * 1024;
    $maxSingleBytes = 5 * 1024 * 1024;
    $totalBytes = 0;

    if ($zip->numFiles < 1 || $zip->numFiles > $maxFiles) {
        throw new RuntimeException('Theme package has an unsafe number of files.');
    }

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string)$zip->getNameIndex($i);
        $normalized = str_replace('\\', '/', $name);
        $trimmed = trim($normalized, '/');
        $stat = $zip->statIndex($i) ?: [];
        $size = (int)($stat['size'] ?? 0);
        $depth = substr_count($trimmed, '/');

        if ($normalized === '' || str_contains($normalized, "\0") || str_starts_with($normalized, '/') || preg_match('#(^|/)\.\.(/|$)#', $normalized) || preg_match('#^[A-Za-z]:#', $normalized)) {
            throw new RuntimeException('Unsafe ZIP path detected.');
        }
        if (strlen($normalized) > 240 || $depth > 10) {
            throw new RuntimeException('Theme package contains paths that are too deep or too long.');
        }
        if (bms_theme_installer_zip_entry_is_symlink($zip, $i)) {
            throw new RuntimeException('Theme package contains a symbolic link, which is not allowed.');
        }
        if ($size > $maxSingleBytes) {
            throw new RuntimeException('Theme package contains a file larger than the allowed limit.');
        }
        $totalBytes += $size;
        if ($totalBytes > $maxTotalBytes) {
            throw new RuntimeException('Theme package expands beyond the allowed size limit.');
        }

        if (!str_ends_with($normalized, '/') && !bms_theme_installer_is_allowed_file($normalized)) {
            throw new RuntimeException('Theme package contains a file type or path that is not allowed: ' . basename($normalized));
        }
    }

    if (!$zip->extractTo($destination)) {
        throw new RuntimeException('Could not extract the theme package.');
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($destination, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isLink()) {
            throw new RuntimeException('Theme package extracted a symbolic link, which is not allowed.');
        }
        if ($item->isFile() && !bms_theme_installer_is_allowed_file(substr(str_replace('\\', '/', $item->getPathname()), strlen(str_replace('\\', '/', $destination)) + 1))) {
            throw new RuntimeException('Theme package extracted a file that is not allowed: ' . $item->getBasename());
        }
    }
}

function bms_theme_installer_find_manifest_candidates(string $extractRoot): array
{
    $candidates = [];
    $extractRoot = rtrim($extractRoot, '/\\');

    if (is_file($extractRoot . '/theme.json')) {
        $candidates[] = [
            'manifest' => $extractRoot . '/theme.json',
            'private_root' => $extractRoot,
            'public_root' => $extractRoot,
            'package_root' => $extractRoot,
        ];
    }

    foreach (array_diff(scandir($extractRoot) ?: [], ['.', '..']) as $child) {
        $path = $extractRoot . '/' . $child;
        if (is_dir($path) && is_file($path . '/theme.json')) {
            $candidates[] = [
                'manifest' => $path . '/theme.json',
                'private_root' => $path,
                'public_root' => $path,
                'package_root' => $path,
            ];
        }
    }

    $privateThemesRoot = $extractRoot . '/_bonumark_stream/themes';
    if (is_dir($privateThemesRoot)) {
        foreach (array_diff(scandir($privateThemesRoot) ?: [], ['.', '..']) as $entry) {
            $privateRoot = $privateThemesRoot . '/' . $entry;
            if (!is_dir($privateRoot) || !is_file($privateRoot . '/theme.json')) {
                continue;
            }
            $candidates[] = [
                'manifest' => $privateRoot . '/theme.json',
                'private_root' => $privateRoot,
                'public_root' => $extractRoot . '/assets/themes/' . $entry,
                'package_root' => $extractRoot,
            ];
        }
    }

    return $candidates;
}

function bms_theme_installer_asset_source_candidates(string $packageRoot, string $privateRoot, string $publicRoot, string $asset): array
{
    $asset = ltrim(str_replace('\\', '/', $asset), '/');
    return array_values(array_unique([
        rtrim($publicRoot, '/\\') . '/' . $asset,
        rtrim($privateRoot, '/\\') . '/assets/' . $asset,
        rtrim($privateRoot, '/\\') . '/' . $asset,
        rtrim($packageRoot, '/\\') . '/assets/' . $asset,
        rtrim($packageRoot, '/\\') . '/' . $asset,
    ]));
}

function bms_theme_installer_copy_file(string $source, string $destination): void
{
    if (!is_file($source)) {
        throw new RuntimeException('Theme package is missing a required file: ' . basename($destination));
    }
    $dir = dirname($destination);
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        throw new RuntimeException('Could not create theme install directory: ' . $dir);
    }
    if (!copy($source, $destination)) {
        throw new RuntimeException('Could not copy theme file: ' . basename($source));
    }
}

function bms_theme_installer_copy_optional_doc_files(string $sourceRoot, string $destinationRoot): void
{
    foreach (['README.md', 'README.txt', 'LICENSE', 'LICENSE.txt', 'CHANGELOG.md'] as $file) {
        $source = rtrim($sourceRoot, '/\\') . '/' . $file;
        if (is_file($source)) {
            bms_theme_installer_copy_file($source, rtrim($destinationRoot, '/\\') . '/' . $file);
        }
    }
}

function bms_theme_installer_manifest_asset_refs(array $manifest): array
{
    $refs = [];
    $assets = is_array($manifest['assets'] ?? null) ? $manifest['assets'] : [];
    foreach (['css', 'images', 'fonts'] as $type) {
        foreach ((array)($assets[$type] ?? []) as $asset) {
            $asset = bms_theme_asset_reference((string)$asset);
            if ($asset !== '') {
                $refs[] = $asset;
            }
        }
    }
    $screenshot = bms_theme_asset_reference((string)($manifest['screenshot'] ?? ''));
    if ($screenshot !== '') {
        $refs[] = $screenshot;
    }
    return array_values(array_unique($refs));
}

function bms_theme_installer_install_candidate(array $candidate, bool $replaceExisting = false, bool $activate = false): array
{
    $manifest = bms_read_theme_manifest_file((string)$candidate['manifest']);
    if (!is_array($manifest)) {
        throw new RuntimeException('The uploaded theme has an invalid theme.json manifest.');
    }

    $slug = bms_theme_slug_or_empty((string)($manifest['slug'] ?? ''));
    if ($slug === '' || !preg_match('/^[a-z0-9][a-z0-9_-]{1,63}$/', $slug)) {
        throw new RuntimeException('The uploaded theme slug is missing or invalid.');
    }

    if (in_array($slug, ['default'], true)) {
        throw new RuntimeException('Protected themes cannot be replaced through the theme uploader. Use a different theme slug.');
    }

    $privateRoot = rtrim((string)$candidate['private_root'], '/\\');
    $publicRoot = rtrim((string)$candidate['public_root'], '/\\');
    $packageRoot = rtrim((string)$candidate['package_root'], '/\\');

    $assetStage = bms_root_path('tmp/theme-install-assets-' . bin2hex(random_bytes(4)));
    $privateStage = bms_root_path('tmp/theme-install-private-' . bin2hex(random_bytes(4)));
    $targetPrivate = bms_themes_path($slug);
    $targetAssets = bms_public_theme_asset_path($slug);
    $backupPrivate = '';
    $backupAssets = '';
    $installedPrivate = false;
    $installedAssets = false;

    try {
        if (!is_dir($privateStage) && !mkdir($privateStage, 0755, true)) {
            throw new RuntimeException('Could not prepare theme install staging area.');
        }
        if (!is_dir($assetStage) && !mkdir($assetStage, 0755, true)) {
            throw new RuntimeException('Could not prepare theme asset staging area.');
        }

        bms_theme_installer_copy_file($privateRoot . '/theme.json', $privateStage . '/theme.json');
        bms_theme_installer_copy_optional_doc_files($privateRoot, $privateStage);

        foreach (bms_theme_installer_manifest_asset_refs($manifest) as $asset) {
            $source = '';
            foreach (bms_theme_installer_asset_source_candidates($packageRoot, $privateRoot, $publicRoot, $asset) as $candidatePath) {
                if (is_file($candidatePath)) {
                    $source = $candidatePath;
                    break;
                }
            }
            if ($source === '') {
                throw new RuntimeException('Theme package is missing a declared asset: ' . $asset);
            }
            bms_theme_installer_copy_file($source, $assetStage . '/' . $asset);
        }

        $health = bms_public_theme_package_health_at_paths($manifest, $privateStage, $assetStage);
        if (empty($health['valid'])) {
            $errors = is_array($health['errors'] ?? null) ? $health['errors'] : ['Theme did not pass validation.'];
            throw new RuntimeException('Theme did not pass validation: ' . implode(' ', $errors));
        }

        if ((file_exists($targetPrivate) || file_exists($targetAssets)) && !$replaceExisting) {
            throw new RuntimeException('A theme with this slug already exists. Check Update this theme if it already exists to install an update.');
        }

        if ($replaceExisting) {
            if (is_dir($targetPrivate)) {
                $backupPrivate = bms_root_path('tmp/theme-backup-private-' . $slug . '-' . bin2hex(random_bytes(4)));
                if (!rename($targetPrivate, $backupPrivate)) {
                    throw new RuntimeException('Could not prepare existing theme metadata backup.');
                }
            }
            if (is_dir($targetAssets)) {
                $backupAssets = bms_root_path('tmp/theme-backup-assets-' . $slug . '-' . bin2hex(random_bytes(4)));
                if (!rename($targetAssets, $backupAssets)) {
                    throw new RuntimeException('Could not prepare existing theme asset backup.');
                }
            }
        }

        if (!is_dir(dirname($targetPrivate)) && !mkdir(dirname($targetPrivate), 0755, true)) {
            throw new RuntimeException('Could not create theme directory.');
        }
        if (!is_dir(dirname($targetAssets)) && !mkdir(dirname($targetAssets), 0755, true)) {
            throw new RuntimeException('Could not create public theme asset directory.');
        }

        if (!rename($privateStage, $targetPrivate)) {
            throw new RuntimeException('Could not install theme metadata.');
        }
        $installedPrivate = true;
        if (!rename($assetStage, $targetAssets)) {
            throw new RuntimeException('Could not install theme assets.');
        }
        $installedAssets = true;

        $installed = bms_read_theme_manifest($slug);
        if (!is_array($installed)) {
            throw new RuntimeException('Installed theme manifest could not be read.');
        }
        $installedHealth = bms_public_theme_package_health($installed);
        if (empty($installedHealth['valid'])) {
            throw new RuntimeException('Installed theme did not pass final validation.');
        }

        if ($backupPrivate !== '') {
            bms_theme_installer_remove_directory($backupPrivate);
        }
        if ($backupAssets !== '') {
            bms_theme_installer_remove_directory($backupAssets);
        }

        if ($activate) {
            bms_set_setting('active_public_theme', $slug);
        }

        return [
            'slug' => $slug,
            'name' => (string)($installed['name'] ?? $slug),
            'version' => (string)($installed['version'] ?? '1.0.0'),
            'activated' => $activate,
        ];
    } catch (Throwable $e) {
        bms_theme_installer_remove_directory($privateStage);
        bms_theme_installer_remove_directory($assetStage);
        if ($installedPrivate) {
            bms_theme_installer_remove_directory($targetPrivate);
        }
        if ($installedAssets) {
            bms_theme_installer_remove_directory($targetAssets);
        }
        if ($backupPrivate !== '' && is_dir($backupPrivate) && !is_dir($targetPrivate)) {
            @rename($backupPrivate, $targetPrivate);
        }
        if ($backupAssets !== '' && is_dir($backupAssets) && !is_dir($targetAssets)) {
            @rename($backupAssets, $targetAssets);
        }
        throw $e;
    }
}

function bms_install_public_theme_zip(array $file, bool $replaceExisting = false, bool $activate = false): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Theme ZIP installation requires the PHP Zip extension.');
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Theme upload failed.');
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    $name = (string)($file['name'] ?? 'theme.zip');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('Theme upload is missing.');
    }
    if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'zip') {
        throw new RuntimeException('Theme packages must be uploaded as ZIP files.');
    }

    $extractRoot = bms_root_path('tmp/theme-upload-' . bin2hex(random_bytes(6)));
    if (!mkdir($extractRoot, 0755, true)) {
        throw new RuntimeException('Could not prepare theme upload directory.');
    }

    $zip = new ZipArchive();
    try {
        if ($zip->open($tmp) !== true) {
            throw new RuntimeException('Could not open the uploaded theme ZIP.');
        }
        bms_theme_installer_safe_extract($zip, $extractRoot);
        $zip->close();

        $candidates = bms_theme_installer_find_manifest_candidates($extractRoot);
        if (!$candidates) {
            throw new RuntimeException('Theme package must contain a theme.json manifest.');
        }
        if (count($candidates) > 1) {
            throw new RuntimeException('Theme package contains multiple theme.json manifests. Upload one theme at a time.');
        }

        return bms_theme_installer_install_candidate($candidates[0], $replaceExisting, $activate);
    } catch (Throwable $e) {
        if ($zip instanceof ZipArchive) {
            @$zip->close();
        }
        throw $e;
    } finally {
        bms_theme_installer_remove_directory($extractRoot);
    }
}

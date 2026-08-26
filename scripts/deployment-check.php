<?php
/**
 * Read-only installed-site deployment check.
 *
 * This CLI-only helper validates version markers, package-managed file hashes,
 * obsolete package leftovers, required runtime-directory presence, database
 * compatibility, pending migrations, and migration recovery state.
 * It does not change files, database records, permissions, or settings.
 *
 * Admin > System Check remains authoritative for capabilities that depend on
 * the web/PHP runtime identity, web-server routing, or HTTP access controls.
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
require_once $root . '/_bonumark_stream/app/database.php';

$failures = [];
$rootVersion = trim((string)@file_get_contents($root . '/VERSION'));
$privateVersion = trim((string)@file_get_contents($root . '/_bonumark_stream/VERSION'));

echo "Bonumark Stream deployment check\n";
echo "Application version: " . ($rootVersion !== '' ? $rootVersion : 'unknown') . "\n";

if ($rootVersion === '' || $privateVersion === '' || $rootVersion !== $privateVersion) {
    $failures[] = 'Root and private VERSION markers are missing or do not match.';
} else {
    echo "Version markers: PASS ({$rootVersion})\n";
}

if (!is_file($root . '/_bonumark_stream/config.php') || !is_file($root . '/_bonumark_stream/installed.lock')) {
    $failures[] = 'Installed config or installed.lock is missing. This helper is for installed sites.';
}

$manifestPath = $root . '/_bonumark_stream/RELEASE-MANIFEST.json';
$manifest = is_file($manifestPath) ? json_decode((string)file_get_contents($manifestPath), true) : null;
$manifestFileSet = [];
if (!is_array($manifest) || !is_array($manifest['files'] ?? null)) {
    $failures[] = 'Installed release manifest is missing or invalid.';
} else {
    $manifestVersion = trim((string)($manifest['version'] ?? ''));
    if ($rootVersion !== '' && $manifestVersion !== $rootVersion) {
        $failures[] = 'Release manifest version does not match the installed VERSION marker.';
    }
    foreach ($manifest['files'] as $entry) {
        $relative = str_replace('\\', '/', trim((string)($entry['path'] ?? '')));
        $expected = strtolower(trim((string)($entry['sha256'] ?? '')));
        if ($relative === '' || preg_match('/^[a-f0-9]{64}$/', $expected) !== 1) {
            $failures[] = 'Release manifest contains an invalid file entry.';
            continue;
        }
        $manifestFileSet[$relative] = true;

        // The release manifest also covers repository-only source metadata such
        // as .github/. Installed-site integrity intentionally checks only the
        // paths Bonumark deploys and manages as application software.
        if (!bms_package_managed_software_path($relative)) {
            continue;
        }

        $path = $root . '/' . $relative;
        if (!is_file($path)) {
            $failures[] = 'Package-managed file is missing: ' . $relative;
            continue;
        }
        $actual = hash_file('sha256', $path);
        if (!is_string($actual) || !hash_equals($expected, strtolower($actual))) {
            $failures[] = 'Package-managed file hash mismatch: ' . $relative;
        }
    }
    $manifestFileSet['_bonumark_stream/RELEASE-MANIFEST.json'] = true;
    if (!array_filter($failures, static fn(string $failure): bool => str_contains($failure, 'Package-managed file') || str_contains($failure, 'Release manifest'))) {
        echo "Package-managed file integrity: PASS\n";
    }

    $obsoleteFiles = bms_deployment_obsolete_package_files($root, $manifestFileSet);
    if ($obsoleteFiles !== []) {
        $failures[] = 'Obsolete package-managed files remain from an older release: '
            . implode(', ', array_slice($obsoleteFiles, 0, 25))
            . (count($obsoleteFiles) > 25 ? ' (+' . (count($obsoleteFiles) - 25) . ' more)' : '');
    } else {
        echo "Obsolete package files: PASS (0 found)\n";
    }
}

foreach (bms_runtime_directory_definitions() as $directory) {
    $path = (string)($directory['path'] ?? '');
    $relative = (string)($directory['relative_path'] ?? 'runtime storage');
    if ($path === '' || !is_dir($path)) {
        $failures[] = 'Required runtime directory is missing: ' . $relative;
    }
}
if (!array_filter($failures, static fn(string $failure): bool => str_contains($failure, 'runtime directory'))) {
    echo "Runtime directory presence: PASS\n";
}

if (bms_has_database_config()) {
    try {
        $pdo = bms_db();
        $pdo->query('SELECT 1');
        $databaseInfo = bms_database_server_compatibility($pdo);
        $databaseLabel = (string)($databaseInfo['display'] ?? 'database server');
        if (!empty($databaseInfo['supported'])) {
            echo "Database compatibility: PASS {$databaseLabel}\n";
        } else {
            $failures[] = (string)($databaseInfo['message'] ?? 'Database server is below the documented compatibility floor.');
        }

        $recoveryState = bms_upgrade_recovery_state();
        if ($recoveryState !== []) {
            $failures[] = 'Database migration recovery is still required for v'
                . (string)($recoveryState['to_version'] ?? 'unknown')
                . '. Use scripts/run-migrations.php with the same installed target release.';
        }

        $pendingMigrations = bms_pending_migration_names($pdo);
        if ($pendingMigrations !== []) {
            $failures[] = 'Pending database migrations: ' . implode(', ', $pendingMigrations)
                . '. Run php scripts/run-migrations.php --check and complete the owner-run migration workflow.';
        } else {
            echo "Pending database migrations: PASS (0)\n";
        }
    } catch (Throwable $e) {
        $failures[] = 'Database check failed: ' . $e->getMessage();
    }
} else {
    $failures[] = 'Database configuration is incomplete.';
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    fwrite(STDERR, "Admin > System Check remains authoritative for PHP runtime writability, private HTTP protection, web-based upgrades, and theme ZIP installation.\n");
    exit(1);
}

echo "Deployment check passed.\n";
echo "Next: open Admin > System Check for PHP runtime writability, private HTTP protection, web-based upgrade capability, and theme ZIP installation capability.\n";
exit(0);

/**
 * Report package-managed files that are no longer present in the installed
 * release manifest while preserving runtime data and custom themes.
 *
 * @param array<string,bool> $manifestFiles
 * @return list<string>
 */
function bms_deployment_obsolete_package_files(string $root, array $manifestFiles): array
{
    if (!is_dir($root)) {
        return [];
    }

    $privateThemeSlugs = bms_deployment_manifest_theme_slugs($manifestFiles, '_bonumark_stream/themes');
    $publicThemeSlugs = bms_deployment_manifest_theme_slugs($manifestFiles, 'assets/themes');
    $obsolete = [];
    $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/');

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($iterator as $item) {
        if (!$item->isFile() || $item->isLink()) {
            continue;
        }
        $path = str_replace('\\', '/', $item->getPathname());
        $relative = ltrim(substr($path, strlen($normalizedRoot)), '/');
        if ($relative === '' || isset($manifestFiles[$relative]) || bms_deployment_persistent_path($relative)) {
            continue;
        }

        if (preg_match('#^_bonumark_stream/themes/([^/]+)/#', $relative, $matches) === 1) {
            $slug = (string)$matches[1];
            if (isset($privateThemeSlugs[$slug]) || bms_deployment_retired_bundled_theme($root, $slug)) {
                $obsolete[] = $relative;
            }
            continue;
        }

        if (preg_match('#^assets/themes/([^/]+)/#', $relative, $matches) === 1) {
            $slug = (string)$matches[1];
            if (isset($publicThemeSlugs[$slug]) || bms_deployment_retired_bundled_theme($root, $slug)) {
                $obsolete[] = $relative;
            }
            continue;
        }

        if (bms_package_managed_software_path($relative)) {
            $obsolete[] = $relative;
        }
    }

    $obsolete = array_values(array_unique($obsolete));
    sort($obsolete);
    return $obsolete;
}

/** @param array<string,bool> $manifestFiles */
function bms_deployment_manifest_theme_slugs(array $manifestFiles, string $prefix): array
{
    $slugs = [];
    foreach (array_keys($manifestFiles) as $relative) {
        if (preg_match('#^' . preg_quote($prefix, '#') . '/([^/]+)/#', $relative, $matches) === 1) {
            $slugs[(string)$matches[1]] = true;
        }
    }
    return $slugs;
}

function bms_deployment_persistent_path(string $relative): bool
{
    $relative = str_replace('\\', '/', ltrim($relative, '/'));
    if (isset([
        '_bonumark_stream/config.php' => true,
        '_bonumark_stream/installed.lock' => true,
    ][$relative])) {
        return true;
    }
    foreach ([
        '_bonumark_stream/data/',
        '_bonumark_stream/tmp/',
        '_bonumark_stream/backups/',
        '_bonumark_stream/content/',
        '_bonumark_stream/import-staging/',
        'media/',
        'uploads/',
    ] as $prefix) {
        if (str_starts_with($relative, $prefix)) {
            return true;
        }
    }
    return false;
}

function bms_deployment_retired_bundled_theme(string $root, string $slug): bool
{
    if ($slug === '') {
        return false;
    }
    $manifestPath = rtrim($root, '/\\') . '/_bonumark_stream/themes/' . $slug . '/theme.json';
    if (is_file($manifestPath)) {
        $theme = json_decode((string)@file_get_contents($manifestPath), true);
        if (is_array($theme)) {
            $package = strtolower(trim((string)($theme['package'] ?? '')));
            if (in_array($package, ['bundled-theme', 'bundled-declarative-proof-theme', 'bundled', 'core'], true)
                || !empty($theme['bundled'])
                || !empty($theme['core_theme'])) {
                return true;
            }
            return false;
        }
    }

    return in_array($slug, ['microblog-stream', 'profile-editorial', 'profile-split'], true);
}

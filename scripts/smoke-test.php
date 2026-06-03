<?php
/**
 * Bonumark Stream package smoke test.
 *
 * This script validates package metadata, migrations, release manifest hashes,
 * theme manifests, CSS brace balance, and common release hygiene rules.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];

function bm_smoke_fail(array &$failures, string $message): void
{
    $failures[] = $message;
}

function bm_smoke_relative(string $root, string $path): string
{
    return str_replace('\\', '/', substr($path, strlen($root) + 1));
}

function bm_smoke_files(string $root): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($iterator as $item) {
        if ($item->isFile()) {
            $files[] = $item->getPathname();
        }
    }

    sort($files);
    return $files;
}

$rootVersion = trim((string)@file_get_contents($root . '/VERSION'));
$privateVersion = trim((string)@file_get_contents($root . '/_bonumark_stream/VERSION'));
$packagePath = $root . '/_bonumark_stream/PACKAGE.json';
$package = is_file($packagePath) ? json_decode((string)file_get_contents($packagePath), true) : null;

if ($rootVersion === '') {
    bm_smoke_fail($failures, 'Root VERSION is missing or empty.');
}
if ($privateVersion === '') {
    bm_smoke_fail($failures, 'Private VERSION is missing or empty.');
}
if ($rootVersion !== '' && $privateVersion !== '' && $rootVersion !== $privateVersion) {
    bm_smoke_fail($failures, 'Root VERSION and private VERSION do not match.');
}
if (!is_array($package)) {
    bm_smoke_fail($failures, 'PACKAGE.json is missing or invalid.');
} elseif (($package['version'] ?? '') !== $rootVersion) {
    bm_smoke_fail($failures, 'PACKAGE.json version does not match VERSION.');
}
if (is_array($package) && (($package['license'] ?? '') !== 'AGPL-3.0-or-later')) {
    bm_smoke_fail($failures, 'PACKAGE.json license must be AGPL-3.0-or-later.');
}

$license = @file_get_contents($root . '/LICENSE') ?: '';
if (!str_contains($license, 'GNU AFFERO GENERAL PUBLIC LICENSE')) {
    bm_smoke_fail($failures, 'LICENSE does not contain the AGPLv3 license text.');
}
if (!str_contains($license, 'SPDX-License-Identifier: AGPL-3.0-or-later')) {
    bm_smoke_fail($failures, 'LICENSE does not contain the project SPDX notice.');
}

$configSample = @file_get_contents($root . '/_bonumark_stream/config.sample.php') ?: '';
if ($rootVersion !== '' && !str_contains($configSample, "'version' => '" . $rootVersion . "'")) {
    bm_smoke_fail($failures, 'config.sample.php does not contain the current version.');
}

$functionDefaults = @file_get_contents($root . '/_bonumark_stream/app/functions.php') ?: '';
if ($rootVersion !== '' && !str_contains($functionDefaults, "'version' => '" . $rootVersion . "'")) {
    bm_smoke_fail($failures, 'functions.php default config does not contain the current version.');
}

$changelog = @file_get_contents($root . '/_bonumark_stream/CHANGELOG.md') ?: '';
if ($rootVersion !== '' && !str_contains($changelog, '## ' . $rootVersion . ' - ')) {
    bm_smoke_fail($failures, 'CHANGELOG.md does not include the current version heading.');
}

$readme = @file_get_contents($root . '/README.md') ?: '';
if ($rootVersion !== '' && !str_contains($readme, 'Current version: **' . $rootVersion . '**')) {
    bm_smoke_fail($failures, 'README.md current version is stale.');
}

$migrationDir = $root . '/_bonumark_stream/migrations';
$migrationFiles = glob($migrationDir . '/*.php') ?: [];
sort($migrationFiles);
$lastNumber = 0;
foreach ($migrationFiles as $file) {
    $base = basename($file);
    if (!preg_match('/^(\d{4})_[a-z0-9_]+\.php$/', $base, $match)) {
        bm_smoke_fail($failures, 'Migration filename is invalid: ' . $base);
        continue;
    }
    $number = (int)$match[1];
    if ($lastNumber > 0 && $number !== $lastNumber + 1) {
        bm_smoke_fail($failures, 'Migration sequence gap before: ' . $base);
    }
    $lastNumber = $number;

    $migration = require $file;
    if (!is_array($migration) || array_values($migration) !== $migration) {
        bm_smoke_fail($failures, 'Migration must return a numeric list: ' . $base);
        continue;
    }
    foreach ($migration as $statement) {
        if (!is_string($statement)) {
            bm_smoke_fail($failures, 'Migration statement is not a string: ' . $base);
        }
    }
}

$manifestPath = $root . '/_bonumark_stream/RELEASE-MANIFEST.json';
$manifest = is_file($manifestPath) ? json_decode((string)file_get_contents($manifestPath), true) : null;
if (!is_array($manifest) || !isset($manifest['files']) || !is_array($manifest['files'])) {
    bm_smoke_fail($failures, 'Release manifest is missing or invalid.');
} else {
    $manifestFiles = [];
    foreach ($manifest['files'] as $entry) {
        $relative = str_replace('\\', '/', (string)($entry['path'] ?? ''));
        $hash = strtolower((string)($entry['sha256'] ?? ''));
        if ($relative === '' || !preg_match('/^[a-f0-9]{64}$/', $hash)) {
            bm_smoke_fail($failures, 'Release manifest contains an invalid entry.');
            continue;
        }
        $path = $root . '/' . $relative;
        if (!is_file($path)) {
            bm_smoke_fail($failures, 'Release manifest references a missing file: ' . $relative);
            continue;
        }
        if (!hash_equals($hash, hash_file('sha256', $path))) {
            bm_smoke_fail($failures, 'Release manifest hash mismatch: ' . $relative);
        }
        $manifestFiles[$relative] = true;
    }

    foreach (bm_smoke_files($root) as $path) {
        $relative = bm_smoke_relative($root, $path);
        if ($relative === '_bonumark_stream/RELEASE-MANIFEST.json') {
            continue;
        }
        if (!isset($manifestFiles[$relative])) {
            bm_smoke_fail($failures, 'Package file is not listed in release manifest: ' . $relative);
        }
    }
}

foreach (glob($root . '/_bonumark_stream/themes/*/theme.json') ?: [] as $themeManifest) {
    $theme = json_decode((string)file_get_contents($themeManifest), true);
    $themeName = basename(dirname($themeManifest));
    if (!is_array($theme)) {
        bm_smoke_fail($failures, 'Theme manifest is invalid: ' . $themeName);
        continue;
    }
    foreach (['name', 'version', 'assets'] as $required) {
        if (empty($theme[$required])) {
            bm_smoke_fail($failures, 'Theme manifest missing ' . $required . ': ' . $themeName);
        }
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname($themeManifest), FilesystemIterator::SKIP_DOTS)) as $themeFile) {
        if ($themeFile instanceof SplFileInfo && strtolower($themeFile->getExtension()) === 'php') {
            bm_smoke_fail($failures, 'Theme package contains PHP code: ' . $themeName . '/' . $themeFile->getFilename());
        }
    }
    foreach (['templates', 'view_slots', 'required_templates'] as $legacyThemeKey) {
        if (array_key_exists($legacyThemeKey, $theme)) {
            bm_smoke_fail($failures, 'Theme manifest contains a legacy layout key: ' . $themeName);
        }
    }
}

foreach (bm_smoke_files($root) as $path) {
    $relative = bm_smoke_relative($root, $path);
    $contents = (string)file_get_contents($path);

    $conflictPattern = '/<' . '<<<<<<|=' . '======|>' . '>>>>>>/';
    if ($relative !== 'scripts/smoke-test.php' && preg_match($conflictPattern, $contents)) {
        bm_smoke_fail($failures, 'Merge conflict marker found: ' . $relative);
    }

    $markerPattern = '/\b(' . 'TODO' . '|' . 'FIXME' . ')\b/';
    if ($relative !== 'scripts/smoke-test.php' && preg_match($markerPattern, $contents)) {
        bm_smoke_fail($failures, 'Unresolved development marker found: ' . $relative);
    }

    if (preg_match('/\b(var_dump|print_r)\s*\(/', $contents)) {
        bm_smoke_fail($failures, 'Debug output call found: ' . $relative);
    }

    if (str_ends_with($relative, '.css')) {
        $open = substr_count($contents, '{');
        $close = substr_count($contents, '}');
        if ($open !== $close) {
            bm_smoke_fail($failures, 'CSS brace mismatch: ' . $relative);
        }
    }
}

$forbiddenPaths = [
    '_bonumark_stream/config.php',
    '_bonumark_stream/installed.lock',
    'index.html',
    'feed.xml',
];
foreach ($forbiddenPaths as $relative) {
    if (file_exists($root . '/' . $relative)) {
        bm_smoke_fail($failures, 'Runtime file should not be packaged: ' . $relative);
    }
}

if ($failures !== []) {
    fwrite(STDERR, "Bonumark smoke test failed:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, '- ' . $failure . "\n");
    }
    exit(1);
}

echo 'Bonumark smoke test passed for version ' . $rootVersion . PHP_EOL;

<?php
/**
 * Bonumark Stream package smoke test.
 *
 * This script validates package metadata, migrations, release manifest hashes,
 * theme manifests, CSS brace balance, and common release hygiene rules.
 *
 * Database-backed smoke tests are intentionally separate because they require
 * real BMS_DB_* environment variables and BMS_DB_DANGER_RESET=1. That keeps
 * the package smoke test from touching a live database by accident.
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
$checkReleaseManifest = !in_array('--source-tree', $argv ?? [], true);

if (is_file($root . '/_bonumark_stream/installed.lock') || is_file($root . '/_bonumark_stream/config.php')) {
    fwrite(STDERR, "Bonumark package smoke test is for a clean source/release tree, not an installed site. Use Admin > System Check for live installation diagnostics.\n");
    exit(2);
}

$failures = [];
$functionsSource = @file_get_contents($root . '/_bonumark_stream/app/functions.php') ?: '';
$databaseSource = @file_get_contents($root . '/_bonumark_stream/app/database.php') ?: '';

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

function bm_smoke_render_layout_fixture(string $fixtureRoot, string $surface, array $data, array $theme): ?string
{
    $surface = strtolower(trim($surface));
    $schema = (int)($theme['layout_schema'] ?? 0);
    $layouts = is_array($theme['layouts'] ?? null) ? $theme['layouts'] : [];
    $reference = bms_theme_layout_reference((string)($layouts[$surface] ?? ''));
    if ($surface === '' || $schema < 1 || $reference === '') {
        return null;
    }

    $path = rtrim($fixtureRoot, '/\\') . '/' . $reference;
    if (!is_file($path)) {
        return null;
    }
    $document = json_decode((string)file_get_contents($path), true);
    if (!is_array($document) || bms_theme_layout_document_errors($document, $surface, $schema) !== []) {
        return null;
    }
    $root = $document['root'] ?? null;
    if (!is_array($root)) {
        return null;
    }
    return bms_render_public_theme_layout_node($root, $surface, $schema, $data, true);
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

if (is_array($package) && (($package['package_type'] ?? '') !== 'self-hosted')) {
    bm_smoke_fail($failures, 'PACKAGE.json package_type must describe Bonumark as self-hosted rather than a single hosting environment.');
}
if (is_array($package)) {
    $databaseMinimums = is_array($package['database_minimums'] ?? null) ? $package['database_minimums'] : [];
    if (($databaseMinimums['mysql'] ?? '') !== '8.0.0' || ($databaseMinimums['mariadb'] ?? '') !== '10.6.0') {
        bm_smoke_fail($failures, 'PACKAGE.json database_minimums must match the documented MySQL/MariaDB compatibility floors.');
    }
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

$publicChangelog = @file_get_contents($root . '/CHANGELOG.md') ?: '';
$releaseNotesPath = $root . '/docs/releases/v' . $rootVersion . '.md';
$releaseNotes = is_file($releaseNotesPath) ? (string)file_get_contents($releaseNotesPath) : '';
$serviceWorkerSource = @file_get_contents($root . '/sw.js') ?: '';
if ($rootVersion !== '' && !str_contains($publicChangelog, '## ' . $rootVersion . ' - ')) {
    bm_smoke_fail($failures, 'Public CHANGELOG.md does not include the current version heading.');
}
if ($rootVersion !== '' && ($releaseNotes === '' || !str_contains($releaseNotes, '# Bonumark Stream v' . $rootVersion . ':'))) {
    bm_smoke_fail($failures, 'Current-version GitHub release notes are missing or stale.');
}
if ($rootVersion !== '' && !str_contains($serviceWorkerSource, "bonumark-stream-static-v{$rootVersion}")) {
    bm_smoke_fail($failures, 'Service-worker cache identity does not contain the current version.');
}

$installerSource = @file_get_contents($root . '/install.php') ?: '';
$upgradeSourceEarly = @file_get_contents($root . '/admin/upgrade.php') ?: '';
$upgraderSource = @file_get_contents($root . '/_bonumark_stream/app/upgrader.php') ?: '';
$ownerUpgradeSource = @file_get_contents($root . '/scripts/deploy-update.php') ?: '';
if (!str_contains($installerSource, "\$pdo->exec(\"SET time_zone = '+00:00'\");")) {
    bm_smoke_fail($failures, 'Fresh installer database connection must force a UTC session before schema and seed writes.');
}
if (!str_contains($installerSource, "'email_verified_at' => gmdate('Y-m-d H:i:s')")) {
    bm_smoke_fail($failures, 'Fresh installer Admin verification timestamp must be explicit UTC.');
}

$runtimeDirectoryTokens = [
    'function bms_runtime_directory_definitions(): array',
    'function bms_runtime_directory_status(): array',
    'function bms_ensure_runtime_directories(): array',
    "'_bonumark_stream/tmp'",
    "'_bonumark_stream/content/versions'",
    "'_bonumark_stream/import-staging'",
    "'_bonumark_stream/import-staging/previews'",
    "'media'",
];
foreach ($runtimeDirectoryTokens as $runtimeDirectoryToken) {
    if (!str_contains($functionsSource, $runtimeDirectoryToken)) {
        bm_smoke_fail($failures, 'Runtime filesystem capability contract is missing: ' . $runtimeDirectoryToken);
    }
}
if (!str_contains($installerSource, 'bms_ensure_runtime_directories()')
    || str_contains($installerSource, "foreach (['content/import-markdown', 'content/versions', 'backups/upgrades'")) {
    bm_smoke_fail($failures, 'Fresh installer is not using the centralized runtime-directory capability contract.');
}
if (!str_contains($functionsSource, 'foreach (bms_runtime_directory_status() as $directory)')) {
    bm_smoke_fail($failures, 'System Check security status is not using the centralized read-only runtime-directory capability contract.');
}

if (!str_contains($upgradeSourceEarly, 'bms_ensure_runtime_directories()') || !str_contains($upgradeSourceEarly, 'Upgrade completed, but some runtime storage still needs hosting attention:')) {
    bm_smoke_fail($failures, 'Completed upgrades do not provision or report the centralized runtime-directory capability contract.');
}

foreach ([
    'function bms_package_managed_software_path(string $relative): bool',
    'function bms_installed_release_manifest_paths(): array',
    'function bms_automatic_upgrade_capability(?array $relativePaths = null): array',
] as $requiredUpgradeCapabilityFunction) {
    if (!str_contains($functionsSource, $requiredUpgradeCapabilityFunction)) {
        bm_smoke_fail($failures, 'Upgrade capability foundation is missing: ' . $requiredUpgradeCapabilityFunction);
    }
}
if (!str_contains($functionsSource, "'label' => 'Web-based software upgrades'")
    || !str_contains($upgraderSource, "'automatic_upgrade' =>")
    || !str_contains($upgraderSource, 'This PHP process cannot safely replace package-managed application files.')
    || !str_contains($upgradeSourceEarly, 'Web-based software upgrades are unavailable on this installation.')
    || !str_contains($upgradeSourceEarly, 'php scripts/deploy-update.php /path/to/release.zip')
    || !str_contains($upgradeSourceEarly, "empty(\$precheck['backup_ready']) || !\$precheckAutomatic ? 'disabled' : ''")) {
    bm_smoke_fail($failures, 'Admin/System Check web-upgrade capability reporting or owner-run CLI handoff is incomplete.');
}
if (!str_contains($upgraderSource, '$changedPackageFiles = [];')
    || !str_contains($upgraderSource, '$removedDuringInstall = [];')
    || !str_contains($upgraderSource, 'function bms_upgrade_restore_changed_software(')
    || !str_contains($upgraderSource, 'No rollback was necessary.')
    || str_contains($upgraderSource, 'bms_upgrade_restore_backup($softwareItems, $backupRoot, $publicRoot)')) {
    bm_smoke_fail($failures, 'Shared pre-migration upgrade recovery is not limited to software actually changed by the attempt.');
}
if (!str_contains($upgradeSourceEarly, "require_once __DIR__ . '/../_bonumark_stream/app/upgrader.php';")
    || str_contains($upgradeSourceEarly, 'function bms_upgrade_safe_extract(')
    || !str_contains($upgraderSource, 'function bms_upgrade_safe_extract(')
    || !str_contains($upgraderSource, 'function bms_upgrade_install(')) {
    bm_smoke_fail($failures, 'Admin and CLI upgrade paths are not using one shared core upgrade engine.');
}
if (!str_contains($ownerUpgradeSource, "PHP_SAPI !== 'cli'")
    || !str_contains($ownerUpgradeSource, 'bms_upgrade_inspect_package($zipPath)')
    || !str_contains($ownerUpgradeSource, 'bms_upgrade_install($zipPath)')
    || !str_contains($ownerUpgradeSource, '--confirm-db-backup')
    || !str_contains($ownerUpgradeSource, '--site-root=')
    || !str_contains($ownerUpgradeSource, 'Refusing to run as root by default')
    || !str_contains($ownerUpgradeSource, 'Privilege escalation: NONE')
    || !str_contains($ownerUpgradeSource, "hash_equals('UPGRADE', strtoupper(\$answer))")
    || !str_contains($ownerUpgradeSource, 'capitalization does not matter')
    || !str_contains($ownerUpgradeSource, "require \$root . '/scripts/deployment-check.php';")) {
    bm_smoke_fail($failures, 'Owner-run CLI upgrade workflow is missing validation, privilege-boundary, migration-backup, or post-upgrade verification behavior.');
}

foreach ([
    'function bms_web_server_capability(): array',
    'function bms_php_ini_bytes(string $value): ?int',
    'function bms_upload_limit_capability(): array',
    'function bms_theme_zip_install_capability(): array',
    "'label' => 'Web server'",
    "'label' => 'cURL features'",
    "'label' => 'Theme ZIP installation'",
    "'label' => 'Media upload ceiling'",
    "'label' => 'Image processing'",
] as $hostingCapabilityToken) {
    if (!str_contains($functionsSource, $hostingCapabilityToken)) {
        bm_smoke_fail($failures, 'Hosting capability reporting is missing: ' . $hostingCapabilityToken);
    }
}

foreach ([
    'function bms_database_compatibility_requirements(): array',
    'function bms_database_server_info_from_version(string $rawVersion): array',
    'function bms_database_server_compatibility(PDO $pdo): array',
    'function bms_database_require_supported(PDO $pdo): array',
] as $databaseCompatibilityToken) {
    if (!str_contains($databaseSource, $databaseCompatibilityToken)) {
        bm_smoke_fail($failures, 'Database compatibility reporting is missing: ' . $databaseCompatibilityToken);
    }
}
if (!str_contains($functionsSource, "'label' => 'Database server compatibility'")) {
    bm_smoke_fail($failures, 'System Check does not report database server compatibility.');
}
if (!str_contains($installerSource, 'Database compatibility: MySQL 8.0+ or MariaDB 10.6+')
    || !str_contains($installerSource, 'bms_db_test_connection($db)')) {
    bm_smoke_fail($failures, 'Fresh installer does not advertise/enforce the documented database compatibility floor.');
}

$themeInstallAdmin = @file_get_contents($root . '/admin/theme-install.php') ?: '';
if (!str_contains($themeInstallAdmin, 'bms_theme_zip_install_capability()')
    || !str_contains($themeInstallAdmin, 'Theme ZIP installation requires manual deployment here.')
    || !str_contains($themeInstallAdmin, '$themeInstallAvailable ? \'\' : \'disabled\'')) {
    bm_smoke_fail($failures, 'Admin theme installer does not honor the hosting theme-install capability.');
}

$nginxDocsPath = $root . '/docs/server/NGINX.md';
$nginxConfigPath = $root . '/docs/server/bonumark-stream-nginx.conf';
$nginxDocs = @file_get_contents($nginxDocsPath) ?: '';
$nginxConfig = @file_get_contents($nginxConfigPath) ?: '';
if (!is_file($nginxDocsPath) || !is_file($nginxConfigPath)) {
    bm_smoke_fail($failures, 'Nginx deployment documentation/configuration is missing.');
} else {
    foreach ([
        'location ^~ /_bonumark_stream/',
        'location ^~ /scripts/',
        'fastcgi_param HTTP_AUTHORIZATION $http_authorization;',
        'client_max_body_size',
        '__bonumark_route=stream',
        '__bonumark_route=api_stream_posts',
        '__bonumark_route=profile',
        '__bonumark_route=page',
        '__bonumark_route=search',
    ] as $nginxToken) {
        if (!str_contains($nginxConfig, $nginxToken)) {
            bm_smoke_fail($failures, 'Nginx configuration is missing required routing/security token: ' . $nginxToken);
        }
    }
    if (!str_contains($nginxDocs, 'Admin → System Check') || !str_contains($nginxDocs, 'HTTP `403`')) {
        bm_smoke_fail($failures, 'Nginx documentation is missing live validation guidance.');
    }
}

$readmeSource = @file_get_contents($root . '/README.md') ?: '';
$installDocsEarly = @file_get_contents($root . '/docs/INSTALL.md') ?: '';
$upgradeDocsEarly = @file_get_contents($root . '/docs/UPGRADING.md') ?: '';
foreach ([$readmeSource, $installDocsEarly] as $requirementsSource) {
    if (!str_contains($requirementsSource, 'PHP cURL')
        || !str_contains($requirementsSource, 'ZipArchive')
        || !str_contains($requirementsSource, 'mbstring')
        || !str_contains($requirementsSource, 'Core requirements')) {
        bm_smoke_fail($failures, 'Hosting requirements do not distinguish core requirements from optional feature capabilities, including mbstring fallbacks.');
        break;
    }
}
$manualDeployDocs = @file_get_contents($root . '/docs/server/MANUAL-DEPLOYMENT.md') ?: '';
$manualThemeDocs = @file_get_contents($root . '/docs/server/MANUAL-THEME-DEPLOYMENT.md') ?: '';
if (!str_contains($upgradeDocsEarly, 'Owner-run CLI upgrade')
    || !str_contains($upgradeDocsEarly, 'php scripts/deploy-update.php')
    || !str_contains($upgradeDocsEarly, '--site-root=/path/to/live/bonumark')
    || !str_contains($manualDeployDocs, 'rsync -avnc')
    || !str_contains($manualDeployDocs, 'Do **not** add `--delete`')
    || !str_contains($manualDeployDocs, 'php scripts/deployment-check.php')
    || !str_contains($manualDeployDocs, 'no privileged deployment helper')) {
    bm_smoke_fail($failures, 'Owner-run and manual locked-tree software deployment documentation is incomplete.');
}
if (!str_contains($manualThemeDocs, '_bonumark_stream/themes/<slug>/')
    || !str_contains($manualThemeDocs, 'assets/themes/<slug>/')
    || !str_contains($manualThemeDocs, 'Theme Health')) {
    bm_smoke_fail($failures, 'Manual locked-tree theme deployment documentation is incomplete.');
}
$deploymentCheckSource = @file_get_contents($root . '/scripts/deployment-check.php') ?: '';
if (!str_contains($deploymentCheckSource, 'Read-only installed-site deployment check')
    || !str_contains($deploymentCheckSource, 'bms_database_server_compatibility')
    || !str_contains($deploymentCheckSource, 'bms_runtime_directory_definitions()')
    || !str_contains($deploymentCheckSource, 'Package-managed file integrity')
    || !str_contains($deploymentCheckSource, 'Admin > System Check remains authoritative')) {
    bm_smoke_fail($failures, 'Installed-site deployment-check helper is missing or incomplete.');
}

$manualMigrationSource = @file_get_contents($root . '/scripts/run-migrations.php') ?: '';
if (!str_contains($databaseSource, 'function bms_pending_migration_names(')
    || !str_contains($databaseSource, 'function bms_record_manual_upgrade_history(')
    || !str_contains($manualMigrationSource, '--confirm-backup')
    || !str_contains($manualMigrationSource, 'bms_write_upgrade_recovery_state')
    || !str_contains($manualMigrationSource, 'bms_run_migrations($fromVersion)')
    || !str_contains($manualMigrationSource, "PHP_SAPI !== 'cli'")) {
    bm_smoke_fail($failures, 'Owner-run manual migration workflow is missing or incomplete.');
}
if (!str_contains($deploymentCheckSource, 'Pending database migrations:')
    || !str_contains($deploymentCheckSource, 'Obsolete package files: PASS')
    || !str_contains($deploymentCheckSource, 'bms_deployment_obsolete_package_files')
    || !str_contains($deploymentCheckSource, 'if (!bms_package_managed_software_path($relative))')) {
    bm_smoke_fail($failures, 'Installed-site deployment check does not cover pending migrations, obsolete package files, and the repository-only manifest boundary.');
}
if (!str_contains($manualDeployDocs, "--exclude='.github/'")
    || !str_contains($manualDeployDocs, 'repository-only `.github/` directory')) {
    bm_smoke_fail($failures, 'Manual deployment documentation does not preserve the repository-only .github boundary.');
}
if (!str_contains($functionsSource, "'label' => 'Database migration state'")
    || !str_contains($functionsSource, 'bms_pending_migration_names(bms_db())')) {
    bm_smoke_fail($failures, 'System Check does not report database migration state.');
}
if (!str_contains($installerSource, "private_storage_verified")
    || !str_contains($installerSource, 'Bonumark will not silently treat an inconclusive probe as protected.')
    || !str_contains($installerSource, 'I independently verified that')) {
    bm_smoke_fail($failures, 'Installer does not require explicit private-storage verification after an inconclusive probe.');
}
if (!str_contains($functionsSource, 'function bms_public_url_probe_response(')
    || !str_contains($functionsSource, 'function bms_probe_public_url_mode(')
    || !str_contains($functionsSource, "bms_url_path('api/v1/status')")
    || str_contains($functionsSource, "'message' => 'Stream permalink routing is active.'")) {
    bm_smoke_fail($failures, 'Public URL mode is not backed by a real read-only Bonumark clean-route probe.');
}

$publicUrlProbeCode = 'require ' . var_export($root . '/_bonumark_stream/app/functions.php', true) . '; '
    . '$values=['
    . 'bms_public_url_probe_response(200,json_encode(["ok"=>true,"api"=>"bonumark-stream"])), '
    . 'bms_public_url_probe_response(200,"not bonumark"), '
    . 'bms_public_url_probe_response(404,"")]; '
    . 'echo json_encode($values);';
$publicUrlProbeCommand = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($publicUrlProbeCode) . ' 2>/dev/null';
$publicUrlProbe = json_decode(trim((string)shell_exec($publicUrlProbeCommand)), true);
if (!is_array($publicUrlProbe)
    || ($publicUrlProbe[0]['status'] ?? '') !== 'pass'
    || ($publicUrlProbe[1]['status'] ?? '') !== 'fail'
    || ($publicUrlProbe[2]['status'] ?? '') !== 'fail') {
    bm_smoke_fail($failures, 'Public URL probe response classifier failed expected pass/fail cases.');
}

$privateHtaccess = @file_get_contents($root . '/_bonumark_stream/.htaccess') ?: '';
$scriptsHtaccess = @file_get_contents($root . '/scripts/.htaccess') ?: '';
foreach ([$privateHtaccess, $scriptsHtaccess] as $denyHtaccess) {
    if (!str_contains($denyHtaccess, '<IfModule mod_authz_core.c>')
        || !str_contains($denyHtaccess, '<IfModule !mod_authz_core.c>')
        || !str_contains($denyHtaccess, 'Require all denied')
        || !str_contains($denyHtaccess, 'Deny from all')) {
        bm_smoke_fail($failures, 'Private/script Apache deny rules are not compatible across authorization directive generations.');
        break;
    }
}

$compatibilityDocs = @file_get_contents($root . '/docs/COMPATIBILITY.md') ?: '';
$compatibilityWorkflow = @file_get_contents($root . '/.github/workflows/compatibility.yml') ?: '';
if (!str_contains($compatibilityDocs, 'PHP 8.1')
    || !str_contains($compatibilityDocs, 'MySQL 8.0')
    || !str_contains($compatibilityDocs, 'MariaDB 10.6')
    || !str_contains($compatibilityWorkflow, "mysql:8.0")
    || !str_contains($compatibilityWorkflow, "mariadb:10.6")
    || !str_contains($compatibilityWorkflow, 'php scripts/database-smoke-test.php')
    || !str_contains($compatibilityWorkflow, 'php scripts/migration-recovery-smoke-test.php')
    || !str_contains($compatibilityWorkflow, 'php scripts/api-database-smoke-test.php')) {
    bm_smoke_fail($failures, 'Compatibility documentation/CI matrix is missing the documented floor targets or database smoke and recovery tests.');
}
if (!str_contains($installDocsEarly, 'Fresh install on a locked-down application tree')
    || !str_contains($manualDeployDocs, 'php scripts/run-migrations.php --check')
    || !str_contains($manualDeployDocs, '--confirm-backup --from-version="$CURRENT_VERSION"')) {
    bm_smoke_fail($failures, 'Locked-down fresh-install or generic manual migration documentation is incomplete.');
}
if (str_contains($readmeSource, 'For the v0.6.0 release, an upgrade from the last public GitHub release')) {
    bm_smoke_fail($failures, 'README primary upgrade guidance still contains stale v0.6.0-specific instructions.');
}

$unguardedMbPatterns = [
    '$excerpt = mb_substr(',
    'return mb_strlen($source)',
    '$titleText = mb_substr(',
    'return mb_substr($text, 0, 156)',
    '$label = mb_substr(',
    '$safeError = mb_substr(',
    'if (mb_strlen($value',
];
foreach (bm_smoke_files($root) as $phpPath) {
    if (strtolower(pathinfo($phpPath, PATHINFO_EXTENSION)) !== 'php') {
        continue;
    }
    if (bm_smoke_relative($root, $phpPath) === 'scripts/smoke-test.php') {
        continue;
    }
    $phpSource = @file_get_contents($phpPath) ?: '';
    foreach ($unguardedMbPatterns as $pattern) {
        if (str_contains($phpSource, $pattern)) {
            bm_smoke_fail($failures, 'Potential unguarded mbstring dependency remains: ' . bm_smoke_relative($root, $phpPath) . ' (' . $pattern . ')');
        }
    }
}

$noMbProbeCode = 'require ' . var_export($root . '/_bonumark_stream/app/functions.php', true) . '; '
    . 'echo (function_exists("mb_strlen")?"mb-on":"mb-off") . "|" . bms_text_length("abc") . "|" . bms_text_substr("abcdef",0,3);';
$noMbProbeCommand = escapeshellarg(PHP_BINARY) . ' -n -r ' . escapeshellarg($noMbProbeCode) . ' 2>/dev/null';
$noMbProbe = trim((string)shell_exec($noMbProbeCommand));
if ($noMbProbe !== 'mb-off|3|abc') {
    bm_smoke_fail($failures, 'Core multibyte fallbacks did not work with php -n / mbstring unavailable. Got: ' . $noMbProbe);
}

$hostingProbeCode = 'require ' . var_export($root . '/_bonumark_stream/app/functions.php', true) . '; '
    . '$_SERVER["SERVER_SOFTWARE"]="nginx/1.24.0"; '
    . '$web=bms_web_server_capability(); '
    . '$sizes=[bms_php_ini_bytes("2M"),bms_php_ini_bytes("1G")]; '
    . 'echo json_encode(["family"=>$web["family"]??"","sizes"=>$sizes]);';
$hostingProbeCommand = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($hostingProbeCode) . ' 2>/dev/null';
$hostingProbe = json_decode(trim((string)shell_exec($hostingProbeCommand)), true);
if (!is_array($hostingProbe)
    || ($hostingProbe['family'] ?? '') !== 'nginx'
    || ($hostingProbe['sizes'][0] ?? 0) !== 2097152
    || ($hostingProbe['sizes'][1] ?? 0) !== 1073741824) {
    bm_smoke_fail($failures, 'Hosting capability helper probe failed for Nginx detection or PHP ini byte parsing.');
}

$databaseCompatibilityProbeCode = 'require ' . var_export($root . '/_bonumark_stream/app/database.php', true) . '; '
    . '$values=['
    . 'bms_database_server_info_from_version("8.0.36-0ubuntu0.22.04.1"),'
    . 'bms_database_server_info_from_version("5.5.5-10.11.8-MariaDB-0+deb12u1"),'
    . 'bms_database_server_info_from_version("5.7.44-log"),'
    . 'bms_database_server_info_from_version("10.5.27-MariaDB")];'
    . 'echo json_encode($values);';
$databaseCompatibilityProbeCommand = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($databaseCompatibilityProbeCode) . ' 2>/dev/null';
$databaseCompatibilityProbe = json_decode(trim((string)shell_exec($databaseCompatibilityProbeCommand)), true);
if (!is_array($databaseCompatibilityProbe)
    || ($databaseCompatibilityProbe[0]['family'] ?? '') !== 'mysql'
    || empty($databaseCompatibilityProbe[0]['supported'])
    || ($databaseCompatibilityProbe[1]['family'] ?? '') !== 'mariadb'
    || ($databaseCompatibilityProbe[1]['version'] ?? '') !== '10.11.8'
    || empty($databaseCompatibilityProbe[1]['supported'])
    || !empty($databaseCompatibilityProbe[2]['supported'])
    || !empty($databaseCompatibilityProbe[3]['supported'])) {
    bm_smoke_fail($failures, 'Database compatibility parser failed MySQL/MariaDB supported/unsupported version probes.');
}

$quickEditEndpoint = @file_get_contents($root . '/admin/stream-quick-edit.php') ?: '';
$cardTemplate = @file_get_contents($root . '/_bonumark_stream/app/views/default/templates/card.php') ?: '';
$streamCardComponentDir = $root . '/_bonumark_stream/app/views/default/components/stream-card';
$streamCardComponentFiles = glob($streamCardComponentDir . '/*.php') ?: [];
sort($streamCardComponentFiles);
$streamCardComponentsSource = '';
foreach ($streamCardComponentFiles as $streamCardComponentFile) {
    $streamCardComponentsSource .= "
" . (@file_get_contents($streamCardComponentFile) ?: '');
}
$streamCardPublicSource = $cardTemplate . "
" . $streamCardComponentsSource;
$streamScript = @file_get_contents($root . '/assets/stream.js') ?: '';
$streamTrashEndpoint = @file_get_contents($root . '/admin/stream-trash.php') ?: '';
if (!str_contains($quickEditEndpoint, "bms_record_revision_from_page(\$page, 'published'") || !str_contains($quickEditEndpoint, 'bms_update_stream_post_body($page, $body)')) {
    bm_smoke_fail($failures, 'Front-end Quick edit endpoint must archive the published revision and use the body-only database updater.');
}
if (!str_contains($streamCardPublicSource, 'data-stream-quick-edit-open') || !str_contains($streamCardPublicSource, 'Open full editor')) {
    bm_smoke_fail($failures, 'Public card template is missing Quick edit or full-editor actions.');
}
if (!str_contains($streamScript, 'function setupQuickEdits(root)') || !str_contains($streamScript, 'setupQuickEdits(feed)')) {
    bm_smoke_fail($failures, 'Stream JavaScript is missing front-end Quick edit or Load More initialization.');
}
if (!str_contains($streamTrashEndpoint, 'bms_delete_content_file(\'published\', $file)')
    || !str_contains($streamTrashEndpoint, 'bms_current_user_can(\'edit_content\', $subject)')
    || !str_contains($streamTrashEndpoint, 'hash_equals((string)($_SESSION[\'csrf_token\'] ?? \'\'), $token)')) {
    bm_smoke_fail($failures, 'Front-end Move to trash endpoint is missing recoverable deletion, post-specific permission, or CSRF enforcement.');
}
if (!str_contains($streamCardPublicSource, 'data-stream-trash-form') || !str_contains($streamCardPublicSource, 'Move to trash')) {
    bm_smoke_fail($failures, 'Public card template is missing the front-end Move to trash action.');
}
if (!str_contains($streamScript, 'function setupStreamTrash(root)') || !str_contains($streamScript, 'setupStreamTrash(feed)')) {
    bm_smoke_fail($failures, 'Stream JavaScript is missing front-end Trash confirmation or Load More initialization.');
}
$profilesSource = @file_get_contents($root . '/_bonumark_stream/app/profiles.php') ?: '';
$routesSource = @file_get_contents($root . '/_bonumark_stream/app/routes.php') ?: '';
$systemFunctionsSource = @file_get_contents($root . '/_bonumark_stream/app/functions.php') ?: '';
if (!str_contains($systemFunctionsSource, 'function bms_readonly_http_probe(')
    || !str_contains($systemFunctionsSource, "\$probeUrl = \$baseUrl . '/_bonumark_stream/VERSION';")
    || str_contains($systemFunctionsSource, 'security-probe-')) {
    bm_smoke_fail($failures, 'Private-folder exposure diagnostics must use a read-only known-file HTTP probe.');
}
if (!str_contains($routesSource, "\$rawSlug = trim((string)(\$_GET['slug'] ?? ''));")
    || !str_contains($routesSource, "\$slug = \$rawSlug !== '' ? bms_slugify(\$rawSlug) : '';")) {
    bm_smoke_fail($failures, 'Stream route must preserve an empty slug so /stream renders the archive instead of an untitled single-post lookup.');
}
$packageSmokeSource = @file_get_contents($root . '/scripts/smoke-test.php') ?: '';
if (!str_contains($packageSmokeSource, "is_file(\$root . '/_bonumark_stream/installed.lock')")
    || !str_contains($packageSmokeSource, 'Use Admin > System Check for live installation diagnostics.')) {
    bm_smoke_fail($failures, 'Package smoke test must refuse installed-site trees with a clear System Check handoff.');
}
$profileTemplate = @file_get_contents($root . '/_bonumark_stream/app/views/default/templates/profile.php') ?: '';
$profileTemplateHelpers = @file_get_contents($root . '/_bonumark_stream/app/views/default/templates/_helpers.php') ?: '';
$profileComponentDir = $root . '/_bonumark_stream/app/views/default/components/profile';
$profileComponentFiles = glob($profileComponentDir . '/*.php') ?: [];
sort($profileComponentFiles);
$profileComponentsSource = '';
foreach ($profileComponentFiles as $profileComponentFile) {
    $profileComponentsSource .= "
" . (@file_get_contents($profileComponentFile) ?: '');
}
$profilePublicSource = $profileTemplate . "
" . $profileComponentsSource;
$accountTemplate = @file_get_contents($root . '/_bonumark_stream/app/views/default/templates/account.php') ?: '';
$profileMigration = @file_get_contents($root . '/_bonumark_stream/migrations/0015_profile_identity_foundation.php') ?: '';
$profileFeaturedMigration = @file_get_contents($root . '/_bonumark_stream/migrations/0016_profile_featured_work.php') ?: '';
$profilePhotosMigration = @file_get_contents($root . '/_bonumark_stream/migrations/0017_profile_photos.php') ?: '';
$defaultThemeManifest = @file_get_contents($root . '/_bonumark_stream/themes/default/theme.json') ?: '';
$defaultThemeCss = @file_get_contents($root . '/_bonumark_stream/themes/default/assets/css/theme.css') ?: '';
$publicDefaultThemeCss = @file_get_contents($root . '/assets/themes/default/assets/css/theme.css') ?: '';
$rootHtaccess = @file_get_contents($root . '/.htaccess') ?: '';
$profileThemesSource = @file_get_contents($root . '/_bonumark_stream/app/themes.php') ?: '';
$profileSitemapSource = @file_get_contents($root . '/_bonumark_stream/app/sitemap.php') ?: '';
$profileRendererSource = @file_get_contents($root . '/_bonumark_stream/app/renderer.php') ?: '';
$profilePortabilitySource = @file_get_contents($root . '/_bonumark_stream/app/profile-portability.php') ?: '';
$profileExportEndpoint = @file_get_contents($root . '/profile-export.php') ?: '';

if (!str_contains($profileMigration, '`{{prefix}}user_profiles`')
    || !str_contains($profileMigration, '`headline` VARCHAR(180)')
    || !str_contains($profileMigration, '`about_markdown` MEDIUMTEXT')
    || !str_contains($profileMigration, '`links_json` MEDIUMTEXT')) {
    bm_smoke_fail($failures, 'Profile Identity migration is missing required identity storage.');
}
if (!str_contains($profilesSource, "bms_url_path('profile/' . rawurlencode(\$username))")) {
    bm_smoke_fail($failures, 'Public Profile URL helper must use the clean username route.');
}
if (!str_contains($profileFeaturedMigration, '`featured_items_json` LONGTEXT')
    || !str_contains($profilesSource, 'featured_items_json')
    || !str_contains($profilesSource, 'bms_profile_normalize_featured_input')
    || !str_contains($profilesSource, 'bms_profile_resolved_featured_items')) {
    bm_smoke_fail($failures, 'Profile Featured Work migration or semantic curation helpers are missing.');
}
if (!str_contains($profilesSource, "bms_find_database_content_by_slug_status(\$slug, 'published', \$type)")) {
    bm_smoke_fail($failures, 'Featured internal Profile items must resolve only published Stream posts or Pages.');
}
if (!str_contains($profilePhotosMigration, '`profile_photos_json` LONGTEXT')
    || !str_contains($profilesSource, 'profile_photos_json')
    || !str_contains($profilesSource, 'function bms_apply_current_user_profile_photos_from_request')
    || !str_contains($profilesSource, "'media/profile-photos/' . \$userId . '/'")
    || !str_contains($profilesSource, 'bms_media_privacy_store_upload')
    || !str_contains($profilesSource, 'bms_profile_photo_generate_variants')) {
    bm_smoke_fail($failures, 'Profile Gallery migration or Profile-owned image validation/privacy/derivative helpers are missing.');
}
if (!str_contains($profilesSource, 'return [240, 360, 480, 800, 1200];')
    || !str_contains($profilesSource, 'return [480, 640, 960, 1280, 1600];')
    || !str_contains($profilesSource, 'function bms_profile_image_variant_srcset(')
    || !str_contains($profilesSource, 'function bms_profile_modern_variant_srcset(')
    || !str_contains($profilesSource, 'function bms_profile_generate_modern_variant(')
    || !str_contains($profilesSource, 'function bms_profile_cover_picture_markup(')
    || !str_contains($profilesSource, 'function bms_profile_photo_picture_markup(')
    || !str_contains($profilesSource, 'function bms_profile_cover_preload_markup(')
    || !str_contains($profilesSource, "'loading' => 'eager'")
    || !str_contains($profilesSource, "'fetchpriority' => 'high'")
    || !str_contains($profilesSource, "'fetchpriority' => 'low'")
    || !str_contains($profilesSource, "'sizes'] = '(max-width: 760px) calc(100vw - 1rem), (max-width: 1120px) calc(100vw - 2rem), 1040px'")
    || !str_contains($profilesSource, "'loading' => 'lazy'")
    || !str_contains($profilesSource, "'sizes'] = 'auto, (max-width: 640px) calc(50vw - 1.25rem), (max-width: 1100px) calc(50vw - 2rem), 520px'")
    || !str_contains($profilesSource, '<source type="image/webp"')
    || !str_contains($profilesSource, 'imagesrcset=')
    || !str_contains($profilesSource, "'head_preload_html' => \$coverPreloadMarkup")
    || !str_contains($profileTemplateHelpers, "head_preload_html")
    || !str_contains($profileComponentsSource, "image_markup")
    || !str_contains((string)@file_get_contents($root . '/assets/style.css'), '.profile-photo-picture,')
    || !str_contains((string)@file_get_contents($root . '/assets/style.css'), '.profile-cover-picture')
    || !str_contains($profilesSource, "cover_image_path")) {
    bm_smoke_fail($failures, 'Profile image delivery optimization must expose responsive fallback candidates, Profile-only WebP picture sources, explicit cover preload/LCP priority, and lazy low-priority gallery delivery.');
}
if (str_contains($routesSource, 'bms_find_public_user_by_handle($username)')) {
    bm_smoke_fail($failures, 'Public Profile routing must not resolve display names as canonical handles.');
}
if (str_contains($profilePublicSource, "recent_posts") || str_contains($profilePublicSource, '>Stream posts<')) {
    bm_smoke_fail($failures, 'Public Profile must not duplicate the Stream with a recent-post activity section.');
}
foreach (['>About<', '>Featured<', '>Photos<', '>Now<', '>Interests<', '>Links<'] as $requiredProfileSection) {
    if (!str_contains($profilePublicSource, $requiredProfileSection)) {
        bm_smoke_fail($failures, 'Public Profile is missing identity section: ' . strip_tags($requiredProfileSection));
    }
}
if (!str_contains($accountTemplate, "account.php?section=profile")
    || !str_contains($accountTemplate, 'profile_links[label][]')
    || !str_contains($accountTemplate, '1600 × 600')) {
    bm_smoke_fail($failures, 'Focused Profile editor is missing its route, flexible links, or cover guidance.');
}
if (!str_contains($accountTemplate, 'data-profile-links-editor')
    || !str_contains($accountTemplate, 'data-profile-link-add')
    || !str_contains($accountTemplate, '>Profile settings<')
    || !str_contains($accountTemplate, 'class="profile-toggle-line"')) {
    bm_smoke_fail($failures, 'Profile editor polish is missing compact link controls, Profile settings, or aligned optional-detail controls.');
}
if (!str_contains($accountTemplate, '>Featured work<')
    || !str_contains($accountTemplate, 'data-profile-featured-editor')
    || !str_contains($accountTemplate, 'featured_items[type][]')
    || !str_contains($accountTemplate, 'featured_items[target][]')
    || !str_contains($accountTemplate, 'data-max-featured="4"')) {
    bm_smoke_fail($failures, 'Profile Featured Work editor is missing its deliberate four-item curation controls.');
}
if (!str_contains($accountTemplate, '>Photos<')
    || !str_contains($accountTemplate, 'data-profile-photos-editor')
    || !str_contains($accountTemplate, 'data-max-photos="4"')
    || !str_contains($accountTemplate, 'profile_photo_files[')
    || !str_contains($accountTemplate, 'data-profile-photo-up')
    || !str_contains($accountTemplate, 'data-profile-photo-down')
    || !str_contains($routesSource, 'bms_apply_current_user_profile_photos_from_request($_POST, $_FILES)')) {
    bm_smoke_fail($failures, 'Profile Gallery editor is missing upload, four-photo limit, ordering, removal, or save-route integration.');
}
if (!str_contains($profilePublicSource, 'profile-photo-gallery')
    || !str_contains($profilePublicSource, 'data-stream-media-viewer')
    || !str_contains($streamScript, "'.stream-media-gallery, .profile-photo-gallery'")
    || !str_contains($streamScript, 'function setupProfilePhotosEditor(root)')) {
    bm_smoke_fail($failures, 'Public Profile photos are missing semantic gallery markup, full-image viewer integration, or editor JavaScript.');
}
if (!str_contains($accountTemplate, '>Profile portability<')
    || !str_contains($accountTemplate, 'Download Profile ZIP')
    || !str_contains($accountTemplate, "profile.json")
    || !str_contains($routesSource, "'profile_export_url' =>")
    || !str_contains($profileExportEndpoint, 'bms_create_current_user_profile_export_zip()')) {
    bm_smoke_fail($failures, 'Profile portability is missing its owner-controlled editor surface or export endpoint.');
}
if (!str_contains($profilePortabilitySource, "'format' => 'bonumark-profile'")
    || !str_contains($profilePortabilitySource, "'format_version' => 1")
    || !str_contains($profilePortabilitySource, "'profile.json'")
    || !str_contains($profilePortabilitySource, "'profile.md'")
    || !str_contains($profilePortabilitySource, "'profile-media/'")
    || !str_contains($profilePortabilitySource, "'featured_items'")
    || !str_contains($profilePortabilitySource, "'photos'")
    || !str_contains($profilePortabilitySource, "'profile-media/photo-'")
    || !str_contains($profilePortabilitySource, "'optional_details'")) {
    bm_smoke_fail($failures, 'Profile portability package is missing the structured identity, Markdown, media, Featured Work, or preference export contract.');
}
foreach (['email', 'password_hash', 'role', 'login_attempts', 'api_tokens'] as $privateProfileExportField) {
    if (preg_match('/[\"\']' . preg_quote($privateProfileExportField, '/') . '[\"\']\s*=>/', $profilePortabilitySource) === 1) {
        bm_smoke_fail($failures, 'Profile portability must not export private account/security field: ' . $privateProfileExportField);
    }
}
if (!str_contains($profileExportEndpoint, "bms_verify_csrf();")
    || !str_contains($profileExportEndpoint, "if (!bms_is_logged_in())")
    || !str_contains($profileExportEndpoint, "Cache-Control: no-store, private")) {
    bm_smoke_fail($failures, 'Profile export endpoint must require login, verify CSRF, and prevent caching.');
}
if (!str_contains($routesSource, "is_array(\$_POST['featured_items'] ?? null) ? \$_POST['featured_items'] : []")) {
    bm_smoke_fail($failures, 'Profile save route is not carrying Featured Work input into identity normalization.');
}
if (!str_contains($profilesSource, 'if ($rows === [])')
    || str_contains($profilesSource, 'if (count($rows) < $rowCount)')
    || str_contains($profilesSource, 'while (count($rows) < $rowCount)')) {
    bm_smoke_fail($failures, 'Profile link form rows must show saved links only, with one starter row only when the Profile has no links.');
}
if (!str_contains($accountTemplate, '>Remove cover image<')
    || !str_contains($accountTemplate, '>Remove profile picture<')
    || !str_contains($accountTemplate, 'class="profile-about-textarea"')) {
    bm_smoke_fail($failures, 'Profile foundation cleanup is missing compact image-removal labels or the tightened About editor.');
}
if (!str_contains($streamScript, 'function setupProfileEditor(root)')
    || !str_contains($streamScript, "'[data-profile-link-add]'")
    || !str_contains($streamScript, "'[data-profile-link-remove]'")
    || !str_contains($streamScript, 'starterIsBlank')) {
    bm_smoke_fail($failures, 'Profile link editor JavaScript is missing Add Link, Remove, or empty-starter handling.');
}
if (!str_contains($streamScript, 'function setupProfilePhotosEditor(root)')
    || !str_contains($streamScript, "'[data-profile-photo-add]'")
    || !str_contains($streamScript, "'[data-profile-photo-remove]'")
    || !str_contains($streamScript, "'[data-profile-photo-up]'")
    || !str_contains($streamScript, "'[data-profile-photo-down]'")) {
    bm_smoke_fail($failures, 'Profile photo editor JavaScript is missing Add, Remove, or reorder controls.');
}
if (!str_contains($streamScript, 'function setupProfileFeaturedEditor(root)')
    || !str_contains($streamScript, "'[data-profile-featured-add]'")
    || !str_contains($streamScript, "'[data-profile-featured-remove]'")
    || !str_contains($streamScript, 'maxFeatured = 4')) {
    bm_smoke_fail($failures, 'Profile Featured Work JavaScript is missing Add, Remove, type sync, or four-item limit handling.');
}
if (!str_contains($defaultThemeCss, 'align-items: flex-start;')
    || !str_contains($publicDefaultThemeCss, 'align-items: flex-start;')) {
    bm_smoke_fail($failures, 'Bundled public shell must top-align the site grid so short pages do not stretch across the viewport.');
}
if (!str_contains($defaultThemeCss, '.profile-photo-gallery')
    || !str_contains($publicDefaultThemeCss, '.profile-photo-gallery')
    || !str_contains($defaultThemeCss, '.profile-photo-edit-row')
    || !str_contains($publicDefaultThemeCss, '.profile-photo-edit-row')) {
    bm_smoke_fail($failures, 'Bundled theme is missing Profile photo gallery or editor presentation rules.');
}
if (!str_contains($defaultThemeCss, '.profile-featured-item:last-child:nth-child(odd)')
    || !str_contains($defaultThemeCss, 'overflow-wrap: anywhere;')
    || !str_contains($publicDefaultThemeCss, '.profile-featured-item:last-child:nth-child(odd)')
    || !str_contains($publicDefaultThemeCss, 'overflow-wrap: anywhere;')) {
    bm_smoke_fail($failures, 'Bundled Featured cards are missing odd-item row balancing or long-text wrapping.');
}
if (str_contains($defaultThemeManifest, '"profile_layouts"')) {
    bm_smoke_fail($failures, 'Bundled theme must not advertise a universal Profile layout selector.');
}
if (str_contains($profilesSource, 'SELECT id, username, display_name, email')) {
    bm_smoke_fail($failures, 'Purpose-built public Profile queries must not select account email.');
}
if (!str_contains($profilesSource, 'function bms_profile_metadata_payload')
    || !str_contains($profilesSource, "'@type' => 'ProfilePage'")
    || !str_contains($profilesSource, "'@type' => 'Person'")
    || !str_contains($profilesSource, "\$person['sameAs'] = \$sameAs")
    || !str_contains($profilesSource, "\$person['knowsAbout'] = array_values(\$interests)")
    || !str_contains($profilesSource, "'seo_social_title' => \$socialTitle")
    || !str_contains($profilesSource, "'profile_metadata' => \$profileMetadata")) {
    bm_smoke_fail($failures, 'Profile Identity metadata payload is missing semantic ProfilePage/Person data, identity links, interests, or social-title handoff.');
}
if (!str_contains($profileThemesSource, 'function bms_inject_profile_identity_metadata_head')
    || !str_contains($profileThemesSource, 'twitter:card')
    || !str_contains($profileThemesSource, 'twitter:description')
    || !str_contains($profileThemesSource, 'og:image:alt')
    || !str_contains($profileThemesSource, 'profile:username')
    || !str_contains($profileThemesSource, 'data-bonumark-profile-metadata')) {
    bm_smoke_fail($failures, 'Theme-independent Profile head injection is missing Open Graph, Twitter, username, image-alt, or JSON-LD metadata.');
}
if (!str_contains($profileSitemapSource, 'function bms_sitemap_profile_lastmod')
    || !str_contains($profileSitemapSource, "contains(sitemap:loc, '/profile/')")
    || str_contains($profileSitemapSource, 'SELECT id, username, display_name, email')) {
    bm_smoke_fail($failures, 'Profile sitemap handling must use Profile identity modification time, recognize clean Profile routes, and avoid account email.');
}
if (!str_contains($profileRendererSource, 'property="article:author"')
    || !str_contains($profileRendererSource, 'bms_public_profile_url_for_user($authorUser)')) {
    bm_smoke_fail($failures, 'Published Stream metadata must reference the existing public author Profile when one is available.');
}
if (preg_match('#profile/[^\\s]+/stream#i', $rootHtaccess . "\n" . $routesSource) === 1) {
    bm_smoke_fail($failures, 'Profile Identity foundation must not add a per-profile Stream route.');
}

$readme = @file_get_contents($root . '/README.md') ?: '';
$upgradeDocs = @file_get_contents($root . '/docs/UPGRADING.md') ?: '';
foreach (['## v0.5.35 - Root RSS Discovery Hotfix', '## v0.5.37 - Local Places Composer Simplification Pass', '## Legacy timestamp display compatibility'] as $requiredUpgradeText) {
    if (!str_contains($upgradeDocs, $requiredUpgradeText)) {
        bm_smoke_fail($failures, 'Upgrade guide is missing required release-hardening text: ' . $requiredUpgradeText);
    }
}
if (str_contains($upgradeDocs, '## Scheduled Tasks run-history alignment')) {
    bm_smoke_fail($failures, 'Upgrade guide still contains the mislabeled v0.5.24 timestamp section.');
}

if ($rootVersion !== '' && !str_contains($readme, 'Current version: **' . $rootVersion . '**')) {
    bm_smoke_fail($failures, 'README.md current version is stale.');
}
if (preg_match('/^## v0\./m', $readme)) {
    bm_smoke_fail($failures, 'README.md contains release-history sections that belong in CHANGELOG.md.');
}
if (!str_contains($readme, '[CHANGELOG.md](CHANGELOG.md)')) {
    bm_smoke_fail($failures, 'README.md does not link to the complete release history.');
}

$adminUiGuidelines = @file_get_contents($root . '/docs/ADMIN-UI-GUIDELINES.md') ?: '';
$adminOperationsCss = @file_get_contents($root . '/assets/admin-operations.css') ?: '';
if (!str_contains($adminOperationsCss, '@media (min-width: 1081px)')
    || !str_contains($adminOperationsCss, 'body.admin-screen-upgrade .operations-workflow-rail .operations-fact-list > div')
    || !str_contains($adminOperationsCss, 'body.admin-screen-upgrade .operations-workflow-rail .operations-fact-list dd')
    || !str_contains($adminOperationsCss, 'grid-template-columns: minmax(0, 1fr);')
    || !str_contains($adminOperationsCss, 'text-align: left;')) {
    bm_smoke_fail($failures, 'Upgrade protected-data facts must stack and align left in the narrow desktop operations rail.');
}
$contributing = @file_get_contents($root . '/CONTRIBUTING.md') ?: '';
foreach ([
    '# Bonumark Stream Admin UI Guidelines',
    '## Use the closest established workflow',
    '## CSS ownership',
    '## Responsive behavior',
    '## Accessibility',
    '## Acceptance checklist',
] as $requiredAdminUiGuideline) {
    if (!str_contains($adminUiGuidelines, $requiredAdminUiGuideline)) {
        bm_smoke_fail($failures, 'Admin UI contract is missing required guidance: ' . $requiredAdminUiGuideline);
    }
}
if (!str_contains($adminUiGuidelines, 'Do not add new authenticated Admin workflow styling to `admin.css` by default.')) {
    bm_smoke_fail($failures, 'Admin UI contract does not protect the legacy admin.css compatibility boundary.');
}
if (!str_contains($readme, 'docs/ADMIN-UI-GUIDELINES.md')) {
    bm_smoke_fail($failures, 'README.md does not link to the Admin UI contract.');
}
if (!str_contains($contributing, 'docs/ADMIN-UI-GUIDELINES.md') || !str_contains($contributing, 'closest existing workflow')) {
    bm_smoke_fail($failures, 'CONTRIBUTING.md does not enforce the Admin UI contract or workflow-reference requirement.');
}

$installDocs = @file_get_contents($root . '/docs/INSTALL.md') ?: '';
$remotePostingDocs = @file_get_contents($root . '/docs/REMOTE-POSTING.md') ?: '';
if ($rootVersion !== '' && !str_contains($installDocs, 'Bonumark Stream v' . $rootVersion)) {
    bm_smoke_fail($failures, 'Install guide current version is stale.');
}
if ($rootVersion !== '' && !str_contains($upgradeDocs, 'Bonumark Stream v' . $rootVersion)) {
    bm_smoke_fail($failures, 'Upgrade guide current version is stale.');
}
if ($rootVersion !== '' && !str_contains($remotePostingDocs, 'Current status in v' . $rootVersion)) {
    bm_smoke_fail($failures, 'Remote Posting guide current version is stale.');
}

$composerTemplate = @file_get_contents($root . '/_bonumark_stream/app/views/default/templates/composer.php') ?: '';
$quickPostSource = @file_get_contents($root . '/admin/quick-post.php') ?: '';
$newPostRoute = @file_get_contents($root . '/admin/new.php') ?: '';
$adminLayoutSource = @file_get_contents($root . '/admin/_layout.php') ?: '';
foreach (['data-stream-action="draft"', 'data-stream-action="continue"', 'data-stream-more-menu', 'data-stream-advanced-panel', 'name="stream_slug"', 'name="stream_robots"'] as $requiredComposerText) {
    if (!str_contains($composerTemplate, $requiredComposerText)) {
        bm_smoke_fail($failures, 'Unified Stream composer is missing required control: ' . $requiredComposerText);
    }
}
foreach (["['publish', 'schedule', 'draft', 'continue']", 'bms_sync_stream_metadata($page, $targetSection', 'edit.php?type=draft&file='] as $requiredQuickPostText) {
    if (!str_contains($quickPostSource, $requiredQuickPostText)) {
        bm_smoke_fail($failures, 'Front composer save route is missing unified workflow behavior: ' . $requiredQuickPostText);
    }
}
if (str_contains($composerTemplate, 'stream-compose-secondary-actions') || str_contains($composerTemplate, '<summary>Advanced</summary>')) {
    bm_smoke_fail($failures, 'Front composer still exposes the cluttered v0.5.46 secondary action row or always-visible Advanced control.');
}
if (!str_contains($composerTemplate, 'data-stream-advanced-panel hidden') || !str_contains($composerTemplate, 'data-stream-advanced-toggle')) {
    bm_smoke_fail($failures, 'Advanced composer metadata is not hidden behind the compact More options workflow.');
}
if (!str_contains($newPostRoute, 'bms_stream_composer_url()') || str_contains($newPostRoute, 'bms_new_content_form')) {
    bm_smoke_fail($failures, 'admin/new.php is not a clean compatibility redirect to the stream composer.');
}
if (str_contains($adminLayoutSource, '>New Stream Post<') || !str_contains($adminLayoutSource, "'label' => 'Stream Composer'")) {
    bm_smoke_fail($failures, 'Admin navigation does not identify the stream composer as the creation surface.');
}

$streamSettings = @file_get_contents($root . '/admin/settings-reading.php') ?: '';
if (!str_contains($streamSettings, "bms_admin_header('Reading Settings'")
    || !str_contains($streamSettings, 'Control how the stream is read and discovered.')
    || !str_contains($streamSettings, 'Save Reading Settings')) {
    bm_smoke_fail($failures, 'Reading settings screen is missing the current workflow title or save action.');
}
if (str_contains($streamSettings, "bms_admin_header('Stream Settings'") || str_contains($streamSettings, 'Save Stream Settings')) {
    bm_smoke_fail($failures, 'Reading settings screen still contains the retired Stream Settings title.');
}

if (str_contains($streamSettings, 'name="stream_composer_enabled"')) {
    bm_smoke_fail($failures, 'Stream settings still expose an off switch for the canonical composer.');
}
$writingSettings = @file_get_contents($root . '/admin/settings-writing.php') ?: '';
if (str_contains($writingSettings, 'name="default_content_status"')) {
    bm_smoke_fail($failures, 'Writing settings still expose the retired backend-new-post default status.');
}
foreach (['admin/_layout.php', 'admin/welcome.php', 'admin/content.php', 'admin/index.php'] as $composerLinkFile) {
    $composerLinkSource = @file_get_contents($root . '/' . $composerLinkFile) ?: '';
    if (str_contains($composerLinkSource, "bms_admin_url('new.php')")) {
        bm_smoke_fail($failures, 'Admin UI still links to the retired new-post route: ' . $composerLinkFile);
    }
}

$apiDocs = @file_get_contents($root . '/docs/API.md') ?: '';
if ($rootVersion !== '' && !str_contains($apiDocs, '"version": "' . $rootVersion . '"')) {
    bm_smoke_fail($failures, 'API documentation response examples do not use the current version.');
}
if (!is_file($root . '/api/v1/stream/posts.php')) {
    bm_smoke_fail($failures, 'Remote stream posts API endpoint is missing.');
}
if (!is_file($root . '/api/v1/media.php')) {
    bm_smoke_fail($failures, 'Remote media API endpoint is missing.');
}
if (!is_file($root . '/api/v1/media/import.php')) {
    bm_smoke_fail($failures, 'Remote media import API endpoint is missing.');
}
if (!str_contains($apiDocs, 'POST /api/v1/stream/posts')) {
    bm_smoke_fail($failures, 'docs/API.md does not document the remote stream posts endpoint.');
}
if (!str_contains($apiDocs, 'GET /api/v1/stream/posts')) {
    bm_smoke_fail($failures, 'docs/API.md does not document the read-only stream posts endpoint.');
}
if (!str_contains($apiDocs, 'POST /api/v1/media')) {
    bm_smoke_fail($failures, 'docs/API.md does not document the remote media endpoint.');
}
if (!str_contains($apiDocs, 'POST /api/v1/media/import')) {
    bm_smoke_fail($failures, 'docs/API.md does not document the remote media import endpoint.');
}
if (!str_contains($apiDocs, 'placeholder_media_rejected')) {
    bm_smoke_fail($failures, 'docs/API.md does not document placeholder media rejection.');
}
if (!str_contains($apiDocs, 'media_uploads')) {
    bm_smoke_fail($failures, 'docs/API.md does not document embedded media upload fields for remote stream posts.');
}
if (substr_count($apiDocs, 'Uploaded media can still be used in a second request') > 1) {
    bm_smoke_fail($failures, 'docs/API.md contains duplicate uploaded-media second-request wording.');
}
if (!str_contains($apiDocs, 'embedded_media')) {
    bm_smoke_fail($failures, 'docs/API.md does not document embedded media in the remote post response.');
}
if (!str_contains($apiDocs, 'stream:publish')) {
    bm_smoke_fail($failures, 'docs/API.md does not document the stream:publish scope.');
}
if (!str_contains($apiDocs, 'Idempotency-Key')) {
    bm_smoke_fail($failures, 'docs/API.md does not document idempotency.');
}
if (!str_contains($apiDocs, 'media:upload')) {
    bm_smoke_fail($failures, 'docs/API.md does not document the media:upload scope.');
}
$openApiPath = $root . '/docs/openapi/bonumark-stream-api.json';
if (!is_file($openApiPath)) {
    bm_smoke_fail($failures, 'OpenAPI schema is missing.');
} else {
    $openApi = json_decode((string)file_get_contents($openApiPath), true);
    if (!is_array($openApi) || ($openApi['openapi'] ?? '') === '' || empty($openApi['paths']['/api/v1/stream/posts']) || empty($openApi['paths']['/api/v1/media']) || empty($openApi['paths']['/api/v1/media/import'])) {
        bm_smoke_fail($failures, 'OpenAPI schema is invalid or missing required API paths.');
    } else {
        if ($rootVersion !== '' && (string)($openApi['info']['version'] ?? '') !== $rootVersion) {
            bm_smoke_fail($failures, 'OpenAPI info.version does not match VERSION.');
        }
        foreach (($openApi['paths'] ?? []) as $path => $methods) {
            if (is_array($methods) && array_key_exists('head', $methods)) {
                bm_smoke_fail($failures, 'OpenAPI Action schema must not include HEAD operations: ' . $path);
            }
            if (is_array($methods)) {
                foreach ($methods as $method => $operation) {
                    if (is_array($operation) && strlen((string)($operation['description'] ?? '')) > 300) {
                        bm_smoke_fail($failures, 'OpenAPI operation description is over 300 characters: ' . $path . ' ' . $method);
                    }
                }
            }
        }
    }
}
if (!is_file($root . '/docs/CHATGPT-ACTIONS.md')) {
    bm_smoke_fail($failures, 'ChatGPT Actions documentation is missing.');
}
$clientDocsPath = $root . '/docs/REMOTE-POSTING-CLIENTS.md';
$clientDocs = @file_get_contents($clientDocsPath) ?: '';
if (!is_file($clientDocsPath)) {
    bm_smoke_fail($failures, 'Remote Posting client examples documentation is missing.');
} else {
    foreach (['PowerShell', 'curl', 'Python', 'GitHub Actions', 'Apple Shortcuts', 'Zapier Webhooks', 'Make HTTP module', 'IFTTT Webhooks', 'Generic no-code automation tools'] as $requiredClientSection) {
        if (!str_contains($clientDocs, '## ' . $requiredClientSection)) {
            bm_smoke_fail($failures, 'Remote Posting client examples are missing section: ' . $requiredClientSection);
        }
    }
    foreach (['GET /api/v1/stream/posts', 'POST /api/v1/stream/posts', 'POST /api/v1/media', 'media_import_url', 'Idempotency-Key', 'Authorization: Bearer YOUR_API_TOKEN_HERE'] as $requiredClientText) {
        if (!str_contains($clientDocs, $requiredClientText)) {
            bm_smoke_fail($failures, 'Remote Posting client examples are missing required text: ' . $requiredClientText);
        }
    }
}
if (!str_contains($readme, 'docs/REMOTE-POSTING-CLIENTS.md')) {
    bm_smoke_fail($failures, 'README.md does not link to Remote Posting client examples.');
}
if (!str_contains($apiDocs, 'docs/REMOTE-POSTING-CLIENTS.md')) {
    bm_smoke_fail($failures, 'docs/API.md does not link to Remote Posting client examples.');
}

$apiApp = @file_get_contents($root . '/_bonumark_stream/app/api.php') ?: '';
$indexPhp = @file_get_contents($root . '/index.php') ?: '';
$htaccess = @file_get_contents($root . '/.htaccess') ?: '';
$remotePostingDocs = @file_get_contents($root . '/docs/REMOTE-POSTING.md') ?: '';
$chatGptActionsDocs = @file_get_contents($root . '/docs/CHATGPT-ACTIONS.md') ?: '';
$apiDatabaseSmoke = @file_get_contents($root . '/scripts/api-database-smoke-test.php') ?: '';
$apiRouteFiles = [
    'api/v1/status.php' => 'bms_api_handle_status_endpoint();',
    'api/v1/stream/posts.php' => 'bms_api_handle_stream_posts_endpoint();',
    'api/v1/media.php' => 'bms_api_handle_media_endpoint();',
    'api/v1/media/import.php' => 'bms_api_handle_media_import_endpoint();',
];
foreach ($apiRouteFiles as $relative => $handlerCall) {
    $path = $root . '/' . $relative;
    $contents = @file_get_contents($path) ?: '';
    if (!is_file($path)) {
        bm_smoke_fail($failures, 'Required API route file is missing: ' . $relative);
        continue;
    }
    if (!str_contains($contents, "require_once __DIR__") || !str_contains($contents, '_bonumark_stream/app/api.php') || !str_contains($contents, $handlerCall)) {
        bm_smoke_fail($failures, 'API route file does not load the shared API handler correctly: ' . $relative);
    }
}
$requiredHtaccessRules = [
    'SetEnvIf Authorization "(.+)" HTTP_AUTHORIZATION=$1',
    'RewriteRule ^api/v1/status/?$ index.php?__bonumark_route=api_status [L,QSA]',
    'RewriteRule ^api/v1/stream/posts/?$ index.php?__bonumark_route=api_stream_posts [L,QSA]',
    'RewriteRule ^api/v1/media/import/?$ index.php?__bonumark_route=api_media_import [L,QSA]',
    'RewriteRule ^api/v1/media/?$ index.php?__bonumark_route=api_media [L,QSA]',
];
foreach ($requiredHtaccessRules as $requiredRule) {
    if (!str_contains($htaccess, $requiredRule)) {
        bm_smoke_fail($failures, '.htaccess is missing Remote API clean URL routing or Authorization passthrough: ' . $requiredRule);
    }
}
if (!str_contains($indexPhp, "['api_status', 'api_stream_posts', 'api_media', 'api_media_import']") || !str_contains($indexPhp, "require_once __DIR__ . '/_bonumark_stream/app/api.php'")) {
    bm_smoke_fail($failures, 'index.php does not dispatch Remote API routes before installed-site/public routing.');
}
foreach (['api_status' => 'bms_api_handle_status_endpoint();', 'api_stream_posts' => 'bms_api_handle_stream_posts_endpoint();', 'api_media' => 'bms_api_handle_media_endpoint();', 'api_media_import' => 'bms_api_handle_media_import_endpoint();'] as $routeName => $handlerCall) {
    if (!str_contains($indexPhp, "\$route === '{$routeName}'") || !str_contains($indexPhp, $handlerCall)) {
        bm_smoke_fail($failures, 'index.php is missing Remote API route dispatch for: ' . $routeName);
    }
}
foreach (['status:read', 'stream:read', 'stream:draft', 'stream:publish', 'media:upload'] as $requiredScope) {
    if (!str_contains($apiApp, "'" . $requiredScope . "' =>")) {
        bm_smoke_fail($failures, 'Remote API scope definition is missing: ' . $requiredScope);
    }
    if (!str_contains($apiDocs, $requiredScope) || !str_contains($remotePostingDocs, $requiredScope)) {
        bm_smoke_fail($failures, 'Remote API documentation is missing required scope: ' . $requiredScope);
    }
}
if (!str_contains($apiApp, "bms_api_query_choice('status', ['published'], 'published')")
    || !str_contains($apiApp, "AND status = :status LIMIT 1")
    || !str_contains($apiDocs, '`status`: `published` only')) {
    bm_smoke_fail($failures, 'The stream:read scope is not restricted to published Stream posts.');
}
$streamHandlerStart = strpos($apiApp, 'function bms_api_handle_stream_posts_endpoint(): never');
$mediaHandlerStart = strpos($apiApp, 'function bms_api_handle_media_endpoint(): never');
$mediaHandlerEnd = strpos($apiApp, 'function bms_api_handle_media_import_endpoint(): never');
if ($streamHandlerStart === false || $mediaHandlerStart === false || $mediaHandlerEnd === false || $mediaHandlerEnd <= $mediaHandlerStart) {
    bm_smoke_fail($failures, 'Remote API route handlers could not be inspected.');
} else {
    $streamHandler = substr($apiApp, $streamHandlerStart, $mediaHandlerStart - $streamHandlerStart);
    if (!str_contains($streamHandler, 'bms_api_handle_stream_posts_read_endpoint();')) {
        bm_smoke_fail($failures, 'Stream posts endpoint does not dispatch GET and HEAD requests to the read handler.');
    }
    $mediaHandler = substr($apiApp, $mediaHandlerStart, $mediaHandlerEnd - $mediaHandlerStart);
    if (str_contains($mediaHandler, 'bms_api_handle_stream_posts_read_endpoint')) {
        bm_smoke_fail($failures, 'Remote media endpoint incorrectly dispatches to the Stream read endpoint.');
    }
    if (!str_contains($mediaHandler, "header('Allow: POST')")) {
        bm_smoke_fail($failures, 'Remote media endpoint no longer advertises POST-only behavior.');
    }
}
foreach (['remote_posting_disabled', 'missing_bearer_token', 'invalid_bearer_token', 'missing_scope', 'publish_confirmation_required', 'idempotency_key_conflict'] as $requiredApiCode) {
    if (!str_contains($apiApp, $requiredApiCode)) {
        bm_smoke_fail($failures, 'Remote API code path is missing expected API error code: ' . $requiredApiCode);
    }
}
foreach (['Idempotency-Key', 'idempotency_key', 'client_request_id', 'idempotency_key_conflict'] as $requiredIdempotencyText) {
    if (!str_contains($apiDocs, $requiredIdempotencyText) || !str_contains($remotePostingDocs, $requiredIdempotencyText)) {
        bm_smoke_fail($failures, 'Remote API idempotency documentation is missing required text: ' . $requiredIdempotencyText);
    }
}
foreach (['POST /api/v1/media', 'POST /api/v1/media/import', 'media_uploads', 'media_imports', 'media_import_url', 'embedded_media', 'source_url'] as $requiredMediaText) {
    if (!str_contains($apiDocs, $requiredMediaText)) {
        bm_smoke_fail($failures, 'Remote API media upload/import documentation is missing required text: ' . $requiredMediaText);
    }
}
if (!str_contains($remotePostingDocs, 'POST /api/v1/media') || !str_contains($remotePostingDocs, 'POST /api/v1/media/import') || !str_contains($remotePostingDocs, 'media:upload')) {
    bm_smoke_fail($failures, 'Remote Posting documentation does not cover media upload/import behavior.');
}
if (!str_contains($chatGptActionsDocs, 'docs/REMOTE-POSTING-CLIENTS.md') || !str_contains($clientDocs, 'docs/API.md')) {
    bm_smoke_fail($failures, 'Remote Posting cross-document references are incomplete.');
}
if (!is_file($root . '/scripts/api-database-smoke-test.php')) {
    bm_smoke_fail($failures, 'Optional Remote API database smoke test script is missing.');
} else {
    foreach (['disabled_api', 'missing_token', 'invalid_token', 'stream_read', 'draft_create', 'publish_scope', 'publish_confirmation', 'media_scope', 'idempotency_replay', 'idempotency_conflict'] as $requiredScenario) {
        if (!str_contains($apiDatabaseSmoke, "'" . $requiredScenario . "'")) {
            bm_smoke_fail($failures, 'Optional Remote API database smoke test is missing scenario: ' . $requiredScenario);
        }
    }
    if (!str_contains($apiDatabaseSmoke, 'BMS_DB_DANGER_RESET=1') || !str_contains($apiDatabaseSmoke, 'bms_api_ci_')) {
        bm_smoke_fail($failures, 'Optional Remote API database smoke test must require explicit DB reset permission and use a temporary API table prefix.');
    }
}

$previewApp = @file_get_contents($root . '/_bonumark_stream/app/preview.php') ?: '';
$functionsApp = @file_get_contents($root . '/_bonumark_stream/app/functions.php') ?: '';
$rendererApp = @file_get_contents($root . '/_bonumark_stream/app/renderer.php') ?: '';
$appearanceApp = @file_get_contents($root . '/_bonumark_stream/app/appearance.php') ?: '';
$commentsApp = @file_get_contents($root . '/_bonumark_stream/app/comments.php') ?: '';
$cardTemplate = @file_get_contents($root . '/_bonumark_stream/app/views/default/templates/card.php') ?: '';
$headerTemplate = @file_get_contents($root . '/_bonumark_stream/app/views/default/templates/header.php') ?: '';
$homeTemplate = @file_get_contents($root . '/_bonumark_stream/app/views/default/templates/home.php') ?: '';
$homeComponentDir = $root . '/_bonumark_stream/app/views/default/components/home';
$homeComponentFiles = glob($homeComponentDir . '/*.php') ?: [];
sort($homeComponentFiles);
$homeComponentsSource = '';
foreach ($homeComponentFiles as $homeComponentFile) {
    $homeComponentsSource .= "\n" . (@file_get_contents($homeComponentFile) ?: '');
}
$siteHeaderComponentDir = $root . '/_bonumark_stream/app/views/default/components/site-header';
$siteHeaderComponentFiles = glob($siteHeaderComponentDir . '/*.php') ?: [];
sort($siteHeaderComponentFiles);
$siteHeaderComponentsSource = '';
foreach ($siteHeaderComponentFiles as $siteHeaderComponentFile) {
    $siteHeaderComponentsSource .= "
" . (@file_get_contents($siteHeaderComponentFile) ?: '');
}
$footerTemplate = @file_get_contents($root . '/_bonumark_stream/app/views/default/templates/footer.php') ?: '';
$streamJs = @file_get_contents($root . '/assets/stream.js') ?: '';
$adminCss = @file_get_contents($root . '/assets/admin.css') ?: '';
$adminContentListCss = @file_get_contents($root . '/assets/admin-content-list.css') ?: '';
$adminEditorWorkflowCss = @file_get_contents($root . '/assets/admin-editor-workflow.css') ?: '';
$adminMediaLibraryCss = @file_get_contents($root . '/assets/admin-media-library.css') ?: '';
$adminCommentsCss = @file_get_contents($root . '/assets/admin-comments.css') ?: '';
$adminAccountsCss = @file_get_contents($root . '/assets/admin-accounts.css') ?: '';
$adminRegistrationCss = @file_get_contents($root . '/assets/admin-registration.css') ?: '';
$adminAppearanceCss = @file_get_contents($root . '/assets/admin-appearance.css') ?: '';
$adminSettingsCss = @file_get_contents($root . '/assets/admin-settings.css') ?: '';
$adminPlacesCss = @file_get_contents($root . '/assets/admin-places.css') ?: '';
$adminOperationsCss = @file_get_contents($root . '/assets/admin-operations.css') ?: '';
$adminPlacesJs = @file_get_contents($root . '/assets/admin-places.js') ?: '';
$placesAdmin = @file_get_contents($root . '/admin/places.php') ?: '';
$placeEditAdmin = @file_get_contents($root . '/admin/place-edit.php') ?: '';
$placeDeleteAdmin = @file_get_contents($root . '/admin/place-delete.php') ?: '';
$placesApp = @file_get_contents($root . '/_bonumark_stream/app/places.php') ?: '';
$generalSettingsAdmin = @file_get_contents($root . '/admin/settings.php') ?: '';
$writingSettingsAdmin = @file_get_contents($root . '/admin/settings-writing.php') ?: '';
$readingSettingsAdmin = @file_get_contents($root . '/admin/settings-reading.php') ?: '';
$securitySettingsAdmin = @file_get_contents($root . '/admin/security.php') ?: '';
$mailSettingsAdmin = @file_get_contents($root . '/admin/mail.php') ?: '';
$remotePostingAdmin = @file_get_contents($root . '/admin/remote-posting.php') ?: '';
$scheduledTasksAdmin = @file_get_contents($root . '/admin/scheduled-tasks.php') ?: '';
$toolsAdmin = @file_get_contents($root . '/admin/tools.php') ?: '';
$importAdmin = @file_get_contents($root . '/admin/import.php') ?: '';
$importMarkdownAdmin = @file_get_contents($root . '/admin/import-markdown.php') ?: '';
$exportAdmin = @file_get_contents($root . '/admin/export.php') ?: '';
$upgradeAdmin = @file_get_contents($root . '/admin/upgrade.php') ?: '';
$systemCheckAdmin = @file_get_contents($root . '/admin/system-check.php') ?: '';
$analyticsAdmin = @file_get_contents($root . '/admin/analytics.php') ?: '';
$helpAdmin = @file_get_contents($root . '/admin/help.php') ?: '';
$themesAdmin = @file_get_contents($root . '/admin/theme.php') ?: '';
$themeSettingsAdmin = @file_get_contents($root . '/admin/theme-settings.php') ?: '';
$themeDetailsAdmin = @file_get_contents($root . '/admin/theme-details.php') ?: '';
$themeInstallAdmin = @file_get_contents($root . '/admin/theme-install.php') ?: '';
$themeDeleteAdmin = @file_get_contents($root . '/admin/theme-delete.php') ?: '';
$siteIdentityAdmin = @file_get_contents($root . '/admin/site-identity.php') ?: '';
$navigationAdmin = @file_get_contents($root . '/admin/navigation.php') ?: '';
$registrationAdmin = @file_get_contents($root . '/admin/registration.php') ?: '';
$commentsUi = @file_get_contents($root . '/admin/_comments-ui.php') ?: '';
$usersAdmin = @file_get_contents($root . '/admin/users.php') ?: '';
$userNewAdmin = @file_get_contents($root . '/admin/user-new.php') ?: '';
$authApp = @file_get_contents($root . '/_bonumark_stream/app/auth.php') ?: '';
$adminShellCss = @file_get_contents($root . '/assets/admin-shell.css') ?: '';
$adminJs = @file_get_contents($root . '/assets/admin.js') ?: '';
$adminLayout = @file_get_contents($root . '/admin/_layout.php') ?: '';
$contentAdmin = @file_get_contents($root . '/admin/content.php') ?: '';
$pagesAdmin = @file_get_contents($root . '/admin/pages.php') ?: '';
$pageEditAdmin = @file_get_contents($root . '/admin/page-edit.php') ?: '';
$pageNewAdmin = @file_get_contents($root . '/admin/page-new.php') ?: '';
$editorApp = @file_get_contents($root . '/_bonumark_stream/app/editor.php') ?: '';
$editorJs = @file_get_contents($root . '/assets/editor.js') ?: '';
$mediaAdmin = @file_get_contents($root . '/admin/media.php') ?: '';
if ($adminShellCss === '' || !str_contains($adminShellCss, 'Admin Shell Foundation Pass')) {
    bm_smoke_fail($failures, 'Admin shell foundation stylesheet is missing.');
}
if (!str_contains($adminLayout, "bms_asset_url('assets/admin-shell.css')") || !str_contains($adminLayout, 'data-admin-nav-open') || !str_contains($adminLayout, 'admin-sidebar-backdrop')) {
    bm_smoke_fail($failures, 'Shared Admin layout does not load or expose the responsive shell controls.');
}
if (!str_contains($adminLayout, "'label' => 'Publish'") || !str_contains($adminLayout, "'label' => 'Manage'") || !str_contains($adminLayout, "'label' => 'Design'") || !str_contains($adminLayout, "'label' => 'System'")) {
    bm_smoke_fail($failures, 'Shared Admin navigation is missing task-oriented sections.');
}
if (!str_contains($adminJs, "classList.add('admin-js-ready')") || !str_contains($adminJs, 'function attachAdminNavigation()') || !str_contains($adminJs, "event.key === 'Escape'") || !str_contains($adminJs, "body.classList.toggle('admin-nav-open'")) {
    bm_smoke_fail($failures, 'Admin navigation script is missing drawer, Escape, or state handling.');
}
if (!str_contains($adminShellCss, '@media (max-width: 900px)') || !str_contains($adminShellCss, 'html.admin-js-ready body.bonumark-admin .admin-sidebar') || !str_contains($adminShellCss, 'transform: translateX(-105%)') || !str_contains($adminShellCss, 'min-height: 44px')) {
    bm_smoke_fail($failures, 'Admin shell stylesheet is missing mobile drawer or touch-target rules.');
}

$adminCssLineCount = substr_count($adminCss, "\n");
if ($adminCssLineCount >= 7258) {
    bm_smoke_fail($failures, 'Legacy Admin CSS consolidation pass 3 did not reduce assets/admin.css below its 7,258-line starting point.');
}
foreach ([
    '--admin-surface-3:#101318',
    '--admin-bg:#f0f0f1',
    '--admin-bg:#0b0d10',
    '--editor-composer-min-height:720px',
    '--editor-composer-max-height:6500px',
    '--editor-composer-max-height:5400px',
    'min-height:58vh',
    '.admin-shell{min-height:100vh',
    '.admin-topbar{min-height:48px',
    '.import-preview-heading{align-items:flex-start',
] as $supersededAdminCssFragment) {
    if (str_contains(str_replace(' ', '', $adminCss), $supersededAdminCssFragment)) {
        bm_smoke_fail($failures, 'Legacy Admin CSS still contains a superseded shell or editor declaration: ' . $supersededAdminCssFragment);
    }
}
if (!str_contains($adminShellCss, '--admin-bg: #0a0d12')
    || !str_contains($adminShellCss, '--admin-sidebar-width: 264px')
    || !str_contains($adminEditorWorkflowCss, '--editor-composer-min-height: 380px')
    || !str_contains($adminEditorWorkflowCss, '--editor-composer-max-height: 1400px')
    || !str_contains($adminOperationsCss, 'body.bonumark-admin .upgrade-confirm-actions')
    || !str_contains($adminShellCss, 'body.bonumark-admin .dashboard-metric-grid')
    || !str_contains($adminOperationsCss, 'body.bonumark-admin .import-preview-heading')
    || !str_contains($adminPlacesCss, 'body.bonumark-admin .editor-location-card .local-places-selected')) {
    bm_smoke_fail($failures, 'Dedicated Admin shell, editor, or operations component stylesheet is missing a definition that replaced removed legacy CSS.');
}

if (!str_contains($adminCss, 'Admin Row Action Hover Stability Hotfix') || !str_contains($adminCss, 'body.bonumark-admin .row-actions .link-button:hover') || !str_contains($adminCss, 'body.bonumark-admin .table-actions .link-button:hover')) {
    bm_smoke_fail($failures, 'Admin CSS is missing row-action hover stability selectors.');
}
if (!str_contains($adminCss, 'font: inherit') || !str_contains($adminCss, 'font-weight: 500') || !str_contains($adminCss, 'padding: 0') || !str_contains($adminCss, 'border: 0')) {
    bm_smoke_fail($failures, 'Admin row-action CSS must keep hover layout metrics stable.');
}
if (!str_contains($adminCss, '.row-actions .state-link') || !str_contains($adminCss, '.row-actions .danger-link') || !str_contains($adminCss, '.table-actions .danger-link')) {
    bm_smoke_fail($failures, 'Admin row-action CSS must preserve state and destructive action styling.');
}
if (!str_contains($contentAdmin, 'class="content-action-button state-link">Publish</button>') || !str_contains($contentAdmin, 'class="content-action-button state-link">Move to drafts</button>') || !str_contains($contentAdmin, 'class="content-action-button danger-link">Move to Trash</button>')) {
    bm_smoke_fail($failures, 'Stream post action menus must preserve state and destructive action classes.');
}
if (!str_contains($pagesAdmin, 'class="content-action-button state-link">Restore</button>')
    || !str_contains($pagesAdmin, 'class="content-action-button state-link">Publish page</button>')
    || !str_contains($pagesAdmin, 'class="content-action-button danger-link">Move to Trash</button>')
    || !str_contains($pagesAdmin, 'class="content-action-button danger-link">Delete permanently</button>')) {
    bm_smoke_fail($failures, 'Page action menus must preserve state and destructive action classes.');
}

$commentsAdmin = @file_get_contents($root . '/admin/comments.php') ?: '';
if (!str_contains($adminContentListCss, 'Bonumark Stream Admin Content List') || !str_contains($adminContentListCss, '.content-record-header') || !str_contains($adminContentListCss, '.content-record-actions') || !str_contains($adminContentListCss, '@media (max-width: 760px)')) {
    bm_smoke_fail($failures, 'Responsive Stream Posts record-list CSS is missing required desktop or mobile component coverage.');
}
if (!str_contains($adminLayout, "bms_asset_url('assets/admin-media-library.css')")) {
    bm_smoke_fail($failures, 'Shared Admin layout does not load the Media Library component stylesheet.');
}
if (!str_contains($adminMediaLibraryCss, 'Bonumark Stream Admin Media Library')
    || !str_contains($adminMediaLibraryCss, '.media-library-grid')
    || !str_contains($adminMediaLibraryCss, 'grid-template-columns: repeat(2, minmax(0, 1fr));')
    || !str_contains($adminMediaLibraryCss, '.media-details-dialog')
    || !str_contains($adminMediaLibraryCss, 'inset: auto 0 0;')) {
    bm_smoke_fail($failures, 'Media Library CSS is missing browsing-grid, phone-grid, dialog, or bottom-sheet coverage.');
}
if (!str_contains($mediaAdmin, 'class="media-library-grid"')
    || !str_contains($mediaAdmin, 'data-media-details-open')
    || !str_contains($mediaAdmin, 'data-media-details-dialog')
    || !str_contains($mediaAdmin, 'data-media-detail-markdown')
    || substr_count($mediaAdmin, 'class="copy-field"') !== 1) {
    bm_smoke_fail($failures, 'Media Library must use compact cards and one shared on-demand details surface instead of repeated Markdown fields.');
}
if (!str_contains($adminJs, 'function attachMediaDetailsDialog()')
    || !str_contains($adminJs, "dialog.showModal()")
    || !str_contains($adminJs, "card.classList.toggle('is-selected', box.checked)")) {
    bm_smoke_fail($failures, 'Media Library dialog or selection-state behavior is missing from Admin JavaScript.');
}
if (!str_contains((string)@file_get_contents($root . '/sw.js'), "'assets/admin-media-library.css'")) {
    bm_smoke_fail($failures, 'Service worker asset list does not include the Media Library component stylesheet.');
}

if (!str_contains($contentAdmin, 'class="content-record-header"') || !str_contains($contentAdmin, 'id="stream-content-list"') || !str_contains($contentAdmin, 'class="content-actions-menu"') || !str_contains($contentAdmin, 'data-select-scope="#stream-content-list"')) {
    bm_smoke_fail($failures, 'Stream Posts must use the responsive record-list structure, scoped selection, and compact action menus.');
}
if (!str_contains($adminContentListCss, 'body.bonumark-admin.admin-screen-content .admin-content') || !str_contains($adminContentListCss, 'max-width: 1500px')) {
    bm_smoke_fail($failures, 'The Stream Posts record list must use an intentional desktop workspace width.');
}
if (!str_contains($pagesAdmin, 'class="content-record-header page-content-record-header"')
    || !str_contains($pagesAdmin, 'class="content-record page-content-record')
    || !str_contains($pagesAdmin, 'class="content-actions-menu"')
    || !str_contains($adminContentListCss, '.page-content-record')
    || !str_contains($adminContentListCss, 'admin-screen-pages')) {
    bm_smoke_fail($failures, 'Pages must use the shared responsive record-list structure and page-specific desktop/mobile layout.');
}
if (!str_contains($pagesAdmin, 'content-filter pages-content-filter')
    || !str_contains($adminContentListCss, '.pages-content-filter')
    || !str_contains($adminContentListCss, 'grid-template-columns: repeat(2, minmax(0, 1fr));')) {
    bm_smoke_fail($failures, 'Phone Page status filters must remain fully visible in a two-column grid.');
}
if (!str_contains($pageEditAdmin, "bms_editor_screen_controls_action('page')")
    || !str_contains($pageEditAdmin, "'content_type' => 'page'")
    || !str_contains($pageEditAdmin, 'bms_editor_mobile_action_bar')
    || !str_contains($pageNewAdmin, 'bms_editor_mobile_action_bar')
    || !str_contains($editorApp, 'data-editor-card="page-url"')
    || !str_contains($editorApp, 'data-editor-card="page-settings"')
    || !str_contains($editorApp, "? bms_current_user_can(\$isPageContent ? 'manage_pages' : 'publish_content')")) {
    bm_smoke_fail($failures, 'Page editor workflow is missing page-aware screen controls, metadata cards, permissions, or mobile actions.');
}
if (!str_contains($pageEditAdmin, "\$submitAction === 'publish'")
    || !str_contains($pageEditAdmin, "\$targetSection = bms_page_status_section(\$targetStatus)")
    || !str_contains($editorJs, "'page-url': true")
    || !str_contains($editorJs, '[data-page-final-url-input]')) {
    bm_smoke_fail($failures, 'Page editor does not publish current form contents or keep page URL previews synchronized.');
}
if (!str_contains($editorApp, "\$isPageContent ? 'page' : 'post'")
    || str_contains($editorApp, 'Save changes first when the post has unsaved edits.</p>')) {
    bm_smoke_fail($failures, 'Publish preview guidance must use page-specific language in the Page editor.');
}
if (!str_contains($adminLayout, "bms_asset_url('assets/admin-comments.css')")
    || !str_contains($adminCommentsCss, 'Bonumark Stream Admin Comments')
    || !str_contains($adminCommentsCss, '.comment-record-header')
    || !str_contains($adminCommentsCss, '.comment-record-mobile-status')
    || !str_contains($adminCommentsCss, 'grid-template-columns: repeat(3, minmax(0, 1fr));')) {
    bm_smoke_fail($failures, 'Comments moderation stylesheet is missing desktop records, phone cards, or visible mobile status filters.');
}
if (!str_contains($commentsAdmin, 'class="comment-record-header"')
    || !str_contains($commentsAdmin, 'id="comment-record-list"')
    || !str_contains($commentsAdmin, 'data-select-scope="#comment-record-list"')
    || !str_contains($commentsAdmin, 'class="content-actions-menu"')
    || !str_contains($commentsAdmin, 'Search comments, authors, usernames, posts, or slugs')
    || !str_contains($commentsAdmin, 'Restore as approved')
    || !str_contains($commentsAdmin, 'Delete permanently')) {
    bm_smoke_fail($failures, 'Comments must use the responsive moderation records, scoped bulk controls, search, and complete state actions.');
}
if (!str_contains($commentsApp, 'function bms_admin_comment_status_counts')
    || !str_contains($commentsApp, 'function bms_update_comment_statuses')
    || !str_contains($commentsApp, 'function bms_delete_trashed_comments_permanently')
    || !str_contains($commentsApp, "WHERE id = :id AND status = :status")) {
    bm_smoke_fail($failures, 'Comment moderation helpers must support counts, bulk state changes, and Trash-only permanent deletion.');
}
if (!str_contains((string)@file_get_contents($root . '/sw.js'), "'assets/admin-comments.css'")) {
    bm_smoke_fail($failures, 'Service worker asset list does not include the Comments moderation stylesheet.');
}

if (!str_contains($commentsAdmin, "require_once __DIR__ . '/_comments-ui.php'")
    || !str_contains($commentsAdmin, 'str_starts_with($action, \'bulk_\') && !$selected')
    || str_contains($commentsUi, '$action')
    || !str_contains($commentsUi, 'function bms_comments_admin_date')) {
    bm_smoke_fail($failures, 'Comments warning correction or runtime-safe display helpers are missing.');
}
if (!is_file($root . '/scripts/admin-comments-runtime-test.php')) {
    bm_smoke_fail($failures, 'Comments runtime warning regression test is missing.');
}
if (!str_contains($adminCommentsCss, 'v0.5.60 desktop moderation control correction')
    || !str_contains($adminCommentsCss, 'flex: 0 0 220px;')
    || !str_contains($adminCommentsCss, 'white-space: nowrap;')) {
    bm_smoke_fail($failures, 'Comments desktop control bar correction is missing.');
}
if (!str_contains($adminLayout, "bms_asset_url('assets/admin-accounts.css')")
    || !str_contains($adminLayout, "'label' => 'Accounts'")
    || !str_contains($adminLayout, "'users.php', 'user-new.php', 'user-edit.php'")) {
    bm_smoke_fail($failures, 'Accounts navigation or component stylesheet integration is incomplete.');
}
if (!str_contains($usersAdmin, 'class="account-record-header"')
    || !str_contains($usersAdmin, 'class="account-record-list"')
    || !str_contains($usersAdmin, 'Manage Account')
    || !str_contains($usersAdmin, 'Approve Account')
    || str_contains($usersAdmin, 'class="admin-table')
    || str_contains($usersAdmin, '<h2>Add commenter</h2>')) {
    bm_smoke_fail($failures, 'Accounts must use responsive records, one Actions menu, and a separate creation screen.');
}
if (!str_contains($userNewAdmin, 'name="new_commenter_username"')
    || !str_contains($userNewAdmin, 'autocomplete="new-password"')
    || !str_contains($userNewAdmin, 'data-1p-ignore')
    || !str_contains($userNewAdmin, 'data-lpignore="true"')) {
    bm_smoke_fail($failures, 'Dedicated Add Commenter fields are missing autofill-resistant account controls.');
}
if (!str_contains($adminAccountsCss, 'Bonumark Stream Admin Accounts')
    || !str_contains($adminAccountsCss, '.account-record-header')
    || !str_contains($adminAccountsCss, '.account-record-mobile-label')) {
    bm_smoke_fail($failures, 'Accounts component stylesheet is missing desktop records or responsive account cards.');
}
if (!str_contains($authApp, "'users.php', 'user-new.php', 'user-edit.php' => 'manage_users'")
    || !str_contains($authApp, 'profile_visibility, avatar_path')) {
    bm_smoke_fail($failures, 'Account creation route protection or account-list profile data is incomplete.');
}
if (!str_contains((string)@file_get_contents($root . '/sw.js'), "'assets/admin-accounts.css'")) {
    bm_smoke_fail($failures, 'Service worker asset list does not include the Accounts stylesheet.');
}
if (!str_contains($commentsAdmin, 'class="content-filter-link')
    || !str_contains($commentsAdmin, 'class="content-filter-label"')
    || !str_contains($commentsAdmin, 'class="content-filter-count"')
    || !str_contains($usersAdmin, 'class="content-filter-link')
    || !str_contains($usersAdmin, 'class="content-filter-label"')
    || !str_contains($usersAdmin, 'class="content-filter-count"')) {
    bm_smoke_fail($failures, 'Comments and Accounts filters must keep labels and counts as separate styled elements.');
}
if (!str_contains($adminContentListCss, 'v0.5.61 shared filter label and count treatment')
    || !str_contains($adminContentListCss, '.content-filter-count')
    || !str_contains($adminContentListCss, 'font-variant-numeric: tabular-nums;')) {
    bm_smoke_fail($failures, 'Shared filter count badge styling is missing.');
}
if (!str_contains($usersAdmin, 'accounts-summary-total')
    || !str_contains($adminAccountsCss, '.accounts-summary-total')
    || !str_contains($adminAccountsCss, 'grid-column: 1 / -1;')) {
    bm_smoke_fail($failures, 'Total accounts must span the full second row in the phone summary grid.');
}

if (!str_contains($adminLayout, "bms_asset_url('assets/admin-registration.css')")
    || !str_contains((string)@file_get_contents($root . '/sw.js'), "'assets/admin-registration.css'")) {
    bm_smoke_fail($failures, 'Registration component stylesheet is not integrated into the Admin shell and service worker.');
}
if (!str_contains($registrationAdmin, "bms_admin_header('Registration'")
    || !str_contains($registrationAdmin, 'registration-summary-panel')
    || !str_contains($registrationAdmin, 'class="registration-option-card"')
    || !str_contains($registrationAdmin, 'class="registration-invite-record"')
    || str_contains($registrationAdmin, 'class="admin-table')) {
    bm_smoke_fail($failures, 'Registration must use the modern summary, option-card, and responsive invite-record workflow.');
}
if (!str_contains($adminRegistrationCss, 'Bonumark Stream Admin Registration')
    || !str_contains($adminRegistrationCss, '.registration-workflow-grid')
    || !str_contains($adminRegistrationCss, '.registration-invite-record')
    || !str_contains($adminRegistrationCss, '.registration-mobile-label')) {
    bm_smoke_fail($failures, 'Registration component stylesheet is missing desktop or phone workflow rules.');
}
if (!str_contains($registrationAdmin, '$registrationEnabled = $mode !== \'disabled\';')
    || !str_contains($registrationAdmin, 'if ($registrationEnabled && $verify && !$mailReady)')
    || !str_contains($registrationAdmin, "'Required when enabled'")
    || !str_contains($registrationAdmin, "'Automatic when enabled'")) {
    bm_smoke_fail($failures, 'Registration state guidance must stay quiet while registration is disabled and clarify deferred verification and approval rules.');
}

if (!str_contains($adminLayout, "bms_asset_url('assets/admin-appearance.css')")
    || !str_contains((string)@file_get_contents($root . '/sw.js'), "'assets/admin-appearance.css'")) {
    bm_smoke_fail($failures, 'Appearance component stylesheet is not integrated into the Admin shell and service worker.');
}
if (!str_contains($adminAppearanceCss, 'Bonumark Stream Admin Appearance')
    || !str_contains($adminAppearanceCss, '.appearance-theme-grid')
    || !str_contains($adminAppearanceCss, '.appearance-identity-layout')
    || !str_contains($adminAppearanceCss, '.appearance-navigation-item')
    || !str_contains($adminAppearanceCss, '@media (max-width: 640px)')) {
    bm_smoke_fail($failures, 'Appearance component stylesheet is missing theme, identity, navigation, or phone workflow rules.');
}
if (!str_contains($adminAppearanceCss, '@media (min-width: 641px)')
    || !str_contains($adminAppearanceCss, '.appearance-active-theme-facts > div:first-child')
    || !str_contains($adminAppearanceCss, 'grid-column: 1 / -1;')) {
    bm_smoke_fail($failures, 'Active-theme metadata must reserve a full desktop row for the slug without changing the accepted phone layout.');
}

if (!str_contains($adminLayout, "bms_asset_url('assets/admin-settings.css')")
    || !str_contains((string)@file_get_contents($root . '/sw.js'), "'assets/admin-settings.css'")) {
    bm_smoke_fail($failures, 'Settings component stylesheet is not integrated into the Admin shell and service worker.');
}
if (!str_contains($adminLayout, "'label' => 'Reading'")
    || !str_contains($adminLayout, "'label' => 'Security'")
    || !str_contains($authApp, "'settings-reading.php', 'security.php', 'registration.php'")) {
    bm_smoke_fail($failures, 'Settings navigation or Security route capability is incomplete.');
}
if (!str_contains($adminSettingsCss, 'Bonumark Stream Admin Settings')
    || !str_contains($adminSettingsCss, '.settings-workflow-grid')
    || !str_contains($adminSettingsCss, '.settings-option-card')
    || !str_contains($adminSettingsCss, '.settings-token-record')
    || !str_contains($adminSettingsCss, '@media (max-width: 720px)')) {
    bm_smoke_fail($failures, 'Settings component stylesheet is missing workflow, option, record, or phone rules.');
}
if (!str_contains($adminSettingsCss, 'Settings URL Overflow Hotfix')
    || !str_contains($adminSettingsCss, '.settings-technical-value')
    || !str_contains($adminSettingsCss, 'overflow-wrap: anywhere;')
    || !str_contains($adminSettingsCss, 'word-break: break-word;')
    || !str_contains($adminSettingsCss, '.settings-workflow-grid > *')) {
    bm_smoke_fail($failures, 'Settings technical URLs must shrink and wrap inside Reading, Remote Posting, Scheduled Tasks, and related panels.');
}
if (!str_contains($readingSettingsAdmin, 'class="settings-technical-value"')
    || !str_contains($remotePostingAdmin, 'class="settings-technical-value"')
    || !str_contains($scheduledTasksAdmin, 'class="settings-technical-value"')) {
    bm_smoke_fail($failures, 'Settings URL containers are missing the shared technical-value overflow treatment.');
}
if (!str_contains($generalSettingsAdmin, 'settings-summary-grid')
    || !str_contains($writingSettingsAdmin, 'settings-option-card')
    || !str_contains($readingSettingsAdmin, "bms_admin_header('Reading Settings'")
    || !str_contains($readingSettingsAdmin, 'settings-section-list')) {
    bm_smoke_fail($failures, 'General, Writing, or Reading settings are missing the modern workflow structure.');
}
if (!str_contains($securitySettingsAdmin, 'settings-security-grid')
    || str_contains($securitySettingsAdmin, "bms_redirect(bms_admin_url('system-check.php'))")
    || !str_contains($securitySettingsAdmin, 'API tokens')) {
    bm_smoke_fail($failures, 'Security must be a real settings hub instead of redirecting to System Check.');
}
if (!str_contains($mailSettingsAdmin, 'settings-mail-record')
    || str_contains($mailSettingsAdmin, 'class="admin-table')
    || !str_contains($remotePostingAdmin, 'settings-token-record')
    || !str_contains($remotePostingAdmin, 'settings-audit-record')
    || str_contains($remotePostingAdmin, 'class="admin-table')) {
    bm_smoke_fail($failures, 'Mail and Remote Posting must use responsive settings records instead of legacy tables.');
}
if (!str_contains($scheduledTasksAdmin, 'settings-history-record')
    || str_contains($scheduledTasksAdmin, 'class="admin-table')
    || !str_contains($scheduledTasksAdmin, 'Protected web cron')) {
    bm_smoke_fail($failures, 'Scheduled Tasks must use the modern runner workflow and responsive history records.');
}


if (!str_contains($adminLayout, "bms_asset_url('assets/admin-operations.css')")
    || !str_contains((string)@file_get_contents($root . '/sw.js'), "'assets/admin-operations.css'")) {
    bm_smoke_fail($failures, 'Operations component stylesheet is not integrated into the Admin shell and service worker.');
}
if (!str_contains($adminOperationsCss, 'Bonumark Stream Admin Operations')
    || !str_contains($adminOperationsCss, '.operations-danger-zone')
    || !str_contains($adminOperationsCss, '.operations-workflow-grid')
    || !str_contains($adminOperationsCss, '.operations-record')
    || !str_contains($adminOperationsCss, '@media (max-width: 720px)')) {
    bm_smoke_fail($failures, 'Operations stylesheet is missing risk, workflow, record, or phone rules.');
}
if (!str_contains($adminOperationsCss, '.operations-check-card > *')
    || !str_contains($adminOperationsCss, 'body.admin-screen-tools .operations-group-list')
    || !str_contains($adminOperationsCss, 'body.admin-screen-upgrade .operations-workflow-history')
    || !str_contains($adminOperationsCss, 'grid-template-areas:')
    || !str_contains($upgradeAdmin, 'operations-workflow-history')
    || !str_contains($upgradeAdmin, 'Version change')
    || !str_contains($upgradeAdmin, 'is-version-change')) {
    bm_smoke_fail($failures, 'Tools and System acceptance polish is missing diagnostic wrapping, Upgrade flow placement, or compact history records.');
}
if (!str_contains($toolsAdmin, 'Move and protect data')
    || !str_contains($toolsAdmin, 'Change system software')
    || !str_contains($toolsAdmin, 'Monitor and automate')
    || !str_contains($toolsAdmin, 'Access and support')) {
    bm_smoke_fail($failures, 'Tools must distinguish data movement, upgrades, diagnostics, and access workflows.');
}
if (!str_contains($importAdmin, 'Preview first')
    || !str_contains($importAdmin, 'Step 3')
    || !str_contains($importAdmin, 'Writes database')
    || !str_contains($importMarkdownAdmin, 'Advanced migration')) {
    bm_smoke_fail($failures, 'Import workflows must distinguish staging, confirmation, database writes, and private-folder migration.');
}
if (!str_contains($exportAdmin, 'Portable outputs')
    || !str_contains($exportAdmin, 'Private backups')
    || !str_contains($exportAdmin, 'Sensitive data')) {
    bm_smoke_fail($failures, 'Export must distinguish portable outputs from sensitive private backups.');
}
if (!str_contains($upgradeAdmin, 'High-risk operation')
    || !str_contains($upgradeAdmin, 'Step 1')
    || !str_contains($upgradeAdmin, 'Final confirmation')
    || str_contains($upgradeAdmin, 'class="admin-table')) {
    bm_smoke_fail($failures, 'Upgrade must use staged package checks, explicit final confirmation, and responsive history records.');
}
if (!str_contains($systemCheckAdmin, 'Read-only diagnostics')
    || !str_contains($systemCheckAdmin, 'operations-check-grid')
    || !str_contains($analyticsAdmin, 'operations-danger-zone')
    || !str_contains($helpAdmin, 'Operational help')) {
    bm_smoke_fail($failures, 'Diagnostics, analytics danger actions, or operational Help are incomplete.');
}
if (!str_contains($adminLayout, "['label' => 'Scheduled Tasks'")
    || !str_contains($adminLayout, "['label' => 'Remote Posting'")) {
    bm_smoke_fail($failures, 'Scheduled Tasks and Remote Posting must remain available in System navigation.');
}

if (!str_contains($adminLayout, "bms_asset_url('assets/admin-places.css')")
    || !str_contains($adminLayout, "bms_asset_url('assets/admin-places.js')")
    || !str_contains((string)@file_get_contents($root . '/sw.js'), "'assets/admin-places.css'")
    || !str_contains((string)@file_get_contents($root . '/sw.js'), "'assets/admin-places.js'")) {
    bm_smoke_fail($failures, 'Local Places component assets are not integrated into the Admin shell and service worker.');
}
if (!str_contains($adminPlacesCss, 'Bonumark Stream Admin Local Places')
    || !str_contains($adminPlacesCss, '.places-record')
    || !str_contains($adminPlacesCss, '.places-editor-layout')
    || !str_contains($adminPlacesCss, '.places-nearby-results')
    || !str_contains($adminPlacesCss, '@media (max-width: 640px)')) {
    bm_smoke_fail($failures, 'Local Places component stylesheet is missing directory, editor, nearby, or phone workflow rules.');
}
if (!str_contains($placesAdmin, 'data-places-directory-nearby')
    || !str_contains($placesAdmin, 'class="places-record-list"')
    || !str_contains($placesAdmin, 'class="places-search-form"')
    || str_contains($placesAdmin, 'local-places-directory-item')) {
    bm_smoke_fail($failures, 'Local Places directory must use search, nearby results, and the responsive record system.');
}
if (!str_contains($placeEditAdmin, 'data-place-editor')
    || !str_contains($placeEditAdmin, 'data-place-editor-nearby')
    || !str_contains($placeEditAdmin, 'data-place-preview-primary')
    || !str_contains($placeEditAdmin, 'data-place-preview-marker')
    || !str_contains($placeEditAdmin, 'Enter a place name to preview the public label')
    || !str_contains($placeEditAdmin, 'class="places-editor-layout"')) {
    bm_smoke_fail($failures, 'Local Places editor is missing the preview, empty-state marker treatment, nearby duplicate check, or modern editor layout.');
}
if (!str_contains($adminPlacesJs, "previewMarker.hidden = !hasPrimary")
    || !str_contains($adminPlacesJs, "previewPrimary.classList.toggle('is-placeholder', !hasPrimary)")
    || !str_contains($adminPlacesCss, '.places-public-preview.is-empty')
    || !str_contains($adminPlacesCss, '.places-preview-marker svg')) {
    bm_smoke_fail($failures, 'Local Places preview must hide the marker and show neutral guidance until a real public label exists.');
}
if (!str_contains($placeDeleteAdmin, 'name="confirm_name"')
    || !str_contains($placeDeleteAdmin, 'Delete Place Permanently')
    || !str_contains($placeDeleteAdmin, 'Existing Stream Posts keep the public location snapshot')) {
    bm_smoke_fail($failures, 'Local Places deletion must use a dedicated confirmation workflow and preserve existing post snapshots.');
}
if (!str_contains($placesApp, 'function bms_places_filter(')
    || !str_contains($adminPlacesJs, 'function attachDirectoryNearby()')
    || !str_contains($adminPlacesJs, 'function attachPlaceEditor()')
    || !str_contains($adminPlacesJs, 'Location permission was denied')) {
    bm_smoke_fail($failures, 'Local Places search, nearby loading/error handling, or editor interaction code is incomplete.');
}

if (!str_contains($themesAdmin, 'class="appearance-theme-grid"')
    || !str_contains($themesAdmin, 'class="appearance-summary-grid"')
    || !str_contains($themesAdmin, 'View Details')
    || !str_contains($themesAdmin, 'Active Theme Settings')) {
    bm_smoke_fail($failures, 'Themes must act as the Site Design hub with theme summaries, cards, and lifecycle actions.');
}
if (!str_contains($themeSettingsAdmin, 'type="hidden" name="active_public_theme"')
    || !str_contains($themeSettingsAdmin, 'class="appearance-setting-list"')
    || !str_contains($themeSettingsAdmin, 'Choose Another Theme')
    || str_contains($themeSettingsAdmin, '<select id="active_public_theme"')) {
    bm_smoke_fail($failures, 'Theme Settings must edit the active theme only and leave activation to the Themes workflow.');
}
if (!str_contains($themeDetailsAdmin, 'class="appearance-theme-detail-layout"')
    || !str_contains($themeDetailsAdmin, 'class="appearance-record-list"')
    || !str_contains($themeInstallAdmin, 'class="appearance-file-drop"')
    || !str_contains($themeInstallAdmin, 'Code-free only')
    || !str_contains($themeDeleteAdmin, 'class="appearance-delete-form"')) {
    bm_smoke_fail($failures, 'Theme details, installation, or deletion is missing the modern package lifecycle structure.');
}
if (!str_contains($siteIdentityAdmin, 'appearance-identity-layout')
    || !str_contains($siteIdentityAdmin, 'appearance-favicon-current')
    || !str_contains($siteIdentityAdmin, 'appearance-save-bar')) {
    bm_smoke_fail($failures, 'Site Identity must separate public framing, favicon management, and the save action.');
}
if (!str_contains($navigationAdmin, 'appearance-navigation-option-list')
    || !str_contains($navigationAdmin, 'appearance-navigation-item')
    || !str_contains($navigationAdmin, 'appearance-navigation-add-grid')
    || !str_contains($navigationAdmin, 'Show automatic account links')) {
    bm_smoke_fail($failures, 'Navigation must expose menu state, editable ordered records, and page/custom additions.');
}

if (!str_contains($functionsApp, 'function bms_public_preview_mode') || !str_contains($functionsApp, 'function bms_with_public_preview_mode')) {
    bm_smoke_fail($failures, 'Preview-mode core helpers are missing.');
}
if (!str_contains($previewApp, 'bms_with_public_preview_mode')) {
    bm_smoke_fail($failures, 'Admin preview rendering must enable public preview mode.');
}
if (!str_contains($rendererApp, "'preview_mode' =>") || !str_contains($rendererApp, "'enabled' => !\$previewMode")) {
    bm_smoke_fail($failures, 'Renderer does not pass preview mode or disable preview interactions.');
}
if (!str_contains($commentsApp, 'bms_render_comments_preview_panel')) {
    bm_smoke_fail($failures, 'Preview comments panel helper is missing.');
}
if (!str_contains($streamCardPublicSource, "!empty(\$like['enabled'])") || !str_contains($streamCardPublicSource, '$backLabel')) {
    bm_smoke_fail($failures, 'Card template does not honor preview-safe action state.');
}
if (!str_contains($headerTemplate, "'Preview'")) {
    bm_smoke_fail($failures, 'Header template does not expose preview state.');
}
if (!str_contains($appearanceApp, "'show_count_chip' => !\$previewMode") || !str_contains($appearanceApp, "'preview_header_state' => \$previewMode") || !str_contains($appearanceApp, "'show_public_menu' => !\$previewMode") || !str_contains($appearanceApp, "'navigation_html' => \$previewMode ? '' : \$navHtml")) {
    bm_smoke_fail($failures, 'Appearance renderer does not pass preview-safe header state or suppress public navigation in preview.');
}
if (!str_contains($appearanceApp, 'function bms_public_footer_items') || !str_contains($appearanceApp, "'footer_items' => \$footerItems") || !str_contains($appearanceApp, "'footer_separator' => ''")) {
    bm_smoke_fail($failures, 'Appearance renderer must pass normalized footer item data without a default slash separator.');
}
if (!str_contains($footerTemplate, '$footerItems') || !str_contains($footerTemplate, 'foreach ($footerItems as $index => $item)') || !str_contains($footerTemplate, '$index > 0 && $separator !==') || !str_contains($footerTemplate, 'footer-separator')) {
    bm_smoke_fail($failures, 'Footer template must render separators only when an explicit non-empty separator is supplied.');
}
if (str_contains($footerTemplate, '$separator = trim((string)($data[\'footer_separator\'] ?? \'/\'))') || str_contains($appearanceApp, "'footer_separator' => '/'")) {
    bm_smoke_fail($failures, 'Public footer must not default to a slash separator.');
}
if (!str_contains($headerTemplate, '$showPostCount = !$previewMode && $showCountChip') || !str_contains($headerTemplate, '!$previewMode && $showPublicMenu && $navigationHtml !==') || str_contains($headerTemplate, 'data-preview-menu')) {
    bm_smoke_fail($failures, 'Header template does not hide preview count/menu controls safely.');
}
if (!str_contains($headerTemplate, "bms_render_public_theme_layout_surface('site-header', \$data, \$headerTheme)")
    || !str_contains($headerTemplate, "is-declarative-site-header")
    || !str_contains($appearanceApp, "'navigation_account_items_enabled' => \$includeAccountNavigation")
    || !str_contains($appearanceApp, "'menu_label' => \$menuLabel")) {
    bm_smoke_fail($failures, 'Site Header template is not wired to validated declarative composition while retaining prepared core data.');
}
if (count($siteHeaderComponentFiles) !== 4) {
    bm_smoke_fail($failures, 'Site Header foundation must contain exactly four core-owned component files.');
}
foreach (['bms_db(', 'bms_table(', 'bms_setting_or_config(', '$_POST', '$_GET', '$_FILES'] as $forbiddenSiteHeaderFetch) {
    if (str_contains($siteHeaderComponentsSource, $forbiddenSiteHeaderFetch)) {
        bm_smoke_fail($failures, 'Site Header components must render prepared data instead of reading application/request state: ' . $forbiddenSiteHeaderFetch);
    }
}
if (!str_contains($appearanceApp, 'function bms_public_navigation_items(bool $includeAccountItems = true)')
    || !str_contains($appearanceApp, '$includeAccountItems && bms_public_navigation_account_links_enabled()')
    || !str_contains($appearanceApp, '$includeAccountNavigation = !$staticExport')
    || !str_contains($rendererApp, 'function bms_static_site_export_rendering(): bool')
    || !str_contains($rendererApp, 'bms_set_static_site_export_rendering(true)')
    || !str_contains($rendererApp, 'finally {')
    || !str_contains($rendererApp, 'bms_set_static_site_export_rendering($previousStaticRenderState)')) {
    bm_smoke_fail($failures, 'Static Site Export must render public navigation without session-specific account destinations and restore render state afterward.');
}
if (!str_contains($streamJs, 'function isPreviewMode') || !str_contains($streamJs, 'if (!isPreviewMode())')) {
    bm_smoke_fail($failures, 'Public JavaScript does not skip live interactions in preview mode.');
}
$htaccess = @file_get_contents($root . '/.htaccess') ?: '';
$frontController = @file_get_contents($root . '/index.php') ?: '';
if (!str_contains($htaccess, 'api/v1/stream/posts')) {
    bm_smoke_fail($failures, '.htaccess does not route the remote stream posts endpoint.');
}
if (!str_contains($htaccess, 'api/v1/media')) {
    bm_smoke_fail($failures, '.htaccess does not route the remote media endpoint.');
}
if (!str_contains($htaccess, 'api/v1/media/import')) {
    bm_smoke_fail($failures, '.htaccess does not route the remote media import endpoint.');
}
if (!str_contains($htaccess, '__bonumark_route=api_status') || !str_contains($htaccess, '__bonumark_route=api_stream_posts') || !str_contains($htaccess, '__bonumark_route=api_media') || !str_contains($htaccess, '__bonumark_route=api_media_import')) {
    bm_smoke_fail($failures, '.htaccess must route API clean URLs through index.php for upgrade compatibility.');
}
if (!str_contains($frontController, 'bms_api_handle_status_endpoint') || !str_contains($frontController, 'bms_api_handle_stream_posts_endpoint') || !str_contains($frontController, 'bms_api_handle_media_endpoint') || !str_contains($frontController, 'bms_api_handle_media_import_endpoint')) {
    bm_smoke_fail($failures, 'index.php must dispatch API clean URL routes.');
}
$apiRuntime = @file_get_contents($root . '/_bonumark_stream/app/api.php') ?: '';
$createPostPosition = strpos($apiRuntime, 'function bms_api_create_remote_stream_post');
$embedCallPosition = strpos($apiRuntime, '$embeddedMedia = bms_api_embedded_media($payload, $token)');
$bodyPersistPosition = strpos($apiRuntime, 'bms_api_body_with_embedded_media($body');
$buildPosition = strpos($apiRuntime, 'bms_build_markdown_document($fields, $body)');
if ($createPostPosition === false || $embedCallPosition === false || $bodyPersistPosition === false || $buildPosition === false || $embedCallPosition < $createPostPosition || $bodyPersistPosition < $embedCallPosition || $buildPosition < $bodyPersistPosition) {
    bm_smoke_fail($failures, 'Remote stream post creation must embed media into the post body before persistence.');
}
if (!str_contains($apiRuntime, 'Content or embedded media is required.')) {
    bm_smoke_fail($failures, 'Remote media-only posts must be allowed when embedded media is supplied.');
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



// PWA and mobile share-target checks.
$pwaRequiredFiles = [
    'manifest.php',
    'pwa-icon.php',
    'sw.js',
    'assets/pwa.js',
    'assets/icons/bonumark-icon-192.png',
    'assets/icons/bonumark-icon-512.png',
    '_bonumark_stream/app/pwa.php',
    'admin/share-target.php',
];
foreach ($pwaRequiredFiles as $relative) {
    if (!is_file($root . '/' . $relative)) {
        bm_smoke_fail($failures, 'PWA/share-target file is missing: ' . $relative);
    }
}

$manifestOutput = trim((string)shell_exec('cd ' . escapeshellarg($root) . ' && php manifest.php 2>/dev/null'));
$manifestData = $manifestOutput !== '' ? json_decode($manifestOutput, true) : null;
if (!is_array($manifestData)) {
    bm_smoke_fail($failures, 'PWA manifest did not produce valid JSON.');
} else {
    foreach (['name', 'short_name', 'description', 'start_url', 'display', 'theme_color', 'background_color', 'scope', 'icons'] as $field) {
        if (!array_key_exists($field, $manifestData)) {
            bm_smoke_fail($failures, 'PWA manifest missing required field: ' . $field);
        }
    }
    if (($manifestData['display'] ?? '') !== 'standalone') {
        bm_smoke_fail($failures, 'PWA manifest display mode must be standalone.');
    }
    if (empty($manifestData['icons']) || !is_array($manifestData['icons'])) {
        bm_smoke_fail($failures, 'PWA manifest icons must be present.');
    } else {
        $iconSources = array_column($manifestData['icons'], 'src');
        if (!in_array('/assets/icons/bonumark-icon-192.png', $iconSources, true) || !in_array('/assets/icons/bonumark-icon-512.png', $iconSources, true)) {
            bm_smoke_fail($failures, 'PWA manifest must use bundled fallback icons when no Site Identity favicon is configured.');
        }
    }
    $shareTarget = $manifestData['share_target'] ?? null;
    if (!is_array($shareTarget) || ($shareTarget['action'] ?? '') === '' || ($shareTarget['method'] ?? '') !== 'POST' || ($shareTarget['params']['text'] ?? '') !== 'text' || ($shareTarget['params']['url'] ?? '') !== 'url') {
        bm_smoke_fail($failures, 'PWA manifest share_target must use POST and support shared text and URLs.');
    }
}

$serviceWorker = @file_get_contents($root . '/sw.js') ?: '';
if (!str_contains($serviceWorker, 'bonumark-stream-static-v' . $rootVersion)) {
    bm_smoke_fail($failures, 'Service worker cache name is not tied to the current version.');
}
if (!str_contains($serviceWorker, 'bmsBlockedPrivatePath') || !str_contains($serviceWorker, "relative.indexOf('admin/')") || !str_contains($serviceWorker, "relative.indexOf('api/')")) {
    bm_smoke_fail($failures, 'Service worker must explicitly avoid admin and API responses.');
}
if (!str_contains($serviceWorker, 'bmsSafeStaticAsset') || !str_contains($serviceWorker, "relative.indexOf('assets/')")) {
    bm_smoke_fail($failures, 'Service worker must cache only safe static assets.');
}
if (str_contains($serviceWorker, 'bonumark-icon-192.png') || str_contains($serviceWorker, 'bonumark-icon-512.png')) {
    bm_smoke_fail($failures, 'Service worker must not pre-cache PWA icons because Site Identity icons use versioned dynamic URLs.');
}

$pwaIconOutput = (string)shell_exec('cd ' . escapeshellarg($root) . ' && php pwa-icon.php 2>/dev/null');
if (!str_starts_with($pwaIconOutput, "\x89PNG\r\n\x1a\n")) {
    bm_smoke_fail($failures, 'PWA icon endpoint must return a PNG fallback when no Site Identity favicon is configured.');
}
$pwaRuntime = @file_get_contents($root . '/_bonumark_stream/app/pwa.php') ?: '';
if (!str_contains($pwaRuntime, 'function bms_pwa_site_icon_direct_url')
    || !str_contains($pwaRuntime, 'function bms_pwa_site_icon_native_size')
    || !str_contains($pwaRuntime, 'bms_pwa_site_icon_direct_url($source)')) {
    bm_smoke_fail($failures, 'Selected Site Identity favicons must remain PWA icon sources when GD and Imagick are unavailable.');
}
if (!str_contains($pwaRuntime, "'type' => (string)(\$source['mime'] ?? 'image/png')")
    || !str_contains($pwaRuntime, "'sizes' => bms_pwa_site_icon_native_size(\$source)")) {
    bm_smoke_fail($failures, 'Direct Site Identity PWA manifest icons must use the source image MIME type and native dimensions.');
}

$authRuntime = @file_get_contents($root . '/_bonumark_stream/app/auth.php') ?: '';
$shareRoute = @file_get_contents($root . '/admin/share-target.php') ?: '';
if (!str_contains($authRuntime, "'share-target.php' => 'publish_content'")) {
    bm_smoke_fail($failures, 'Share-target admin route must be mapped to publish_content.');
}
if (!str_contains($shareRoute, 'bms_require_login()') || !str_contains($shareRoute, "bms_require_capability('publish_content')")) {
    bm_smoke_fail($failures, 'Share-target route must require authentication and stream-publish capability.');
}
if (!str_contains($shareRoute, 'bms_share_target_store_pending($incoming)') || !str_contains($shareRoute, 'bms_share_target_front_composer_url()')) {
    bm_smoke_fail($failures, 'Share-target route must hand shared content to the front-end composer.');
}
if (str_contains($shareRoute, 'bms_sync_stream_metadata') || str_contains($shareRoute, "'status' => 'draft'")) {
    bm_smoke_fail($failures, 'Share-target route must not create backend drafts directly.');
}
if (preg_match('/share[-_]target.*published|published.*share[-_]target/i', $shareRoute) === 1) {
    bm_smoke_fail($failures, 'Share-target route must not publish shared content directly.');
}
if (!str_contains($shareRoute, 'bms_share_target_payload_from_array($_POST)') || str_contains($shareRoute, 'bms_share_target_payload_from_array($_GET)')) {
    bm_smoke_fail($failures, 'Share-target intake must read shared payloads from POST only.');
}
if (!str_contains($shareRoute, "share-target.php?pending=1") || !str_contains($shareRoute, 'bms_request_origin_is_same_site_or_absent')) {
    bm_smoke_fail($failures, 'Share-target must preserve only a pending-session continuation and validate browser origin metadata.');
}
$composerTemplate = @file_get_contents($root . '/_bonumark_stream/app/views/default/templates/composer.php') ?: '';
$rendererRuntime = @file_get_contents($root . '/_bonumark_stream/app/renderer.php') ?: '';
if (!str_contains($composerTemplate, "data['body_value']") || !str_contains($rendererRuntime, 'bms_share_target_take_pending_payload')) {
    bm_smoke_fail($failures, 'Front-end composer must support prefilled share-target content.');
}

// Remember-me app login checks.
$rememberMigration = @file_get_contents($root . '/_bonumark_stream/migrations/0006_remember_me_sessions.php') ?: '';
$adminLogin = @file_get_contents($root . '/admin/login.php') ?: '';
$accountTemplate = @file_get_contents($root . '/_bonumark_stream/app/views/default/templates/account.php') ?: '';
$accountRoute = @file_get_contents($root . '/_bonumark_stream/app/routes.php') ?: '';
$appearanceRuntime = @file_get_contents($root . '/_bonumark_stream/app/appearance.php') ?: '';
$readingSettings = @file_get_contents($root . '/admin/settings-reading.php') ?: '';
if (!str_contains($rememberMigration, 'remember_tokens') || !str_contains($rememberMigration, 'remember_login_enabled') || !str_contains($rememberMigration, 'remember_login_days')) {
    bm_smoke_fail($failures, 'Remember-me migration must create tokens table and default settings.');
}
foreach (['bms_create_remember_token', 'bms_restore_remembered_login', 'bms_revoke_current_remember_token', 'bms_revoke_user_remember_tokens'] as $requiredRememberFunction) {
    if (!str_contains($authRuntime, 'function ' . $requiredRememberFunction . '(')) {
        bm_smoke_fail($failures, 'Remember-me auth helper is missing: ' . $requiredRememberFunction);
    }
}
if (!str_contains($authRuntime, 'function bms_attempt_login(string $username, string $password, bool $remember = false)')) {
    bm_smoke_fail($failures, 'Login handler must accept the remember-me flag.');
}
if (!str_contains($authRuntime, 'hash(\'sha256\', $validator)') || !str_contains($authRuntime, 'last_used_at = NOW()')) {
    bm_smoke_fail($failures, 'Remember-me tokens must store hashed validators and rotate on use.');
}
if (!str_contains($authRuntime, "'httponly' => true") || !str_contains($authRuntime, "'samesite' => 'Lax'")) {
    bm_smoke_fail($failures, 'Remember-me cookie must be HttpOnly and SameSite=Lax.');
}
if (!str_contains($adminLogin, 'name="remember_me"') || !str_contains($accountTemplate, 'name="remember_me"')) {
    bm_smoke_fail($failures, 'Login forms must include Remember this device when enabled.');
}
if (!str_contains($accountTemplate, 'method="post"') || !str_contains($accountTemplate, 'name="action" value="logout"') || !str_contains($accountRoute, 'Sign out must be completed from the account page.') || preg_match('/GET[^\n]{0,400}bms_logout/', $accountRoute) === 1 || str_contains($appearanceRuntime, 'account.php?action=logout')) {
    bm_smoke_fail($failures, 'Logout must remain a CSRF-protected POST action without a legacy GET logout path or navigation link.');
}
if (!str_contains($readingSettings, 'remember_login_enabled') || !str_contains($readingSettings, 'remember_login_days')) {
    bm_smoke_fail($failures, 'Settings > Stream must expose remember-me login controls.');
}
if (!str_contains($authRuntime, 'bms_revoke_user_remember_tokens($currentId)') || !str_contains($authRuntime, 'bms_revoke_user_remember_tokens($id)')) {
    bm_smoke_fail($failures, 'Remembered devices must be revoked on password changes and admin password resets.');
}
if (!str_contains($functionsSource, 'function bms_session_cookie_name') || !str_contains($functionsSource, 'function bms_session_cookie_path') || !str_contains($functionsSource, 'session_name(bms_session_cookie_name())')) {
    bm_smoke_fail($failures, 'Sessions must use a Bonumark-specific cookie name and install-scoped cookie path.');
}
if (!str_contains($functionsSource, 'function bms_log_admin_exception') || !str_contains($functionsSource, 'function bms_request_origin_is_same_site_or_absent')) {
    bm_smoke_fail($failures, 'Release remediation security helpers are missing.');
}
$adminFiles = glob($root . '/admin/*.php') ?: [];
foreach ($adminFiles as $adminFile) {
    $adminSource = (string)file_get_contents($adminFile);
    if (preg_match('/bms_flash\([^\n;]*\$e->getMessage\(/', $adminSource) === 1) {
        bm_smoke_fail($failures, 'Admin UI must not flash raw exception details: ' . basename($adminFile));
    }
}

$migrationRepairPath = $root . '/_bonumark_stream/migrations/0011_published_timestamp_cutover_repair.php';
if (!is_file($migrationRepairPath) || !str_contains((string)file_get_contents($migrationRepairPath), 'stream_published_at_utc_cutover')) {
    bm_smoke_fail($failures, 'Published timestamp corrective migration is missing.');
}
if (!str_contains($databaseSource, 'function bms_resolve_stream_published_at_utc_cutover') || !str_contains($databaseSource, 'function bms_migration_contains_ddl') || !str_contains($databaseSource, 'function bms_database_table_exists')) {
    bm_smoke_fail($failures, 'Timestamp repair or resumable DDL migration support is missing.');
}
if (str_contains($databaseSource, 'SHOW TABLES LIKE :table_name') || !str_contains($databaseSource, "SHOW TABLES LIKE ' . \$pdo->quote(\$table)")) {
    bm_smoke_fail($failures, 'Database table-existence checks must use MariaDB-compatible quoted SHOW TABLES syntax.');
}
$databaseSmokeSource = @file_get_contents($root . '/scripts/database-smoke-test.php') ?: '';
$databaseSmokeFixturePath = $root . '/scripts/fixtures/v0.4.x-initial-schema.php';
if (!is_file($databaseSmokeFixturePath)) {
    bm_smoke_fail($failures, 'Historical v0.4.x database smoke fixture is missing.');
} else {
    $databaseSmokeFixtureSource = (string)file_get_contents($databaseSmokeFixturePath);
    $databaseSmokeFixtureGitSha = sha1('blob ' . strlen($databaseSmokeFixtureSource) . "\0" . $databaseSmokeFixtureSource);
    if ($databaseSmokeFixtureGitSha !== '3e7e70385dcaa2fb621809430e1e660bdce9459b') {
        bm_smoke_fail($failures, 'Historical v0.4.x database smoke fixture no longer matches the verified public baseline.');
    }
}
if (!str_contains($databaseSmokeSource, 'bms_install_schema($pdo, $freshPrefix)')
    || !str_contains($databaseSmokeSource, "/scripts/fixtures/v0.4.x-initial-schema.php")
    || !str_contains($databaseSmokeSource, 'Supported-upgrade schema smoke test passed')) {
    bm_smoke_fail($failures, 'Database smoke test must verify fresh-install and historical supported-upgrade paths separately.');
}
foreach (['SHOW COLUMNS FROM `{$prefix}posts` LIKE :column_name', 'SHOW INDEX FROM `{$prefix}posts` WHERE Key_name = :index_name', 'SHOW TABLES LIKE :table_name'] as $unsupportedShowStatement) {
    if (str_contains($databaseSmokeSource, $unsupportedShowStatement)) {
        bm_smoke_fail($failures, 'Database smoke test contains a MariaDB-incompatible parameterized SHOW statement: ' . $unsupportedShowStatement);
    }
}
$upgradeSource = $upgraderSource;
if (!str_contains($functionsSource, "'profile-export.php' => true") || !str_contains($upgradeSource, "'profile-export.php',")) {
    bm_smoke_fail($failures, 'Upgrade management must treat the Profile export endpoint as package-managed software.');
}
if (!str_contains($upgradeSource, '$skipPublic = [\'media\' => true, \'uploads\' => true]')
    || !str_contains($upgradeSource, '!isset($skipPublic[$topLevel])')
    || !str_contains($upgradeSource, '$preservedRuntimeItems = [\'media\' => true, \'uploads\' => true]')
    || !str_contains($upgradeSource, 'isset($preservedRuntimeItems[$item])')) {
    bm_smoke_fail($failures, 'Upgrade software selection and backups must skip public media and upload runtime directories.');
}
if (!str_contains($functionsSource, "'CHANGELOG.md' => true")
    || !str_contains($functionsSource, "'analytics.php' => true")) {
    bm_smoke_fail($failures, 'Upgrade cleanup coverage is missing package-managed top-level files.');
}
if (!str_contains($upgradeSource, "'profile-editorial' => true")
    || !str_contains($upgradeSource, "'profile-split' => true")
    || !str_contains($upgradeSource, "'bundled-declarative-proof-theme'")
    || !str_contains($upgradeSource, 'Warm retired bundled-theme detection before obsolete-file removal starts.')) {
    bm_smoke_fail($failures, 'Upgrade cleanup must safely recognize and retire only former Bonumark declarative proof-theme leftovers.');
}
foreach ([
    "'manifest.php' => true",
    "'pwa-icon.php' => true",
    "'sw.js' => true",
] as $requiredManagedPathText) {
    if (!str_contains($functionsSource, $requiredManagedPathText)) {
        bm_smoke_fail($failures, 'Package-managed software contract is missing: ' . $requiredManagedPathText);
    }
}
foreach ([
    'bms_run_migrations($historyFromVersion)',
    'bms_write_upgrade_recovery_state',
    'bms_upgrade_record_recovery_required',
    'bms_upgrade_recovery_message',
] as $requiredUpgradeText) {
    if (!str_contains($upgradeSource, $requiredUpgradeText)) {
        bm_smoke_fail($failures, 'Upgrade remediation behavior is missing: ' . $requiredUpgradeText);
    }
}

if (!str_contains($databaseSource, 'function bms_upgrade_recovery_marker_path')
    || !str_contains($databaseSource, 'function bms_write_upgrade_recovery_state')
    || !str_contains($databaseSource, 'function bms_upgrade_recovery_matches_package')) {
    bm_smoke_fail($failures, 'Forward-only upgrade recovery state helpers are missing.');
}

$pwaSource = @file_get_contents($root . '/_bonumark_stream/app/pwa.php') ?: '';
if (!str_contains($pwaSource, 'function bms_share_target_client_hash')
    || !str_contains($pwaSource, 'function bms_share_target_rate_limit_path')
    || !str_contains($pwaSource, 'flock($handle, LOCK_EX)')
    || !str_contains($pwaSource, 'count($clean) > 1000')) {
    bm_smoke_fail($failures, 'Share target must use bounded server-side locked rate limit state keyed by a salted client hash.');
}
if (!str_contains($pwaSource, 'function bms_pwa_icon_source')
    || !str_contains($pwaSource, 'function bms_pwa_icon_url')
    || !str_contains($pwaSource, 'function bms_pwa_output_icon')
    || !str_contains($pwaSource, 'pwa-icon.php?size=')) {
    bm_smoke_fail($failures, 'PWA must derive installed app icon URLs from the Site Identity favicon when available.');
}

$topLevelScriptFiles = glob($root . '/scripts/*.php') ?: [];
sort($topLevelScriptFiles);
foreach ($topLevelScriptFiles as $scriptPath) {
    $script = bm_smoke_relative($root, $scriptPath);
    $scriptSource = @file_get_contents($scriptPath) ?: '';
    if (!str_contains($scriptSource, "PHP_SAPI !== 'cli'") || !str_contains($scriptSource, "exit('CLI only.')")) {
        bm_smoke_fail($failures, 'CLI script must refuse web execution: ' . $script);
    }
}

if (!str_contains($authRuntime, 'function bms_remember_expires_at_utc') || !str_contains($authRuntime, 'bms_remember_expires_at_utc($expires)')) {
    bm_smoke_fail($failures, 'Remember-me expiration must be stored in UTC.');
}

$registrationSource = @file_get_contents($root . '/_bonumark_stream/app/registration.php') ?: '';
if (!str_contains($registrationSource, 'bms_site_timezone()')
    || !str_contains($registrationSource, 'bms_utc_timezone()')
    || !str_contains($registrationSource, 'function bms_registration_invite_expiration_to_utc')
    || !str_contains($registrationSource, 'function bms_registration_format_invite_expiration')) {
    bm_smoke_fail($failures, 'Invite expirations must convert site-local input to UTC and render back in site time.');
}

$likeEndpoint = @file_get_contents($root . '/_bonumark_stream/app/stream-like-endpoint.php') ?: '';
if (!str_contains($likeEndpoint, 'bms_request_origin_is_same_site_or_absent')) {
    bm_smoke_fail($failures, 'Public like endpoint must reject cross-site browser submissions.');
}
$themeInstaller = @file_get_contents($root . '/_bonumark_stream/app/theme-installer.php') ?: '';
if (substr_count($themeInstaller, 'bms_read_theme_manifest_file((string)$candidate[\'manifest\'])') !== 1) {
    bm_smoke_fail($failures, 'Theme installer must read a candidate manifest once.');
}
$placesApp = @file_get_contents($root . '/_bonumark_stream/app/places.php') ?: '';
if (!str_contains($placesApp, 'function bms_places_nearby') || !str_contains($placesApp, 'function bms_place_request_fields')) {
    bm_smoke_fail($failures, 'Local Places core functions are missing.');
}
if (!str_contains($placesApp, 'data-place-create-modal')
    || !str_contains($placesApp, 'data-place-new-public-label')
    || str_contains($placesApp, 'data-place-new-category')
    || str_contains($placesApp, 'data-place-display-select')) {
    bm_smoke_fail($failures, 'Local Places composer controls are not using the simplified picker and add-place dialog.');
}
$placesMigration = @file_get_contents($root . '/_bonumark_stream/migrations/0014_local_places.php') ?: '';
if (!str_contains($placesMigration, '{{prefix}}places')) {
    bm_smoke_fail($failures, 'Local Places migration is missing or invalid.');
}
$composerTemplate = @file_get_contents($root . '/_bonumark_stream/app/views/default/templates/composer.php') ?: '';
if (!str_contains($composerTemplate, 'data-local-places-toggle')) {
    bm_smoke_fail($failures, 'Front-end composer is missing the Local Places control.');
}
$locationTemplate = @file_get_contents($root . '/_bonumark_stream/app/views/default/templates/location.php') ?: '';
if ($locationTemplate === '' || str_contains($locationTemplate, 'latitude') || str_contains($locationTemplate, 'longitude')) {
    bm_smoke_fail($failures, 'Public Local Places template is missing or exposes coordinates.');
}

$activityPubSource = @file_get_contents($root . '/_bonumark_stream/app/activitypub.php') ?: '';
$publicationSource = @file_get_contents($root . '/_bonumark_stream/app/publication.php') ?: '';
$schedulerSource = @file_get_contents($root . '/_bonumark_stream/app/scheduler.php') ?: '';
$activityPubMigration = @file_get_contents($root . '/_bonumark_stream/migrations/0018_activitypub_foundation.php') ?: '';
if (!str_contains($functionDefaults, "'activitypub_enabled' => '0'")
    || !str_contains($configSample, "'activitypub_enabled' => '0'")
    || !str_contains($activityPubSource, 'function bms_activitypub_enabled(): bool')
    || !str_contains($activityPubSource, 'if (!bms_activitypub_enabled()')
    || !str_contains($activityPubSource, 'bms_activitypub_encrypt_private_key_with_key')
    || !str_contains($activityPubSource, 'bms_register_publication_transition_handler')) {
    bm_smoke_fail($failures, 'The default-off ActivityPub capability and protected signing-key foundation is incomplete.');
}
if (!str_contains($publicationSource, 'function bms_dispatch_publication_transition')
    || !str_contains($databaseSource, "['source' => 'database_upsert']")
    || !str_contains($schedulerSource, "['source' => 'scheduled_tasks']")) {
    bm_smoke_fail($failures, 'The core publication-transition seam is incomplete.');
}
if (!str_contains($schedulerSource, 'function bms_register_scheduled_task_handler')
    || !str_contains($schedulerSource, 'function bms_run_registered_scheduled_tasks')
    || !str_contains($schedulerSource, "'task_results' =>")) {
    bm_smoke_fail($failures, 'The generic scheduled-task handler registry is incomplete.');
}
foreach (['activitypub_keys', 'activitypub_local_objects', 'activitypub_publication_events', 'activitypub_deliveries'] as $activityPubTable) {
    if (!str_contains($activityPubMigration, '{{prefix}}' . $activityPubTable)) {
        bm_smoke_fail($failures, 'ActivityPub foundation migration is missing table: ' . $activityPubTable);
    }
}

$activityPubDelivery = @file_get_contents($root . '/_bonumark_stream/app/activitypub-delivery.php') ?: '';
$activityPubDeliveryMigration = @file_get_contents($root . '/_bonumark_stream/migrations/0022_activitypub_publication_delivery.php') ?: '';
$activityPubPermalinkMigration = @file_get_contents($root . '/_bonumark_stream/migrations/0023_activitypub_permalink_aliases.php') ?: '';
$activityPubGenerationMigration = @file_get_contents($root . '/_bonumark_stream/migrations/0024_activitypub_publication_generations.php') ?: '';
$activityPubInteractionMigration = @file_get_contents($root . '/_bonumark_stream/migrations/0025_activitypub_remote_interactions.php') ?: '';
$activityPubInteractions = @file_get_contents($root . '/_bonumark_stream/app/activitypub-interactions.php') ?: '';
$activityPubOwnerMigration = @file_get_contents($root . '/_bonumark_stream/migrations/0026_activitypub_owner_participation.php') ?: '';
$activityPubOwner = @file_get_contents($root . '/_bonumark_stream/app/activitypub-owner.php') ?: '';
$activityPubFollowing = @file_get_contents($root . '/_bonumark_stream/app/following.php') ?: '';
$activityPubFollowingTemplate = @file_get_contents($root . '/_bonumark_stream/app/views/default/templates/following.php') ?: '';
$publicRoutesSource = @file_get_contents($root . '/_bonumark_stream/app/routes.php') ?: '';
if (!str_contains($activityPubDelivery, "delivery_type = 'publication'")
    || !str_contains($activityPubDelivery, 'event_id IS NOT NULL')
    || !str_contains($activityPubDelivery, 'bms_activitypub_queue_publication_fanout')
    || !str_contains($activityPubDeliveryMigration, 'transition_fingerprint')
    || !str_contains($activityPubPermalinkMigration, 'activitypub_permalink_aliases')
    || !str_contains($activityPubGenerationMigration, 'post_generation')
    || !str_contains($activityPubDelivery, 'bms_activitypub_highest_publication_generation')
    || !str_contains($activityPubDelivery, 'function bms_activitypub_permalink_alias_target')
    || !str_contains($publicRoutesSource, 'bms_activitypub_permalink_alias_target')) {
    $failures[] = 'Stage 4 durable publication activity and delivery isolation are incomplete.';
}
foreach (['activitypub_blocks', 'activitypub_remote_replies', 'activitypub_remote_interactions', 'activitypub_interaction_log'] as $activityPubTable) {
    if (!str_contains($activityPubInteractionMigration, '{{prefix}}' . $activityPubTable)) {
        bm_smoke_fail($failures, 'ActivityPub Stage 5 migration is missing table: ' . $activityPubTable);
    }
}
if (!str_contains($activityPubInteractions, 'function bms_activitypub_process_reply_create')
    || !str_contains($activityPubInteractions, 'function bms_activitypub_process_reply_update')
    || !str_contains($activityPubInteractions, 'function bms_activitypub_process_reply_delete')
    || !str_contains($activityPubInteractions, 'function bms_activitypub_process_remote_interaction')
    || !str_contains($activityPubInteractions, 'function bms_activitypub_process_interaction_undo')
    || !str_contains($activityPubInteractions, 'target_publication_generation')
    || !str_contains($activityPubInteractions, 'bms_activitypub_sanitize_remote_html')) {
    bm_smoke_fail($failures, 'ActivityPub Stage 5 generation-aware inbound interaction handling is incomplete.');
}
foreach (['activitypub_follow_log', 'activitypub_remote_objects', 'activitypub_owner_interactions', 'activitypub_owner_action_log', 'activitypub_reply_targets'] as $activityPubTable) {
    if (!str_contains($activityPubOwnerMigration, '{{prefix}}' . $activityPubTable)) {
        bm_smoke_fail($failures, 'ActivityPub Stage 6 migration is missing table: ' . $activityPubTable);
    }
}
foreach (['bms_activitypub_follow_remote_actor', 'bms_activitypub_unfollow_remote_actor', 'bms_activitypub_fetch_remote_object', 'bms_activitypub_create_owner_reply_draft', 'bms_activitypub_owner_interact', 'bms_activitypub_owner_undo_interaction', 'bms_activitypub_run_owner_deliveries'] as $ownerFunction) {
    if (!str_contains($activityPubOwner, 'function ' . $ownerFunction)) {
        bm_smoke_fail($failures, 'ActivityPub Stage 6 owner participation is missing: ' . $ownerFunction);
    }
}
if (!str_contains($activityPubFollowing, 'function bms_handle_activitypub_following_route')
    || !str_contains($activityPubFollowing, 'bms_activitypub_following_private_headers')
    || !str_contains($activityPubFollowing, "bms_current_user_can('view_admin')")
    || !str_contains($activityPubFollowing, 'bms_verify_csrf()')
    || !str_contains($activityPubFollowing, "'private_surface' => true")
    || !str_contains($activityPubFollowingTemplate, 'following_action')) {
    bm_smoke_fail($failures, 'ActivityPub Stage 6.5 private owner frontend boundaries are incomplete.');
}
$activityPubRoutes = @file_get_contents($root . '/_bonumark_stream/app/activitypub-routes.php') ?: '';
$activityPubSerialization = @file_get_contents($root . '/_bonumark_stream/app/activitypub-serialization.php') ?: '';
$activityPubSecurity = @file_get_contents($root . '/_bonumark_stream/app/activitypub-security.php') ?: '';
$activityPubInbox = @file_get_contents($root . '/_bonumark_stream/app/activitypub-inbox.php') ?: '';
$activityPubInboxMigration = @file_get_contents($root . '/_bonumark_stream/migrations/0020_activitypub_inbox_followers.php') ?: '';
$frontController = @file_get_contents($root . '/index.php') ?: '';
$apacheRoutes = @file_get_contents($root . '/.htaccess') ?: '';
$nginxRoutes = @file_get_contents($root . '/docs/server/bonumark-stream-nginx.conf') ?: '';
if (!str_contains($apacheRoutes, 'following/conversation') || !str_contains($nginxRoutes, 'following/conversation')) {
    bm_smoke_fail($failures, 'ActivityPub Stage 6.5 private Following routing is incomplete.');
}
if (!str_contains($activityPubRoutes, 'function bms_dispatch_activitypub_route')
    || !str_contains($activityPubSerialization, 'function bms_activitypub_actor_document')
    || !str_contains($activityPubSerialization, 'function bms_activitypub_outbox_document')) {
    bm_smoke_fail($failures, 'ActivityPub read-only identity and serialization are incomplete.');
}
foreach (['activitypub_webfinger', 'activitypub_actor', 'activitypub_inbox', 'activitypub_outbox', 'activitypub_followers', 'activitypub_following', 'activitypub_object', 'activitypub_create_activity', 'activitypub_event_activity', 'activitypub_owner_activity'] as $activityPubRoute) {
    if (!str_contains($frontController . $apacheRoutes . $nginxRoutes, $activityPubRoute)) {
        bm_smoke_fail($failures, 'ActivityPub routing is incomplete: ' . $activityPubRoute);
    }
}
if (!str_contains($apacheRoutes, 'activitypub/objects/([1-9][0-9]*)/generations/([1-9][0-9]*)')
    || !str_contains($nginxRoutes, 'activitypub/objects/([1-9][0-9]*)/generations/([1-9][0-9]*)')) {
    bm_smoke_fail($failures, 'ActivityPub generation-aware object routing is incomplete.');
}
if (str_contains($activityPubRoutes, 'curl_')
    || !str_contains($activityPubSecurity, 'CURLOPT_RESOLVE')
    || !str_contains($activityPubSecurity, 'CURLOPT_FOLLOWLOCATION => false')
    || !str_contains($activityPubSecurity, 'function bms_activitypub_verify_rfc9421_http_signature')
    || !str_contains($activityPubSecurity, "'format' => 'rfc9421'")
    || !str_contains($activityPubInbox, "delivery_type = 'follower_response'")
    || !str_contains($activityPubInbox, 'event_id IS NULL')) {
    bm_smoke_fail($failures, 'The Stage 3 inbox and response-delivery security boundary is incomplete.');
}
foreach (['activitypub_remote_actors', 'activitypub_inbox_receipts', 'activitypub_signature_replays', 'activitypub_followers', 'activitypub_following'] as $activityPubTable) {
    if (!str_contains($activityPubInboxMigration, '{{prefix}}' . $activityPubTable)) {
        bm_smoke_fail($failures, 'ActivityPub inbox migration is missing table: ' . $activityPubTable);
    }
}
foreach ([
    "RewriteRule ^profile/([A-Za-z0-9._-]+)/?$",
    "RewriteRule ^stream/([A-Za-z0-9._-]+)/?$",
] as $humanRoute) {
    if (!str_contains($apacheRoutes, $humanRoute)) {
        bm_smoke_fail($failures, 'ActivityPub changed or removed an existing human-facing route: ' . $humanRoute);
    }
}

$manifestPath = $root . '/_bonumark_stream/RELEASE-MANIFEST.json';
$manifest = is_file($manifestPath) ? json_decode((string)file_get_contents($manifestPath), true) : null;
if ($checkReleaseManifest && (!is_array($manifest) || !isset($manifest['files']) || !is_array($manifest['files']))) {
    bm_smoke_fail($failures, 'Release manifest is missing or invalid.');
} elseif ($checkReleaseManifest) {
    if (($manifest['name'] ?? '') !== 'bonumark-stream') {
        bm_smoke_fail($failures, 'Release manifest package name is invalid.');
    }
    if (($manifest['version'] ?? '') !== $rootVersion) {
        bm_smoke_fail($failures, 'Release manifest version does not match VERSION.');
    }
    if (trim((string)($manifest['generated_at'] ?? '')) === '') {
        bm_smoke_fail($failures, 'Release manifest generated_at is missing.');
    }
    $manifestFiles = [];
    foreach ($manifest['files'] as $entry) {
        $relative = str_replace('\\', '/', (string)($entry['path'] ?? ''));
        $hash = strtolower((string)($entry['sha256'] ?? ''));
        if ($relative === ''
            || $relative === '_bonumark_stream/RELEASE-MANIFEST.json'
            || str_starts_with($relative, '/')
            || preg_match('#(^|/)\.\.(/|$)#', $relative) === 1
            || !preg_match('/^[a-f0-9]{64}$/', $hash)) {
            bm_smoke_fail($failures, 'Release manifest contains an invalid entry.');
            continue;
        }
        if (isset($manifestFiles[$relative])) {
            bm_smoke_fail($failures, 'Release manifest contains a duplicate path: ' . $relative);
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


$seoFunctionsSource = @file_get_contents($root . '/_bonumark_stream/app/functions.php') ?: '';
if (!str_contains($seoFunctionsSource, "\$documentTemplates = ['layout', 'home', 'archive', 'single', 'page', 'profile', 'account', 'search']")
    || !str_contains($seoFunctionsSource, "if (!in_array(\$template, \$documentTemplates, true))")) {
    bm_smoke_fail($failures, 'Document SEO must be explicitly limited to full-page public templates.');
}

$linkPreviewSource = @file_get_contents($root . '/_bonumark_stream/app/link-preview.php') ?: '';
$documentTitlePos = strpos($linkPreviewSource, '$title = bms_link_preview_document_title($html);');
$openGraphFallbackPos = strpos($linkPreviewSource, '$title = bms_link_preview_meta_value($html, [\'og:title\', \'twitter:title\']);');
if (!str_contains($linkPreviewSource, 'function bms_link_preview_document_title')
    || !str_contains($linkPreviewSource, 'function bms_link_preview_from_html')
    || $documentTitlePos === false
    || $openGraphFallbackPos === false
    || $documentTitlePos >= $openGraphFallbackPos
    || !str_contains($linkPreviewSource, 'function bms_link_preview_strip_local_site_suffix')
    || str_contains($linkPreviewSource, 'function bms_link_preview_normalize_remote_title')
    || str_contains($linkPreviewSource, 'bms_seo_strip_site_title($title, $localSiteName)')) {
    bm_smoke_fail($failures, 'Link previews must preserve the remote document title and must not reconstruct it from the local site name.');
}

require_once $root . '/_bonumark_stream/app/link-preview.php';
$linkPreviewFixtureHtml = '<html><head>'
    . '<title>Remote Article | Remote Site</title>'
    . '<meta property="og:title" content="Remote Article | Wrong Local Name">'
    . '<meta property="og:site_name" content="Remote Site">'
    . '<meta name="description" content="Remote description">'
    . '</head><body></body></html>';
$linkPreviewFixture = bms_link_preview_from_html('https://remote.example/article', $linkPreviewFixtureHtml);
if (($linkPreviewFixture['title'] ?? '') !== 'Remote Article | Remote Site') {
    bm_smoke_fail($failures, 'Link preview must prefer the remote HTML document title over conflicting social metadata.');
}
if (($linkPreviewFixture['site_name'] ?? '') !== 'Remote Site') {
    bm_smoke_fail($failures, 'Link preview must preserve the remote site-name metadata separately from the title.');
}

$linkPreviewContaminated = bms_link_preview_sanitize_payload([
    'url' => 'https://remote.example/article',
    'title' => 'Remote Article | Remote Site | Bonumark Stream',
    'description' => 'Remote description',
    'image' => '',
    'site_name' => 'Remote Site',
]);
if (($linkPreviewContaminated['title'] ?? '') !== 'Remote Article | Remote Site') {
    bm_smoke_fail($failures, 'External link preview titles must remove a trailing local Bonumark site-name contamination.');
}

$linkPreviewLegitimateLocalName = bms_link_preview_sanitize_payload([
    'url' => 'https://bonumark.example/article',
    'title' => 'Remote Article | Bonumark Stream',
    'description' => '',
    'image' => '',
    'site_name' => 'Bonumark Stream',
]);
if (($linkPreviewLegitimateLocalName['title'] ?? '') !== 'Remote Article | Bonumark Stream') {
    bm_smoke_fail($failures, 'A remote site legitimately using the same site name must not have its title altered.');
}


$upgradeSource = $upgraderSource;

$linkPreviewEndpointSource = @file_get_contents($root . '/admin/link-preview.php') ?: '';
$quickPostSource = @file_get_contents($root . '/admin/quick-post.php') ?: '';
$toolsSource = @file_get_contents($root . '/admin/tools.php') ?: '';
if (is_file($root . '/admin/link-preview-pipeline.php')) {
    bm_smoke_fail($failures, 'Temporary link-preview pipeline diagnostic must not ship after the cleanup pass.');
}
if (str_contains($linkPreviewSource, 'bms_link_preview_pipeline_trace_')
    || str_contains($linkPreviewSource, 'bms_link_preview_raw_metadata_from_html')
    || str_contains($linkPreviewEndpointSource, 'bms_link_preview_pipeline_last_fetch')
    || str_contains($quickPostSource, 'bms_link_preview_pipeline_last_submit')
    || str_contains($toolsSource, 'link-preview-pipeline.php')) {
    bm_smoke_fail($failures, 'Temporary link-preview pipeline trace instrumentation remains in a production path.');
}

if (is_file($root . '/admin/runtime-cache.php')) {
    bm_smoke_fail($failures, 'Manual PHP Runtime Cache admin diagnostic must not ship after the v0.5.104 cleanup pass.');
}
if (str_contains($toolsSource, 'runtime-cache.php')
    || str_contains($linkPreviewSource, 'bms_link_preview_runtime_revision')) {
    bm_smoke_fail($failures, 'Manual PHP runtime diagnostic hooks were reintroduced.');
}

$fragmentTitle = 'Builder Receipt: Reworking Bonumark Stream | Jim Lunsford';
$fragmentSeoData = bms_public_seo_view_data('link-preview', [
    'title' => $fragmentTitle,
    'site_name' => 'Bonumark Stream',
]);
if (($fragmentSeoData['title'] ?? '') !== $fragmentTitle) {
    bm_smoke_fail($failures, 'Fragment-template SEO processing must not rewrite a link-preview title.');
}
if (array_key_exists('seo_document_title', $fragmentSeoData)) {
    bm_smoke_fail($failures, 'Fragment-template SEO processing must not inject document SEO fields.');
}

$cardFragmentData = bms_public_seo_view_data('card', [
    'title' => 'Remote card title',
    'site_name' => 'Bonumark Stream',
]);
if (($cardFragmentData['title'] ?? '') !== 'Remote card title') {
    bm_smoke_fail($failures, 'Fragment-template SEO processing must not rewrite card domain data.');
}

$documentSeoData = bms_public_seo_view_data('page', [
    'title' => 'Documentation',
    'site_name' => 'Bonumark Stream',
]);
if (!isset($documentSeoData['seo_document_title']) || trim((string)$documentSeoData['seo_document_title']) === '') {
    bm_smoke_fail($failures, 'Document templates must continue receiving document SEO data.');
}
if (!str_contains($upgradeSource, 'bms_upgrade_invalidate_php_runtime_file')
    || !str_contains($upgradeSource, 'bms_upgrade_reset_php_runtime_cache')
    || !str_contains($upgradeSource, 'opcache_invalidate($path, true)')
    || !str_contains($upgradeSource, 'opcache_reset()')) {
    bm_smoke_fail($failures, 'Upgrade-time PHP runtime-cache invalidation is missing.');
}

$themeLayoutsPath = $root . '/_bonumark_stream/app/theme-layouts.php';
if (!is_file($themeLayoutsPath)) {
    bm_smoke_fail($failures, 'Declarative layout foundation file is missing.');
} else {
    require_once $themeLayoutsPath;

    $legacyThemeProbe = [
        'name' => 'Legacy Probe',
        'slug' => 'legacy-probe',
        'version' => '1.0.0',
    ];
    if (bms_theme_layout_manifest_errors($legacyThemeProbe) !== []) {
        bm_smoke_fail($failures, 'Legacy CSS-only themes must not require declarative layout fields.');
    }

    $layoutThemeProbe = [
        'name' => 'Layout Probe',
        'slug' => 'layout-probe',
        'version' => '1.0.0',
        'layout_schema' => 1,
        'layouts' => ['profile' => 'layouts/profile.json'],
    ];
    if (bms_theme_layout_manifest_errors($layoutThemeProbe) !== []) {
        bm_smoke_fail($failures, 'Valid declarative Profile manifest contract was rejected.');
    }

    $normalizedLayoutTheme = bms_normalize_theme_layout_manifest($layoutThemeProbe);
    if (empty($normalizedLayoutTheme['layout_aware'])
        || ($normalizedLayoutTheme['layout_schema'] ?? null) !== 1
        || (($normalizedLayoutTheme['layouts']['profile'] ?? '') !== 'layouts/profile.json')) {
        bm_smoke_fail($failures, 'Declarative layout manifest normalization is incorrect.');
    }

    $validProfileLayout = [
        'surface' => 'profile',
        'root' => [
            'type' => 'group',
            'name' => 'profile-root',
            'children' => [
                ['type' => 'component', 'name' => 'profile.cover'],
                [
                    'type' => 'group',
                    'name' => 'identity-row',
                    'children' => [
                        ['type' => 'component', 'name' => 'profile.avatar'],
                        ['type' => 'component', 'name' => 'profile.identity'],
                    ],
                ],
                ['type' => 'component', 'name' => 'profile.about'],
                ['type' => 'component', 'name' => 'profile.featured'],
                ['type' => 'component', 'name' => 'profile.photos'],
                ['type' => 'component', 'name' => 'profile.now'],
                ['type' => 'component', 'name' => 'profile.interests'],
                ['type' => 'component', 'name' => 'profile.links'],
                ['type' => 'component', 'name' => 'profile.details'],
            ],
        ],
    ];
    if (bms_theme_layout_document_errors($validProfileLayout, 'profile', 1) !== []) {
        bm_smoke_fail($failures, 'Valid declarative Profile layout was rejected.');
    }

    $streamLayoutThemeProbe = [
        'name' => 'Stream Layout Probe',
        'slug' => 'stream-layout-probe',
        'version' => '1.0.0',
        'layout_schema' => 1,
        'layouts' => ['stream-card' => 'layouts/stream-card.json'],
    ];
    if (bms_theme_layout_manifest_errors($streamLayoutThemeProbe) !== []) {
        bm_smoke_fail($failures, 'Valid declarative Stream Card manifest contract was rejected.');
    }
    $normalizedStreamLayoutTheme = bms_normalize_theme_layout_manifest($streamLayoutThemeProbe);
    if (empty($normalizedStreamLayoutTheme['layout_aware'])
        || ($normalizedStreamLayoutTheme['layout_schema'] ?? null) !== 1
        || (($normalizedStreamLayoutTheme['layouts']['stream-card'] ?? '') !== 'layouts/stream-card.json')) {
        bm_smoke_fail($failures, 'Declarative Stream Card manifest normalization is incorrect.');
    }

    $validStreamCardLayout = [
        'surface' => 'stream-card',
        'root' => [
            'type' => 'group',
            'name' => 'stream-card-root',
            'children' => [
                [
                    'type' => 'group',
                    'name' => 'stream-card-identity',
                    'children' => [
                        ['type' => 'component', 'name' => 'stream-card.avatar'],
                        ['type' => 'component', 'name' => 'stream-card.header'],
                    ],
                ],
                ['type' => 'component', 'name' => 'stream-card.body'],
                ['type' => 'component', 'name' => 'stream-card.location'],
                ['type' => 'component', 'name' => 'stream-card.link-preview'],
                ['type' => 'component', 'name' => 'stream-card.media'],
                ['type' => 'component', 'name' => 'stream-card.actions'],
            ],
        ],
    ];
    if (bms_theme_layout_document_errors($validStreamCardLayout, 'stream-card', 1) !== []) {
        bm_smoke_fail($failures, 'Valid declarative Stream Card layout was rejected.');
    }

    if (!in_array('site-header', bms_theme_layout_supported_surfaces(), true)
        || bms_theme_layout_surface_label('site-header') !== 'Site Header') {
        bm_smoke_fail($failures, 'Site Header must remain a supported Schema 1 declarative surface.');
    }

    $siteHeaderLayoutThemeProbe = [
        'name' => 'Site Header Composition Probe',
        'slug' => 'site-header-composition-probe',
        'version' => '1.0.0',
        'layout_schema' => 1,
        'layouts' => ['site-header' => 'layouts/site-header.json'],
    ];
    if (bms_theme_layout_manifest_errors($siteHeaderLayoutThemeProbe) !== []) {
        bm_smoke_fail($failures, 'Valid Site Header declarative manifest contract was rejected.');
    }

    $validSiteHeaderLayout = [
        'surface' => 'site-header',
        'root' => [
            'type' => 'group',
            'name' => 'site-header-root',
            'children' => [
                ['type' => 'component', 'name' => 'site-header.site-identity'],
                ['type' => 'component', 'name' => 'site-header.primary-navigation'],
                ['type' => 'component', 'name' => 'site-header.stream-count'],
            ],
        ],
    ];
    if (bms_theme_layout_document_errors($validSiteHeaderLayout, 'site-header', 1) !== []) {
        bm_smoke_fail($failures, 'Valid declarative Site Header layout was rejected.');
    }

    $invalidSiteHeaderLayout = $validSiteHeaderLayout;
    $invalidSiteHeaderLayout['root']['children'][] = ['type' => 'component', 'name' => 'site-header.search'];
    if (bms_theme_layout_document_errors($invalidSiteHeaderLayout, 'site-header', 1) === []) {
        bm_smoke_fail($failures, 'Site Header validation must reject unsupported or invented application components.');
    }

    $siteHeaderExpectedComponents = [
        'site-header.site-identity' => ['required' => true, 'template' => 'site-header/site-identity.php'],
        'site-header.primary-navigation' => ['required' => true, 'template' => 'site-header/primary-navigation.php'],
        'site-header.menu-toggle' => ['required' => false, 'template' => 'site-header/menu-toggle.php'],
        'site-header.stream-count' => ['required' => false, 'template' => 'site-header/stream-count.php'],
    ];
    foreach ($siteHeaderExpectedComponents as $componentName => $expectation) {
        $definition = bms_theme_layout_component_definition($componentName);
        if (!is_array($definition)
            || ($definition['surface'] ?? '') !== 'site-header'
            || ($definition['required'] ?? null) !== $expectation['required']
            || ($definition['max'] ?? null) !== 1
            || ($definition['template'] ?? '') !== $expectation['template']) {
            bm_smoke_fail($failures, 'Site Header component registry definition is incorrect: ' . $componentName);
        }
    }

    $siteHeaderComponentData = [
        'site_name' => 'Foundation Site',
        'tagline' => 'Core-owned identity',
        'tagline_html' => 'Core-owned identity',
        'home_url' => '/home/',
        'title_tag' => 'h1',
        'preview_mode' => false,
        'show_public_menu' => true,
        'navigation_html' => '<nav id="site-primary-nav" aria-label="Primary menu"><a href="/">Home</a></nav>',
        'menu_label' => 'Menu',
        'show_count_chip' => true,
        'count_label' => '12 posts',
    ];
    $siteIdentityProbe = bms_render_core_public_component('site-header.site-identity', $siteHeaderComponentData);
    $siteNavigationProbe = bms_render_core_public_component('site-header.primary-navigation', $siteHeaderComponentData);
    $siteMenuProbe = bms_render_core_public_component('site-header.menu-toggle', $siteHeaderComponentData);
    $siteCountProbe = bms_render_core_public_component('site-header.stream-count', $siteHeaderComponentData);
    if (!str_contains($siteIdentityProbe, '<h1 class="site-title">')
        || !str_contains($siteIdentityProbe, 'Foundation Site')
        || !str_contains($siteIdentityProbe, 'Core-owned identity')) {
        bm_smoke_fail($failures, 'Site Header identity component does not render prepared identity data.');
    }
    if ($siteNavigationProbe !== $siteHeaderComponentData['navigation_html']) {
        bm_smoke_fail($failures, 'Site Header primary-navigation component must render core-prepared navigation unchanged.');
    }
    if (!str_contains($siteMenuProbe, 'data-stream-menu-toggle')
        || !str_contains($siteMenuProbe, 'aria-controls="site-primary-nav"')) {
        bm_smoke_fail($failures, 'Site Header menu-toggle component does not preserve core navigation behavior hooks.');
    }
    if (!str_contains($siteCountProbe, '12 posts')) {
        bm_smoke_fail($failures, 'Site Header stream-count component does not render the prepared count label.');
    }

    $alternateStreamCardLayout = $validStreamCardLayout;
    $alternateStreamCardLayout['root']['name'] = 'stream-card-body-first';
    $alternateStreamCardLayout['root']['children'] = [
        ['type' => 'component', 'name' => 'stream-card.body'],
        ['type' => 'component', 'name' => 'stream-card.media'],
        [
            'type' => 'group',
            'name' => 'stream-card-identity',
            'children' => [
                ['type' => 'component', 'name' => 'stream-card.avatar'],
                ['type' => 'component', 'name' => 'stream-card.header'],
            ],
        ],
        ['type' => 'component', 'name' => 'stream-card.location'],
        ['type' => 'component', 'name' => 'stream-card.link-preview'],
        ['type' => 'component', 'name' => 'stream-card.actions'],
    ];
    if (bms_theme_layout_document_errors($alternateStreamCardLayout, 'stream-card', 1) !== []) {
        bm_smoke_fail($failures, 'Alternate declarative Stream Card layout was rejected.');
    }


    require_once $root . '/_bonumark_stream/app/themes.php';

    $layoutRenderData = [
        'cover_markup' => '<img src="cover.jpg" alt="">',
        'avatar_markup' => '<img src="avatar.jpg" alt="">',
        'display_name' => 'Layout Probe',
        'username' => 'layout-probe',
        'headline' => 'Declarative profile proof',
        'location' => 'Indiana',
        'bio' => 'Prepared profile data.',
        'about_html' => '<p>About component</p>',
        'featured_items' => [['type' => 'external', 'url' => 'https://example.com/work', 'title' => 'Work', 'description' => 'Featured', 'external' => true]],
        'profile_photos' => [['url' => '/photo.jpg', 'image_attributes' => 'src="/photo.jpg" alt="Photo"', 'caption' => 'Photo caption']],
        'now_text' => 'Building Theme Architecture 2.0',
        'interests' => ['Publishing'],
        'website' => 'https://example.com',
        'profile_links' => [['url' => 'https://example.org', 'label' => 'Example']],
        'show_post_count' => true,
        'post_count' => 3,
        'show_comment_count' => true,
        'comment_count' => 2,
        'show_member_since' => true,
        'member_since' => '2026',
        'is_profile_owner' => false,
    ];
    $layoutRenderTheme = array_merge($layoutThemeProbe, $normalizedLayoutTheme);
    $layoutThemePath = $root . '/_bonumark_stream/themes/layout-probe';
    @mkdir($layoutThemePath . '/layouts', 0755, true);
    file_put_contents($layoutThemePath . '/layouts/profile.json', json_encode($validProfileLayout, JSON_UNESCAPED_SLASHES));
    $renderedProfileLayout = bms_render_public_theme_layout_surface('profile', $layoutRenderData, $layoutRenderTheme);
    if (!is_string($renderedProfileLayout)
        || !str_contains($renderedProfileLayout, 'data-bms-layout="profile"')
        || !str_contains($renderedProfileLayout, 'data-bms-layout-schema="1"')
        || !str_contains($renderedProfileLayout, 'data-bms-layout-group="identity-row"')
        || substr_count($renderedProfileLayout, 'data-bms-component=') !== 10
        || !str_contains($renderedProfileLayout, 'data-bms-component="profile.identity"')) {
        bm_smoke_fail($failures, 'Declarative Profile renderer did not produce the validated root/group/component hook contract.');
    }
    $legacyLayoutResult = bms_render_public_theme_layout_surface('profile', $layoutRenderData, $legacyThemeProbe);
    if ($legacyLayoutResult !== null) {
        bm_smoke_fail($failures, 'Legacy CSS-only themes must remain on the fixed Profile composition path.');
    }
    @unlink($layoutThemePath . '/layouts/profile.json');
    @rmdir($layoutThemePath . '/layouts');
    @rmdir($layoutThemePath);

    $streamLayoutRenderData = [
        'single' => false,
        'page_url' => '/post/layout-probe',
        'preview_mode' => false,
        'avatar_html' => '<img src="avatar.jpg" alt="Layout Probe">',
        'author_profile_url' => '/profile/layout-probe',
        'author_name' => 'Layout Probe',
        'show_dates' => true,
        'date_label' => 'Aug 10, 2026 10:00 PM',
        'date_iso' => '2026-08-10T22:00:00-04:00',
        'body_html' => '<p>Prepared Stream Card body.</p>',
        'location_html' => '<div class="stream-card-location">Indiana</div>',
        'link_preview_html' => '<a class="stream-link-preview" href="https://example.com">Preview</a>',
        'media_html' => '<div class="stream-card-media"><img src="photo.jpg" alt="Photo"></div>',
        'like' => [
            'enabled' => true,
            'slug' => 'layout-probe',
            'count' => 1,
            'label' => '1 like',
            'liked' => false,
            'endpoint' => '/stream-like.php',
            'endpoint_alt' => '/admin/stream-like.php',
            'action_label' => 'Like this post.',
        ],
        'comments' => [
            'enabled' => true,
            'count' => 2,
            'label' => '2 Comments',
            'url' => '/post/layout-probe#comments',
        ],
        'quick_edit' => [
            'enabled' => true,
            'endpoint' => '/admin/stream-quick-edit.php',
            'csrf_token' => 'probe-token',
            'filename' => 'layout-probe.md',
            'body' => 'Prepared Stream Card body.',
            'content_hash' => 'probe-hash',
        ],
        'edit_url' => '/admin/edit.php?file=layout-probe.md',
        'trash_action' => [
            'enabled' => true,
            'endpoint' => '/admin/stream-trash.php',
            'csrf_token' => 'probe-token',
            'filename' => 'layout-probe.md',
            'content_hash' => 'probe-hash',
            'return_to' => '/',
            'single' => false,
        ],
        'pin_action' => 'pin',
        'pin_action_url' => '/admin/pin.php',
        'pin_csrf' => 'probe-token',
        'pin_return_to' => '/',
        'pin_filename' => 'layout-probe.md',
    ];
    $streamLayoutRenderTheme = array_merge($streamLayoutThemeProbe, $normalizedStreamLayoutTheme);
    $streamLayoutThemePath = $root . '/_bonumark_stream/themes/stream-layout-probe';
    @mkdir($streamLayoutThemePath . '/layouts', 0755, true);
    file_put_contents($streamLayoutThemePath . '/layouts/stream-card.json', json_encode($validStreamCardLayout, JSON_UNESCAPED_SLASHES));
    $renderedStreamLayout = bms_render_public_theme_layout_surface('stream-card', $streamLayoutRenderData, $streamLayoutRenderTheme);
    if (!is_string($renderedStreamLayout)
        || !str_contains($renderedStreamLayout, 'data-bms-layout="stream-card"')
        || !str_contains($renderedStreamLayout, 'data-bms-layout-schema="1"')
        || !str_contains($renderedStreamLayout, 'data-bms-layout-group="stream-card-identity"')
        || substr_count($renderedStreamLayout, 'data-bms-component=') !== 7
        || !str_contains($renderedStreamLayout, 'data-bms-component="stream-card.body"')
        || !str_contains($renderedStreamLayout, 'data-stream-quick-edit-form')
        || !str_contains($renderedStreamLayout, 'data-stream-like')
        || !str_contains($renderedStreamLayout, 'data-stream-actions-menu')) {
        bm_smoke_fail($failures, 'Declarative Stream Card renderer did not preserve the validated component and core behavior-hook contract.');
    }
    foreach (array_keys(bms_theme_layout_component_registry()['stream-card'] ?? []) as $streamComponentName) {
        $componentHtml = bms_render_core_public_component($streamComponentName, $streamLayoutRenderData);
        if (trim($componentHtml) !== '' && !str_contains((string)$renderedStreamLayout, $componentHtml)) {
            bm_smoke_fail($failures, 'Declarative Stream Card composition changed core component output: ' . $streamComponentName);
        }
    }
    $renderCoreTemplate = static function (string $templatePath, array $viewData): string {
        $bms_theme_data = $viewData;
        ob_start();
        include $templatePath;
        return (string)ob_get_clean();
    };
    $streamCardTemplateData = $streamLayoutRenderData;
    $streamCardTemplateData['theme'] = $streamLayoutRenderTheme;
    $streamCardTemplateData['classes'] = 'stream-card stream-card-clickable';
    $renderedDeclarativeCardTemplate = $renderCoreTemplate($root . '/_bonumark_stream/app/views/default/templates/card.php', $streamCardTemplateData);
    if (!str_contains($renderedDeclarativeCardTemplate, '<article class="stream-card stream-card-clickable ledger-stream-card" data-stream-card')
        || !str_contains($renderedDeclarativeCardTemplate, 'data-bms-layout="stream-card"')
        || str_contains($renderedDeclarativeCardTemplate, '<div class="stream-card-inner">')
        || str_contains($renderedDeclarativeCardTemplate, '<div class="stream-card-main">')) {
        bm_smoke_fail($failures, 'Declarative Stream Card template must keep the core article shell while replacing only the legacy inner/main composition.');
    }
    $legacyStreamLayoutResult = bms_render_public_theme_layout_surface('stream-card', $streamLayoutRenderData, $legacyThemeProbe);
    if ($legacyStreamLayoutResult !== null) {
        bm_smoke_fail($failures, 'Legacy CSS-only themes must remain on the fixed Stream Card composition path.');
    }

    file_put_contents($streamLayoutThemePath . '/layouts/stream-card.json', json_encode($alternateStreamCardLayout, JSON_UNESCAPED_SLASHES));
    $renderedAlternateStreamLayout = bms_render_public_theme_layout_surface('stream-card', $streamLayoutRenderData, $streamLayoutRenderTheme);
    $standardHeaderPos = strpos((string)$renderedStreamLayout, 'data-bms-component="stream-card.header"');
    $standardBodyPos = strpos((string)$renderedStreamLayout, 'data-bms-component="stream-card.body"');
    $alternateHeaderPos = strpos((string)$renderedAlternateStreamLayout, 'data-bms-component="stream-card.header"');
    $alternateBodyPos = strpos((string)$renderedAlternateStreamLayout, 'data-bms-component="stream-card.body"');
    if (!is_string($renderedAlternateStreamLayout)
        || $renderedAlternateStreamLayout === $renderedStreamLayout
        || $standardHeaderPos === false || $standardBodyPos === false || $standardHeaderPos > $standardBodyPos
        || $alternateHeaderPos === false || $alternateBodyPos === false || $alternateBodyPos > $alternateHeaderPos) {
        bm_smoke_fail($failures, 'Declarative Stream Card layouts must be able to produce materially different component composition from identical prepared data.');
    }
    @unlink($streamLayoutThemePath . '/layouts/stream-card.json');
    @rmdir($streamLayoutThemePath . '/layouts');
    @rmdir($streamLayoutThemePath);

    $unsafeProfileLayout = $validProfileLayout;
    $unsafeProfileLayout['root']['children'][0]['when'] = 'profile.has_cover';
    $unsafeErrors = bms_theme_layout_document_errors($unsafeProfileLayout, 'profile', 1);
    if (!$unsafeErrors || !str_contains(implode(' ', $unsafeErrors), 'unsupported property')) {
        bm_smoke_fail($failures, 'Declarative layout validation must reject expression-like or unknown node properties.');
    }

    $unknownComponentLayout = $validProfileLayout;
    $unknownComponentLayout['root']['children'][0]['name'] = 'profile.unknown';
    $unknownComponentErrors = bms_theme_layout_document_errors($unknownComponentLayout, 'profile', 1);
    if (!$unknownComponentErrors || !str_contains(implode(' ', $unknownComponentErrors), 'unknown component')) {
        bm_smoke_fail($failures, 'Declarative layout validation must reject unregistered components.');
    }

    if (bms_theme_layout_reference('../profile.json') !== ''
        || bms_theme_layout_reference('profile.json') !== ''
        || bms_theme_layout_reference('layouts/profile.php') !== ''
        || bms_theme_layout_reference('layouts/profile.json') !== 'layouts/profile.json') {
        bm_smoke_fail($failures, 'Declarative layout path validation does not enforce private layouts/*.json references.');
    }

    $layoutPrivateProbe = sys_get_temp_dir() . '/bms-layout-private-' . bin2hex(random_bytes(4));
    $layoutPublicProbe = sys_get_temp_dir() . '/bms-layout-public-' . bin2hex(random_bytes(4));
    @mkdir($layoutPrivateProbe . '/layouts', 0755, true);
    @mkdir($layoutPublicProbe, 0755, true);
    file_put_contents($layoutPrivateProbe . '/layouts/profile.json', json_encode($validProfileLayout, JSON_UNESCAPED_SLASHES));
    $layoutHealthTheme = array_merge($layoutThemeProbe, $normalizedLayoutTheme, [
        'author' => 'Bonumark',
        'description' => 'Declarative layout health probe.',
        'manifest_errors' => [],
        'assets' => ['css' => [], 'images' => [], 'fonts' => []],
        'screenshot' => '',
        'settings' => [],
    ]);

    require_once $root . '/_bonumark_stream/app/themes.php';
    $validLayoutHealth = bms_public_theme_package_health_at_paths($layoutHealthTheme, $layoutPrivateProbe, $layoutPublicProbe);
    if (empty($validLayoutHealth['valid'])
        || ($validLayoutHealth['renderer'] ?? '') !== 'Declarative Layouts'
        || (($validLayoutHealth['layout_schema'] ?? null) !== 1)
        || (($validLayoutHealth['layout_surfaces'] ?? []) !== ['profile'])) {
        bm_smoke_fail($failures, 'Theme Health does not accept and report a valid declarative Profile layout.');
    }

    @unlink($layoutPrivateProbe . '/layouts/profile.json');
    $missingLayoutHealth = bms_public_theme_package_health_at_paths($layoutHealthTheme, $layoutPrivateProbe, $layoutPublicProbe);
    if (!empty($missingLayoutHealth['valid']) || !str_contains(implode(' ', (array)($missingLayoutHealth['errors'] ?? [])), 'Missing declared layout file')) {
        bm_smoke_fail($failures, 'Theme Health must reject a missing declared layout file before activation.');
    }
    @rmdir($layoutPrivateProbe . '/layouts');
    @rmdir($layoutPrivateProbe);
    @rmdir($layoutPublicProbe);

    $streamHealthPrivateProbe = sys_get_temp_dir() . '/bms-stream-layout-private-' . bin2hex(random_bytes(4));
    $streamHealthPublicProbe = sys_get_temp_dir() . '/bms-stream-layout-public-' . bin2hex(random_bytes(4));
    @mkdir($streamHealthPrivateProbe . '/layouts', 0755, true);
    @mkdir($streamHealthPublicProbe, 0755, true);
    file_put_contents($streamHealthPrivateProbe . '/layouts/stream-card.json', json_encode($validStreamCardLayout, JSON_UNESCAPED_SLASHES));
    $streamHealthTheme = array_merge($streamLayoutThemeProbe, $normalizedStreamLayoutTheme, [
        'author' => 'Bonumark',
        'description' => 'Declarative Stream Card health probe.',
        'manifest_errors' => [],
        'assets' => ['css' => [], 'images' => [], 'fonts' => []],
        'screenshot' => '',
        'settings' => [],
    ]);
    $validStreamLayoutHealth = bms_public_theme_package_health_at_paths($streamHealthTheme, $streamHealthPrivateProbe, $streamHealthPublicProbe);
    if (empty($validStreamLayoutHealth['valid'])
        || ($validStreamLayoutHealth['renderer'] ?? '') !== 'Declarative Layouts'
        || (($validStreamLayoutHealth['layout_schema'] ?? null) !== 1)
        || (($validStreamLayoutHealth['layout_surfaces'] ?? []) !== ['stream-card'])) {
        bm_smoke_fail($failures, 'Theme Health does not accept and report a valid declarative Stream Card layout.');
    }
    @unlink($streamHealthPrivateProbe . '/layouts/stream-card.json');
    @rmdir($streamHealthPrivateProbe . '/layouts');
    @rmdir($streamHealthPrivateProbe);
    @rmdir($streamHealthPublicProbe);

    $defaultThemeProbe = bms_read_theme_manifest('default');
    $defaultThemeAssetUrl = bms_public_theme_asset_url('assets/css/theme.css', 'default');
    if (!is_array($defaultThemeProbe)
        || ($defaultThemeProbe['version'] ?? '') !== '1.9.1'
        || !str_contains($defaultThemeAssetUrl, 'v=1.9.1')
        || str_contains($defaultThemeAssetUrl, 'v=' . rawurlencode($rootVersion))) {
        bm_smoke_fail($failures, 'Public theme assets must use the theme manifest version as their cache revision.');
    }


    // v0.5.119 keeps the two materially different declarative proof designs
    // as internal regression fixtures instead of bundled/user-selectable themes.
    // The fixtures continue to validate all four Schema 1 surfaces without
    // appearing in Theme Manager or public theme assets.
    $proofFixtureBase = $root . '/scripts/fixtures/declarative-themes';
    $proofThemes = [
        'profile-editorial' => [
            'root_group' => 'editorial-profile',
            'stream_root_group' => 'editorial-stream-card',
            'responsive_css' => '@media (max-width: 780px)',
            'stream_responsive_css' => '@media (max-width: 640px)',
            'theme_version' => '1.3.1',
            'hardening_css' => '@media (max-width: 420px)',
            'stream_css' => '/* Theme Architecture 2.0 proof: Editorial Stream Cards */',
            'header_root_group' => 'editorial-site-header',
            'header_css' => '/* Declarative Site Header proof: editorial masthead with always-visible navigation. */',
            'header_toggle' => false,
            'home_root_group' => 'editorial-home',
            'home_css' => '/* Declarative Home proof: reading-first editorial composition. */',
            'site_hardening_css' => '/* Site Composition hardening: shared Header + Home containment. */',
        ],
        'profile-split' => [
            'root_group' => 'split-profile',
            'stream_root_group' => 'split-stream-card',
            'responsive_css' => '@media (max-width: 860px)',
            'stream_responsive_css' => '@media (max-width: 760px)',
            'theme_version' => '1.3.1',
            'hardening_css' => '/* Declarative Profile width containment */',
            'stream_css' => '/* Theme Architecture 2.0 proof: Split Stream Cards */',
            'header_root_group' => 'split-site-header',
            'header_css' => '/* Declarative Site Header proof: utility-first masthead with core-owned menu toggle. */',
            'header_toggle' => true,
            'home_root_group' => 'split-home',
            'home_css' => '/* Declarative Home proof: workspace composition with a publish rail. */',
            'site_hardening_css' => '/* Site Composition hardening: shared Header + Home containment. */',
        ],
    ];

    if (bms_public_theme_bundled_slugs() !== ['default']) {
        bm_smoke_fail($failures, 'Midnight Ledger must be the only bundled/protected theme after consolidation.');
    }
    if (!bms_public_theme_is_retired_bundled_manifest(['slug' => 'profile-editorial', 'package' => 'bundled-declarative-proof-theme'])
        || bms_public_theme_is_retired_bundled_manifest(['slug' => 'profile-editorial', 'package' => 'third-party-theme'])
        || bms_public_theme_is_retired_bundled_manifest(['slug' => 'custom-editorial', 'package' => 'bundled-declarative-proof-theme'])) {
        bm_smoke_fail($failures, 'Retired proof-theme detection must require both a former proof slug and the exact Bonumark bundled marker.');
    }
    $discoveredThemePackages = bms_public_theme_packages();
    if (array_diff(['profile-editorial', 'profile-split'], array_keys($discoveredThemePackages)) !== ['profile-editorial', 'profile-split']) {
        bm_smoke_fail($failures, 'Internal declarative regression fixtures must not be discovered as installed themes.');
    }
    foreach (['profile-editorial', 'profile-split'] as $retiredProofSlug) {
        if (is_dir($root . '/_bonumark_stream/themes/' . $retiredProofSlug)
            || is_dir($root . '/assets/themes/' . $retiredProofSlug)
            || bms_public_theme_is_bundled($retiredProofSlug)) {
            bm_smoke_fail($failures, 'Retired proof themes must not remain bundled or publicly mirrored: ' . $retiredProofSlug);
        }
    }

    $proofRenderedProfiles = [];
    $proofRenderedStreamCards = [];
    $proofRenderedSiteHeaders = [];
    $proofRenderedHomes = [];
    $proofFixtureThemes = [];
    foreach ($proofThemes as $proofSlug => $proofExpectation) {
        $fixtureRoot = $proofFixtureBase . '/' . $proofSlug;
        $proofTheme = bms_read_theme_manifest_file($fixtureRoot . '/theme.json', $proofSlug);
        if (!is_array($proofTheme)) {
            bm_smoke_fail($failures, 'Internal declarative regression fixture manifest is missing: ' . $proofSlug);
            continue;
        }
        $proofFixtureThemes[$proofSlug] = $proofTheme;
        $proofHealth = bms_public_theme_package_health_at_paths($proofTheme, $fixtureRoot, $fixtureRoot);
        if (($proofTheme['version'] ?? '') !== $proofExpectation['theme_version']) {
            bm_smoke_fail($failures, 'Internal declarative regression fixture revision is stale: ' . $proofSlug);
        }
        if (empty($proofHealth['valid'])
            || ($proofHealth['renderer'] ?? '') !== 'Declarative Layouts'
            || ($proofHealth['layout_schema'] ?? null) !== 1
            || ($proofHealth['layout_surfaces'] ?? []) !== ['home', 'profile', 'site-header', 'stream-card']) {
            bm_smoke_fail($failures, 'Internal declarative regression fixture does not pass Theme Health: ' . $proofSlug);
        }

        foreach (['profile', 'stream-card', 'site-header', 'home'] as $surface) {
            $layoutPath = $fixtureRoot . '/' . (string)(($proofTheme['layouts'] ?? [])[$surface] ?? '');
            $layout = json_decode((string)@file_get_contents($layoutPath), true);
            if (!is_array($layout) || bms_theme_layout_document_errors($layout, $surface, 1) !== []) {
                bm_smoke_fail($failures, 'Internal declarative regression fixture layout is invalid: ' . $proofSlug . ' / ' . $surface);
            }
        }

        $renderedProof = bm_smoke_render_layout_fixture($fixtureRoot, 'profile', $layoutRenderData, $proofTheme);
        $proofRenderedProfiles[$proofSlug] = is_string($renderedProof) ? $renderedProof : '';
        if (!is_string($renderedProof)
            || substr_count($renderedProof, 'data-bms-component=') !== 10
            || substr_count($renderedProof, '<h1 id="profile-name">') !== 1
            || substr_count($renderedProof, 'data-stream-media-viewer') !== 1
            || !str_contains($renderedProof, 'data-bms-layout-group="' . $proofExpectation['root_group'] . '"')) {
            bm_smoke_fail($failures, 'Internal declarative fixture did not render the complete Profile component/accessibility contract: ' . $proofSlug);
        }
        foreach (array_keys(bms_theme_layout_component_registry()['profile'] ?? []) as $componentName) {
            $componentHtml = bms_render_core_public_component($componentName, $layoutRenderData);
            if (trim($componentHtml) !== '' && !str_contains((string)$renderedProof, $componentHtml)) {
                bm_smoke_fail($failures, 'Internal Profile fixture changed core component output instead of composition: ' . $proofSlug . ' / ' . $componentName);
            }
        }

        $renderedStreamProof = bm_smoke_render_layout_fixture($fixtureRoot, 'stream-card', $streamLayoutRenderData, $proofTheme);
        $proofRenderedStreamCards[$proofSlug] = is_string($renderedStreamProof) ? $renderedStreamProof : '';
        if (!is_string($renderedStreamProof)
            || substr_count($renderedStreamProof, 'data-bms-component=') !== 7
            || !str_contains($renderedStreamProof, 'data-bms-layout="stream-card"')
            || !str_contains($renderedStreamProof, 'data-bms-layout-group="' . $proofExpectation['stream_root_group'] . '"')
            || !str_contains($renderedStreamProof, 'data-stream-quick-edit-form')
            || !str_contains($renderedStreamProof, 'data-stream-like')
            || !str_contains($renderedStreamProof, 'data-stream-actions-menu')) {
            bm_smoke_fail($failures, 'Internal declarative fixture did not render the complete Stream Card component/behavior contract: ' . $proofSlug);
        }
        foreach (array_keys(bms_theme_layout_component_registry()['stream-card'] ?? []) as $componentName) {
            $componentHtml = bms_render_core_public_component($componentName, $streamLayoutRenderData);
            if (trim($componentHtml) !== '' && !str_contains((string)$renderedStreamProof, $componentHtml)) {
                bm_smoke_fail($failures, 'Internal Stream Card fixture changed core component output instead of composition: ' . $proofSlug . ' / ' . $componentName);
            }
        }

        $siteHeaderProofData = [
            'site_name' => 'Header Proof',
            'tagline' => 'Same core identity and navigation',
            'tagline_html' => 'Same core identity and navigation',
            'home_url' => '/',
            'title_tag' => 'h1',
            'preview_mode' => false,
            'show_public_menu' => true,
            'navigation_html' => '<nav class="site-nav stream-site-nav" id="site-primary-nav" aria-label="Primary navigation"><ul class="site-nav-list"><li><a href="/" aria-current="page">Home</a></li><li><a href="/profile/jim">Profile</a></li></ul></nav>',
            'menu_label' => 'Menu',
            'show_count_chip' => true,
            'count_label' => '12 posts',
        ];
        $renderedHeaderProof = bm_smoke_render_layout_fixture($fixtureRoot, 'site-header', $siteHeaderProofData, $proofTheme);
        $proofRenderedSiteHeaders[$proofSlug] = is_string($renderedHeaderProof) ? $renderedHeaderProof : '';
        if (!is_string($renderedHeaderProof)
            || !str_contains($renderedHeaderProof, 'data-bms-layout="site-header"')
            || !str_contains($renderedHeaderProof, 'data-bms-layout-group="' . $proofExpectation['header_root_group'] . '"')
            || substr_count($renderedHeaderProof, '<h1 class="site-title">') !== 1
            || !str_contains($renderedHeaderProof, 'id="site-primary-nav"')
            || !str_contains($renderedHeaderProof, 'aria-current="page"')
            || !str_contains($renderedHeaderProof, '12 posts')) {
            bm_smoke_fail($failures, 'Internal declarative fixture did not preserve the Site Header contract: ' . $proofSlug);
        }
        $hasHeaderToggle = str_contains((string)$renderedHeaderProof, 'data-stream-menu-toggle');
        if ($hasHeaderToggle !== $proofExpectation['header_toggle']) {
            bm_smoke_fail($failures, 'Internal Site Header fixture does not match its intended optional menu-toggle composition: ' . $proofSlug);
        }

        $homeProofData = [
            'notices_html' => '<div class="stream-public-notices" role="status">Saved.</div>',
            'composer_html' => '<section class="stream-compose" data-stream-composer><form><input type="hidden" name="_token" value="csrf-proof"><textarea name="body"></textarea></form></section>',
            'pinned_posts_html' => '<section class="stream-pinned-posts"><article data-stream-card data-proof-post="pinned">Pinned card</article></section>',
            'feed_html' => '<article data-stream-card data-proof-post="feed">Feed card</article>',
            'pagination_html' => '<nav class="stream-pagination" data-stream-pagination><a href="/stream/page/2/">Load more</a></nav>',
        ];
        $renderedHomeProof = bm_smoke_render_layout_fixture($fixtureRoot, 'home', $homeProofData, $proofTheme);
        $proofRenderedHomes[$proofSlug] = is_string($renderedHomeProof) ? $renderedHomeProof : '';
        if (!is_string($renderedHomeProof)
            || substr_count($renderedHomeProof, 'data-bms-component=') !== 5
            || !str_contains($renderedHomeProof, 'data-bms-layout="home"')
            || !str_contains($renderedHomeProof, 'data-bms-layout-group="' . $proofExpectation['home_root_group'] . '"')
            || !str_contains($renderedHomeProof, 'data-stream-composer')
            || !str_contains($renderedHomeProof, 'csrf-proof')
            || !str_contains($renderedHomeProof, 'data-proof-post="pinned"')
            || !str_contains($renderedHomeProof, 'data-proof-post="feed"')
            || !str_contains($renderedHomeProof, 'data-stream-pagination')) {
            bm_smoke_fail($failures, 'Internal declarative fixture did not preserve the complete Home contract: ' . $proofSlug);
        }
        foreach (array_keys(bms_theme_layout_component_registry()['home'] ?? []) as $componentName) {
            $componentHtml = bms_render_core_public_component($componentName, $homeProofData);
            if (trim($componentHtml) !== '' && !str_contains((string)$renderedHomeProof, $componentHtml)) {
                bm_smoke_fail($failures, 'Internal Home fixture changed core component output instead of composition: ' . $proofSlug . ' / ' . $componentName);
            }
        }

        $cssSource = (string)@file_get_contents($fixtureRoot . '/assets/css/theme.css');
        $fixtureScreenshot = $fixtureRoot . '/assets/images/screenshot.svg';
        if ($cssSource === '' || !is_file($fixtureScreenshot)
            || !str_contains($cssSource, '.bms-layout-profile')
            || !str_contains($cssSource, 'min-width: 0;')
            || !str_contains($cssSource, 'overflow-wrap: anywhere;')
            || !str_contains($cssSource, $proofExpectation['responsive_css'])
            || !str_contains($cssSource, $proofExpectation['hardening_css'])
            || !str_contains($cssSource, '.bms-layout-stream-card')
            || !str_contains($cssSource, $proofExpectation['stream_css'])
            || !str_contains($cssSource, $proofExpectation['stream_responsive_css'])
            || !str_contains($cssSource, '.bms-layout-site-header')
            || !str_contains($cssSource, $proofExpectation['header_css'])
            || !str_contains($cssSource, '.bms-layout-home')
            || !str_contains($cssSource, $proofExpectation['home_css'])
            || !str_contains($cssSource, $proofExpectation['site_hardening_css'])) {
            bm_smoke_fail($failures, 'Internal declarative regression fixture assets or responsive layout CSS are incomplete: ' . $proofSlug);
        }
    }

    if (isset($proofRenderedProfiles['profile-editorial'], $proofRenderedProfiles['profile-split'])) {
        $editorial = $proofRenderedProfiles['profile-editorial'];
        $split = $proofRenderedProfiles['profile-split'];
        $editorialAbout = strpos($editorial, 'data-bms-component="profile.about"');
        $editorialDetails = strpos($editorial, 'data-bms-component="profile.details"');
        $splitAbout = strpos($split, 'data-bms-component="profile.about"');
        $splitDetails = strpos($split, 'data-bms-component="profile.details"');
        if ($editorial === $split
            || $editorialAbout === false || $editorialDetails === false || $editorialAbout > $editorialDetails
            || $splitAbout === false || $splitDetails === false || $splitDetails > $splitAbout) {
            bm_smoke_fail($failures, 'Internal dual Profile fixtures must preserve materially different validated composition.');
        }
    }

    if (isset($proofRenderedStreamCards['profile-editorial'], $proofRenderedStreamCards['profile-split'])) {
        $editorialStream = $proofRenderedStreamCards['profile-editorial'];
        $splitStream = $proofRenderedStreamCards['profile-split'];
        $editorialBody = strpos($editorialStream, 'data-bms-component="stream-card.body"');
        $editorialHeader = strpos($editorialStream, 'data-bms-component="stream-card.header"');
        $splitBody = strpos($splitStream, 'data-bms-component="stream-card.body"');
        $splitHeader = strpos($splitStream, 'data-bms-component="stream-card.header"');
        if ($editorialStream === $splitStream
            || $editorialBody === false || $editorialHeader === false || $editorialBody > $editorialHeader
            || $splitBody === false || $splitHeader === false || $splitHeader > $splitBody) {
            bm_smoke_fail($failures, 'Internal dual Stream Card fixtures must preserve materially different validated composition.');
        }
    }

    if (isset($proofRenderedSiteHeaders['profile-editorial'], $proofRenderedSiteHeaders['profile-split'])) {
        $editorialHeaderProof = $proofRenderedSiteHeaders['profile-editorial'];
        $splitHeaderProof = $proofRenderedSiteHeaders['profile-split'];
        $editorialNavigation = strpos($editorialHeaderProof, 'data-bms-component="site-header.primary-navigation"');
        $editorialCount = strpos($editorialHeaderProof, 'data-bms-component="site-header.stream-count"');
        $splitNavigation = strpos($splitHeaderProof, 'data-bms-component="site-header.primary-navigation"');
        $splitCount = strpos($splitHeaderProof, 'data-bms-component="site-header.stream-count"');
        if ($editorialHeaderProof === $splitHeaderProof
            || $editorialNavigation === false || $editorialCount === false || $editorialNavigation > $editorialCount
            || $splitNavigation === false || $splitCount === false || $splitCount > $splitNavigation
            || str_contains($editorialHeaderProof, 'data-stream-menu-toggle')
            || !str_contains($splitHeaderProof, 'data-stream-menu-toggle')) {
            bm_smoke_fail($failures, 'Internal dual Site Header fixtures must preserve materially different validated composition.');
        }
    }

    if (isset($proofRenderedHomes['profile-editorial'], $proofRenderedHomes['profile-split'])) {
        $editorialHomeProof = $proofRenderedHomes['profile-editorial'];
        $splitHomeProof = $proofRenderedHomes['profile-split'];
        $editorialFeed = strpos($editorialHomeProof, 'data-bms-component="home.feed"');
        $editorialComposer = strpos($editorialHomeProof, 'data-bms-component="home.composer"');
        $splitFeed = strpos($splitHomeProof, 'data-bms-component="home.feed"');
        $splitComposer = strpos($splitHomeProof, 'data-bms-component="home.composer"');
        if ($editorialHomeProof === $splitHomeProof
            || $editorialFeed === false || $editorialComposer === false || $editorialFeed > $editorialComposer
            || $splitFeed === false || $splitComposer === false || $splitComposer > $splitFeed) {
            bm_smoke_fail($failures, 'Internal dual Home fixtures must preserve materially different validated composition.');
        }
    }

    // Integrated Site Composition proof using internal fixtures. The nested
    // Stream Card HTML is still the exact output of the core components.
    foreach (['profile-editorial', 'profile-split'] as $proofSlug) {
        $proofTheme = $proofFixtureThemes[$proofSlug] ?? null;
        $fixtureRoot = $proofFixtureBase . '/' . $proofSlug;
        $headerHtml = $proofRenderedSiteHeaders[$proofSlug] ?? '';
        $cardHtml = $proofRenderedStreamCards[$proofSlug] ?? '';
        if (!is_array($proofTheme) || $headerHtml === '' || $cardHtml === '') {
            bm_smoke_fail($failures, 'Integrated Site Composition fixture is missing a required surface: ' . $proofSlug);
            continue;
        }

        $nestedHomeData = [
            'notices_html' => '<div class="stream-public-notices" role="status">Integrated proof notice.</div>',
            'composer_html' => '<section class="stream-compose" data-stream-composer><form><input type="hidden" name="csrf_token" value="integrated-csrf"><textarea name="body"></textarea></form></section>',
            'pinned_posts_html' => '<section class="stream-pinned-posts"><h2>Pinned</h2>' . $cardHtml . '</section>',
            'feed_html' => $cardHtml . $cardHtml,
            'pagination_html' => '<nav class="stream-pagination" data-stream-pagination><a href="/stream/page/2/">Load more</a></nav>',
        ];
        $nestedHomeHtml = bm_smoke_render_layout_fixture($fixtureRoot, 'home', $nestedHomeData, $proofTheme);
        $combinedHtml = $headerHtml . (string)$nestedHomeHtml;
        if (!is_string($nestedHomeHtml)
            || substr_count($nestedHomeHtml, 'data-bms-layout="stream-card"') !== 3
            || !str_contains($nestedHomeHtml, 'integrated-csrf')
            || !str_contains($nestedHomeHtml, 'data-stream-quick-edit-form')
            || !str_contains($nestedHomeHtml, 'data-stream-like')
            || !str_contains($nestedHomeHtml, 'data-stream-actions-menu')
            || !str_contains($nestedHomeHtml, 'data-stream-pagination')
            || substr_count($combinedHtml, '<h1') !== 1
            || substr_count($combinedHtml, 'id="site-primary-nav"') !== 1) {
            bm_smoke_fail($failures, 'Integrated Site Composition fixture failed to preserve nested application contracts: ' . $proofSlug);
        }
    }

    $legacyDefaultTheme = bms_read_theme_manifest('default');
    $midnightProfileHtml = is_array($legacyDefaultTheme)
        ? bms_render_public_theme_layout_surface('profile', $layoutRenderData, $legacyDefaultTheme)
        : null;
    $midnightStreamHtml = is_array($legacyDefaultTheme)
        ? bms_render_public_theme_layout_surface('stream-card', $streamLayoutRenderData, $legacyDefaultTheme)
        : null;
    $midnightHeaderHtml = is_array($legacyDefaultTheme)
        ? bms_render_public_theme_layout_surface('site-header', $siteHeaderProofData, $legacyDefaultTheme)
        : null;
    $midnightHomeHtml = is_array($legacyDefaultTheme)
        ? bms_render_public_theme_layout_surface('home', $homeProofData, $legacyDefaultTheme)
        : null;
    if (!is_array($legacyDefaultTheme)
        || ($legacyDefaultTheme['layout_schema'] ?? null) !== 1
        || ($legacyDefaultTheme['layouts']['profile'] ?? '') !== 'layouts/profile.json'
        || ($legacyDefaultTheme['layouts']['stream-card'] ?? '') !== 'layouts/stream-card.json'
        || ($legacyDefaultTheme['layouts']['site-header'] ?? '') !== 'layouts/site-header.json'
        || ($legacyDefaultTheme['layouts']['home'] ?? '') !== 'layouts/home.json'
        || isset(($legacyDefaultTheme['settings'] ?? [])['show_status_chip'])
        || isset(($legacyDefaultTheme['settings'] ?? [])['status_label'])
        || isset(($legacyDefaultTheme['settings'] ?? [])['show_post_count'])
        || !is_string($midnightHomeHtml)
        || !str_contains($midnightHomeHtml, 'data-bms-layout-group="midnight-home"')
        || !str_contains($midnightHomeHtml, 'data-bms-layout-group="midnight-home-publish"')
        || !str_contains($midnightHomeHtml, 'data-bms-layout-group="midnight-home-timeline"')
        || substr_count($midnightHomeHtml, 'data-bms-component=') !== 5
        || strpos($midnightHomeHtml, 'data-bms-component="home.composer"') > strpos($midnightHomeHtml, 'data-bms-component="home.feed"')
        || !is_string($midnightProfileHtml)
        || !str_contains($midnightProfileHtml, 'data-bms-layout-group="midnight-profile"')
        || !str_contains($midnightProfileHtml, 'data-bms-layout-group="midnight-profile-content"')
        || !str_contains($midnightProfileHtml, 'data-bms-layout-group="midnight-profile-rail"')
        || str_contains($midnightProfileHtml, 'data-bms-layout-group="midnight-profile-primary"')
        || substr_count($midnightProfileHtml, 'data-bms-component=') !== 10
        || strpos($midnightProfileHtml, 'data-bms-component="profile.about"') > strpos($midnightProfileHtml, 'data-bms-component="profile.featured"')
        || strpos($midnightProfileHtml, 'data-bms-component="profile.featured"') > strpos($midnightProfileHtml, 'data-bms-component="profile.photos"')
        || strpos($midnightProfileHtml, 'data-bms-component="profile.photos"') > strpos($midnightProfileHtml, 'data-bms-layout-group="midnight-profile-rail"')
        || !is_string($midnightStreamHtml)
        || !str_contains($midnightStreamHtml, 'data-bms-layout-group="midnight-stream-card-inner"')
        || substr_count($midnightStreamHtml, 'data-bms-component=') !== 7
        || !is_string($midnightHeaderHtml)
        || !str_contains($midnightHeaderHtml, 'data-bms-layout-group="midnight-site-header"')
        || !str_contains($midnightHeaderHtml, 'data-bms-component="site-header.site-identity"')
        || !str_contains($midnightHeaderHtml, 'data-bms-component="site-header.menu-toggle"')
        || !str_contains($midnightHeaderHtml, 'data-bms-component="site-header.primary-navigation"')
        || str_contains($midnightHeaderHtml, 'data-bms-component="site-header.stream-count"')
        || !str_contains($midnightHeaderHtml, 'data-stream-menu-toggle')
        || substr_count($midnightHeaderHtml, 'id="site-primary-nav"') !== 1) {
        bm_smoke_fail($failures, 'Midnight Ledger must use declarative Profile/Stream Card/Site Header/Home composition while omitting status/count Header chrome and preserving publish-first Home order.');
    }
    foreach (array_keys(bms_theme_layout_component_registry()['profile'] ?? []) as $componentName) {
        $componentHtml = bms_render_core_public_component($componentName, $layoutRenderData);
        if (trim($componentHtml) !== '' && !str_contains((string)$midnightProfileHtml, $componentHtml)) {
            bm_smoke_fail($failures, 'Midnight Ledger Profile baseline changed core component output: ' . $componentName);
        }
    }
    foreach (array_keys(bms_theme_layout_component_registry()['stream-card'] ?? []) as $componentName) {
        $componentHtml = bms_render_core_public_component($componentName, $streamLayoutRenderData);
        if (trim($componentHtml) !== '' && !str_contains((string)$midnightStreamHtml, $componentHtml)) {
            bm_smoke_fail($failures, 'Midnight Ledger Stream Card baseline changed core component output: ' . $componentName);
        }
    }
    foreach (array_keys(bms_theme_layout_component_registry()['home'] ?? []) as $componentName) {
        $componentHtml = bms_render_core_public_component($componentName, $homeProofData);
        if (trim($componentHtml) !== '' && !str_contains((string)$midnightHomeHtml, $componentHtml)) {
            bm_smoke_fail($failures, 'Midnight Ledger Home composition changed core component output: ' . $componentName);
        }
    }
    $midnightCss = (string)@file_get_contents($root . '/_bonumark_stream/themes/default/assets/css/theme.css');
    if (!str_contains($midnightCss, '/* Midnight Ledger declarative baseline: Profile + Stream Card.')
        || !str_contains($midnightCss, '.bms-layout-group-midnight-profile-hero')
        || !str_contains($midnightCss, '.bms-layout-group-midnight-stream-card-main')
        || !str_contains($midnightCss, 'Midnight Ledger Stream & Mobile Refinement 1.7.0')
        || !str_contains($midnightCss, 'Midnight Ledger Home Composition 1.8.0')
        || !str_contains($midnightCss, '.bms-layout-group-midnight-home-publish')
        || !str_contains($midnightCss, '.bms-layout-group-midnight-home-timeline')
        || !str_contains($midnightCss, '.bms-layout-group-midnight-site-header-bar')
        || !str_contains($midnightCss, '.stream-link-preview:not(.no-image)')
        || !str_contains($midnightCss, 'Midnight Ledger Responsive Polish 1.8.1')
        || !str_contains($midnightCss, '@media (min-width: 360px) and (max-width: 760px)')
        || !str_contains($midnightCss, '.ledger-single-shell .stream-single-card .stream-card-content')
        || !str_contains($midnightCss, 'height: 120px;')
        || !str_contains($midnightCss, 'Midnight Ledger Canvas Alignment 1.8.2')
        || !str_contains($midnightCss, 'context-home-stream-home .ledger-header')
        || !str_contains($midnightCss, 'context-stream-single .ledger-header')
        || !str_contains($midnightCss, 'context-stream-archive .ledger-header')
        || !str_contains($midnightCss, 'context-search-page .ledger-header')
        || !str_contains($midnightCss, 'context-profile-page .ledger-header')
        || !str_contains($midnightCss, 'width: min(100%, 860px);')
        || !str_contains($midnightCss, 'width: min(100%, 1040px);')
        || !str_contains($midnightCss, 'Midnight Ledger Profile Banner Alignment 1.8.3')
        || !str_contains($midnightCss, '[data-bms-component="profile.cover"]')
        || !str_contains($midnightCss, 'object-position: 50% 50%;')
        || !str_contains($midnightCss, 'Midnight Ledger Profile Content Resilience 1.9.0')
        || !str_contains($midnightCss, 'Midnight Ledger Empty Profile State 1.9.1')
        || !str_contains($midnightCss, 'margin: 0 auto;')
        || !str_contains($midnightCss, '[data-bms-component="profile.cover"] + .bms-layout-group-midnight-profile-hero')
        || !str_contains($midnightCss, 'margin-top: -3.5rem;')
        || !str_contains($midnightCss, 'margin-top: -2rem;')
        || !str_contains($midnightCss, 'grid-template-areas:')
        || !str_contains($midnightCss, '"about rail"')
        || !str_contains($midnightCss, '"featured featured"')
        || !str_contains($midnightCss, '"photos photos"')
        || !str_contains($midnightCss, '.profile-photo-gallery-count-4')
        || !str_contains($midnightCss, 'grid-template-columns: repeat(4, minmax(0, 1fr));')) {
        bm_smoke_fail($failures, 'Midnight Ledger declarative/refinement/canvas CSS bridge is incomplete.');
    }


    $declarativeLayoutDocs = (string)@file_get_contents($root . '/docs/DECLARATIVE-LAYOUTS.md');
    $documentedLayoutApis = array_merge(
        bms_theme_layout_supported_surfaces(),
        array_keys(bms_theme_layout_component_registry()['profile'] ?? []),
        array_keys(bms_theme_layout_component_registry()['stream-card'] ?? []),
        array_keys(bms_theme_layout_component_registry()['site-header'] ?? []),
        array_keys(bms_theme_layout_component_registry()['home'] ?? [])
    );
    if ($declarativeLayoutDocs === '') {
        bm_smoke_fail($failures, 'Stable Declarative Layouts theme-author documentation is missing.');
    } else {
        foreach ($documentedLayoutApis as $layoutApi) {
            if (!str_contains($declarativeLayoutDocs, '`' . $layoutApi . '`')) {
                bm_smoke_fail($failures, 'Stable Declarative Layouts documentation is missing public API identifier: ' . $layoutApi);
            }
        }
        foreach (['no expression language', 'Legacy fallback', 'Theme Health', 'Theme versioning'] as $requiredDocBoundary) {
            if (stripos($declarativeLayoutDocs, $requiredDocBoundary) === false) {
                bm_smoke_fail($failures, 'Stable Declarative Layouts documentation is missing compatibility/safety boundary: ' . $requiredDocBoundary);
            }
        }
    }

    if (!str_contains($profileRendererSource, 'function bms_generate_static_site_index')
        || !str_contains($profileRendererSource, 'bms_render_stream_index($pages)')
        || !str_contains($profileRendererSource, "bms_render_stream_index(\$pages, false, 1, 'archive')")) {
        bm_smoke_fail($failures, 'Static Site Export must continue sharing the normal Home/Stream renderer rather than introducing a parallel declarative rendering path.');
    }
}

if (!str_contains($profileThemesSource, "require_once __DIR__ . '/theme-layouts.php';")
    || !str_contains($profileThemesSource, 'bms_theme_layout_manifest_errors($decoded)')
    || !str_contains($profileThemesSource, "\$decoded['layout_aware'] = \$layoutManifest['layout_aware'];")) {
    bm_smoke_fail($failures, 'Theme manifest reader is not wired to the inert declarative layout foundation.');
}
if (!str_contains($profileTemplate, "bms_render_public_theme_layout_surface('profile', \$data, \$profileTheme)")
    || !str_contains((string)@file_get_contents($themeLayoutsPath), 'data-bms-layout=')
    || !str_contains((string)@file_get_contents($themeLayoutsPath), 'data-bms-component=')) {
    bm_smoke_fail($failures, 'Profile template is not wired to the validated declarative composition renderer and stable layout/component hooks.');
}

$expectedProfileComponents = [
    'profile.cover' => 'profile/cover.php',
    'profile.avatar' => 'profile/avatar.php',
    'profile.identity' => 'profile/identity.php',
    'profile.about' => 'profile/about.php',
    'profile.featured' => 'profile/featured.php',
    'profile.photos' => 'profile/photos.php',
    'profile.now' => 'profile/now.php',
    'profile.interests' => 'profile/interests.php',
    'profile.links' => 'profile/links.php',
    'profile.details' => 'profile/details.php',
];
foreach ($expectedProfileComponents as $componentName => $componentReference) {
    $definition = function_exists('bms_theme_layout_component_definition') ? bms_theme_layout_component_definition($componentName) : null;
    $componentPath = $root . '/_bonumark_stream/app/views/default/components/' . $componentReference;
    if (!is_array($definition)
        || ($definition['template'] ?? '') !== $componentReference
        || !is_file($componentPath)
        || !str_contains($profileTemplate, "bms_render_core_public_component('" . $componentName . "', \$data)")) {
        bm_smoke_fail($failures, 'Profile core component extraction is incomplete for: ' . $componentName);
    }
}
if (count($profileComponentFiles) !== 10) {
    bm_smoke_fail($failures, 'Profile component extraction must contain exactly ten registered core component files.');
}
if (!str_contains($profileTemplate, '<section class="profile-hero ledger-profile-hero" aria-labelledby="profile-name">')
    || !str_contains($profileTemplate, "bms_render_core_public_component('profile.avatar', \$data)")
    || !str_contains($profileTemplate, "bms_render_core_public_component('profile.identity', \$data)")) {
    bm_smoke_fail($failures, 'Legacy Profile fallback must keep the existing hero wrapper around avatar and identity components.');
}
foreach (['bms_db(', 'bms_table(', 'bms_setting_or_config(', '$_POST', '$_GET', '$_FILES'] as $forbiddenComponentFetch) {
    if (str_contains($profileComponentsSource, $forbiddenComponentFetch)) {
        bm_smoke_fail($failures, 'Profile core components must render prepared data only and must not fetch application state: ' . $forbiddenComponentFetch);
    }
}
if (!str_contains((string)@file_get_contents($themeLayoutsPath), 'function bms_render_core_public_component(')
    || !str_contains((string)@file_get_contents($themeLayoutsPath), "'template' => 'profile/cover.php'")
    || !str_contains((string)@file_get_contents($themeLayoutsPath), "'template' => 'profile/details.php'")) {
    bm_smoke_fail($failures, 'Core component registry is missing Profile template mappings or the core component renderer.');
}

$expectedStreamCardComponents = [
    'stream-card.avatar' => 'stream-card/avatar.php',
    'stream-card.header' => 'stream-card/header.php',
    'stream-card.body' => 'stream-card/body.php',
    'stream-card.location' => 'stream-card/location.php',
    'stream-card.link-preview' => 'stream-card/link-preview.php',
    'stream-card.media' => 'stream-card/media.php',
    'stream-card.actions' => 'stream-card/actions.php',
];
foreach ($expectedStreamCardComponents as $componentName => $componentReference) {
    $definition = function_exists('bms_theme_layout_component_definition') ? bms_theme_layout_component_definition($componentName) : null;
    $componentPath = $root . '/_bonumark_stream/app/views/default/components/' . $componentReference;
    if (!is_array($definition)
        || ($definition['template'] ?? '') !== $componentReference
        || ($definition['surface'] ?? '') !== 'stream-card'
        || !is_file($componentPath)
        || !str_contains($cardTemplate, "bms_render_core_public_component('" . $componentName . "', \$data)")) {
        bm_smoke_fail($failures, 'Stream Card core component extraction is incomplete for: ' . $componentName);
    }
}
if (count($streamCardComponentFiles) !== 7) {
    bm_smoke_fail($failures, 'Stream Card component extraction must contain exactly seven registered core component files.');
}
if (!in_array('stream-card', bms_theme_layout_supported_surfaces(), true)
    || !str_contains($cardTemplate, "bms_render_public_theme_layout_surface('stream-card', \$data, \$cardTheme)")) {
    bm_smoke_fail($failures, 'Stream Card template is not wired to the validated Schema 1 declarative composition surface.');
}
if (!str_contains($cardTemplate, '<div class="stream-card-inner">')
    || !str_contains($cardTemplate, '<div class="stream-card-main">')
    || !str_contains($cardTemplate, "bms_render_core_public_component('stream-card.avatar', \$data)")
    || !str_contains($cardTemplate, "bms_render_core_public_component('stream-card.header', \$data)")
    || !str_contains($cardTemplate, "bms_render_core_public_component('stream-card.body', \$data)")
    || !str_contains($cardTemplate, "bms_render_core_public_component('stream-card.actions', \$data)")) {
    bm_smoke_fail($failures, 'Legacy Stream Card template must keep the existing article/inner/main shell around extracted components.');
}
foreach (['bms_db(', 'bms_table(', 'bms_setting_or_config(', '$_POST', '$_GET', '$_FILES'] as $forbiddenStreamComponentFetch) {
    if (str_contains($streamCardComponentsSource, $forbiddenStreamComponentFetch)) {
        bm_smoke_fail($failures, 'Stream Card core components must render prepared data only and must not fetch application state: ' . $forbiddenStreamComponentFetch);
    }
}
foreach (['data-stream-quick-edit-content', 'data-stream-quick-edit-form', 'data-stream-like', 'data-stream-actions-menu', 'data-stream-trash-form', 'stream-pin-form'] as $requiredStreamBehaviorHook) {
    if (!str_contains($streamCardComponentsSource, $requiredStreamBehaviorHook)) {
        bm_smoke_fail($failures, 'Stream Card extraction lost core behavior hook: ' . $requiredStreamBehaviorHook);
    }
}
if (!str_contains($themeInstaller, 'function bms_theme_installer_manifest_layout_refs')
    || !str_contains($themeInstaller, "bms_theme_installer_copy_file(\$privateRoot . '/' . \$layout, \$privateStage . '/' . \$layout)")
    || !str_contains($themeInstaller, 'bms_theme_layout_file_errors($manifest, $privateRoot)')) {
    bm_smoke_fail($failures, 'Theme installer is not copying and validating only declared private layout JSON files.');
}
if (!str_contains($themesAdmin, '<dt>Renderer</dt>')
    || !str_contains($themeDetailsAdmin, 'Declarative layouts')
    || !str_contains($themeDetailsAdmin, '<dt>Layout schema</dt>')
    || !str_contains($themeInstallAdmin, 'layouts/*.json')) {
    bm_smoke_fail($failures, 'Theme Manager, details, or install guidance is missing Declarative Layout reporting.');
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

$runtimeDataPattern = '#^(?:media/(?!\.gitkeep$)|uploads/|exports/|_bonumark_stream/(?:tmp|import-staging|content)/|_bonumark_stream/data/(?!\.gitkeep$)|_bonumark_stream/backups/(?!\.gitkeep$|upgrades/\.gitkeep$))#i';
$sensitiveNamePattern = '#(^|/)(?:\.env(?:\..*)?|id_(?:rsa|ed25519)|[^/]+\.(?:sql|sqlite|sqlite3|db|log|pem|key))$#i';
$privateKeyPattern = '/-----BEGIN (?:RSA |EC |OPENSSH |DSA )?PRIVATE KEY-----\s+[A-Za-z0-9+\/=\r\n]{80,}-----END (?:RSA |EC |OPENSSH |DSA )?PRIVATE KEY-----/';
foreach (bm_smoke_files($root) as $path) {
    $relative = bm_smoke_relative($root, $path);
    if (is_link($path)) {
        bm_smoke_fail($failures, 'Package must not contain symbolic links: ' . $relative);
        continue;
    }
    if (preg_match($runtimeDataPattern, $relative) === 1) {
        bm_smoke_fail($failures, 'Package contains runtime or owner data: ' . $relative);
    }
    if (preg_match($sensitiveNamePattern, $relative) === 1) {
        bm_smoke_fail($failures, 'Package contains a sensitive file type or name: ' . $relative);
    }
    $contents = (string)file_get_contents($path);
    if (str_contains($contents, 'activitypub-test' . '.bonumark.org')) {
        bm_smoke_fail($failures, 'Package contains the permanently retired ActivityPub test identity: ' . $relative);
    }
    if (preg_match($privateKeyPattern, $contents) === 1) {
        bm_smoke_fail($failures, 'Package contains private key material: ' . $relative);
    }
}

// Pinned-post release checks. These protect the core-owned pin boundary
// without requiring a live database during package smoke testing.

// v0.5.108 Declarative Home Composition: expose the prepared Home component
// family through Schema 1 while preserving the complete legacy fallback.
if (!in_array('home', bms_theme_layout_supported_surfaces(), true)) {
    bm_smoke_fail($failures, 'v0.5.108 must enable Home as a Schema 1 declarative surface.');
}
$homeExpectedComponents = [
    'home.notices' => 'home/notices.php',
    'home.composer' => 'home/composer.php',
    'home.pinned-posts' => 'home/pinned-posts.php',
    'home.feed' => 'home/feed.php',
    'home.pagination' => 'home/pagination.php',
];
foreach ($homeExpectedComponents as $componentName => $templatePath) {
    $definition = bms_theme_layout_component_definition($componentName);
    if (!is_array($definition)
        || ($definition['surface'] ?? '') !== 'home'
        || empty($definition['required'])
        || (int)($definition['max'] ?? 0) !== 1
        || ($definition['template'] ?? '') !== $templatePath) {
        bm_smoke_fail($failures, 'Home component registry contract is incorrect for ' . $componentName . '.');
    }
}
if (count($homeComponentFiles) !== 5) {
    bm_smoke_fail($failures, 'Declarative Home composition must keep exactly five core-owned Home component files.');
}
foreach (['bms_db(', 'bms_table(', 'bms_setting_or_config(', '$_POST', '$_GET', '$_FILES', '$_SERVER'] as $forbiddenHomeComponentText) {
    if (str_contains($homeComponentsSource, $forbiddenHomeComponentText)) {
        bm_smoke_fail($failures, 'Home components must render prepared data only and may not fetch application/request state: ' . $forbiddenHomeComponentText);
    }
}
$homeComponentProbeData = [
    'notices_html' => '<div class="stream-public-notices" role="status">Saved.</div>',
    'composer_html' => '<section data-stream-composer>Composer</section>',
    'pinned_posts_html' => '<section class="stream-pinned-posts">Pinned</section>',
    'feed_html' => '<article data-stream-card>Post</article>',
    'pagination_html' => '<nav class="stream-pagination">More</nav>',
];
$homeNoticesProbe = bms_render_core_public_component('home.notices', $homeComponentProbeData);
$homeComposerProbe = bms_render_core_public_component('home.composer', $homeComponentProbeData);
$homePinnedProbe = bms_render_core_public_component('home.pinned-posts', $homeComponentProbeData);
$homeFeedProbe = bms_render_core_public_component('home.feed', $homeComponentProbeData);
$homePaginationProbe = bms_render_core_public_component('home.pagination', $homeComponentProbeData);
if ($homeNoticesProbe !== $homeComponentProbeData['notices_html']
    || $homeComposerProbe !== $homeComponentProbeData['composer_html']
    || $homePinnedProbe !== $homeComponentProbeData['pinned_posts_html']
    || $homePaginationProbe !== $homeComponentProbeData['pagination_html']) {
    bm_smoke_fail($failures, 'Home atomic components do not preserve prepared core HTML.');
}
if (!str_contains($homeFeedProbe, '<section class="stream-feed ledger-stream-feed" aria-label="Stream posts">')
    || !str_contains($homeFeedProbe, $homeComponentProbeData['feed_html'])) {
    bm_smoke_fail($failures, 'home.feed must own the core semantic Stream region around prepared feed/empty-state HTML.');
}
$homeManifestProbe = [
    'name' => 'Home Composition Probe',
    'slug' => 'home-composition-probe',
    'version' => '1.0.0',
    'layout_schema' => 1,
    'layouts' => ['home' => 'layouts/home.json'],
];
if (bms_theme_layout_manifest_errors($homeManifestProbe) !== []) {
    bm_smoke_fail($failures, 'v0.5.108 must accept themes that opt into the supported Home surface.');
}
$validHomeLayoutProbe = [
    'surface' => 'home',
    'root' => [
        'type' => 'group',
        'name' => 'home-probe',
        'children' => [
            ['type' => 'component', 'name' => 'home.notices'],
            ['type' => 'component', 'name' => 'home.composer'],
            ['type' => 'component', 'name' => 'home.pinned-posts'],
            ['type' => 'component', 'name' => 'home.feed'],
            ['type' => 'component', 'name' => 'home.pagination'],
        ],
    ],
];
if (bms_theme_layout_document_errors($validHomeLayoutProbe, 'home', 1) !== []) {
    bm_smoke_fail($failures, 'Schema 1 Home layout validation rejected the complete required component contract.');
}
$invalidHomeLayoutProbe = $validHomeLayoutProbe;
$invalidHomeLayoutProbe['root']['children'][3]['name'] = 'home.search';
if (!str_contains(implode(' ', bms_theme_layout_document_errors($invalidHomeLayoutProbe, 'home', 1)), 'unknown component')) {
    bm_smoke_fail($failures, 'Schema 1 Home validation must reject unsupported/invented Home components.');
}
if (!str_contains($homeTemplate, "bms_render_public_theme_layout_surface('home', \$data, \$homeTheme)")
    || !str_contains($homeTemplate, "if (\$declarativeHomeHtml !== null)")
    || !str_contains($homeTemplate, "(\$data['items_html'] ?? '')")) {
    bm_smoke_fail($failures, 'Home template must use declarative composition when supplied and preserve the existing legacy Home fallback.');
}
foreach (["'notices_html' => \$notices", "'pinned_posts_html' => \$pinnedItems", "'feed_html' => \$feedItems", "'items_html' => \$items"] as $requiredHomePreparedBoundary) {
    if (!str_contains($rendererApp, $requiredHomePreparedBoundary)) {
        bm_smoke_fail($failures, 'Home renderer is missing prepared composition boundary: ' . $requiredHomePreparedBoundary);
    }
}
if (!str_contains($rendererApp, '$items = $notices . $pinnedItems . $feedItems;')) {
    bm_smoke_fail($failures, 'Home renderer must preserve the exact legacy items_html concatenation for non-declarative themes.');
}

$pinnedMigrationPath = $root . '/_bonumark_stream/migrations/0008_pinned_posts.php';
if (!is_file($pinnedMigrationPath)) {
    bm_smoke_fail($failures, 'Pinned-post migration is missing.');
} else {
    $pinnedMigration = require $pinnedMigrationPath;
    if (!is_array($pinnedMigration) || count($pinnedMigration) < 3) {
        bm_smoke_fail($failures, 'Pinned-post migration is invalid.');
    } else {
        $pinnedSql = implode("
", $pinnedMigration);
        foreach (['is_pinned', 'pinned_at', 'post_type_status_pinned_at'] as $requiredPinnedSql) {
            if (!str_contains($pinnedSql, $requiredPinnedSql)) {
                bm_smoke_fail($failures, 'Pinned-post migration is missing required SQL: ' . $requiredPinnedSql);
            }
        }
    }
}

$databaseSource = @file_get_contents($root . '/_bonumark_stream/app/database.php') ?: '';
$functionsSource = @file_get_contents($root . '/_bonumark_stream/app/functions.php') ?: '';
$rendererSource = @file_get_contents($root . '/_bonumark_stream/app/renderer.php') ?: '';
$migrationSource = @file_get_contents($root . '/_bonumark_stream/migrations/0010_published_timestamp_storage_cutover.php') ?: '';
$pinEndpoint = @file_get_contents($root . '/admin/pin.php') ?: '';
$contentList = @file_get_contents($root . '/admin/content.php') ?: '';
$cardTemplate = @file_get_contents($root . '/_bonumark_stream/app/views/default/templates/card.php') ?: '';
$themeCss = @file_get_contents($root . '/assets/themes/default/assets/css/theme.css') ?: '';
$coreCss = @file_get_contents($root . '/assets/style.css') ?: '';

foreach (['function bms_list_pinned_stream_posts', 'ORDER BY pinned_at DESC, id DESC', 'function bms_set_stream_post_pinned_state', 'is_pinned = CASE WHEN ? = 1 THEN is_pinned ELSE 0 END', 'pinned_at = CASE WHEN ? = 1 THEN pinned_at ELSE NULL END'] as $requiredPinnedDatabaseText) {
    if (!str_contains($databaseSource, $requiredPinnedDatabaseText)) {
        bm_smoke_fail($failures, 'Pinned-post database behavior is missing: ' . $requiredPinnedDatabaseText);
    }
}

foreach ([
    'function bms_apply_site_timezone',
    'function bms_stream_published_at_is_utc',
    'stream_published_at_utc_cutover',
    'date_default_timezone_set($timezone)',
    "SET time_zone = '+00:00'",
    '\'published_at\' => $record[\'status\'] === \'published\' ? gmdate(\'Y-m-d H:i:s\') : null',
    "->setTimezone(bms_site_timezone())->format('M j, Y g:i A')",
] as $requiredTimezoneText) {
    if (str_contains($requiredTimezoneText, 'to_version')) {
        $timezoneSource = $migrationSource;
    } elseif (str_contains($requiredTimezoneText, 'SET time_zone') || str_contains($requiredTimezoneText, "'published_at' => ")) {
        $timezoneSource = $databaseSource;
    } elseif (str_contains($requiredTimezoneText, 'setTimezone')) {
        $timezoneSource = $rendererSource;
    } else {
        $timezoneSource = $functionsSource;
    }
    if (!str_contains($timezoneSource, $requiredTimezoneText)) {
        bm_smoke_fail($failures, 'Timezone runtime behavior is missing: ' . $requiredTimezoneText);
    }
}
foreach (['bms_render_pinned_stream_posts($pinnedPosts)', 'bms_render_public_flash_notices()', 'stream-pinned-posts', 'bms_list_pinned_stream_posts()'] as $requiredPinnedRendererText) {
    if (!str_contains($rendererSource, $requiredPinnedRendererText)) {
        bm_smoke_fail($failures, 'Pinned-post renderer behavior is missing: ' . $requiredPinnedRendererText);
    }
}
foreach (['bms_require_login();', 'bms_verify_csrf();', "bms_require_content_file_access('published'", 'bms_set_stream_post_pinned_state'] as $requiredPinnedEndpointText) {
    if (!str_contains($pinEndpoint, $requiredPinnedEndpointText)) {
        bm_smoke_fail($failures, 'Pinned-post endpoint security or action is missing: ' . $requiredPinnedEndpointText);
    }
}
foreach (['status=pinned', 'Pin to Stream', 'Unpin from Stream', 'Pinned <span><?= $pinnedCount ?>'] as $requiredPinnedAdminText) {
    if (!str_contains($contentList, $requiredPinnedAdminText)) {
        bm_smoke_fail($failures, 'Pinned-post admin list behavior is missing: ' . $requiredPinnedAdminText);
    }
}
foreach (['pin_action', 'pin_csrf', 'stream-pin-form'] as $requiredPinnedCardText) {
    if (!str_contains($streamCardPublicSource, $requiredPinnedCardText)) {
        bm_smoke_fail($failures, 'Pinned-post front-end controls are missing: ' . $requiredPinnedCardText);
    }
}
foreach (['stream-post-actions-menu', 'stream-post-actions-toggle', 'stream-post-actions-popover', 'stream-post-action-item', 'Post options'] as $requiredPostMenuText) {
    if (!str_contains($streamCardPublicSource, $requiredPostMenuText)) {
        bm_smoke_fail($failures, 'Front-end post actions menu is missing: ' . $requiredPostMenuText);
    }
}
$streamJs = @file_get_contents($root . '/assets/stream.js') ?: '';
if (!str_contains($streamJs, 'summary, details, [data-stream-actions-menu]')) {
    bm_smoke_fail($failures, 'Card click handling must ignore the front-end post actions menu.');
}
foreach (['assets/style.css', '_bonumark_stream/themes/default/assets/css/theme.css', 'assets/themes/default/assets/css/theme.css'] as $menuCssPath) {
    $menuCss = (string)file_get_contents($root . '/' . $menuCssPath);
    if (!str_contains($menuCss, 'top: calc(100% + 0.45rem);') || !str_contains($menuCss, 'bottom: auto;')) {
        bm_smoke_fail($failures, 'Post options menu must open below its trigger in ' . $menuCssPath . '.');
    }
}
foreach (['_bonumark_stream/themes/default/assets/css/theme.css', 'assets/themes/default/assets/css/theme.css'] as $themeMenuCssPath) {
    $themeMenuCss = (string)file_get_contents($root . '/' . $themeMenuCssPath);
    if (!preg_match('/\.stream-card\s*\{[^}]*overflow:\s*visible;/s', $themeMenuCss) || !str_contains($themeMenuCss, '.stream-card:has(.stream-post-actions-menu[open])')) {
        bm_smoke_fail($failures, 'Bundled theme must keep the below-trigger post options menu visible above the stream in ' . $themeMenuCssPath . '.');
    }
}
if (!str_contains($coreCss, '.stream-post-actions-menu[open]') || !str_contains($coreCss, 'z-index: 31;')) {
    bm_smoke_fail($failures, 'Core fallback must keep an open post options menu above nearby stream content.');
}

foreach (['.stream-pinned-posts', '.stream-card-pinned', '.stream-post-actions-menu', '.stream-post-actions-popover'] as $requiredPinnedStyle) {
    if (!str_contains($coreCss, $requiredPinnedStyle) || !str_contains($themeCss, $requiredPinnedStyle)) {
        bm_smoke_fail($failures, 'Pinned-post fallback or reference-theme styling is missing: ' . $requiredPinnedStyle);
    }
}
foreach ([$coreCss, $themeCss] as $menuCss) {
    if (!preg_match('/\.stream-post-action-item\s*\{[^}]*justify-content:\s*flex-start;/s', $menuCss)) {
        bm_smoke_fail($failures, 'Post options menu actions must explicitly left-align button and link items.');
    }
}
if (!str_contains($readme, '## Pinned posts') || !str_contains($readme, 'RSS/feed order') || !str_contains($readme, 'static export output')) {
    bm_smoke_fail($failures, 'README pinned-post documentation is missing required behavior details.');
}

// Markdown image-rendering checks load markdown.php by itself, so these
// smoke-test fallbacks are intentionally wrapped. If the real media helpers are
// loaded first, the test must not redeclare app functions.
if (!function_exists('bms_media_resolve_existing_public_relative_from_url')) {
    function bms_media_resolve_existing_public_relative_from_url(string $url): string
    {
        return '';
    }
}
if (!function_exists('bms_media_public_relative_from_url')) {
    function bms_media_public_relative_from_url(string $url): string
    {
        return '';
    }
}
if (!function_exists('bms_media_image_attributes')) {
    function bms_media_image_attributes(string $url, string $alt = '', array $options = []): string
    {
        return 'src="/media/2026/06/example.jpg" alt="Example" loading="lazy" decoding="async" width="2400" height="1800" srcset="/media/_generated/2026/06/example-480w.jpg 480w, /media/2026/06/example.jpg 960w, /media/_generated/2026/06/example-1200w.jpg 1200w, /media/2026/06/example.jpg 2400w" sizes="(max-width: 720px) calc(100vw - 2rem), min(100vw - 4rem, 900px)"';
    }
}
require_once $root . '/_bonumark_stream/app/markdown.php';
$renderedMarkdownImage = bms_markdown_to_html("Testing image render.

![Example](https://example.com/media/2026/06/example.jpg)

Caption stays visible.");
if (!str_contains($renderedMarkdownImage, '<img ')) {
    bm_smoke_fail($failures, 'Markdown image rendering smoke test did not produce an image tag.');
}
if (!str_contains($renderedMarkdownImage, 'srcset="/media/_generated/2026/06/example-480w.jpg 480w')) {
    bm_smoke_fail($failures, 'Markdown image rendering smoke test did not preserve generated responsive variants.');
}
if (str_contains($renderedMarkdownImage, '<em>generated') || str_contains($renderedMarkdownImage, '</em>generated') || str_contains($renderedMarkdownImage, 'srcset="/media/<')) {
    bm_smoke_fail($failures, 'Markdown image rendering leaked generated responsive srcset text into visible content.');
}
if (!str_contains($renderedMarkdownImage, '<p>Caption stays visible.</p>')) {
    bm_smoke_fail($failures, 'Markdown image rendering smoke test did not keep the caption visible.');
}



// Four-photo gallery checks.
$quickPostSource = @file_get_contents($root . '/admin/quick-post.php') ?: '';
$editorSource = @file_get_contents($root . '/_bonumark_stream/app/editor.php') ?: '';
$editorJs = @file_get_contents($root . '/assets/editor.js') ?: '';
$mediaTemplate = @file_get_contents($root . '/_bonumark_stream/app/views/default/templates/media.php') ?: '';
$composerTemplate = @file_get_contents($root . '/_bonumark_stream/app/views/default/templates/composer.php') ?: '';
$themingDocs = @file_get_contents($root . '/docs/THEMING.md') ?: '';
$apiSource = @file_get_contents($root . '/_bonumark_stream/app/api.php') ?: '';
foreach (['function bms_normalize_media_gallery', "'media_gallery' =>", 'media_gallery:'] as $requiredGalleryFunctionText) {
    if (!str_contains($functionsSource, $requiredGalleryFunctionText)) {
        bm_smoke_fail($failures, 'Core gallery front-matter support is missing: ' . $requiredGalleryFunctionText);
    }
}
if (!str_contains($quickPostSource, 'count($files) > 4') || !str_contains($quickPostSource, "'media_gallery' => \$mediaGallery") || !str_contains($quickPostSource, 'bms_media_discard_new_upload')) {
    bm_smoke_fail($failures, 'Quick Post must enforce four files, save ordered gallery metadata, and roll back failed uploads.');
}
if (!str_contains($composerTemplate, 'name="stream_media[]"') || !str_contains($composerTemplate, 'multiple') || !str_contains($composerTemplate, 'data-stream-max-files')) {
    bm_smoke_fail($failures, 'Front-end composer must expose a multiple file input capped by the gallery controller.');
}
foreach (['setupEditorPhotoGallery', 'addMediaToPhotoGallery', "payload.append('image_only', '1')"] as $requiredEditorGalleryText) {
    if (!str_contains($editorJs, $requiredEditorGalleryText)) {
        bm_smoke_fail($failures, 'Admin editor gallery control is missing: ' . $requiredEditorGalleryText);
    }
}
if (!str_contains($editorSource, 'data-editor-photo-gallery') || !str_contains($editorSource, 'name="media_gallery[]"') || !str_contains($editorSource, 'data-media-picker-mode="gallery"')) {
    bm_smoke_fail($failures, 'Admin editor must render ordered gallery controls and hidden gallery fields.');
}
foreach (['stream-media-gallery-count-', 'stream-media-gallery-layout-', 'stream-media-gallery-item-'] as $requiredGalleryMarkup) {
    if (!str_contains($mediaTemplate, $requiredGalleryMarkup)) {
        bm_smoke_fail($failures, 'Core media template gallery contract is missing: ' . $requiredGalleryMarkup);
    }
}
foreach (['.stream-media-gallery', '--bms-media-gallery-gap', '--bms-media-gallery-object-fit'] as $requiredGalleryCss) {
    if (!str_contains($coreCss, $requiredGalleryCss) || !str_contains($themeCss, $requiredGalleryCss)) {
        bm_smoke_fail($failures, 'Core fallback or bundled theme gallery styling is missing: ' . $requiredGalleryCss);
    }
}
if (!str_contains($themingDocs, '## Photo gallery presentation') || !str_contains($themingDocs, 'Older themes remain compatible')) {
    bm_smoke_fail($failures, 'Theme documentation must define the gallery contract and older-theme fallback.');
}
if (!str_contains($functionsSource, "img-src 'self' data: blob: https: http:")) {
    bm_smoke_fail($failures, 'Composer photo previews require blob: in the image Content Security Policy.');
}
foreach (['previewObjectUrls', 'revokePreviewObjectUrls', 'window.URL.createObjectURL'] as $requiredPreviewText) {
    if (!str_contains($streamJs, $requiredPreviewText)) {
        bm_smoke_fail($failures, 'Front-end gallery preview URL lifecycle is missing: ' . $requiredPreviewText);
    }
}
foreach (['function setupMediaViewer', 'data-stream-media-viewer-modal', 'Close photo viewer', "event.key === 'Escape'", 'mediaViewerControllerInitialized'] as $requiredViewerText) {
    if (!str_contains($streamJs, $requiredViewerText)) {
        bm_smoke_fail($failures, 'Core photo viewer behavior is missing: ' . $requiredViewerText);
    }
}
if (!str_contains($mediaTemplate, 'data-stream-media-viewer')) {
    bm_smoke_fail($failures, 'Core image links must opt into the photo viewer contract.');
}
foreach (['.stream-media-viewer', '.stream-media-viewer-close', 'body.stream-media-viewer-open'] as $requiredViewerCss) {
    if (!str_contains($coreCss, $requiredViewerCss)) {
        bm_smoke_fail($failures, 'Core photo viewer styling is missing: ' . $requiredViewerCss);
    }
}
if (!str_contains($themingDocs, 'Core also owns the full-size photo viewer')) {
    bm_smoke_fail($failures, 'Theme documentation does not define the core photo viewer boundary.');
}
if (!str_contains($apiSource, 'function bms_api_media_display_mode') || !str_contains($apiSource, "'display' => 'gallery'") || !str_contains($apiDocs, 'media_display')) {
    bm_smoke_fail($failures, 'Remote API gallery mode or its documentation is missing.');
}

$galleryProbeCode = 'require ' . var_export($root . '/_bonumark_stream/app/functions.php', true) . '; '
    . '$raw=bms_build_markdown_document(["title"=>"Gallery","slug"=>"gallery","status"=>"draft","date"=>"2026-08-04","featured_media"=>"media/a.jpg","media_gallery"=>["media/a.jpg","media/b.jpg","media/c.jpg","media/d.jpg","media/e.jpg"]],""); '
    . '$page=bms_parse_markdown_string($raw); echo json_encode([$page["featured_media"],$page["media_gallery"]]);';
$galleryProbeCommand = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($galleryProbeCode) . ' 2>/dev/null';
$galleryProbe = json_decode(trim((string)shell_exec($galleryProbeCommand)), true);
if (!is_array($galleryProbe) || ($galleryProbe[0] ?? '') !== 'media/a.jpg' || count((array)($galleryProbe[1] ?? [])) !== 4 || (($galleryProbe[1][3] ?? '') !== 'media/d.jpg')) {
    bm_smoke_fail($failures, 'Gallery front matter must preserve order, keep the first image featured, and cap galleries at four.');
}

$editorWorkflowCss = @file_get_contents($root . '/assets/admin-editor-workflow.css') ?: '';
if (str_contains($editorWorkflowCss, 'max-height: calc(100vh - 104px)') || str_contains($editorWorkflowCss, 'scrollbar-gutter: stable')) {
    bm_smoke_fail($failures, 'Editor metadata rail must not retain the nested desktop scrollbar from v0.5.52.');
}
if (!str_contains($editorWorkflowCss, '.editor-layout-form.has-sticky-sidebar:not(.is-single-column-editor) .publish-card')
    || !str_contains($editorWorkflowCss, 'position: sticky;')) {
    bm_smoke_fail($failures, 'Editor correction must keep only the Publish card sticky on wide desktop layouts.');
}
if (!str_contains($editorJs, 'if (window.innerWidth <= 640) { return 260; }')
    || !str_contains($editorJs, 'if (window.innerWidth <= 900) { return 300; }')) {
    bm_smoke_fail($failures, 'Editor mobile writing surface baselines are not reduced to the v0.5.53 values.');
}
if (!str_contains($editorSource, '<section class="side-card editor-secondary-card editor-location-card"')
    || !str_contains($editorSource, '<h3>Location</h3>')
    || str_contains($editorSource, '<details class="side-card editor-secondary-card editor-native-disclosure editor-location-card"')
    || !str_contains($editorWorkflowCss, 'body.bonumark-admin .editor-location-card .editor-card-heading')) {
    bm_smoke_fail($failures, 'Location must use the shared collapsible side-card component without exposing its old duplicate heading.');
}
if (!str_contains($editorSource, 'data-editor-mobile-action-bar')
    || !str_contains($editorWorkflowCss, 'inset: auto 0 0 0 !important;')
    || !str_contains($editorWorkflowCss, '.editor-mobile-action-bar.is-context-hidden')
    || !str_contains($editorWorkflowCss, '--editor-mobile-action-reserve: calc(84px + env(safe-area-inset-bottom));')
    || !str_contains($editorJs, 'controlsEnterDockZone()')
    || !str_contains($editorJs, 'window.visualViewport')
    || !str_contains($editorJs, 'entry.intersectionRatio >= 0.24')) {
    bm_smoke_fail($failures, 'Mobile editor action dock hotfix is incomplete or missing its viewport-safety behavior.');
}

if ($failures !== []) {
    fwrite(STDERR, "Bonumark smoke test failed:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, '- ' . $failure . "\n");
    }
    exit(1);
}

echo 'Bonumark smoke test passed for version ' . $rootVersion . PHP_EOL;

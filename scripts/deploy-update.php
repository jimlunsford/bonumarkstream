<?php
/**
 * Owner-run Bonumark Stream software upgrade workflow.
 *
 * Run this as the operating-system account that owns/deploys the Bonumark
 * application tree. The script does not use sudo, setuid helpers, daemons, or
 * any other privilege-escalation mechanism.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    if (!headers_sent()) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
    }
    exit('CLI only.');
}

$toolRoot = dirname(__DIR__);

$args = array_slice($_SERVER['argv'] ?? [], 1);
$options = [
    'check' => false,
    'yes' => false,
    'confirm_db_backup' => false,
    'allow_root' => false,
    'site_root' => '',
];
$zipArgument = '';

foreach ($args as $arg) {
    if ($arg === '--check') {
        $options['check'] = true;
        continue;
    }
    if ($arg === '--yes') {
        $options['yes'] = true;
        continue;
    }
    if ($arg === '--confirm-db-backup') {
        $options['confirm_db_backup'] = true;
        continue;
    }
    if ($arg === '--allow-root') {
        $options['allow_root'] = true;
        continue;
    }
    if (str_starts_with($arg, '--site-root=')) {
        $options['site_root'] = trim(substr($arg, strlen('--site-root=')));
        continue;
    }
    if ($arg === '--help' || $arg === '-h') {
        bms_owner_upgrade_usage(0);
    }
    if (str_starts_with($arg, '-')) {
        fwrite(STDERR, "Unknown option: {$arg}\n");
        bms_owner_upgrade_usage(1);
    }
    if ($zipArgument !== '') {
        fwrite(STDERR, "Only one release ZIP may be supplied.\n");
        bms_owner_upgrade_usage(1);
    }
    $zipArgument = $arg;
}

if ($zipArgument === '') {
    bms_owner_upgrade_usage(1);
}

$rootCandidate = $options['site_root'] !== '' ? $options['site_root'] : $toolRoot;
$root = realpath($rootCandidate);
if ($root === false || !is_dir($root)) {
    fwrite(STDERR, "Bonumark site root is missing or not readable: {$rootCandidate}\n");
    exit(1);
}
$root = rtrim($root, '/\\');

if (!is_file($root . '/_bonumark_stream/config.php') || !is_file($root . '/_bonumark_stream/installed.lock')) {
    fwrite(STDERR, "Target is not an installed Bonumark Stream site: {$root}\n");
    fwrite(STDERR, "For the first upgrade from a release that does not yet contain this helper, extract the new release and pass --site-root=/path/to/live/site.\n");
    exit(1);
}

// Load the target installation's runtime/database functions first. When this
// helper is being run from an extracted newer release, the shared upgrade
// engine then operates on the target site's config, paths, database, and data.
require_once $root . '/_bonumark_stream/app/database.php';
require_once $toolRoot . '/_bonumark_stream/app/upgrader.php';

$zipPath = realpath($zipArgument);
if ($zipPath === false || !is_file($zipPath) || !is_readable($zipPath)) {
    fwrite(STDERR, "Release ZIP is missing or not readable: {$zipArgument}\n");
    exit(1);
}

$identity = bms_owner_upgrade_identity();
if (!empty($identity['is_root']) && !$options['allow_root']) {
    fwrite(STDERR, "Refusing to run as root by default. Run the upgrade as the application owner. If root execution is intentional, rerun with --allow-root.\n");
    exit(1);
}

try {
    $plan = bms_upgrade_inspect_package($zipPath);
} catch (Throwable $e) {
    fwrite(STDERR, 'Upgrade precheck failed: ' . $e->getMessage() . "\n");
    exit(1);
}

bms_owner_upgrade_print_plan($plan, $identity, $zipPath);

$capability = is_array($plan['deployment_capability'] ?? null) ? $plan['deployment_capability'] : [];
if (empty($plan['backup_ready'])) {
    fwrite(STDERR, "BLOCKED: The private upgrade backup location cannot be created or written by the current CLI user.\n");
    exit(1);
}
if (empty($capability['available'])) {
    $blocked = is_array($capability['blocked'] ?? null) ? $capability['blocked'] : [];
    $first = (string)($blocked[0]['relative_path'] ?? 'package-managed application files');
    fwrite(STDERR, "BLOCKED: The current CLI user cannot safely replace the application tree. First blocked path: {$first}\n");
    fwrite(STDERR, "Run this command as the Bonumark application owner. Do not make the PHP-FPM/web process own the application just to enable upgrades.\n");
    exit(1);
}

if ($options['check']) {
    echo "\nPrecheck passed. No files were changed.\n";
    exit(0);
}

$pendingMigrations = is_array($plan['pending_migrations'] ?? null) ? $plan['pending_migrations'] : [];
$interactive = bms_owner_upgrade_interactive();

if (!$options['yes']) {
    if (!$interactive) {
        fwrite(STDERR, "Non-interactive execution requires --yes after you review the plan.\n");
        exit(1);
    }
    echo "\nType UPGRADE to replace package-managed software (capitalization does not matter): ";
    $answer = trim((string)fgets(STDIN));
    if (!hash_equals('UPGRADE', strtoupper($answer))) {
        fwrite(STDERR, "Upgrade cancelled.\n");
        exit(1);
    }
}

$expectedZipHash = trim((string)($plan['zip_sha256'] ?? ''));
$actualZipHash = hash_file('sha256', $zipPath) ?: '';
if ($expectedZipHash === '' || $actualZipHash === '' || !hash_equals($expectedZipHash, $actualZipHash)) {
    fwrite(STDERR, "Upgrade ZIP changed after precheck. Refusing to continue.\n");
    exit(1);
}

if ($pendingMigrations !== [] && !$options['confirm_db_backup']) {
    if (!$interactive) {
        fwrite(STDERR, "This release has pending database migrations. Back up the database and rerun with --confirm-db-backup.\n");
        exit(1);
    }
    echo "\nThis release has pending database migrations. Confirm that a current external database backup exists.\n";
    echo "Type BACKUP CONFIRMED to continue: ";
    $answer = trim((string)fgets(STDIN));
    if (!hash_equals('BACKUP CONFIRMED', $answer)) {
        fwrite(STDERR, "Upgrade cancelled before software replacement.\n");
        exit(1);
    }
}

try {
    $result = bms_upgrade_install($zipPath);
} catch (Throwable $e) {
    fwrite(STDERR, "\nUpgrade failed: " . $e->getMessage() . "\n");
    exit(1);
}

$migrations = is_array($result['migrations'] ?? null) ? $result['migrations'] : [];
$removed = is_array($result['removed'] ?? null) ? $result['removed'] : [];

echo "\nBonumark Stream upgrade completed.\n";
echo 'Version: v' . (string)($result['from'] ?? 'unknown') . ' -> v' . (string)($result['to'] ?? 'unknown') . "\n";
echo 'Software backup: ' . (string)($result['backup'] ?? 'unknown') . "\n";
echo 'Database migrations: ' . count($migrations) . "\n";
echo 'Obsolete package files removed: ' . count($removed) . "\n";
echo "\nRunning installed-site deployment verification...\n\n";

// Load the just-deployed verification helper in this same owner-run process.
// It exits with a non-zero status if package/database state is incomplete.
require $root . '/scripts/deployment-check.php';

function bms_owner_upgrade_usage(int $status): never
{
    $script = basename(__FILE__);
    $message = <<<TXT
Usage:
  php scripts/{$script} /path/to/bonumark-stream-vX.Y.Z.zip
  php scripts/{$script} --check /path/to/bonumark-stream-vX.Y.Z.zip
  php /path/to/extracted-release/scripts/{$script} --site-root=/path/to/live/site /path/to/bonumark-stream-vX.Y.Z.zip

Options:
  --check                Validate the package and deployment permissions only.
  --yes                  Non-interactive confirmation of software replacement.
  --confirm-db-backup    Confirm an external database backup exists when migrations are pending.
  --allow-root           Allow an intentionally root-run deployment. Application-owner execution is preferred.
  --site-root=PATH       Upgrade a different installed Bonumark root. Useful to bootstrap this workflow from an extracted newer release.
  --help, -h             Show this help.

The helper never invokes sudo or elevates privileges. Run it as the operating-system account that owns/deploys the Bonumark application tree.
TXT;
    fwrite($status === 0 ? STDOUT : STDERR, $message);
    exit($status);
}

/** @return array{label:string,is_root:bool} */
function bms_owner_upgrade_identity(): array
{
    $label = '';
    $isRoot = false;
    if (function_exists('posix_geteuid')) {
        $uid = (int)posix_geteuid();
        $isRoot = $uid === 0;
        if (function_exists('posix_getpwuid')) {
            $pw = posix_getpwuid($uid);
            if (is_array($pw) && !empty($pw['name'])) {
                $label = (string)$pw['name'] . ' (uid ' . $uid . ')';
            }
        }
        if ($label === '') {
            $label = 'uid ' . $uid;
        }
    }
    if ($label === '') {
        $envUser = trim((string)(getenv('USER') ?: getenv('USERNAME') ?: ''));
        $label = $envUser !== '' ? $envUser : 'current CLI user';
        $isRoot = strtolower($envUser) === 'root';
    }
    return ['label' => $label, 'is_root' => $isRoot];
}

function bms_owner_upgrade_interactive(): bool
{
    if (function_exists('stream_isatty')) {
        return @stream_isatty(STDIN);
    }
    if (function_exists('posix_isatty')) {
        return @posix_isatty(STDIN);
    }
    return false;
}

/** @param array<string,mixed> $plan @param array{label:string,is_root:bool} $identity */
function bms_owner_upgrade_print_plan(array $plan, array $identity, string $zipPath): void
{
    $capability = is_array($plan['deployment_capability'] ?? null) ? $plan['deployment_capability'] : [];
    $blocked = is_array($capability['blocked'] ?? null) ? $capability['blocked'] : [];
    $migrations = is_array($plan['pending_migrations'] ?? null) ? $plan['pending_migrations'] : [];

    echo "Bonumark Stream owner-run upgrade\n";
    echo 'CLI identity: ' . $identity['label'] . "\n";
    echo 'Target site: ' . bms_public_path() . "\n";
    echo 'Release ZIP: ' . $zipPath . "\n";
    echo 'Current version: v' . (string)($plan['current_version'] ?? 'unknown') . "\n";
    echo 'Target version:  v' . (string)($plan['package_version'] ?? 'unknown') . "\n";
    if (trim((string)($plan['release_notes'] ?? '')) !== '') {
        echo 'Release: ' . trim((string)$plan['release_notes']) . "\n";
    }
    echo 'Release manifest files: ' . (int)($plan['manifest_file_count'] ?? 0) . "\n";
    echo 'Package-managed files: ' . (int)($plan['managed_file_count'] ?? 0) . "\n";
    echo 'New package-managed files: ' . (int)($plan['new_file_count'] ?? 0) . "\n";
    echo 'Pending migrations: ' . count($migrations) . "\n";
    echo 'Software backup path: ' . (!empty($plan['backup_ready']) ? 'READY' : 'BLOCKED') . "\n";
    echo 'Current user can replace software: ' . (!empty($capability['available']) ? 'YES' : 'NO') . "\n";
    if ($blocked !== []) {
        echo 'First blocked path: ' . (string)($blocked[0]['relative_path'] ?? 'unknown') . "\n";
    }
    echo "Preserved owner/runtime data: config, install lock, runtime data, backups, content/import staging, media/uploads, and non-package custom themes.\n";
    echo "Privilege escalation: NONE\n";
}

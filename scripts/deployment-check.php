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

require_once $root . '/_bonumark_stream/app/deployment-verification.php';
$result = bms_deployment_check($root);
foreach ($result['messages'] as $message) {
    echo $message;
}
$failures = $result['failures'];

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

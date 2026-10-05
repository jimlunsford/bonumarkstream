<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once __DIR__ . '/../_bonumark_stream/app/connect.php';
$action = $argv[1] ?? '';
$ownerId = filter_var($argv[2] ?? '', FILTER_VALIDATE_INT) ?: 0;
try {
    if ($action === 'initialize') { bms_connect_initialize($ownerId); }
    elseif ($action === 'maintenance') { bms_connect_maintenance($ownerId); }
    elseif ($action === 'provision' && isset($argv[3])) {
        $client = json_decode((string)file_get_contents($argv[3]), true, 8, JSON_THROW_ON_ERROR);
        bms_connect_provision_client($ownerId, $client);
    } elseif (in_array($action, ['recovery', 'clone', 'revoke_all'], true) && ($argv[3] ?? '') === '--confirm-revoke') {
        bms_connect_manage($ownerId, $action);
    } else { throw new RuntimeException('usage'); }
    fwrite(STDOUT, "Connect administration completed.\n");
} catch (Throwable $e) {
    fwrite(STDERR, "Connect administration failed. Use initialize|maintenance OWNER_ID, provision OWNER_ID CLIENT_JSON, or recovery|clone|revoke_all OWNER_ID --confirm-revoke. Check local configuration and migrations.\n");
    exit(1);
}

<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit('CLI only.'); }
if (getenv('BMS_DB_DANGER_RESET') !== '1') { exit(1); }
require_once ($argv[1] ?? '') . '/_bonumark_stream/app/connect.php';
$input = json_decode((string)stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
try {
    if (($input['operation'] ?? '') === 'revoke') {
        bms_db()->exec('SET SESSION innodb_lock_wait_timeout = 1');
        bms_connect_manage((int)$input['owner'], 'revoke', $input['grant_id']);
        echo "revoked\n";
        exit;
    }
    bms_connect_exchange($input);
    echo "issued\n";
} catch (BMS_Connect_Error $e) { echo $e->resultCode . "\n"; }
catch (PDOException $e) { if (($e->errorInfo[1] ?? 0) === 1205) { echo "lock_blocked\n"; } else { echo "database_failure\n"; exit(1); } }
catch (Throwable $e) { echo "internal_failure\n"; exit(1); }

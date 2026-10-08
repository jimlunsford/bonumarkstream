<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || getenv('BMS_DB_DANGER_RESET') !== '1') { exit(1); }
$root = $argv[1]; $action = $argv[2];
$input = json_decode((string)stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
if ($action === 'setup') {
    $config = ['base_url' => 'https://site.example.com', 'base_path' => '', 'timezone' => 'UTC', 'security_salt' => bin2hex(random_bytes(32)), 'database' => ['host' => getenv('BMS_DB_HOST'), 'name' => getenv('BMS_DB_NAME'), 'user' => getenv('BMS_DB_USER'), 'password' => getenv('BMS_DB_PASS') ?: '', 'prefix' => 'bms_e2e_' . bin2hex(random_bytes(6)) . '_', 'charset' => 'utf8mb4']];
    file_put_contents($root . '/_bonumark_stream/config.php', '<?php return ' . var_export($config, true) . ';');
    touch($root . '/_bonumark_stream/installed.lock');
}
require_once $root . '/_bonumark_stream/app/connect.php';
if ($action === 'setup') {
    bms_install_schema(bms_db(), bms_table_prefix());
    bms_db_insert_initial_data(['site_name' => 'Connect E2E', 'site_tagline' => '', 'timezone' => 'UTC', 'base_url' => 'https://site.example.com', 'base_path' => ''], ['username' => 'connectowner', 'display_name' => 'Connect Owner', 'email' => 'owner@example.test', 'password' => $input['password']]);
    $owner = (int)bms_connect_query('SELECT id FROM ' . bms_table('users') . ' WHERE username = ?', ['connectowner'])->fetchColumn();
    bms_connect_initialize($owner);
    bms_connect_provision_client($owner, ['client_id' => 'bmc_test_client_0123456789', 'display_name' => 'Trusted Connect', 'callbacks' => ['https://relay.example.com/callback']]);
} elseif ($action === 'inspect-request') {
    $id = $input['request'];
    $session = bms_connect_query('SELECT status FROM ' . bms_table('connect_sessions') . ' WHERE session_id = ?', [$id])->fetchColumn();
    $grants = bms_connect_query('SELECT state FROM ' . bms_table('connect_grants') . ' WHERE session_id = ?', [$id])->fetchAll(PDO::FETCH_COLUMN);
    $codes = bms_connect_query('SELECT consumed_at IS NOT NULL AS consumed FROM ' . bms_table('connect_codes') . ' WHERE session_id = ?', [$id])->fetchAll(PDO::FETCH_COLUMN);
    $credentials = (int)bms_connect_query('SELECT COUNT(*) FROM ' . bms_table('connect_credentials') . ' c JOIN ' . bms_table('connect_grants') . ' g ON c.grant_id = g.grant_id WHERE g.session_id = ?', [$id])->fetchColumn();
    $audit = bms_connect_query('SELECT operation FROM ' . bms_table('connect_audit') . ' a JOIN ' . bms_table('connect_grants') . ' g ON a.grant_id = g.grant_id WHERE g.session_id = ? ORDER BY a.id', [$id])->fetchAll(PDO::FETCH_COLUMN);
    echo json_encode(['session' => $session, 'grants' => $grants, 'codes' => array_map('intval', $codes), 'credentials' => $credentials, 'audit' => $audit], JSON_THROW_ON_ERROR);
    exit;
} elseif ($action === 'cleanup') {
    foreach (bms_db()->query('SHOW TABLES LIKE ' . bms_db()->quote(bms_table_prefix() . '%'))->fetchAll(PDO::FETCH_COLUMN) as $table) {
        if (str_starts_with($table, bms_table_prefix())) { bms_db()->exec('DROP TABLE `' . $table . '`'); }
    }
}
echo "ok\n";

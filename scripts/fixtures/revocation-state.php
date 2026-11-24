<?php
// CLI-only helper copied into a disposable test root; no HTTP access.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/_bonumark_stream/app/registration.php';
$pdo = bms_db();
$result = [];
foreach (['api_tokens'=>['id','token_name','scopes_json','status','expires_at'], 'registration_invites'=>['id','label','max_uses','used_count','status','expires_at'], 'settings'=>['setting_key','setting_value']] as $table=>$columns) {
    $result[$table] = $pdo->query('SELECT ' . implode(',', $columns) . ' FROM ' . bms_table($table) . ' ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);
}
$result['invite_valid'] = [];
foreach (json_decode(file_get_contents(__DIR__ . '/fixtures.json'), true) as $name=>$fixture) {
    try { bms_registration_validate_invite_code($fixture['code']); $result['invite_valid'][$name] = true; }
    catch (RuntimeException $e) { $result['invite_valid'][$name] = false; }
}
echo json_encode($result);

<?php
$homeOnlyNav = json_encode([
    ['label' => 'Home', 'url' => '/', 'target' => '_self'],
], JSON_UNESCAPED_SLASHES);

return [
    "INSERT IGNORE INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('primary_navigation_enabled', '1', NOW())",
    "INSERT IGNORE INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('primary_navigation', '" . str_replace("'", "''", $homeOnlyNav) . "', NOW())",
    "INSERT IGNORE INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('legacy_page_navigation_migrated', '0', NOW())",
    "INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('version', '0.2.12', NOW()) ON DUPLICATE KEY UPDATE setting_value = '0.2.12', updated_at = NOW()",
    "INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('fresh_install_baseline', '0.2.12', NOW()) ON DUPLICATE KEY UPDATE setting_value = '0.2.12', updated_at = NOW()",
    "INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('navigation_manager_rebuild', '1', NOW()) ON DUPLICATE KEY UPDATE setting_value = '1', updated_at = NOW()"
];

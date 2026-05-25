<?php
return [
    "INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('version', '0.2.8', NOW()) ON DUPLICATE KEY UPDATE setting_value = '0.2.8', updated_at = NOW()",
    "INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('content_storage_mode', 'database', NOW()) ON DUPLICATE KEY UPDATE setting_value = 'database', updated_at = NOW()",
    "INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('fresh_install_baseline', '0.2.8', NOW()) ON DUPLICATE KEY UPDATE setting_value = '0.2.8', updated_at = NOW()",
];

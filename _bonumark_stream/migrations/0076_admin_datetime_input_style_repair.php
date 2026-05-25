<?php
return [
    "INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('version', '0.2.52', NOW()) ON DUPLICATE KEY UPDATE setting_value = '0.2.52', updated_at = NOW()",
    "INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('fresh_install_baseline', '0.2.52', NOW()) ON DUPLICATE KEY UPDATE setting_value = '0.2.52', updated_at = NOW()",
    "INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('release_name', 'Admin Date-Time Input Style Repair Pass', NOW()) ON DUPLICATE KEY UPDATE setting_value = 'Admin Date-Time Input Style Repair Pass', updated_at = NOW()",
];

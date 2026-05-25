<?php
return [
    "INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('version', '0.2.42', NOW()) ON DUPLICATE KEY UPDATE setting_value = '0.2.42', updated_at = NOW()",
    "INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('fresh_install_baseline', '0.2.42', NOW()) ON DUPLICATE KEY UPDATE setting_value = '0.2.42', updated_at = NOW()",
    "INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('release_name', 'Admin Dashboard Overview Pass', NOW()) ON DUPLICATE KEY UPDATE setting_value = 'Admin Dashboard Overview Pass', updated_at = NOW()",
];

<?php
return [
    "INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('version', '0.2.44', NOW()) ON DUPLICATE KEY UPDATE setting_value = '0.2.44', updated_at = NOW()",
    "INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('fresh_install_baseline', '0.2.44', NOW()) ON DUPLICATE KEY UPDATE setting_value = '0.2.44', updated_at = NOW()",
    "INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('release_name', 'Admin Dashboard Layout Polish Pass', NOW()) ON DUPLICATE KEY UPDATE setting_value = 'Admin Dashboard Layout Polish Pass', updated_at = NOW()",
];

<?php
return [
    "INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('version', '0.2.50', NOW()) ON DUPLICATE KEY UPDATE setting_value = '0.2.50', updated_at = NOW()",
    "INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('fresh_install_baseline', '0.2.50', NOW()) ON DUPLICATE KEY UPDATE setting_value = '0.2.50', updated_at = NOW()",
    "INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('release_name', 'Admin Autofill Input Color Repair Pass', NOW()) ON DUPLICATE KEY UPDATE setting_value = 'Admin Autofill Input Color Repair Pass', updated_at = NOW()",
];

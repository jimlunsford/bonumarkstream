<?php
return [
    "INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('version', '0.2.51', NOW()) ON DUPLICATE KEY UPDATE setting_value = '0.2.51', updated_at = NOW()",
    "INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('fresh_install_baseline', '0.2.51', NOW()) ON DUPLICATE KEY UPDATE setting_value = '0.2.51', updated_at = NOW()",
    "INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('release_name', 'Upgrade Screen Simplification Pass', NOW()) ON DUPLICATE KEY UPDATE setting_value = 'Upgrade Screen Simplification Pass', updated_at = NOW()",
];

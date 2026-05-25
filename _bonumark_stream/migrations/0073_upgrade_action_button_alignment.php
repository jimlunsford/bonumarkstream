<?php
return [
    "INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('version', '0.2.49', NOW()) ON DUPLICATE KEY UPDATE setting_value = '0.2.49', updated_at = NOW()",
    "INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('fresh_install_baseline', '0.2.49', NOW()) ON DUPLICATE KEY UPDATE setting_value = '0.2.49', updated_at = NOW()",
    "INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('release_name', 'Upgrade Action Button Alignment Pass', NOW()) ON DUPLICATE KEY UPDATE setting_value = 'Upgrade Action Button Alignment Pass', updated_at = NOW()",
];

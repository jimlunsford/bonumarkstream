<?php
return [
    "UPDATE `{{prefix}}settings` SET setting_value = 'default', updated_at = NOW() WHERE setting_key = 'active_public_theme' AND setting_value = 'microblog-stream'",
    "DELETE FROM `{{prefix}}settings` WHERE setting_key = 'public_theme_settings_microblog-stream'",
    "INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('version', '0.2.36', NOW()) ON DUPLICATE KEY UPDATE setting_value = '0.2.36', updated_at = NOW()",
    "INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('fresh_install_baseline', '0.2.36', NOW()) ON DUPLICATE KEY UPDATE setting_value = '0.2.36', updated_at = NOW()",
];

<?php
return [
    "ALTER TABLE `{{prefix}}users` ADD COLUMN `social_links` LONGTEXT NULL AFTER `website`",
    "INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('version', '0.2.18', NOW()) ON DUPLICATE KEY UPDATE setting_value = '0.2.18', updated_at = NOW()",
    "INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('fresh_install_baseline', '0.2.18', NOW()) ON DUPLICATE KEY UPDATE setting_value = '0.2.18', updated_at = NOW()",
    "INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('public_profile_social_links', '1', NOW()) ON DUPLICATE KEY UPDATE setting_value = '1', updated_at = NOW()"
];

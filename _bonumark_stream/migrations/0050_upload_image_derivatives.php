<?php
return [
    "ALTER TABLE `{{prefix}}media` ADD COLUMN `image_variants_json` LONGTEXT NULL AFTER `file_hash`",
    "INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('version', '0.2.26', NOW()) ON DUPLICATE KEY UPDATE setting_value = '0.2.26', updated_at = NOW()",
    "INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('fresh_install_baseline', '0.2.26', NOW()) ON DUPLICATE KEY UPDATE setting_value = '0.2.26', updated_at = NOW()",
    "INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('upload_image_derivatives_pass', '1', NOW()) ON DUPLICATE KEY UPDATE setting_value = '1', updated_at = NOW()"
];

<?php
return [
"ALTER TABLE `{{prefix}}posts` ADD COLUMN `content_body` LONGTEXT NULL AFTER `description`",
"ALTER TABLE `{{prefix}}posts` ADD COLUMN `content_front_matter` LONGTEXT NULL AFTER `content_body`",
"ALTER TABLE `{{prefix}}posts` ADD COLUMN `content_source` VARCHAR(30) NOT NULL DEFAULT 'database' AFTER `content_front_matter`",
"ALTER TABLE `{{prefix}}posts` ADD COLUMN `storage_mode` VARCHAR(30) NOT NULL DEFAULT 'database' AFTER `content_source`",
"ALTER TABLE `{{prefix}}posts` MODIFY `markdown_path` VARCHAR(255) NULL",
"ALTER TABLE `{{prefix}}posts` ADD KEY `post_type_status_slug` (`post_type`, `status`, `slug`)",
"INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('content_storage_mode', 'database', NOW()) ON DUPLICATE KEY UPDATE setting_value = 'database', updated_at = NOW()",
"INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('database_first_import_complete', '0', NOW()) ON DUPLICATE KEY UPDATE setting_value = setting_value",
];

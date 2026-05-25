<?php
return [
"ALTER TABLE `{{prefix}}revisions` MODIFY `markdown_path` VARCHAR(255) NULL",
"ALTER TABLE `{{prefix}}revisions` ADD COLUMN `content_body` LONGTEXT NULL AFTER `original_filename`",
"ALTER TABLE `{{prefix}}revisions` ADD COLUMN `content_front_matter` LONGTEXT NULL AFTER `content_body`",
"ALTER TABLE `{{prefix}}revisions` ADD COLUMN `content_source` VARCHAR(30) NOT NULL DEFAULT 'database' AFTER `content_front_matter`",
"ALTER TABLE `{{prefix}}trash` MODIFY `markdown_path` VARCHAR(255) NULL",
"ALTER TABLE `{{prefix}}trash` ADD COLUMN `post_type` VARCHAR(40) NOT NULL DEFAULT 'stream' AFTER `markdown_path`",
"ALTER TABLE `{{prefix}}trash` ADD COLUMN `content_body` LONGTEXT NULL AFTER `post_type`",
"ALTER TABLE `{{prefix}}trash` ADD COLUMN `content_front_matter` LONGTEXT NULL AFTER `content_body`",
"ALTER TABLE `{{prefix}}trash` ADD COLUMN `content_source` VARCHAR(30) NOT NULL DEFAULT 'database' AFTER `content_front_matter`",
"INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('content_storage_mode', 'database', NOW()) ON DUPLICATE KEY UPDATE setting_value = 'database', updated_at = NOW()",
];

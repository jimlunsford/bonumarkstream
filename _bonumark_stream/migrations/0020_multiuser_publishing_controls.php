<?php
return [
"ALTER TABLE `{{prefix}}posts` ADD COLUMN `review_status` VARCHAR(30) NOT NULL DEFAULT '' AFTER `status`",
"ALTER TABLE `{{prefix}}posts` ADD COLUMN `submitted_at` DATETIME NULL AFTER `review_status`",
"ALTER TABLE `{{prefix}}posts` ADD COLUMN `reviewed_at` DATETIME NULL AFTER `submitted_at`",
"ALTER TABLE `{{prefix}}posts` ADD COLUMN `reviewed_by` BIGINT UNSIGNED NULL AFTER `reviewed_at`",
"ALTER TABLE `{{prefix}}posts` ADD KEY `review_status` (`review_status`)",
"ALTER TABLE `{{prefix}}posts` ADD KEY `author_status_review` (`author_id`, `status`, `review_status`)",
"INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('user_publish_mode', 'draft_review', NOW()) ON DUPLICATE KEY UPDATE setting_value = setting_value",
"INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('media_limit_administrator_mb', '32', NOW()) ON DUPLICATE KEY UPDATE setting_value = setting_value",
"INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('media_limit_user_mb', '8', NOW()) ON DUPLICATE KEY UPDATE setting_value = setting_value",
"INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('media_limit_commenter_mb', '2', NOW()) ON DUPLICATE KEY UPDATE setting_value = setting_value"
];

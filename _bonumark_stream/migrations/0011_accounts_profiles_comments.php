<?php
return [
"ALTER TABLE `{{prefix}}users` ADD COLUMN `bio` TEXT NULL AFTER `email`",
"ALTER TABLE `{{prefix}}users` ADD COLUMN `website` VARCHAR(255) NOT NULL DEFAULT '' AFTER `bio`",
"ALTER TABLE `{{prefix}}users` ADD COLUMN `profile_visibility` VARCHAR(30) NOT NULL DEFAULT 'public' AFTER `website`",
"ALTER TABLE `{{prefix}}users` ADD KEY `profile_visibility` (`profile_visibility`)",
"UPDATE `{{prefix}}users` SET role = 'user' WHERE role IN ('author','editor')",
"CREATE TABLE IF NOT EXISTS `{{prefix}}comments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `post_slug` VARCHAR(190) NOT NULL,
  `post_id` BIGINT UNSIGNED NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `parent_id` BIGINT UNSIGNED NULL,
  `body` TEXT NOT NULL,
  `status` VARCHAR(30) NOT NULL DEFAULT 'approved',
  `ip_hash` CHAR(64) NOT NULL DEFAULT '',
  `user_agent_hash` CHAR(64) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  `approved_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `post_slug_status` (`post_slug`, `status`),
  KEY `user_id` (`user_id`),
  KEY `status_created` (`status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('comments_enabled', '1', NOW()) ON DUPLICATE KEY UPDATE setting_value = setting_value",
"INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('comment_registration_enabled', '1', NOW()) ON DUPLICATE KEY UPDATE setting_value = setting_value",
"INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('comments_default_status', 'approved', NOW()) ON DUPLICATE KEY UPDATE setting_value = setting_value"
];

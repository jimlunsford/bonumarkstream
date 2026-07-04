<?php
return [
"CREATE TABLE IF NOT EXISTS `{{prefix}}remember_tokens` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `selector` CHAR(24) NOT NULL,
  `token_hash` CHAR(64) NOT NULL,
  `user_agent_hash` CHAR(64) NOT NULL DEFAULT '',
  `expires_at` DATETIME NOT NULL,
  `last_used_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `selector` (`selector`),
  KEY `user_id` (`user_id`),
  KEY `expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"INSERT INTO `{{prefix}}settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('remember_login_enabled', '1', NOW()) ON DUPLICATE KEY UPDATE `setting_value` = `setting_value`",
"INSERT INTO `{{prefix}}settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('remember_login_days', '30', NOW()) ON DUPLICATE KEY UPDATE `setting_value` = `setting_value`"
];

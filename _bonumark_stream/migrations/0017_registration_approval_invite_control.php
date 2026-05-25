<?php
return [
"CREATE TABLE IF NOT EXISTS `{{prefix}}registration_invites` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code_hash` CHAR(64) NOT NULL,
  `code_hint` VARCHAR(40) NOT NULL DEFAULT '',
  `label` VARCHAR(120) NOT NULL DEFAULT '',
  `role` VARCHAR(40) NOT NULL DEFAULT '',
  `max_uses` INT UNSIGNED NOT NULL DEFAULT 1,
  `used_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `expires_at` DATETIME NULL,
  `status` VARCHAR(30) NOT NULL DEFAULT 'active',
  `created_by` BIGINT UNSIGNED NULL,
  `last_used_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code_hash` (`code_hash`),
  KEY `status` (`status`),
  KEY `expires_at` (`expires_at`),
  KEY `created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('registration_require_admin_approval', '0', NOW()) ON DUPLICATE KEY UPDATE setting_value = setting_value",
"INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('registration_user_role_requires_approval', '1', NOW()) ON DUPLICATE KEY UPDATE setting_value = setting_value"
];

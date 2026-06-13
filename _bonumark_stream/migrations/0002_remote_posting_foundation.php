<?php
return [
"CREATE TABLE IF NOT EXISTS `{{prefix}}api_tokens` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `token_name` VARCHAR(120) NOT NULL,
  `token_prefix` VARCHAR(24) NOT NULL DEFAULT '',
  `token_hint` VARCHAR(24) NOT NULL DEFAULT '',
  `token_hash` CHAR(64) NOT NULL,
  `scopes_json` LONGTEXT NULL,
  `status` VARCHAR(30) NOT NULL DEFAULT 'active',
  `created_by` BIGINT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  `last_used_at` DATETIME NULL,
  `last_used_ip_hash` CHAR(64) NOT NULL DEFAULT '',
  `expires_at` DATETIME NULL,
  `revoked_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token_hash` (`token_hash`),
  KEY `status` (`status`),
  KEY `created_by` (`created_by`),
  KEY `expires_at` (`expires_at`),
  KEY `last_used_at` (`last_used_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

"CREATE TABLE IF NOT EXISTS `{{prefix}}api_audit_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `token_id` BIGINT UNSIGNED NULL,
  `event` VARCHAR(80) NOT NULL DEFAULT '',
  `method` VARCHAR(12) NOT NULL DEFAULT '',
  `route` VARCHAR(255) NOT NULL DEFAULT '',
  `ip_hash` CHAR(64) NOT NULL DEFAULT '',
  `user_agent_hash` CHAR(64) NOT NULL DEFAULT '',
  `status_code` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `success` TINYINT(1) NOT NULL DEFAULT 0,
  `message` VARCHAR(255) NOT NULL DEFAULT '',
  `request_id` VARCHAR(80) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `token_id` (`token_id`),
  KEY `event` (`event`),
  KEY `created_at` (`created_at`),
  KEY `ip_hash` (`ip_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

"CREATE TABLE IF NOT EXISTS `{{prefix}}api_rate_limit_attempts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `identifier_hash` CHAR(64) NOT NULL,
  `route` VARCHAR(120) NOT NULL DEFAULT '',
  `success` TINYINT(1) NOT NULL DEFAULT 0,
  `attempted_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `identifier_route_time` (`identifier_hash`, `route`, `attempted_at`),
  KEY `attempted_at` (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

"INSERT INTO `{{prefix}}settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('remote_posting_enabled', '0', NOW()) ON DUPLICATE KEY UPDATE `setting_value` = `setting_value`",
"INSERT INTO `{{prefix}}settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('remote_posting_rate_limit_per_minute', '60', NOW()) ON DUPLICATE KEY UPDATE `setting_value` = `setting_value`"
];

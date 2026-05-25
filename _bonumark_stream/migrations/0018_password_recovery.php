<?php
return [
"CREATE TABLE IF NOT EXISTS `{{prefix}}password_reset_tokens` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `token_hash` CHAR(64) NOT NULL,
  `requested_ip_hash` CHAR(64) NOT NULL DEFAULT '',
  `expires_at` DATETIME NOT NULL,
  `used_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token_hash` (`token_hash`),
  KEY `user_id` (`user_id`),
  KEY `expires_at` (`expires_at`),
  KEY `used_at` (`used_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS `{{prefix}}password_reset_attempts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `identifier_hash` CHAR(64) NOT NULL DEFAULT '',
  `ip_hash` CHAR(64) NOT NULL DEFAULT '',
  `mail_sent` TINYINT(1) NOT NULL DEFAULT 0,
  `attempted_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `identifier_hash` (`identifier_hash`),
  KEY `ip_hash` (`ip_hash`),
  KEY `attempted_at` (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
];

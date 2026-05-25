<?php
return [
"CREATE TABLE IF NOT EXISTS `{{prefix}}stream_like_attempts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `post_slug` VARCHAR(190) NOT NULL,
  `visitor_hash` CHAR(64) NOT NULL,
  `ip_hash` CHAR(64) NOT NULL,
  `attempted_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `post_slug` (`post_slug`),
  KEY `visitor_hash` (`visitor_hash`),
  KEY `ip_hash` (`ip_hash`),
  KEY `attempted_at` (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"ALTER TABLE `{{prefix}}posts` DROP INDEX `slug_status`",
"ALTER TABLE `{{prefix}}posts` ADD UNIQUE KEY `post_type_slug_status` (`post_type`, `slug`, `status`)",
"INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('version', '0.2.8', NOW()) ON DUPLICATE KEY UPDATE setting_value = '0.2.8', updated_at = NOW()",
"INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('fresh_install_baseline', '0.2.8', NOW()) ON DUPLICATE KEY UPDATE setting_value = '0.2.8', updated_at = NOW()"
];

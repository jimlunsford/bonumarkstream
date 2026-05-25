<?php
return [
"CREATE TABLE IF NOT EXISTS `{{prefix}}email_verification_attempts` (
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

<?php
return [
"CREATE TABLE IF NOT EXISTS `{{prefix}}mail_test_deliveries` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `transport` VARCHAR(40) NOT NULL DEFAULT 'disabled',
  `body_format` VARCHAR(30) NOT NULL DEFAULT 'plain_text',
  `recipient_to` TEXT NULL,
  `subject` VARCHAR(255) NOT NULL DEFAULT '',
  `status` VARCHAR(30) NOT NULL DEFAULT 'failed',
  `error_message` TEXT NULL,
  `sent_at` DATETIME NULL,
  `triggered_by` BIGINT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `created_at` (`created_at`),
  KEY `status` (`status`),
  KEY `triggered_by` (`triggered_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
];

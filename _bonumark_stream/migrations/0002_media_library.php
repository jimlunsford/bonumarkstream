<?php
return [
"CREATE TABLE IF NOT EXISTS `{{prefix}}media` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `filename` VARCHAR(190) NOT NULL,
  `original_filename` VARCHAR(255) NOT NULL DEFAULT '',
  `public_path` VARCHAR(255) NOT NULL,
  `mime_type` VARCHAR(100) NOT NULL DEFAULT '',
  `file_size` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `width` INT UNSIGNED NULL,
  `height` INT UNSIGNED NULL,
  `alt_text` VARCHAR(255) NOT NULL DEFAULT '',
  `caption` TEXT NULL,
  `uploaded_by` BIGINT UNSIGNED NULL,
  `file_hash` CHAR(64) NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `created_at` (`created_at`),
  KEY `mime_type` (`mime_type`),
  KEY `uploaded_by` (`uploaded_by`),
  UNIQUE KEY `public_path` (`public_path`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
];

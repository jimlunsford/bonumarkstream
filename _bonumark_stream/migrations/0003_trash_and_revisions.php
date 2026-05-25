<?php
return [
"CREATE TABLE IF NOT EXISTS `{{prefix}}trash` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(255) NOT NULL,
  `slug` VARCHAR(190) NOT NULL,
  `original_status` VARCHAR(30) NOT NULL DEFAULT 'draft',
  `original_filename` VARCHAR(255) NOT NULL,
  `trash_filename` VARCHAR(255) NOT NULL,
  `markdown_path` VARCHAR(255) NOT NULL,
  `content_hash` CHAR(64) NULL,
  `deleted_by` BIGINT UNSIGNED NULL,
  `deleted_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `deleted_at` (`deleted_at`),
  KEY `slug` (`slug`),
  KEY `original_status` (`original_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

"ALTER TABLE `{{prefix}}revisions` ADD COLUMN `title` VARCHAR(255) NOT NULL DEFAULT '' AFTER `slug`",
"ALTER TABLE `{{prefix}}revisions` ADD COLUMN `status` VARCHAR(30) NOT NULL DEFAULT 'published' AFTER `title`",
"ALTER TABLE `{{prefix}}revisions` ADD COLUMN `original_filename` VARCHAR(255) NOT NULL DEFAULT '' AFTER `status`"
];

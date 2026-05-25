<?php
return [
"CREATE TABLE IF NOT EXISTS `{{prefix}}autosaves` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NULL,
  `draft_key` VARCHAR(190) NOT NULL,
  `title` VARCHAR(255) NOT NULL DEFAULT '',
  `slug` VARCHAR(190) NOT NULL DEFAULT '',
  `section` VARCHAR(30) NOT NULL DEFAULT 'drafts',
  `filename` VARCHAR(255) NOT NULL DEFAULT '',
  `markdown` LONGTEXT NOT NULL,
  `fields_json` LONGTEXT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_key` (`user_id`, `draft_key`),
  KEY `updated_at` (`updated_at`),
  KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
];

<?php
return [
"CREATE TABLE IF NOT EXISTS `{{prefix}}users` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(64) NOT NULL,
  `display_name` VARCHAR(120) NOT NULL,
  `email` VARCHAR(190) NOT NULL DEFAULT '',
  `password_hash` VARCHAR(255) NOT NULL,
  `role` VARCHAR(40) NOT NULL DEFAULT 'administrator',
  `status` VARCHAR(30) NOT NULL DEFAULT 'active',
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

"CREATE TABLE IF NOT EXISTS `{{prefix}}settings` (
  `setting_key` VARCHAR(120) NOT NULL,
  `setting_value` LONGTEXT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

"CREATE TABLE IF NOT EXISTS `{{prefix}}posts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `author_id` BIGINT UNSIGNED NULL,
  `title` VARCHAR(255) NOT NULL,
  `slug` VARCHAR(190) NOT NULL,
  `status` VARCHAR(30) NOT NULL DEFAULT 'draft',
  `post_type` VARCHAR(40) NOT NULL DEFAULT 'stream',
  `description` TEXT NULL,
  `category` VARCHAR(190) NOT NULL DEFAULT 'Stream',
  `category_slug` VARCHAR(190) NOT NULL DEFAULT 'stream',
  `markdown_path` VARCHAR(255) NOT NULL,
  `html_path` VARCHAR(255) NULL,
  `date_published` DATE NULL,
  `content_hash` CHAR(64) NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  `published_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug_status` (`slug`, `status`),
  KEY `status` (`status`),
  KEY `category_slug` (`category_slug`),
  KEY `date_published` (`date_published`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

"CREATE TABLE IF NOT EXISTS `{{prefix}}terms` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `term_type` VARCHAR(30) NOT NULL,
  `name` VARCHAR(190) NOT NULL,
  `slug` VARCHAR(190) NOT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `type_slug` (`term_type`, `slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

"CREATE TABLE IF NOT EXISTS `{{prefix}}post_terms` (
  `post_id` BIGINT UNSIGNED NOT NULL,
  `term_id` BIGINT UNSIGNED NOT NULL,
  `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`post_id`, `term_id`),
  KEY `term_id` (`term_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

"CREATE TABLE IF NOT EXISTS `{{prefix}}revisions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `post_id` BIGINT UNSIGNED NULL,
  `slug` VARCHAR(190) NOT NULL,
  `markdown_path` VARCHAR(255) NOT NULL,
  `content_hash` CHAR(64) NULL,
  `author_id` BIGINT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `post_id` (`post_id`),
  KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

"CREATE TABLE IF NOT EXISTS `{{prefix}}login_attempts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(64) NOT NULL DEFAULT '',
  `ip_hash` CHAR(64) NOT NULL DEFAULT '',
  `success` TINYINT(1) NOT NULL DEFAULT 0,
  `attempted_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `attempted_at` (`attempted_at`),
  KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

"CREATE TABLE IF NOT EXISTS `{{prefix}}upgrade_history` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `from_version` VARCHAR(80) NOT NULL DEFAULT '',
  `to_version` VARCHAR(80) NOT NULL DEFAULT '',
  `status` VARCHAR(30) NOT NULL DEFAULT 'complete',
  `notes` TEXT NULL,
  `ran_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ran_at` (`ran_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

"CREATE TABLE IF NOT EXISTS `{{prefix}}migrations` (
  `migration` VARCHAR(120) NOT NULL,
  `ran_at` DATETIME NOT NULL,
  PRIMARY KEY (`migration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
];

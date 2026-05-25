<?php
return [
"CREATE TABLE IF NOT EXISTS `{{prefix}}stream_likes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `post_id` BIGINT UNSIGNED NOT NULL,
  `post_slug` VARCHAR(190) NOT NULL,
  `visitor_hash` CHAR(64) NOT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `post_visitor` (`post_id`, `visitor_hash`),
  KEY `post_id` (`post_id`),
  KEY `post_slug` (`post_slug`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"UPDATE `{{prefix}}settings` SET `setting_value` = '0', `updated_at` = NOW() WHERE `setting_key` = 'stream_show_edit_links'"
];

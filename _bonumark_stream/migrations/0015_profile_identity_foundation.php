<?php
return [
    "CREATE TABLE IF NOT EXISTS `{{prefix}}user_profiles` (
      `user_id` BIGINT UNSIGNED NOT NULL,
      `headline` VARCHAR(180) NOT NULL DEFAULT '',
      `about_markdown` MEDIUMTEXT NOT NULL,
      `location` VARCHAR(190) NOT NULL DEFAULT '',
      `now_text` TEXT NOT NULL,
      `cover_image_path` VARCHAR(500) NOT NULL DEFAULT '',
      `links_json` MEDIUMTEXT NOT NULL,
      `interests_json` MEDIUMTEXT NOT NULL,
      `show_post_count` TINYINT(1) NOT NULL DEFAULT 0,
      `show_comment_count` TINYINT(1) NOT NULL DEFAULT 0,
      `show_member_since` TINYINT(1) NOT NULL DEFAULT 0,
      `created_at` DATETIME NOT NULL,
      `updated_at` DATETIME NOT NULL,
      PRIMARY KEY (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "INSERT IGNORE INTO `{{prefix}}user_profiles` (`user_id`, `headline`, `about_markdown`, `location`, `now_text`, `cover_image_path`, `links_json`, `interests_json`, `show_post_count`, `show_comment_count`, `show_member_since`, `created_at`, `updated_at`) SELECT `id`, '', '', '', '', '', CASE WHEN TRIM(COALESCE(`social_links`, '')) = '' THEN '[]' ELSE `social_links` END, '[]', 0, 0, 0, NOW(), NOW() FROM `{{prefix}}users`",
];

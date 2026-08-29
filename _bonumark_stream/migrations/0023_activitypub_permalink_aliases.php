<?php
return [
    "CREATE TABLE IF NOT EXISTS `{{prefix}}activitypub_permalink_aliases` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `post_id` BIGINT UNSIGNED NOT NULL,
        `slug` VARCHAR(190) NOT NULL,
        `created_at` DATETIME NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `slug` (`slug`),
        KEY `post_id` (`post_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "INSERT IGNORE INTO `{{prefix}}activitypub_permalink_aliases` (`post_id`, `slug`, `created_at`)
     SELECT e.`post_id`, JSON_UNQUOTE(JSON_EXTRACT(e.`state_json`, '$.before.slug')), MIN(e.`created_at`)
     FROM `{{prefix}}activitypub_publication_events` e
     WHERE e.`post_id` IS NOT NULL
       AND e.`event_type` = 'updated'
       AND e.`status` <> 'observed'
       AND JSON_VALID(e.`state_json`) = 1
       AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(e.`state_json`, '$.before.status')), '') = 'published'
       AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(e.`state_json`, '$.after.status')), '') = 'published'
       AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(e.`state_json`, '$.before.slug')), '') <> ''
       AND JSON_UNQUOTE(JSON_EXTRACT(e.`state_json`, '$.before.slug')) <> JSON_UNQUOTE(JSON_EXTRACT(e.`state_json`, '$.after.slug'))
     GROUP BY e.`post_id`, JSON_UNQUOTE(JSON_EXTRACT(e.`state_json`, '$.before.slug'))",
];

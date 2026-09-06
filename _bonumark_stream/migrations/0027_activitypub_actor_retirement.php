<?php
return [
    "CREATE TABLE IF NOT EXISTS `{{prefix}}activitypub_local_actor_lifecycle` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `actor_uri` VARCHAR(500) NOT NULL,
        `lifecycle_state` VARCHAR(20) NOT NULL,
        `delete_activity_uri` VARCHAR(500) NOT NULL,
        `delete_payload_json` LONGTEXT NOT NULL,
        `retired_at` DATETIME NOT NULL,
        `delivery_completed_at` DATETIME NULL,
        `created_at` DATETIME NOT NULL,
        `updated_at` DATETIME NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `actor_uri` (`actor_uri`),
        UNIQUE KEY `delete_activity_uri` (`delete_activity_uri`),
        KEY `state_updated` (`lifecycle_state`, `updated_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];

<?php
return [
    "ALTER TABLE `{{prefix}}activitypub_remote_actors` ADD COLUMN `lifecycle_state` VARCHAR(20) NOT NULL DEFAULT 'active' AFTER `actor_type`",
    "ALTER TABLE `{{prefix}}activitypub_remote_actors` ADD COLUMN `last_fetch_status` SMALLINT UNSIGNED NULL AFTER `expires_at`",
    "ALTER TABLE `{{prefix}}activitypub_remote_actors` ADD COLUMN `last_fetch_error` TEXT NULL AFTER `last_fetch_status`",
    "ALTER TABLE `{{prefix}}activitypub_remote_actors` ADD COLUMN `failure_count` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `last_fetch_error`",
    "ALTER TABLE `{{prefix}}activitypub_remote_actors` ADD COLUMN `last_failed_at` DATETIME NULL AFTER `failure_count`",
    "ALTER TABLE `{{prefix}}activitypub_remote_actors` ADD COLUMN `deleted_at` DATETIME NULL AFTER `last_failed_at`",
    "ALTER TABLE `{{prefix}}activitypub_remote_actors` ADD KEY `lifecycle_expires` (`lifecycle_state`, `expires_at`)",
];

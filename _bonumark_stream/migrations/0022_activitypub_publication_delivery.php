<?php
return [
    "ALTER TABLE `{{prefix}}activitypub_local_objects` ADD COLUMN `last_object_json` LONGTEXT NULL AFTER `content_hash`",
    "ALTER TABLE `{{prefix}}activitypub_local_objects` ADD COLUMN `last_human_url` VARCHAR(500) NULL AFTER `last_object_json`",
    "ALTER TABLE `{{prefix}}activitypub_local_objects` ADD COLUMN `publication_generation` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `last_human_url`",
    "ALTER TABLE `{{prefix}}activitypub_local_objects` ADD COLUMN `transition_sequence` BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `publication_generation`",
    "ALTER TABLE `{{prefix}}activitypub_publication_events` ADD COLUMN `activity_uri` VARCHAR(500) NULL AFTER `event_type`",
    "ALTER TABLE `{{prefix}}activitypub_publication_events` ADD COLUMN `payload_json` LONGTEXT NULL AFTER `state_json`",
    "ALTER TABLE `{{prefix}}activitypub_publication_events` ADD COLUMN `transition_fingerprint` CHAR(64) NULL AFTER `payload_json`",
    "ALTER TABLE `{{prefix}}activitypub_publication_events` ADD UNIQUE KEY `activity_uri` (`activity_uri`)",
    "ALTER TABLE `{{prefix}}activitypub_publication_events` ADD UNIQUE KEY `transition_fingerprint` (`transition_fingerprint`)",
    "ALTER TABLE `{{prefix}}activitypub_deliveries` ADD COLUMN `recipient_actor_ids_json` LONGTEXT NULL AFTER `inbox_url`",
    "ALTER TABLE `{{prefix}}activitypub_deliveries` ADD COLUMN `signature_mode` VARCHAR(20) NOT NULL DEFAULT 'adaptive' AFTER `recipient_actor_ids_json`",
];

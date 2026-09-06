<?php
return [
    "UPDATE `{{prefix}}activitypub_publication_events` SET `status` = 'observed', `processed_at` = COALESCE(`processed_at`, `created_at`) WHERE `status` = 'pending'",
    "ALTER TABLE `{{prefix}}activitypub_publication_events` MODIFY COLUMN `status` VARCHAR(20) NOT NULL DEFAULT 'observed'",
];

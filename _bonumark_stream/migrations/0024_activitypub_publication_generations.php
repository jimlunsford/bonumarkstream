<?php
return [
    "ALTER TABLE `{{prefix}}activitypub_publication_events` ADD COLUMN `publication_generation` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `post_id`",
    "ALTER TABLE `{{prefix}}activitypub_publication_events` ADD COLUMN `object_uri` VARCHAR(500) NULL AFTER `publication_generation`",
    "ALTER TABLE `{{prefix}}activitypub_deliveries` ADD COLUMN `publication_generation` INT UNSIGNED NULL AFTER `event_id`",
    "ALTER TABLE `{{prefix}}activitypub_deliveries` ADD COLUMN `object_uri` VARCHAR(500) NULL AFTER `publication_generation`",
    "UPDATE `{{prefix}}activitypub_publication_events`
     SET `object_uri` = NULLIF(JSON_UNQUOTE(JSON_EXTRACT(`payload_json`, '$.object.id')), '')
     WHERE `payload_json` IS NOT NULL AND JSON_VALID(`payload_json`) = 1",
    "UPDATE `{{prefix}}activitypub_publication_events` e
     INNER JOIN (
         SELECT materialized.`id`, materialized.`generation`
         FROM (
             SELECT `id`, GREATEST(1, SUM(
                 CASE WHEN JSON_VALID(`payload_json`) = 1
                           AND JSON_UNQUOTE(JSON_EXTRACT(`payload_json`, '$.type')) = 'Create'
                      THEN 1 ELSE 0 END
             ) OVER (PARTITION BY `post_id` ORDER BY `id` ROWS UNBOUNDED PRECEDING)) AS `generation`
             FROM `{{prefix}}activitypub_publication_events`
             WHERE `post_id` IS NOT NULL AND `status` <> 'observed'
         ) materialized
     ) generations ON generations.`id` = e.`id`
     SET e.`publication_generation` = generations.`generation`",
    "UPDATE `{{prefix}}activitypub_deliveries` d
     INNER JOIN `{{prefix}}activitypub_publication_events` e ON e.`id` = d.`event_id`
     SET d.`publication_generation` = e.`publication_generation`, d.`object_uri` = e.`object_uri`
     WHERE d.`delivery_type` = 'publication' AND d.`event_id` IS NOT NULL",
    "UPDATE `{{prefix}}activitypub_deliveries` d
     INNER JOIN `{{prefix}}activitypub_publication_events` e ON e.`id` = d.`event_id`
     SET d.`status` = 'retired',
         d.`last_error` = 'Legacy publication work retired because its ActivityPub object URI had already been deleted.',
         d.`updated_at` = UTC_TIMESTAMP()
     WHERE d.`delivery_type` = 'publication'
       AND d.`status` IN ('pending', 'retry', 'processing')
       AND e.`post_id` IS NOT NULL AND e.`object_uri` IS NOT NULL
       AND EXISTS (
           SELECT 1
           FROM `{{prefix}}activitypub_publication_events` retired
           WHERE retired.`post_id` = e.`post_id`
             AND retired.`object_uri` = e.`object_uri`
             AND retired.`id` < e.`id`
             AND retired.`event_type` IN ('unpublished', 'deleted')
             AND retired.`status` <> 'observed'
       )",
    "UPDATE `{{prefix}}activitypub_local_objects`
     SET `publication_generation` = 1
     WHERE `object_uri` NOT REGEXP '/generations/[1-9][0-9]*/?$'",
    "UPDATE `{{prefix}}activitypub_local_objects` lo
     INNER JOIN (
         SELECT `post_id`, `object_uri`, MAX(`created_at`) AS `deleted_at`
         FROM `{{prefix}}activitypub_publication_events`
         WHERE `post_id` IS NOT NULL AND `object_uri` IS NOT NULL
           AND JSON_VALID(`payload_json`) = 1
           AND JSON_UNQUOTE(JSON_EXTRACT(`payload_json`, '$.type')) = 'Delete'
         GROUP BY `post_id`, `object_uri`
     ) retired ON retired.`post_id` = lo.`post_id` AND retired.`object_uri` = lo.`object_uri`
     SET lo.`deleted_at` = COALESCE(lo.`deleted_at`, retired.`deleted_at`)",
    "ALTER TABLE `{{prefix}}activitypub_local_objects` DROP INDEX `post_id`",
    "ALTER TABLE `{{prefix}}activitypub_local_objects` ADD UNIQUE KEY `post_generation` (`post_id`, `publication_generation`)",
    "ALTER TABLE `{{prefix}}activitypub_publication_events` ADD KEY `post_generation_created` (`post_id`, `publication_generation`, `created_at`)",
    "ALTER TABLE `{{prefix}}activitypub_deliveries` ADD KEY `publication_generation` (`publication_generation`)",
];

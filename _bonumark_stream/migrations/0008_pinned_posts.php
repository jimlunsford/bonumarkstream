<?php
return [
    "ALTER TABLE `{{prefix}}posts` ADD COLUMN `is_pinned` TINYINT(1) NOT NULL DEFAULT 0 AFTER `scheduled_at`",
    "ALTER TABLE `{{prefix}}posts` ADD COLUMN `pinned_at` DATETIME NULL AFTER `is_pinned`",
    "ALTER TABLE `{{prefix}}posts` ADD KEY `post_type_status_pinned_at` (`post_type`, `status`, `is_pinned`, `pinned_at`)",
];

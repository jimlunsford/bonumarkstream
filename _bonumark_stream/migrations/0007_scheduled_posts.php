<?php
return [
    "ALTER TABLE `{{prefix}}posts` ADD COLUMN `scheduled_at` DATETIME NULL AFTER `published_at`",
    "ALTER TABLE `{{prefix}}posts` ADD INDEX `status_scheduled_at` (`status`, `scheduled_at`)",
    "INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('scheduled_posts_last_due_check', '0', NOW()) ON DUPLICATE KEY UPDATE setting_key = setting_key",
];

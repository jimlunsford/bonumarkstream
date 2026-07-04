<?php
return [
    "INSERT INTO `{{prefix}}settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('stream_published_at_utc_cutover', DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-%d %H:%i:%s'), NOW()) ON DUPLICATE KEY UPDATE `setting_value` = CASE WHEN `setting_value` = '1970-01-01 00:00:00' OR `setting_value` = '' THEN VALUES(`setting_value`) ELSE `setting_value` END, `updated_at` = CASE WHEN `setting_value` = '1970-01-01 00:00:00' OR `setting_value` = '' THEN NOW() ELSE `updated_at` END",
    "INSERT INTO `{{prefix}}settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('stream_published_at_utc_cutover_source', 'migration_default_utc_now', NOW()) ON DUPLICATE KEY UPDATE `setting_value` = `setting_value`, `updated_at` = `updated_at`"
];

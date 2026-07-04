<?php
return [
    "INSERT INTO `{{prefix}}settings` (`setting_key`, `setting_value`, `updated_at`) SELECT 'stream_published_at_utc_cutover', COALESCE(DATE_FORMAT(MAX(`ran_at`), '%Y-%m-%d %H:%i:%s'), '1970-01-01 00:00:00'), NOW() FROM `{{prefix}}upgrade_history` WHERE `to_version` = '0.5.23' ON DUPLICATE KEY UPDATE `setting_value` = `setting_value`"
];

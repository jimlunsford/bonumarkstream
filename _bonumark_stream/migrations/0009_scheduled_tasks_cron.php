<?php
return [
    "CREATE TABLE IF NOT EXISTS `{{prefix}}scheduled_task_runs` (\n        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\n        source VARCHAR(40) NOT NULL,\n        status VARCHAR(20) NOT NULL,\n        scheduled_posts_published INT UNSIGNED NOT NULL DEFAULT 0,\n        details TEXT NOT NULL,\n        started_at DATETIME NOT NULL,\n        completed_at DATETIME NOT NULL,\n        PRIMARY KEY (id),\n        KEY completed_at (completed_at)\n    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('scheduled_tasks_last_run_at', '0', NOW()) ON DUPLICATE KEY UPDATE setting_key = setting_key",
    "INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('scheduled_tasks_last_source', '', NOW()) ON DUPLICATE KEY UPDATE setting_key = setting_key",
    "INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('scheduled_tasks_last_status', '', NOW()) ON DUPLICATE KEY UPDATE setting_key = setting_key",
    "INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('scheduled_tasks_last_message', '', NOW()) ON DUPLICATE KEY UPDATE setting_key = setting_key",
    "INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('scheduled_tasks_expected_interval_minutes', '5', NOW()) ON DUPLICATE KEY UPDATE setting_key = setting_key",
    "INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('scheduled_tasks_public_traffic_enabled', '1', NOW()) ON DUPLICATE KEY UPDATE setting_key = setting_key",
    "INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('scheduled_tasks_heartbeat_enabled', '1', NOW()) ON DUPLICATE KEY UPDATE setting_key = setting_key",
    "INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('scheduled_tasks_web_cron_enabled', '0', NOW()) ON DUPLICATE KEY UPDATE setting_key = setting_key",
    "INSERT INTO `{{prefix}}settings` (setting_key, setting_value, updated_at) VALUES ('scheduled_tasks_web_cron_key_hash', '', NOW()) ON DUPLICATE KEY UPDATE setting_key = setting_key",
];

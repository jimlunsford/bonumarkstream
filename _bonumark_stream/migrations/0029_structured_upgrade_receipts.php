<?php
return [
    "CREATE TABLE IF NOT EXISTS `{{prefix}}upgrade_operations` (
        operation_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
        schema_version SMALLINT UNSIGNED NOT NULL,
        started_at DATETIME NOT NULL,
        completed_at DATETIME NULL,
        method VARCHAR(20) NOT NULL,
        status VARCHAR(24) NOT NULL,
        error_code VARCHAR(64) NULL,
        evidence LONGTEXT NOT NULL,
        INDEX started_at (started_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS `{{prefix}}upgrade_operation_events` (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        operation_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        observed_at DATETIME NOT NULL,
        event_type VARCHAR(64) NOT NULL,
        evidence LONGTEXT NOT NULL,
        INDEX operation_events (operation_id, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];

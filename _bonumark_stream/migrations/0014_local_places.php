<?php
return [
    "CREATE TABLE IF NOT EXISTS `{{prefix}}places` (
      `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      `name` VARCHAR(190) NOT NULL,
      `category` VARCHAR(40) NOT NULL DEFAULT 'other',
      `area_label` VARCHAR(190) NOT NULL DEFAULT '',
      `locality` VARCHAR(190) NOT NULL DEFAULT '',
      `region` VARCHAR(190) NOT NULL DEFAULT '',
      `country` VARCHAR(120) NOT NULL DEFAULT '',
      `latitude` DECIMAL(10,7) NOT NULL,
      `longitude` DECIMAL(10,7) NOT NULL,
      `default_display_mode` VARCHAR(20) NOT NULL DEFAULT 'exact',
      `created_at` DATETIME NOT NULL,
      `updated_at` DATETIME NOT NULL,
      PRIMARY KEY (`id`),
      KEY `name` (`name`),
      KEY `locality` (`locality`),
      KEY `coordinates` (`latitude`, `longitude`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];

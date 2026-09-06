<?php
return [
    "ALTER TABLE `{{prefix}}trash` ADD COLUMN `post_id` BIGINT UNSIGNED NULL AFTER `id`",
    "ALTER TABLE `{{prefix}}trash` ADD KEY `post_id` (`post_id`)",
];

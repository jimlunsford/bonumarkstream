<?php
return [
"ALTER TABLE `{{prefix}}trash` ADD COLUMN `original_author_id` BIGINT UNSIGNED NULL AFTER `content_hash`",
"ALTER TABLE `{{prefix}}trash` ADD KEY `original_author_id` (`original_author_id`)"
];

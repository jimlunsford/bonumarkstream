<?php
return [
"INSERT IGNORE INTO `{{prefix}}settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('welcome_dismissed', '0', NOW())",
"INSERT IGNORE INTO `{{prefix}}settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('autosave_enabled', '1', NOW())",
"UPDATE `{{prefix}}posts` SET post_type = 'stream' WHERE post_type <> 'stream' OR post_type = ''"
];

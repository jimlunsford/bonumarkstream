<?php
return [
"INSERT IGNORE INTO `{{prefix}}settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('homepage_mode', 'stream', NOW())"
];

<?php
return [
"INSERT IGNORE INTO `{{prefix}}settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('stream_composer_enabled', '1', NOW())",
"INSERT IGNORE INTO `{{prefix}}settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('stream_posts_per_page', '20', NOW())",
"INSERT IGNORE INTO `{{prefix}}settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('stream_show_dates', '1', NOW())",
"INSERT IGNORE INTO `{{prefix}}settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('stream_show_edit_links', '1', NOW())"
];

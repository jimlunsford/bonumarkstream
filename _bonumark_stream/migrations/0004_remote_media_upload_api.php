<?php
return [
"INSERT INTO `{{prefix}}settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('remote_media_upload_enabled', '0', NOW()) ON DUPLICATE KEY UPDATE `setting_value` = `setting_value`"
];

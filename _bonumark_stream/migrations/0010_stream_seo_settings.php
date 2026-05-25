<?php
return [
"INSERT IGNORE INTO `{{prefix}}settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('stream_index_policy', 'smart', NOW())"
];

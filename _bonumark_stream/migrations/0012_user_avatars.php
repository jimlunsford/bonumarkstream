<?php
return [
"ALTER TABLE `{{prefix}}users` ADD COLUMN `avatar_path` VARCHAR(255) NOT NULL DEFAULT '' AFTER `profile_visibility`"
];

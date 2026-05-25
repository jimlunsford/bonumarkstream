<?php
return [
"ALTER TABLE `{{prefix}}media`
  ADD COLUMN `trashed_at` DATETIME NULL AFTER `updated_at`,
  ADD COLUMN `trashed_by` BIGINT UNSIGNED NULL AFTER `trashed_at`,
  ADD KEY `trashed_at` (`trashed_at`),
  ADD KEY `trashed_by` (`trashed_by`)",
];

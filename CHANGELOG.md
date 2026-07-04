# Changelog

This file is the public release summary for Bonumark Stream. Detailed package-by-package upgrade history is kept in [`_bonumark_stream/CHANGELOG.md`](_bonumark_stream/CHANGELOG.md).

## 0.5.30 - GitHub Release Hardening Pass

### Improved

- Prepared the release package for public GitHub distribution with aligned version markers, a single package root, and refreshed repository documentation.
- Added a public release summary and expanded contributor and security-reporting guidance.
- Made the installer display the packaged version dynamically so its welcome screen cannot drift behind the release version again.

### Fixed

- Corrected stale version references in public documentation, OpenAPI metadata, API response examples, and installer copy.

### Upgrade notes

- No database migration runs in this release.
- No posts, pages, users, comments, media, themes, settings, API tokens, scheduled-task history, uploads, or existing configuration are rewritten.
- The built-in upgrader continues to support Bonumark Stream v0.4.0 and newer only.

## 0.5.x since v0.5.0

### Added

- Installable PWA support and a secure text-and-URL mobile share target that hands content to the front-end composer for review.
- Scheduled posts, a shared Scheduled Tasks runner, server cron guidance, protected web cron, task health, and run history.
- Pinned Stream posts with core-owned post actions in both front-end and admin workflows.
- Optional Remote Posting API features for scoped tokens, idempotent post creation, scheduled publishing, media upload, and safe remote image import.

### Improved

- Site-timezone rendering with canonical UTC database timestamps for current content and scheduled work.
- Upgrade recovery and MariaDB compatibility safeguards.
- PWA install icons that follow the Site Identity favicon, including safe fallback behavior on shared hosts without GD or Imagick.

### Fixed

- Existing-post save compatibility on MySQL and MariaDB native prepared statements.
- Legacy post-time display after the timezone runtime update.
- Scheduled-post display and task-history timestamp alignment.

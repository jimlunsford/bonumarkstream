# Changelog

## 0.5.42 - GitHub Release Hardening Pass

### Fixed

- Forces the fresh installer database session to UTC before migrations and initial seed writes, matching normal runtime database behavior.
- Stores the initial Admin email-verification timestamp explicitly in UTC.
- Restores missing v0.5.35 and v0.5.37 upgrade notes and corrects the mislabeled v0.5.24 timestamp section.
- Adds package smoke-test coverage for installer UTC handling and post-v0.5.30 upgrade-guide completeness.

### Upgrade notes

- No database migration runs in this release.
- Existing config, database data, posts, pages, drafts, revisions, users, comments, likes, media, uploads, themes, settings, analytics, API tokens, cron history, scheduled posts, Local Places, coordinates, consent records, and post location snapshots are preserved.

## 0.5.41 - Local Places Metadata Alignment Hotfix

### Fixed

- Removes the floating category badge from each Local Places directory card.
- Places Category beside Default public display in the labeled metadata section.
- Keeps the place name and location primary while reserving the right side for edit and delete actions.
- Changes presentation only. Local Places storage, geolocation, composer behavior, post snapshots, and public output remain unchanged.

### Upgrade notes

- No database migration runs in this release.
- Existing places, coordinates, posts, settings, media, themes, uploads, and other site data are preserved.

## 0.5.40 - Local Places Admin Polish Pass

### Changed

- Replaces the wide Local Places admin table with compact responsive saved-place cards.
- Adds a saved-place count, clearer place-name and location hierarchy, category badges, and easier-to-scan default display details.
- Separates the private-coordinate explanation into a subdued information notice.
- Gives Edit place and Delete distinct, better-spaced actions and improves the one-place and mobile layouts.
- Changes presentation only. Local Places storage, geolocation, composer behavior, post snapshots, and public output remain unchanged.

### Upgrade notes

- No database migration runs in this release.
- Existing places, coordinates, posts, settings, media, themes, uploads, and other site data are preserved.

## 0.5.39 - Local Places Visual Consistency Hotfix

### Fixed

- Matches the Local Places composer panel background, border, radius, fields, selected-place row, and add-place dialog to the existing Schedule panel color system in the default theme.
- Removes the unrelated brown-tinted panel treatment while keeping orange limited to active controls and actions.
- Changes presentation only. Local Places behavior, geolocation, saved places, post snapshots, and database data remain unchanged.

### Upgrade notes

- No database migration runs in this release.
- Existing places, coordinates, posts, settings, media, themes, uploads, and other site data are preserved.

## 0.5.38 - Local Places Geolocation Hotfix

### Fixed

- Allows same-origin browser geolocation instead of disabling geolocation through the global Permissions Policy.
- Moves the Admin Local Places current-location handler from blocked inline JavaScript into the CSP-approved external admin script.
- Adds clear feedback for HTTPS requirements, denied permission, unavailable location, and request timeout failures.
- Restores **Use current location** in Admin and the geolocation foundation used by **Find nearby** in both composers.

### Upgrade notes

- No database migration runs in this release.
- Existing places, coordinates, posts, post location snapshots, settings, media, themes, uploads, and other site data are preserved.

## 0.5.37 - Local Places Composer Simplification Pass

### Changed

- Reduced Local Places in both Stream Post composers to a compact saved-place picker, nearby search, and one **Add a new place** action.
- Moved category, approximate-area, city, region, country, coordinates, and default-display management out of the posting flow and kept those controls under **Admin → Local Places**.
- Replaced the expanded composer form with a small two-field dialog for place name and an optional public location label.
- Collapsed a selected place into a single compact row with Change and Remove actions.
- Uses each saved place's default public display automatically instead of asking for a display mode on every post.

### Upgrade notes

- No database migration runs in this release.
- Existing places, coordinates, categories, display settings, post location snapshots, posts, pages, media, comments, analytics, themes, API tokens, cron history, uploads, and settings are preserved.

## 0.5.36 - Local Places Check-In Pass

### Added

- Private, self-hosted Local Places directory with no paid or external places API.
- Browser-permission nearby matching against places saved on the current Bonumark Stream instance.
- Location controls in both the front-end composer and back-end Stream Post editor.
- Public display choices for place name and city, approximate area, or city only.
- Protected Local Places admin screens for adding, editing, and deleting saved places.
- Markdown export and database front-matter support for portable location snapshots.

### Privacy boundaries

- Location access begins only after the signed-in owner presses a location button.
- Raw device coordinates are used for nearby matching and are stored only when the owner intentionally saves a place.
- Public post markup never includes latitude or longitude.
- No background tracking, external places lookup, or shared-directory dependency is included.

### Upgrade notes

- Adds migration `0014_local_places.php` to create the local places table.
- Existing posts, pages, media, comments, analytics, themes, API tokens, cron history, uploads, and settings are preserved.

This file is the public release summary for Bonumark Stream. Detailed package-by-package upgrade history is kept in [`_bonumark_stream/CHANGELOG.md`](_bonumark_stream/CHANGELOG.md).

## 0.5.35 - Root RSS Discovery Hotfix

### Fixed

- Restored `/feed.xml` as the canonical RSS feed advertised in public page `<link rel="alternate">` metadata.
- Adjusted the root RSS channel link so the root feed identifies the site root instead of `/stream/`.
- Preserved `/stream/feed.xml` as a working compatibility alias for existing subscriptions and the stream archive route.

### Upgrade notes

- No database migration runs in this release.
- No posts, pages, users, comments, analytics, themes, API tokens, cron history, uploads, media files, or existing settings are rewritten.

## 0.5.34 - Privacy-Safe Media Uploads Pass

### Added

- Randomized public filenames for uploaded media so original device or local filenames are not used in public media URLs.
- Best-effort image metadata removal for supported JPG, PNG, and WebP uploads using shared-hosting PHP image handling, with optional Imagick support when available.
- Optional strict media privacy mode that rejects image uploads when metadata removal cannot be confirmed.
- Media privacy status indicators in the admin media library and media edit screen.
- Clear warnings when Bonumark Stream can randomize the filename but cannot confirm metadata removal.

### Changed

- Empty upload alt text now defaults to a generic safe label instead of deriving public alt text from the original filename.

### Upgrade notes

- Adds migration `0013_privacy_safe_media_uploads.php` to add media privacy status fields and the `media_privacy_mode` setting.
- Existing media records are preserved and marked as legacy unchecked until replaced or reuploaded.
- No posts, pages, users, comments, analytics, themes, API tokens, cron history, uploads, or existing media files are rewritten.

## 0.5.33 - Analytics Report Card Polish Pass

### Improved

- Replaced the sparse table-style presentation in the compact Privacy-First Analytics report cards with cleaner label/count summary rows.
- Humanized small aggregate labels such as device categories and browser families, so values like `desktop` and `chrome` display as `Desktop` and `Chrome`.
- Improved empty states for Stream posts, pages, referrers, devices, browsers, entry pages, and daily views so empty cards no longer show table headers.

### Upgrade notes

- No database migration runs in this release.
- No analytics aggregate rows, settings, posts, pages, timestamps, users, comments, media, themes, API tokens, cron history, uploads, or existing configuration are rewritten.
- Analytics collection, storage fields, privacy boundaries, CSV export, and theme behavior are unchanged.

## 0.5.32 - Analytics Report Warning Hotfix

### Fixed

- Corrected the Privacy-First Analytics report-table helper so key-only column definitions no longer trigger PHP 8.1+ `Undefined array key "value"` warnings on **Admin → Tools → Analytics**.
- Restored clean reporting for Views by Day, Top Entry Pages, Referrer Domains, Device Categories, Browser Families, and any future analytics table that uses a direct row key instead of a formatter callback.

### Upgrade notes

- No database migration runs in this release.
- No analytics aggregate rows, settings, posts, pages, timestamps, users, comments, media, themes, API tokens, cron history, uploads, or existing configuration are rewritten.

## 0.5.31 - Privacy-First Analytics Pass

### Added

- Optional self-hosted, cookieless Privacy-First Analytics, disabled by default on fresh installs and upgrades.
- Aggregate public page-view reporting by day, Stream post, page, entry path, referrer domain, broad device category, broad browser family, and sanitized UTM campaign fields.
- Protected **Admin → Tools → Analytics** controls for enablement, retention, aggregate CSV export, and confirmed data clearing.
- A restrained dashboard Traffic card for administrators.

### Privacy boundaries

- No analytics cookies, browser storage, visitor IDs, sessions, fingerprints, unique-visitor estimates, raw or hashed IP addresses, raw user agents, full referrers, query strings, or visitor-level event logs.
- Admin, account, login, API, feed, sitemap, search, cron, install, upgrade, private, and authenticated activity is excluded.

### Upgrade notes

- Adds one idempotent migration for aggregate analytics storage and disabled-by-default analytics settings.
- Existing sites do not collect analytics until an administrator explicitly enables the feature.
- No posts, pages, timestamps, users, comments, media, themes, API tokens, cron history, uploads, or existing configuration are rewritten.

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

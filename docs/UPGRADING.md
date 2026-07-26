# Upgrading Bonumark Stream

Bonumark Stream v0.5.42 continues the v0.4.0+ clean-break foundation.

## v0.5.42 - GitHub Release Hardening Pass

v0.5.42 makes the fresh installer set the MySQL/MariaDB session to UTC before schema migrations and initial seed writes. This aligns fresh-install timestamps with the canonical UTC behavior used by normal runtime database connections.

The release also restores the missing v0.5.35 and v0.5.37 upgrade notes and corrects the older v0.5.24 timestamp-section label. No migration runs, and existing config, database data, posts, pages, drafts, revisions, users, comments, likes, media, uploads, themes, settings, analytics, API tokens, cron history, scheduled posts, Local Places, coordinates, consent records, and post location snapshots are preserved.

## v0.5.41 - Local Places Metadata Alignment Hotfix

v0.5.41 removes the floating category badge from Local Places cards and places Category beside Default public display in the labeled metadata row. This is a presentation-only change.

No migration runs. Existing Local Places, coordinates, posts, post location snapshots, settings, media, themes, uploads, and other site data are preserved.

## v0.5.40 - Local Places Admin Polish Pass

v0.5.40 replaces the wide Local Places admin table with a compact responsive directory. It improves place hierarchy, saved-place counts, privacy guidance, display information, action spacing, and mobile behavior without changing Local Places data or functionality.

No migration runs. Existing Local Places, coordinates, posts, post location snapshots, settings, media, themes, uploads, and other site data are preserved.

## v0.5.39 - Local Places Visual Consistency Hotfix

v0.5.39 aligns the Local Places composer panel and add-place dialog with the existing Schedule panel color system in the default theme. This is a presentation-only release with no database migration or Local Places behavior changes.

No migration runs. Existing Local Places, coordinates, posts, post location snapshots, settings, media, themes, uploads, and other site data are preserved.

## v0.5.38 - Local Places Geolocation Hotfix

v0.5.38 restores browser geolocation for Local Places. It changes the Permissions Policy from blocking geolocation to allowing it for the same Bonumark Stream origin, and moves the Admin **Use current location** behavior from blocked inline JavaScript into the existing external admin script.

No migration runs. Existing Local Places, coordinates, posts, post location snapshots, settings, media, themes, uploads, and other site data are preserved.

## v0.5.37 - Local Places Composer Simplification Pass

v0.5.37 reduces Local Places in both Stream Post composers to saved-place selection, nearby lookup, and a compact add-place dialog. Full place-detail editing remains under **Admin → Local Places**, and each saved place's default public display mode is used automatically.

No migration runs. Existing places, coordinates, categories, display settings, post location snapshots, posts, pages, media, comments, analytics, themes, API tokens, cron history, uploads, and settings are preserved.

## v0.5.36 - Local Places Check-In Pass

v0.5.36 adds the private Local Places directory and location check-ins in both Stream Post composers. Migration `0014_local_places.php` creates the places table. The migration does not rewrite existing posts, pages, media, comments, analytics, themes, API tokens, cron history, uploads, or settings.

After upgrading, open **Admin → Local Places** to add or review saved places. Browser location permission is requested only after pressing a location button, and public posts never expose latitude or longitude.

## v0.5.35 - Root RSS Discovery Hotfix

v0.5.35 restores `/feed.xml` as the canonical RSS feed advertised by public pages and keeps `/stream/feed.xml` as a compatibility alias for existing subscriptions and the stream archive route.

No migration runs. Existing posts, pages, users, comments, analytics, themes, API tokens, cron history, uploads, media files, and settings are preserved.

## v0.5.34 - Privacy-Safe Media Uploads Pass

v0.5.34 adds one idempotent media migration. It adds privacy status fields to the media table and a `media_privacy_mode` setting. Existing media files are not renamed, rewritten, or deleted. New uploads use randomized public filenames. Supported image uploads are re-encoded to remove metadata when possible, and best-effort mode warns instead of rejecting when metadata removal cannot be confirmed. Strict privacy mode can be enabled from **Admin → Settings → Writing**.

## v0.5.33 - Analytics Report Card Polish Pass

v0.5.33 improves the visual presentation of **Admin → Tools → Analytics** by replacing sparse table-style report cards with compact label/count summary rows and clearer empty states. No database migration runs, no analytics rows are changed, and no posts, pages, users, media, themes, settings, API tokens, cron history, uploads, or existing configuration are rewritten. Analytics collection, privacy boundaries, CSV export, and theme behavior are unchanged.

## v0.5.32 - Analytics Report Warning Hotfix

v0.5.32 fixes a PHP 8.1+ warning on **Admin → Tools → Analytics** that occurred when report tables used key-only column definitions. The fix changes only the analytics admin-table helper so it safely reads an optional formatter before calling it. No database migration runs, no analytics rows are changed, and no posts, pages, users, media, themes, settings, API tokens, cron history, uploads, or existing configuration are rewritten.

## v0.5.31 - Privacy-First Analytics Pass

v0.5.31 adds one idempotent analytics migration. It creates optional aggregate analytics storage and disabled-by-default settings without rewriting content, timestamps, themes, users, comments, media, API tokens, cron history, uploads, or existing configuration.

## v0.5.29 - PWA Direct Favicon Fallback Fix

v0.5.29 fixes the v0.5.28 PWA icon fallback for shared-hosting PHP builds without GD or Imagick. The app continues to generate square PNG icons when an image extension is available. Without one, the manifest and Apple app metadata now point directly to the selected Site Identity favicon rather than displaying the bundled Bonumark B. The service-worker cache name changes so PWA metadata can refresh. No database migration runs and no posts, users, themes, media records, settings, uploads, or content are rewritten.


## v0.5.27 - MariaDB Upgrade Compatibility Hotfix

v0.5.27 fixes a MariaDB limitation in the timestamp-cutover upgrade safety check. MariaDB does not accept a bound placeholder in `SHOW TABLES LIKE`, so v0.5.25 could fail after copying package files and before it recorded upgrade completion.

The corrected v0.5.27 package uses quoted `SHOW` statements. Because the currently running v0.5.25 upgrader loads its own database helper before it reads an uploaded package, an affected v0.5.25 MariaDB installation needs the one-time database bridge supplied with this release before uploading v0.5.27. The bridge changes only that one compatibility helper and does not modify stored data or public behavior. Once v0.5.27 completes, the package replaces the bridge with the normal corrected application file.

## v0.5.26 - Upgrade Recovery and UTC Consistency Pass

Future upgrades performed by the v0.5.26+ upgrader are forward-only once the database migration phase begins. Before that phase, a failed upgrade restores prior software files. Once migration begins, Bonumark keeps the newer compatible files, writes a private recovery marker, records recovery-required history, and allows the exact same release package to resume safely. If a server interruption leaves a `migration_in_progress` marker instead of a normal caught failure, Bonumark blocks another upgrade rather than guessing. Review the private upgrade backup and server error log before proceeding.

This behavior cannot retroactively change an older upgrader that is already executing. When upgrading an existing v0.5.25 installation, keep a normal database backup and use the v0.5.26 package only after confirming the backup is available.

v0.5.26 also stores remembered-device and invite expiration values in canonical UTC, retains configured site-time display for public Stream posts, blocks browser execution of shipped test scripts, adds server-side PWA share-target throttling, and removes legacy GET logout behavior. It does not rewrite posts, media, users, themes, API tokens, or settings.

## v0.5.25 - Release Audit Remediation Pass

v0.5.25 repairs the legacy timestamp-cutover fallback used by direct upgrades, changes PWA Web Share Target intake to POST, scopes session cookies per installation, makes migration recovery honest for MySQL/MariaDB DDL, and removes obsolete root PWA files during future upgrades. It does not rewrite posts, media, users, themes, API tokens, or settings.


## Supported upgrade path

The built-in upgrade tool supports upgrades from v0.4.0 and newer only.

Pre-v0.4 development builds are not supported by the current upgrader. Install the current v0.5.42 package fresh instead of trying to upgrade an older development build.

## What the upgrader preserves

The upgrader preserves current v0.4.0+ user-owned data and generated files:

- `_bonumark_stream/config.php`
- `_bonumark_stream/installed.lock`
- `_bonumark_stream/data/`
- `_bonumark_stream/backups/`
- `_bonumark_stream/tmp/`
- `media/`
- `uploads/`
- installed code-free theme packages and public theme assets that are not bundled with the release

The upgrader does not preserve old file-runtime content folders. Markdown remains available for import, export, backup, and portability only. Runtime publishing is database-first.

## Scheduled Tasks and Cron Foundation

v0.5.18 adds a reusable Scheduled Tasks runner, a protected web cron endpoint, a CLI-only server cron script, task health, and manual/cron run history. The upgrade adds a small `scheduled_task_runs` table plus task settings. Existing scheduled posts, their UTC schedule times, public hiding, and current traffic/heartbeat behavior are preserved. After upgrading, open **Admin → Settings → Scheduled Tasks** to choose a runner and copy setup instructions.

## Legacy timestamp display compatibility

v0.5.24 keeps the existing-post PDO save corrections and v0.5.23 runtime timezone handling, then restores correct display for legacy published posts with a compatibility boundary recorded from upgrade history. No post rows, bodies, titles, media, date fields, scheduled posts, drafts, pages, or imports are rewritten.

## Post actions menu update

v0.5.17 keeps the front-end three-dot **Post options** menu below its trigger without clipping and aligns published-post **Edit** plus **Pin to Stream** or **Unpin from Stream** as one consistent left-aligned action list. No database migration or API change is required.

## Pinned posts migration

v0.5.13 adds `is_pinned` and `pinned_at` to the posts table through a safe migration. Existing posts remain unpinned after upgrade. The migration preserves existing post content, author, publish date, schedule state, media, revisions, comments, likes, and exports.

## Static export

Static Site Export is optional tooling. It is not the normal publishing mode.

## Scheduled posts migration

Scheduled publishing was introduced in v0.5.5. Current fresh installs include the `scheduled_at` field, and upgrades from older v0.4.0+ builds receive it through the scheduled-post migration. Existing drafts and published posts are preserved.

As of v0.5.13, safe public dynamic traffic is the primary shared-hosting trigger for due scheduled posts. Admin and front-end composer heartbeats remain as backup helpers. Exact-to-the-minute scheduling still requires server cron or an external ping service hitting a public dynamic URL every minute.

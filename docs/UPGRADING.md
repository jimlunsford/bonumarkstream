# Upgrading Bonumark Stream

Bonumark Stream v0.5.30 continues the v0.4.0+ clean-break foundation.

## v0.5.30 - GitHub Release Hardening Pass

v0.5.30 aligns version markers, public repository documentation, package structure, and installer release copy for the public GitHub release. It does not run a database migration, alter content, rewrite timestamps, change theme behavior, or modify posts, pages, users, comments, media, settings, API tokens, cron history, uploads, or configuration.

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

Pre-v0.4 development builds are not supported by the current upgrader. Install the current v0.5.30 package fresh instead of trying to upgrade an older development build.

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

## Scheduled Tasks run-history alignment

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

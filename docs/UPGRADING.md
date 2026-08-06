# Upgrading Bonumark Stream

Bonumark Stream v0.5.76 continues the v0.4.0+ clean-break foundation.


## v0.5.76 - Public Release Hardening Pass

v0.5.76 changes no database schema. It prevents the upgrader from duplicating the live `media/` directory into every private upgrade backup when the package contains `media/.gitkeep`, keeps media and uploads preserved in place, completes top-level obsolete-file cleanup coverage for `CHANGELOG.md` and `analytics.php`, and aligns public release documentation. Existing config, database records, posts, pages, drafts, revisions, comments, users, media, uploads, custom themes, settings, analytics, API tokens, scheduled tasks, cron history, Local Places, and all other owner data are preserved.

## v0.5.75 - Legacy Admin CSS Consolidation Pass 3

v0.5.75 removes 104 superseded declarations and 14 empty rule blocks from the legacy Admin stylesheet while preserving the final effective Admin cascade. No database migration runs and no stored data or workflow behavior changes.

## v0.5.74 - Legacy Admin CSS Consolidation Pass 2

v0.5.74 removes 127 superseded declarations and 12 empty rule blocks from the legacy Admin stylesheet while preserving the final Admin selector-property cascade. No database migration runs and no stored data or workflow behavior changes.

## v0.5.73 - Legacy Admin CSS Consolidation Pass 1

v0.5.73 begins controlled legacy Admin CSS removal, restores the omitted v0.5.71 release history, and aligns package metadata without changing the final Admin cascade. No database migration runs and no stored data or workflow behavior changes.

## v0.5.72 - Operations Label Pill Hotfix

v0.5.72 corrects the Administrator tools and Software replacement label fit in the operations interface. No database migration runs and no operational behavior or stored data changes.

## v0.5.71 - Tools and System Polish Hotfix

v0.5.71 contains long diagnostic values, tightens Tools card rhythm, and improves Upgrade history placement and phone scanning. No database migration runs and no import, export, upgrade, analytics, scheduled-task, API, security, permission, or stored-data behavior changes.

## v0.5.70 - Tools and System Operations Pass

v0.5.70 rebuilds Tools, Import, Export, Upgrade, System Check, Analytics, Scheduled Tasks, Remote Posting, Security, and Help around explicit operation risk and recovery boundaries. No database migration runs and existing runtime data and owner content remain preserved.

## v0.5.69 - Local Places Preview Polish Hotfix

v0.5.69 corrects the empty Local Places public-label preview and marker behavior. No database migration runs and saved places, private coordinates, post snapshots, permissions, and stored data remain unchanged.

## v0.5.68 - Local Places Workflow Pass

v0.5.68 rebuilds the Local Places Admin directory, editor, nearby checks, public-label preview, and deletion confirmation without changing the Local Places schema or stored records. No database migration runs.

## v0.5.67 - Settings URL Overflow Hotfix

v0.5.67 keeps long API routes, cron endpoints, paths, and technical values contained inside Settings panels. No database migration runs and settings, credentials, tokens, content, and stored data remain unchanged.

## v0.5.66 - Settings Workflow Pass

v0.5.66 rebuilds General, Reading, Writing, Security, Mail, Remote Posting, and Scheduled Tasks around one responsive Settings system. No database migration runs and existing settings, credentials, tokens, histories, routes, content, and stored data remain unchanged.

## v0.5.65 - Appearance Metadata Polish Hotfix

v0.5.65 corrects active-theme metadata wrapping in Theme Settings. No database migration runs and themes, settings, public rendering, permissions, content, and stored data remain unchanged.

## v0.5.64 - Appearance and Site Design Pass

v0.5.64 rebuilds Themes, Theme Settings, theme details, installation, deletion, Site Identity, and Navigation around one responsive Appearance workflow. No database migration runs and existing themes, identity values, favicons, navigation records, content, and stored data remain unchanged.

## v0.5.63 - Registration State Polish Hotfix

v0.5.63 keeps the Mail readiness warning quiet while public registration is closed and retains the warning when registration and verification require Mail. No database migration runs and registration settings, invite codes, accounts, and stored data remain unchanged.

## v0.5.62 - Registration Workflow Pass

v0.5.62 rebuilds Commenter Registration, verification, approval, invite creation, and invite history without changing the registration schema or stored accounts. No database migration runs.

## v0.5.61 - Comments and Accounts Polish Hotfix

v0.5.61 separates filter labels from counts and corrects the phone account-summary grid. No database migration runs and comments, accounts, permissions, profiles, and stored data remain unchanged.

## v0.5.60 - Comments and Accounts Correction Pass

v0.5.60 fixes the Comments undefined-variable warning, compacts desktop bulk controls, and completes the responsive Accounts workflow. No database migration runs and comments, accounts, permissions, profiles, and stored data remain unchanged.

## v0.5.59 - Comments and Moderation Pass

v0.5.59 changes only the Admin Comments presentation and moderation controls. Comments gain responsive desktop records, phone cards, live status counts, search, scoped selection, bulk moderation, clearer author and Stream Post context, deliberate empty states, and accessible per-comment action menus. Permanent deletion is limited to comments already in Trash. No database migration runs. Existing config, database data, comments, commenter accounts, posts, pages, drafts, scheduled posts, revisions, media, uploads, likes, users, API tokens, themes, analytics, cron history, Local Places, permissions, public comment rendering, and all other site data are preserved.

## v0.5.58 - Pages Workflow Polish Hotfix

v0.5.58 changes only the Admin Pages phone filter presentation and page-specific preview guidance. All four Page status filters remain visible in a two-column phone grid, and the editor note now refers to unsaved page edits. The existing viewport-safe mobile action dock behavior is retained and covered by release smoke checks. No database migration runs. Existing config, database data, page records, slugs, public URLs, navigation, dynamic rendering, posts, drafts, scheduled posts, revisions, media, uploads, comments, likes, users, API tokens, themes, analytics, cron history, Local Places, and all other site data are preserved.

## v0.5.57 - Pages Workflow Pass

v0.5.57 changes only the Admin Pages list and page editor presentation. Pages gain a responsive record list, compact phone cards, action menus, collapsible URL and settings cards, page-aware Screen Controls, and the shared mobile action dock. No database migration runs. Existing config, database data, page records, slugs, public URLs, navigation, dynamic rendering, posts, drafts, scheduled posts, revisions, media, uploads, comments, likes, users, API tokens, themes, analytics, cron history, Local Places, and all other site data are preserved.

## v0.5.56 - Media Library Polish Pass

v0.5.56 changes only the Admin Media Library presentation and details interaction. Cards gain consistent height, larger selection and details targets, a full-width View details footer, and cleaner filename handling. The details dialog gains fixed header and action regions around one internally scrolling body, while phones use a true safe-area-aware bottom sheet with background scroll locking. No database migration runs. Existing config, database data, media records, uploaded files, optimized variants, posts, pages, drafts, scheduled posts, revisions, comments, likes, users, API tokens, themes, analytics, cron history, Local Places, and all other site data are preserved.

## v0.5.55 - Media Library Pass

v0.5.55 changes only the Admin Media Library presentation and interaction structure. Media is now browsed through a compact responsive thumbnail grid, while captions, privacy notes, file details, Markdown, Edit, and Open controls appear on demand in one shared details dialog. Phone widths use a two-column grid and bottom-sheet details view. No database migration runs. Existing config, database data, media records, uploaded files, optimized variants, posts, pages, drafts, scheduled posts, revisions, comments, likes, users, API tokens, themes, analytics, cron history, Local Places, and all other site data are preserved.

## v0.5.54 - Mobile Editor Action Bar Hotfix

v0.5.54 keeps the mobile editor action dock at the true visual-viewport bottom, reserves safe scroll space, and hides the dock when it would cover editor controls, duplicate the visible Publish card, or compete with the on-screen keyboard. No database migration runs. Existing config, database data, posts, pages, drafts, scheduled posts, revisions, media, uploads, comments, likes, users, API tokens, themes, analytics, cron history, Local Places, and all other site data are preserved.

## v0.5.53 - Editor Scroll and Disclosure Correction Pass

v0.5.53 corrects the v0.5.52 editor presentation after real desktop and mobile testing. The full metadata rail returns to normal browser scrolling, only Publish remains sticky on wide layouts, phone and tablet writing surfaces start shorter, and Location now uses the same shared collapsible side-card component as the other editor controls. No database migration runs. Existing config, database data, posts, pages, drafts, scheduled posts, revisions, media, uploads, comments, likes, users, API tokens, themes, analytics, cron history, Local Places, and all other site data are preserved.

## v0.5.52 - Editor Workflow Pass

v0.5.52 changes only the Admin editor presentation and interaction structure. The writing surface now grows from content rather than the viewport or metadata rail, Publish remains immediately available, secondary controls begin collapsed, and mobile gains fixed Save and publishing controls. No database migration runs. Existing config, database data, posts, pages, drafts, scheduled posts, revisions, media, uploads, comments, likes, users, API tokens, themes, analytics, cron history, Local Places, and all other site data are preserved.

## v0.5.51 - Content List Responsive Pass

v0.5.51 replaces the Stream Posts admin table with a responsive record-list component, compact metadata, accessible per-post action menus, and purpose-built mobile cards. Search, filters, sorting, selection, and bulk actions remain available. No database migration runs. Existing config, database data, posts, pages, drafts, scheduled posts, revisions, media, uploads, comments, likes, users, API tokens, themes, analytics, cron history, Local Places, and all other site data are preserved.

## v0.5.50 - Admin Shell Foundation Pass

v0.5.50 replaces the shared Admin shell, navigation hierarchy, responsive layout, and Dashboard presentation. Mobile Admin pages now use an off-canvas drawer instead of placing the full navigation before page content. No database migration runs. Existing config, database data, posts, pages, drafts, scheduled posts, revisions, media, uploads, comments, likes, users, API tokens, themes, analytics, cron history, Local Places, and all other site data are preserved.

## v0.5.49 - Front-End Move to Trash Pass

v0.5.49 adds a recoverable Move to trash action to the authorized front-end Stream Post menu. The action uses the existing Trash records and permissions, never permanently deletes from the front end, and requires confirmation, CSRF validation, post-specific edit access, and a stale-content check. No database migration runs. Existing posts, Trash records, revisions, URLs, media, Local Places, link previews, authorship, pins, likes, comments, config, uploads, themes, settings, and all other site data are preserved.

## v0.5.48 - Front-End Quick Edit Pass

v0.5.48 adds a body-only Quick edit action to the existing front-end post options menu for signed-in users who can edit that post. The old edit-link display toggle is retired from the current Settings screen, while its saved value remains untouched for compatibility. Changed saves create revisions and update only the post body, content hash, and modification time. No database migration runs, and URLs, status, dates, metadata, media, Local Places, link previews, authorship, pins, likes, comments, config, uploads, themes, settings, and all other site data are preserved.

## v0.5.47 - Compact Composer Controls Pass

v0.5.47 changes only the front-end composer presentation and interaction layout. Save draft, Continue in full editor, and Advanced options move into a compact More options menu, while media, scheduling, location, and Post remain visible in the main toolbar. No database migration runs, and the unified save routes introduced in v0.5.46 remain unchanged.

## v0.5.46 - Unified Stream Composer Pass

v0.5.46 changes the Stream Post creation workflow without changing the database schema. New posts begin in the public stream composer, which now supports publish, schedule, save-draft, advanced metadata, and continue-in-full-editor actions. Existing drafts, scheduled posts, published posts, revisions, Pages, API publishing, media, users, settings, and uploads remain in place. The upgrader may safely replace `admin/new.php` because it is now a compatibility redirect only.

## v0.5.45 - Gallery Preview and Viewer Fix Pass

v0.5.45 fixes browser-side gallery presentation without changing stored posts or media. The front-end composer now permits its efficient local `blob:` preview URLs through the core Content Security Policy and manages those temporary URLs until the preview is cleared or rebuilt.

Published image links now use a core full-size viewer with an on-screen close control, click-outside closing, Escape-key support, focus restoration, and previous/next controls for galleries. Older custom themes inherit the viewer because its behavior and fallback styling remain in core. No database migration runs, and all existing site data and user-owned files are preserved.

## v0.5.44 - Four-Photo Gallery Pass

v0.5.44 adds ordered one-to-four-photo galleries to both Stream Post composers. No database migration runs because gallery order is stored inside the existing post front-matter JSON. The first gallery image remains `featured_media`, so existing single-image posts, older custom themes, feeds, Open Graph metadata, exports, and integrations continue to work.

Core provides the gallery markup, responsive image sources, dimensions, loading behavior, accessibility, and fallback CSS. Custom themes may style the documented gallery classes and variables but do not need an update to remain functional. Existing config, database data, posts, pages, drafts, revisions, users, comments, likes, media, uploads, themes, settings, analytics, API tokens, cron history, scheduled posts, Local Places, coordinates, consent records, and post location snapshots are preserved.

## v0.5.43 - Token-Scoped Stream Read API

v0.5.43 adds a general-purpose published-content `GET /api/v1/stream/posts` endpoint protected by the new `stream:read` token scope. Existing `POST` behavior remains unchanged. No database migration runs. Existing API tokens continue to work with their current scopes and do not receive read access unless an Administrator explicitly grants `stream:read` or creates a new read token.

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

Pre-v0.4 development builds are not supported by the current upgrader. Install the current v0.5.76 package fresh instead of trying to upgrade an older development build.

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

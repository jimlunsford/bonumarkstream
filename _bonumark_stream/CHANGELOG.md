## 0.5.30 - GitHub Release Hardening Pass

Bonumark Stream v0.5.30 prepares the current v0.5.x line for public GitHub distribution without adding product features or changing runtime behavior.

- Aligns release version markers, package metadata, public documentation, OpenAPI metadata, API examples, and installer copy.
- Makes the installer render its version dynamically from the packaged version marker so future releases cannot leave the welcome screen stale.
- Adds a public root changelog, expanded contribution guidance, and explicit GitHub private vulnerability-reporting guidance.
- Uses a clean single-root release ZIP for predictable GitHub downloads and cPanel extraction.
- Does not modify the database schema, migrations, posts, pages, dates, times, users, comments, media, themes, settings, uploads, backups, cron history, API tokens, or existing installations.

## 0.5.29 - PWA Direct Favicon Fallback Fix

Bonumark Stream v0.5.29 fixes the v0.5.28 PWA icon fallback on shared-hosting PHP builds without GD or Imagick.

- Keeps generated square PNG PWA icons when GD or Imagick is available.
- Uses the selected Site Identity favicon directly, with its native MIME type and dimensions, when the host cannot generate a resized PNG.
- Ensures the PWA manifest and Apple app-icon metadata use the selected favicon instead of falling back to the bundled Bonumark B solely because an image extension is unavailable.
- Keeps the bundled Bonumark B only when no valid Site Identity favicon is selected or its public file cannot be read.
- Rotates the service-worker cache name so installed apps can refresh their PWA metadata after upgrading.
- Does not change posts, users, media records, themes, settings, database schema, or content.

## 0.5.28 - PWA Site Identity Icon Pass

Bonumark Stream v0.5.28 makes installed PWA icons follow the favicon selected in Site Identity.

- Uses the existing Site Identity favicon as the source for versioned 192 × 192 and 512 × 512 PNG PWA icon responses.
- Adds the managed `pwa-icon.php` endpoint, which center-crops the selected image into a square icon and falls back to the bundled Bonumark B when no usable favicon or image processor is available.
- Updates the dynamic web app manifest and Apple mobile app metadata to use the selected favicon-derived icon instead of hardcoded bundled icons.
- Removes hardcoded app icons from the service-worker precache so a changed favicon gets a new versioned icon URL instead of a stale cached install icon.
- Keeps existing Site Identity settings, uploads, media records, themes, posts, users, and PWA settings unchanged. No database migration is required.

## 0.5.27 - MariaDB Upgrade Compatibility Hotfix

Bonumark Stream v0.5.27 fixes a confirmed MariaDB upgrade failure without adding product features.

- Replaces the parameterized `SHOW TABLES LIKE :table_name` migration safety check with MariaDB-compatible quoted SQL. MariaDB rejects placeholders in this `SHOW` statement, which caused the v0.5.25 upgrader to fail after safely copying files and before recording upgrade completion.
- Makes the optional database smoke test use the same MariaDB-compatible quoted `SHOW TABLES`, `SHOW COLUMNS`, and `SHOW INDEX` checks.
- Adds package smoke-test coverage so a parameterized `SHOW` statement cannot quietly return in a future release.
- Does not change posts, dates, times, settings, users, themes, media, uploads, API tokens, cron history, or public behavior.

## 0.5.26 - Upgrade Recovery and UTC Consistency Pass

Bonumark Stream v0.5.26 resolves confirmed release-audit findings without adding product features.

- Makes future admin ZIP upgrades forward-only once the database migration phase begins. Failures before that phase restore the previous software files. Failures after it retain the newer compatible files, write a private recovery marker, record recovery-required history, and allow the exact package to resume safely.
- Stores remember-device expiry and rotation timestamps in canonical UTC. Admin-entered invite expirations are interpreted in the configured site timezone, converted to UTC for storage, validated as UTC, and rendered back in the site timezone.
- Aligns nearby database-bound account verification, comment approval, initial admin verification, and mail-test timestamps with UTC storage.
- Makes package smoke and migration-recovery scripts CLI-only in addition to the existing Apache/LiteSpeed directory denial.
- Adds a server-side, locked, salted-IP-hash throttle for PWA Web Share Target submissions. The payload remains session-bound and never publishes automatically.
- Removes legacy GET logout behavior. Sign-out now remains a CSRF-protected POST action only.
- Keeps public Stream post dates and times displayed in the configured site timezone. Visitor-local conversion is intentionally not added.

## 0.5.25 - Release Audit Remediation Pass

Bonumark Stream v0.5.25 resolves confirmed release-audit findings without adding product features.

- Repairs the legacy `1970-01-01 00:00:00` published-timestamp cutover fallback through a safe upgrade preflight plus corrective migration. Valid cutovers remain untouched, direct legacy upgrades receive the actual upgrade boundary, and fresh UTC-era installs use their install boundary.
- Changes the PWA Web Share Target from GET to POST so shared text and URLs do not enter browser-visible URLs, access logs, or redirect query strings. Shared content remains session-bound and lands only in the front-end composer after login and publish-capability checks.
- Treats MySQL/MariaDB DDL migrations as resumable rather than transactional. Failed DDL migrations are not marked complete and retry through existing idempotent safeguards.
- Scopes a Bonumark session cookie name and path to each installation, including subdirectory installs.
- Adds same-origin protection for anonymous browser like requests while retaining public likes and rate limiting.
- Classifies root `manifest.php` and `sw.js` as managed upgrade files so future removed PWA root files are cleaned safely.
- Replaces raw admin exception messages with sanitized server-side logging and generic UI notices.
- Updates stale release wording and release validation coverage.

## 0.5.24 - Legacy Published Timestamp Compatibility Hotfix

Bonumark Stream v0.5.24 corrects the v0.5.23 regression that shifted pre-existing published Stream posts by the site timezone offset.

- Records the exact v0.5.23 upgrade time from existing upgrade history, without modifying posts.
- Treats published timestamps before that boundary as legacy site-local values, preserving the display they had before today’s timezone work.
- Keeps published timestamps created after the v0.5.23 boundary as canonical UTC values, so new posts remain correct.
- Corrects public Stream cards, single posts, admin content date labels, and chronological Stream sorting through one shared compatibility rule.
- Does not rewrite post records, titles, bodies, media, date fields, scheduled posts, drafts, pages, or imports.

## 0.5.23 - Timezone Runtime and UTC Canonicalization Hotfix

Bonumark Stream v0.5.23 fixes Stream post timestamps displaying four hours ahead when the saved **General Settings** timezone differed from the original `config.php` timezone.

- Makes the persisted site timezone the active PHP runtime timezone after installation, instead of leaving `config.php` as the long-term display authority.
- Locks every PDO MySQL/MariaDB connection to UTC so `NOW()` writes remain canonical regardless of the database server timezone.
- Renders public Stream post dates, account activity dates, dashboard timestamps, and ISO timestamps explicitly in the saved site timezone, without depending on PHP's incidental default timezone.
- Uses UTC when creating direct published-at database values, keeping new posts aligned with scheduled-post and cron behavior.
- Existing post data corrects on display immediately. No migration, content rewrite, or manual timestamp repair is required.

## 0.5.22 - Post Update PDO Binding Compatibility Hotfix

Bonumark Stream v0.5.22 fixes the remaining **Save failed. SQLSTATE[HY093]: Invalid parameter number** error when updating an existing Stream post on MySQL/MariaDB hosting environments that still reject the long named-parameter update statement.

- Replaces the complete existing-post database update bind set with ordered positional PDO placeholders.
- Covers existing draft, published, scheduled, renamed, and pinned Stream posts through the shared database-first save path.
- Leaves the post fields, pin state rules, schedule handling, author preservation, revisions, themes, API behavior, PWA behavior, cron, and public output unchanged.
- Requires no database migration, content rewrite, or configuration change.

## 0.5.21 - Post Update Save Hotfix

Bonumark Stream v0.5.21 fixes the **Save failed. SQLSTATE[HY093]: Invalid parameter number** error when updating an existing post on MySQL/MariaDB installations using native PDO prepared statements.

- Replaces repeated named placeholders in the existing-post update query with distinct bound parameter names.
- Restores normal saves for existing draft, published, scheduled, and pinned stream posts.
- Fixes the same native-prepared-statement compatibility issue in comment-status updates.
- Requires no database migration, content rewrite, or configuration change.
- Leaves cron behavior, scheduled-task history, post timestamps, theme structure, API behavior, PWA behavior, and public output unchanged.

## 0.5.20 - Scheduled Tasks UTC Timestamp Hotfix

Bonumark Stream v0.5.20 fixes the **Admin → Settings → Scheduled Tasks → Run history** time display for server-cron, web-cron, and manual runs.

- Parses stored scheduled-task history timestamps explicitly as UTC before converting them to the configured site timezone.
- Corrects incorrect local times caused by PHP interpreting UTC database values in the server or application default timezone.
- Applies to existing history rows immediately, with no database migration or data rewrite required.
- Leaves cron execution, CLI behavior, web cron keys, task history storage, fallback checks, scheduled-post timing, API behavior, and theme structure unchanged.

## 0.5.19 - Scheduled Tasks Run History Alignment Hotfix

Bonumark Stream v0.5.19 fixes the **Admin → Settings → Scheduled Tasks → Run history** table so each header lines up with the correct value.

- Replaces the reused six-column Stream Posts table pattern with a dedicated five-column task-history table.
- Defines stable column widths for When, Source, Result, Published, and Details.
- Keeps manual, server-cron, and web-cron history data unchanged.
- Improves mobile task-history readability by labeling each stacked value.
- Leaves the reusable task runner, cron paths, keys, fallbacks, scheduled-post timing, permissions, API behavior, and theme structure unchanged.

## 0.5.18 - Scheduled Tasks and Cron Foundation Pass

Bonumark Stream v0.5.18 turns the existing scheduled-post checks into a reusable Scheduled Tasks foundation without changing theme structure or scheduled-post permissions.

- Adds one shared due-task runner for public traffic, browser heartbeat, admin, manual, server cron, and protected web cron execution.
- Preserves the existing scheduled-post publisher, lock, public hiding rules, front-end composer heartbeat, admin heartbeat, and manual behavior.
- Adds a CLI-only server cron script at `scripts/run-scheduled-tasks.php`.
- Adds an optional protected web cron endpoint at `/api/v1/cron`, authenticated with a generated key stored only as a hash.
- Adds Admin → Settings → Scheduled Tasks with fallback controls, server and web cron setup details, runner health, manual execution, and retained manual/cron run history.
- Adds a migration for task-run history and scheduled-task settings.
- Keeps public traffic and active-browser checks as configurable fallbacks instead of treating them as real cron.
- Establishes the reusable task foundation for future features that need dependable scheduled execution.

## 0.5.17 - Post Options Menu Alignment Hotfix

Bonumark Stream v0.5.17 fixes the visual alignment inside the front-end three-dot **Post options** menu.

- Makes button-based actions such as **Pin to Stream** or **Unpin from Stream** use the same left-aligned layout as the **Edit** link.
- Overrides the bundled theme's broad public button centering only inside the post-options menu.
- Keeps the compact menu, its current below-trigger placement, pinning behavior, permissions, feeds, search, exports, API, PWA, and mobile behavior unchanged.
- Updates the bundled Midnight Ledger reference theme CSS to 1.2.6, fallback styling, PWA cache version, package metadata, release manifest, and current-version documentation.

## 0.5.16 - Post Options Menu Visibility Hotfix

Bonumark Stream v0.5.16 fixes the front-end Post options menu so it remains visible and usable when it opens below its three-dot trigger.

- Stops the bundled Midnight Ledger stream card from clipping the open menu.
- Keeps the menu layered above the following stream card while it is open.
- Keeps the compact three-dot control, Edit action, Pin to Stream or Unpin from Stream action, permissions, and mobile behavior unchanged.
- Updates the bundled Midnight Ledger reference theme CSS to 1.2.5, fallback styling, PWA cache version, package metadata, release manifest, and current-version documentation.

## 0.5.15 - Post Options Menu Position Hotfix

Bonumark Stream v0.5.15 fixes the front-end Post options menu placement without changing its actions, authorization, or pinning behavior.

- Positions the three-dot post menu below its trigger instead of opening upward across the current post card.
- Keeps the compact menu, front-end Edit action, secure Pin to Stream or Unpin from Stream action, reader controls, and mobile behavior unchanged.
- Updates the bundled Midnight Ledger reference theme CSS to 1.2.4, fallback styling, PWA cache version, package metadata, release manifest, and current-version documentation.

## 0.5.14 - Post Actions Menu Pass

Bonumark Stream v0.5.14 makes the front-end post action row quieter without changing post permissions or pin behavior.

- Replaces the visible front-end **Edit** and **Pin to Stream** or **Unpin from Stream** pills with one compact three-dot **Post options** menu.
- Keeps reader-facing likes and comments visible in the normal action row.
- Uses semantic `<details>` markup so the menu works without JavaScript, while preventing card click-through navigation when the menu is used.
- Keeps authorization, CSRF-protected pinning, post state rules, RSS, sitemap, search, static export, Remote Posting API, PWA, and share-to-post behavior unchanged.
- Adds core fallback styling and Midnight Ledger styling for the post options menu, including mobile use.
- Updates README, install, architecture, theming, upgrade, API, package metadata, service-worker cache version, and release manifest for v0.5.14.

## 0.5.13 - Pinned Posts Pass

Bonumark Stream v0.5.13 adds core-owned pinned stream posts without changing normal publishing behavior.

- Adds `is_pinned` and `pinned_at` post metadata through a safe database migration.
- Adds secure Pin to Stream and Unpin from Stream actions in the front-end post controls, back-end editor, and Admin → Stream Posts list.
- Supports multiple pinned posts, ordered by most recently pinned first.
- Adds a quiet Pinned area above the homepage timeline and removes those same records from the page-one timeline so posts are not duplicated.
- Prevents drafts, scheduled posts, pages, unpublished content, and trash from being pinned publicly. Moving a pinned post out of published stream status clears pin state.
- Keeps RSS/feed order, sitemap behavior, search, normal archive behavior, static export output, Remote Posting API behavior, PWA install behavior, and share-to-post flow unchanged.
- Adds core fallback styling and Midnight Ledger presentation styling without moving pin logic into themes.
- Updates README, architecture, theming, install, upgrade, package metadata, service-worker cache version, and release manifest for v0.5.13.

## 0.5.12 - Scheduled Admin Sort Source Fix Pass

Bonumark Stream v0.5.12 fixes the admin stream-post list sort source for scheduled posts.

- Uses UTC-aware scheduled and published timestamps when sorting stream posts in admin and public helpers.
- Makes scheduled posts sort by the same effective time shown in the admin Date column.
- Prevents newly scheduled posts from drifting lower in All Stream Posts behind older published posts.
- Keeps the clean scheduled date display added in v0.5.11.
- Leaves scheduling logic, public runner behavior, timestamp publishing behavior, PWA/share flow, front-end composer behavior, and back-end composer behavior unchanged.
- Updates package metadata, service worker cache version, docs, and release manifest for v0.5.12.

## 0.5.11 - Scheduled Admin List Date Polish Pass

Bonumark Stream v0.5.11 fixes scheduled-post ordering and date display in the admin stream-post list.

- Sorts scheduled posts by their scheduled publish time in the All Stream Posts list.
- Keeps scheduled posts in the same date order as published posts instead of drifting lower based on their original creation time.
- Shows the scheduled post date column as a clean site-local date and time without appending the timezone name.
- Keeps the scheduled/published timestamp behavior introduced in v0.5.8.
- Leaves scheduling logic, public runner behavior, PWA/share flow, front-end composer behavior, and back-end composer behavior unchanged.
- Updates package metadata, service worker cache version, docs, and release manifest for v0.5.11.

## 0.5.10 - Backend Composer Publish Box Polish Pass

Bonumark Stream v0.5.10 polishes the back-end composer Publish box without changing scheduled-post logic.

- Hides the scheduled publish time field by default on new draft posts.
- Keeps Save Draft and Post Now as the primary visible back-end editor actions.
- Adds quiet Schedule for later and Reschedule disclosures that reveal the schedule field only when needed.
- Shows already scheduled posts with clear scheduled status, scheduled time, Reschedule, Post Now, and Cancel Schedule actions.
- Leaves scheduling logic, due runner behavior, timestamp handling, PWA/share flow, and the front-end composer unchanged.
- Updates package metadata, service worker cache version, docs, and release manifest for v0.5.10.

## 0.5.9 - Public Traffic Scheduled Runner Pass

Bonumark Stream v0.5.9 makes public traffic the primary shared-hosting trigger for scheduled posts.

- Added a public-request scheduled-post runner helper that only runs on safe GET/HEAD requests.
- Runs due scheduled-post checks before public stream, feed, sitemap, search, profile, account, page, comments, and robots handlers load public output.
- Keeps the existing throttle and lock so normal traffic does not run heavy scheduled-post work on every request.
- Keeps the authenticated admin and front-end composer heartbeats as backup helpers instead of the primary trigger.
- Keeps scheduled posts hidden from public queries until they are published.
- Keeps the v0.5.8 scheduled/published timestamp behavior intact.
- Documents that exact-to-the-minute scheduled publishing requires server cron or an external ping hitting a public URL.

## 0.5.8 - Scheduled Publish Time Fix Pass

Bonumark Stream v0.5.8 tightens scheduled publishing behavior after the front-end scheduling fixes.

- Reduced the conservative scheduled-post traffic runner throttle from five minutes to thirty seconds.
- Added an authenticated scheduled-post runner endpoint for active admin/front-end composer sessions.
- Added lightweight admin and front-end composer heartbeats so due posts are checked while the site owner is actively using Bonumark Stream.
- When a scheduled post becomes public, Bonumark now uses the scheduled/published timestamp for public display, feeds, single-post metadata, and exported Markdown front matter instead of the original creation time.
- Converted scheduled publish dates through the site timezone for date storage.
- Kept scheduled-post storage, permissions, public hiding, PWA/share-to-post, and existing API behavior intact.

## 0.5.7 - Front-End Scheduler Submit Fix Pass

Bonumark Stream v0.5.7 fixes the front-end composer scheduling submit path introduced during the v0.5.6 UI polish.

- Added hidden front-end composer fields for submit intent and active schedule state.
- Updated the composer JavaScript so activating scheduling updates those hidden fields immediately and again at submit time.
- Removed reliance on mutating the visible submit button value for scheduling intent.
- Hardened the quick-post endpoint so it treats either explicit schedule action or active schedule state as a scheduled post request.
- Kept the compact v0.5.6 composer UI, backend scheduling controls, scheduled-post storage, PWA/share-to-post flow, and public hiding behavior intact.
- Updated package metadata, service worker cache version, docs, and release manifest for v0.5.7.

## 0.5.6 - Front-End Composer Scheduling UI Polish Pass

Bonumark Stream v0.5.6 polishes the front-end composer scheduling UI without changing scheduled-post core behavior.

- Reworked the front-end composer into a cleaner posting-box toolbar flow.
- Replaced the large Attach media pill and full-width Schedule instead block with compact composer action buttons.
- Added an inline schedule panel that only appears when scheduling is active.
- Changed the main composer submit button from Post to Schedule when the scheduler is active.
- Kept the back-end composer/editor scheduling controls unchanged.
- Preserved scheduled-post storage, public hiding, due-post publishing, Remote Posting API behavior, and PWA/share-to-post routing.
- Updated package metadata, service worker cache version, docs, and release manifest for v0.5.6.

## 0.5.5 - Scheduled Posts Pass

Bonumark Stream v0.5.5 adds scheduled stream posts while keeping the front-end composer as the primary posting flow.

- Added a scheduled stream-post status and `scheduled_at` database field with migration support for fresh and upgraded installs.
- Added scheduling controls to the front-end composer and back-end editor.
- Added scheduled-post editing, rescheduling, cancel-to-draft, trash/restore, quick edit, admin list filtering, and publish-now support.
- Added a conservative traffic-triggered due-post runner with throttling and a lock, plus a manual Tools action to run due scheduled posts.
- Kept scheduled posts out of public timeline, single routes, feeds, sitemap, search, and static export until published.
- Added site-timezone display for schedule fields while storing canonical scheduled times in UTC.
- Added optional `scheduled_at` support for trusted Remote Posting API clients without changing existing draft/publish behavior.
- Updated README, docs, package metadata, service worker cache version, and release manifest for v0.5.5.

## 0.5.4 - Stream Settings Label Cleanup Hotfix

Bonumark Stream v0.5.4 cleans up stale Reading Settings wording on the admin Stream settings screen.

- Changed the admin settings page title from Reading Settings to Stream Settings.
- Changed the visible page heading from Reading to Stream.
- Changed save/error flash copy and the submit button to use Stream settings wording.
- Updated the intro copy so the screen reflects what it now controls: stream display, composer behavior, sitemap, PWA/mobile share, and app login persistence.
- Added a smoke check to prevent the stale Reading Settings labels from returning.
- Updated package metadata, version references, service worker cache version, and release manifest for v0.5.4.

## 0.5.3 - Remember Me App Login Pass

Bonumark Stream v0.5.3 adds secure app-friendly login persistence so installed/mobile use does not constantly force the owner back through login.

- Added a Remember this device checkbox to the admin login form and public account login form.
- Added persistent login tokens stored in a new remember_tokens database table.
- Uses selector plus validator cookies with hashed validators in the database, HttpOnly cookies, SameSite=Lax, secure cookies on HTTPS, and token rotation when a remembered login is restored.
- Added Stream settings to enable or disable remember-me login and set the remembered-device window from 1 to 90 days, defaulting to 30 days.
- Revokes remembered device tokens on logout, current-user password change, password reset, and admin password reset.
- Kept normal sessions unchanged for users who do not check Remember this device.
- Updated README, install docs, package metadata, migrations, smoke checks, and release manifest for v0.5.3.

## 0.5.2 - Frontend Share Composer Routing Hotfix

Bonumark Stream v0.5.2 corrects the mobile share-to-post flow so shared text and URLs land in the primary front-end composer instead of the backend draft editor.

- Changed the secure share-target route into an intake handoff that stores the shared payload briefly, requires login, requires publishing permission, and redirects back to the public stream.
- Prefills the front-end composer with shared title, text, and URL content so the user can review, edit, and press Post.
- Removed the backend shared draft review form from the share-target path.
- Preserved admin-only publishing controls, because shared content still never auto-publishes and the composer only renders for authenticated users who can publish.
- Kept image/file share-target intake deferred.
- Updated README, install docs, package metadata, smoke checks, service worker versioning, and release manifest for v0.5.2.

## 0.5.1 - PWA and Mobile Share-to-Post Flow Pass

Bonumark Stream v0.5.1 adds the first clean installable-app and mobile share-to-draft layer while preserving admin-only publishing and the existing theme/API structure.

- Added a dynamic web app manifest with app name, short name, description, start URL, scope, display mode, colors, icons, and Web Share Target metadata.
- Added bundled PNG app icons generated from Bonumark Stream identity, with no external or copyrighted assets.
- Added a conservative versioned service worker that caches only safe static assets and avoids admin pages, account pages, draft pages, API responses, private files, and user-specific content.
- Added PWA registration and recovery behavior through a shared core PWA script.
- Added an authenticated admin share-target route for shared text, titles, and URLs.
- Preserved shared text/URL payloads through login when practical, then routed them to a draft review screen.
- Kept shared content draft-only until the Admin reviews and saves it, with normal publishing still handled by the existing admin editor.
- Added Stream settings for enabling installable app metadata/service worker support and the mobile share target.
- Deferred Web Share Target image/file intake to avoid browser-specific upload handoff risk in the first PWA pass.
- Updated README, package metadata, config defaults, installer seed settings, migrations, smoke checks, and release manifest for v0.5.1.

## 0.5.0 - GitHub Release Preparation Pass

Bonumark Stream v0.5.0 prepares the next public GitHub release after v0.4.5 by promoting the local/test work through v0.4.26 into a public release package.

- Bumped package, application, documentation, OpenAPI, and release manifest version references to v0.5.0.
- Polished the public README so it clearly explains what Bonumark Stream is, who it is for, the Admin/Commenter account model, the code-free theme system, install requirements, upgrade expectations, security expectations, and the Remote Posting API.
- Updated public repository-facing files, including `SECURITY.md` and `CONTRIBUTING.md`, so they no longer describe old v0.4.8 development state.
- Added a consolidated release summary for the major work completed since v0.4.5, including Remote Posting API expansion, scoped tokens, API audit/rate limiting, remote media upload/import, ChatGPT Actions support, client documentation, draft preview cleanup, admin polish, and footer cleanup.
- Confirmed public examples stay placeholder-safe with `example.com`, placeholder tokens, and no private site assumptions.
- Kept old changelog history intact.
- Kept features, API behavior, public behavior, theme structure, and database schema unchanged.

## 0.4.26 - General Audit Cleanup Pass

Bonumark Stream v0.4.26 performs a small post-audit cleanup without adding features or changing public/API behavior.

- Removed duplicate wording from the Remote API media upload documentation.
- Added smoke-test coverage to catch duplicate uploaded-media second-request wording in `docs/API.md`.
- Clarified in `scripts/smoke-test.php` that database smoke tests require real `BMS_DB_*` environment variables and `BMS_DB_DANGER_RESET=1` so the package smoke test never touches a live database accidentally.
- Added an explicit smoke-test comment around wrapped media helper fallbacks to avoid app helper redeclaration during isolated Markdown rendering checks.

## 0.4.25 - Remote API Final Validation Pass

Bonumark Stream v0.4.25 locks down Remote Posting API validation coverage before moving on to the next feature area.

- Strengthened package smoke coverage for Remote API route files, shared API handlers, `.htaccess` clean URL routing, Authorization passthrough, and `index.php` API dispatch.
- Strengthened OpenAPI, API docs, Remote Posting docs, client docs, scope, idempotency, and media upload/import documentation checks.
- Added optional `scripts/api-database-smoke-test.php` coverage for disabled API behavior, missing tokens, invalid tokens, draft creation, publish scope enforcement, publish confirmation enforcement, media upload scope enforcement, idempotency replay, and idempotency conflict behavior against a temporary real database install.
- Kept API endpoints, API behavior, admin behavior, public rendering, theme structure, and database schema unchanged.

## 0.4.24 - Remote Posting Client Docs Expansion Pass

Bonumark Stream v0.4.24 expands Remote Posting API documentation for common external clients without changing API behavior.

- Added `docs/REMOTE-POSTING-CLIENTS.md` with setup examples for PowerShell, curl, Python, GitHub Actions, Apple Shortcuts, Zapier Webhooks, Make HTTP module, IFTTT Webhooks, and generic no-code automation tools.
- Added client examples for status checks, draft creation, direct publishing, existing media embedding, media URL import, and local image upload where supported by the client.
- Updated README documentation references so users can find Remote Posting setup, endpoint details, ChatGPT Actions setup, and client examples from the package overview.
- Updated `docs/API.md`, `docs/REMOTE-POSTING.md`, and `docs/CHATGPT-ACTIONS.md` to cross-link the new client examples.
- Kept API endpoints, authentication behavior, token scopes, media behavior, admin settings, public rendering, theme structure, and database behavior unchanged.
- Added smoke coverage requiring the Remote Posting client examples document and its major client sections.

## 0.4.23 - Footer Slash Separator Removal Hotfix

Bonumark Stream v0.4.23 removes the automatic public footer slash separator that still appeared when custom footer text and the Bonumark credit were both shown.

- Removed the default `/` footer separator from shared core footer render data.
- Updated the default footer template so separators are rendered only when an explicit non-empty separator is supplied.
- Preserved custom footer text and the Bonumark credit output without inserting an unwanted slash between them.
- Kept theme structure, admin settings behavior, public rendering outside the footer, API behavior, media behavior, and database behavior unchanged.
- Added smoke coverage proving the shared public footer does not auto-render a slash separator.

## 0.4.22 - Footer Custom Text Separator Hotfix

Bonumark Stream v0.4.22 fixes shared public footer rendering so custom footer text does not create stray separator characters.

- Added core footer item render data so footer output is built from actual non-empty footer items.
- Updated the default footer template to render separators only between real footer items.
- Preserved custom footer text output, optional Bonumark credit output, and the intentional separator when both are shown.
- Kept the fix in shared core footer rendering so Midnight Ledger and code-free custom themes inherit it.
- Added smoke coverage for footer item rendering and separator placement.

## 0.4.21 - Admin Content List Width Utilization Pass

Bonumark Stream v0.4.21 improves admin list layout so content tables use the available panel width instead of collapsing tightly around their cells.

- Made stream post, page, comment, user, token, and compact admin list tables use full available width.
- Added a dedicated stream post table layout so the Post column expands while checkbox, Status, Media, Storage, and Date columns stay stable.
- Improved metadata column spacing and kept row actions stable from v0.4.20.
- Added responsive horizontal scrolling for admin table wrappers without changing mobile stacked table behavior.
- Added CSS smoke coverage for full-width admin list tables and stable stream post metadata columns.

## 0.4.20 - Admin Row Action Hover Stability Hotfix

Bonumark Stream v0.4.20 normalizes admin list row-action styling so links and form buttons behave consistently without hover layout shifts.

- Normalized row actions for stream post lists across draft, published, and trash rows.
- Stabilized Edit, Quick Edit, Preview, Revisions, View, Publish, Move to Drafts, Trash, Restore, and Delete Permanently actions.
- Prevented hover states from changing row height, width, padding, borders, line height, or font weight.
- Kept state-changing actions visually distinct and destructive actions visibly destructive without affecting layout.
- Added similar stability rules for page table row actions that share the same pattern.
- Added smoke coverage for admin row-action styling and content-list action classes.

## 0.4.19 - Draft Preview Public Menu Removal Hotfix

Bonumark Stream v0.4.19 removes public navigation controls from draft preview headers so previews no longer feel like live public pages.

- Removed the public Menu button from draft preview headers.
- Kept one clear Preview indicator only.
- Prevented public navigation HTML from being passed into draft preview header render data.
- Kept the top admin preview bar unchanged.
- Kept the bottom Back to editor button unchanged.
- Kept published public post headers unchanged.
- Applied the fix through core render data so Midnight Ledger and code-free custom themes inherit it.
- Kept comments, likes, API behavior, media behavior, and theme structure unchanged.
- Added smoke coverage proving draft preview does not render the public menu control.

## 0.4.18 - Draft Preview Header Controls Hotfix

Bonumark Stream v0.4.18 fixes draft preview header controls so preview state is clear and not duplicated.

- Hid the public post-count pill in draft preview mode.
- Kept one clear Preview indicator in the public header during draft previews.
- Added explicit preview header state and count-chip render data for core templates and code-free themes.
- Kept the public menu available while preventing draft preview routes from being treated as live/current navigation.
- Left the top admin preview bar and bottom Back to editor action unchanged.
- Kept public published header behavior, comments, likes, API behavior, media behavior, and theme structure unchanged.
- Added smoke coverage for draft preview header controls.

## 0.4.17 - Draft Preview Interaction State Hotfix

Bonumark Stream v0.4.17 makes admin draft previews behave like previews instead of live public posts while preserving the public published post experience.

- Added a core public preview-mode flag available to renderers and theme views.
- Added preview body/header state so themes can detect preview rendering.
- Made likes inactive on draft previews.
- Made comment links and comment loading preview-safe on draft previews.
- Replaced draft-preview single-post back behavior with a preview-safe Back to editor target.
- Prevented draft-preview card clicks from navigating to a live public route.
- Made the header count/status pills show preview state instead of a misleading live post count.
- Kept the menu usable in preview mode without marking the previewed draft as the active public route.
- Added smoke coverage for preview-mode controls.

## 0.4.16 - Remote Imported Media Rendering Hotfix

Bonumark Stream v0.4.16 fixes a rendering issue found during remote URL media import testing.

- Fixed Markdown image rendering so generated responsive image `srcset` and `sizes` metadata stays inside the image tag instead of appearing as visible post text.
- Protected generated image HTML during inline Markdown formatting so underscores in `_generated` paths are not treated as emphasis markers.
- Kept captions visible as normal post content after embedded images.
- Preserved responsive image variant output for performance.
- Added smoke coverage for Markdown image rendering with generated responsive variants.
- Kept remote upload/import API behavior, media validation, token logic, and GPT Action schema structure unchanged.

## 0.4.15 - GPT Media Guardrails and URL Import Pass

Bonumark Stream v0.4.15 improves GPT Actions media behavior by rejecting fake placeholder uploads and adding a safer URL-based image import workflow.

- Added `POST /api/v1/media/import` for importing public HTTP/HTTPS image URLs into the Media library.
- Added clean URL routing and a direct PHP endpoint for remote media imports.
- Reused existing safe remote media download checks, public IP validation, redirect limits, cURL enforcement, image validation, and media upload rules.
- Added API guardrails that reject the known 1x1 placeholder PNG commonly generated by GPT Action tests instead of real image data.
- Added inline stream post support for `media_import`, `media_imports`, `image_url`, `media_import_url`, and `remote_image_url` so clients can import and embed media in one remote post request.
- Added media import audit log entries.
- Fixed the existing remote media import temporary-directory helper typo so URL imports can create temporary files correctly.
- Updated OpenAPI, API docs, ChatGPT Actions docs, Remote Posting docs, README, package metadata, smoke tests, and release manifest.

## 0.4.14 - Remote Media Embed Persistence Hotfix

Bonumark Stream v0.4.14 fixes a remote media embedding bug found during live GPT Actions testing.

- Fixed `POST /api/v1/stream/posts` so `media_id`, `media_ids`, `media_url`, `media_urls`, `public_path`, `public_paths`, `media_items`, `media_upload`, and `media_uploads` are resolved before the post body is saved.
- Fixed remote post responses so `embedded_media` reflects the media actually inserted into the saved post.
- Fixed media-only remote posts so embedded image Markdown can create the post body when no text content is supplied.
- Kept `POST /api/v1/media`, token auth, publish controls, idempotency, and upload validation behavior unchanged.
- Added smoke-test coverage to catch server code that documents media embedding but fails to call the embed/persist helpers.

## 0.4.13 - OpenAPI GPT Actions Schema Hotfix

Bonumark Stream v0.4.13 tightens the OpenAPI schema after live GPT Actions setup showed importer compatibility warnings.

- Shortened the `createStreamPost` operation description to stay under GPT Actions importer limits.
- Removed the `HEAD` operation from `/api/v1/status` in the OpenAPI schema because the GPT Actions importer only needs the documented `GET` status check.
- Kept runtime API behavior unchanged. The status endpoint can still handle normal status checks, and all remote posting/media behavior from v0.4.12 remains intact.
- Added smoke-test checks so operation descriptions stay short and unsupported `HEAD` operations do not reappear in the Action schema.

## 0.4.12 - Remote Media Embed Workflow Pass

Bonumark Stream v0.4.12 extends remote stream post creation so trusted clients can create posts with embedded image media in a cleaner workflow.

- Added embedded media support to `POST /api/v1/stream/posts`.
- Added support for referencing existing library images by `media_id`, `media_ids`, `media_url`, `media_urls`, `public_path`, or `media_items`.
- Added support for one-step post creation with `media_upload` or `media_uploads` JSON payloads when the token also has `media:upload` and remote media uploads are enabled.
- Added `embedded_media` and `media_position` fields to the remote post response.
- Allowed media-only remote posts when embedded media is supplied.
- Updated OpenAPI, API docs, Remote Posting docs, ChatGPT Actions docs, README, package metadata, and smoke tests.

# Changelog

## 0.4.11 - Remote Media Upload API Pass

- Added optional remote image uploads through `POST /api/v1/media`.
- Added the `media:upload` token scope and admin setting for remote media uploads.
- Reused the existing media upload validation, size limits, storage, metadata, and image derivative behavior.
- Added API audit log entries for remote media upload successes and validation failures.
- Returned media ID, URL, filename, alt text, caption, dimensions, and Markdown embed text.
- Updated OpenAPI, API docs, ChatGPT Actions docs, remote posting docs, smoke tests, and package metadata.

## 0.4.10 - Remote Posting Admin Scope Polish Hotfix

Bonumark Stream v0.4.10 polishes the Remote Posting admin screen after live API testing confirmed the endpoint chain works.

- Restyled the API token scope cards so they use the dark admin surface and border variables instead of light fallback colors.
- Changed the scope selector to a balanced two-column layout on desktop and a single-column layout on mobile.
- Improved spacing, checkbox alignment, hover states, disabled/reserved scope styling, and text contrast.
- Kept API behavior, token scopes, publishing controls, idempotency behavior, routing, migrations, and database logic unchanged.
- Updated package metadata, docs, version markers, smoke tests, and release manifest.

## 0.4.9 - API Upgrade Route Hotfix

Bonumark Stream v0.4.9 fixes Remote Posting API routing for sites upgraded from older v0.4 packages whose installed upgrader did not yet know to copy newly introduced top-level directories.

- Routes `/api/v1/status` and `/api/v1/stream/posts` through `index.php` so clean API URLs work even when the physical `/api/` folder was not copied by an older upgrader.
- Keeps the physical `/api/` endpoint files for fresh installs and direct-file compatibility.
- Moves shared API endpoint execution into `_bonumark_stream/app/api.php` to avoid duplicated endpoint logic.
- Future-proofs the admin upgrader by deriving software copy roots from the release manifest instead of relying only on a hardcoded top-level directory list.
- Updates package metadata, docs, smoke tests, and release manifest.

## 0.4.8 - Remote Publish Controls Pass

Bonumark Stream v0.4.8 adds optional direct publishing controls to the Remote Posting API while keeping draft-first behavior as the default.

- Enabled the `stream:publish` API token scope.
- Added direct remote publishing as an Admin-controlled setting, disabled by default.
- Added a default remote post status setting.
- Added publish confirmation behavior with `confirm_publish: true` or `confirmation: "publish"`.
- Added idempotency key support using the `Idempotency-Key` header or request payload keys.
- Added an API idempotency database table and migration.
- Updated `POST /api/v1/stream/posts` to create drafts or published posts based on settings, scopes, and confirmation.
- Returned public URLs for remotely published posts.
- Added a full OpenAPI schema at `docs/openapi/bonumark-stream-api.json`.
- Added ChatGPT Actions setup documentation at `docs/CHATGPT-ACTIONS.md`.
- Updated Remote Posting admin controls, API docs, package metadata, smoke tests, and release manifest.

## 0.4.7 - Remote Draft API Pass

Bonumark Stream v0.4.7 adds the first real remote posting endpoint while keeping remote creation draft-only.

- Added `POST /api/v1/stream/posts` for draft-only remote stream post creation.
- Enabled the `stream:draft` API token scope.
- Added JSON request parsing and remote draft creation helpers.
- Added authenticated draft creation with bearer token scope checks and rate limiting.
- Added API audit logging for remote draft creation and validation failures.
- Returned the new draft ID, slug, title, filename, and admin edit URL after successful creation.
- Added shared-hosting rewrite support for `/api/v1/stream/posts`.
- Added `docs/API.md` with status and remote draft endpoint documentation.
- Updated Remote Posting docs, package metadata, smoke tests, and release manifest.


## 0.4.6 - Remote Posting Foundation Pass

Bonumark Stream v0.4.6 adds the disabled-by-default foundation for remote posting integrations without enabling remote post creation yet.

- Added Remote Posting settings in the admin area.
- Added scoped API token creation and revocation with hashed token storage.
- Added API token, API audit log, and API rate-limit database tables.
- Added reusable API authentication, JSON response, audit logging, and rate-limiting helpers.
- Added `GET /api/v1/status` for API health and authenticated token checks.
- Added shared-hosting routing support for `/api/v1/status`.
- Updated exports, docs, package metadata, smoke tests, and release manifest.

## 0.4.5 - Public Release Legacy Cleanup Pass

Bonumark Stream v0.4.5 cleans the public release package after the Admin and Commenter account reset.

- Aligned upgrade support around v0.4.0 and newer only.
- Removed legacy theme-layout compatibility keys from the code-free theme manifest system.
- Removed old file-runtime content-folder preservation from the upgrader.
- Cleaned active docs so older development history stays in `docs/HISTORY.md`.
- Removed old media-limit wording from admin upload/help text.
- Tightened routing comments and package permissions for public release readiness.
- Updated package metadata, version markers, smoke tests, and release manifest.

## 0.4.4 - Admin and Commenter Account Reset Pass

Bonumark Stream v0.4.4 removes obsolete multi-publisher behavior and resets the account model around one Admin plus Commenter accounts.

- Collapsed public/account roles to Admin and Commenter.
- Made Admin the sole publisher and admin-area user.
- Kept Commenter accounts for comments, profiles, password recovery, verification, approval, and account participation.
- Removed obsolete multi-publisher workflow surfaces, publishing-user settings, and old media-limit rules.
- Reworked registration so public registration creates Commenter accounts only when enabled.
- Updated admin account screens, writing settings, registration settings, route handling, baseline schema, package metadata, and release manifest.

## 0.4.3 - Theme System Clarity Pass

Bonumark Stream v0.4.3 clarifies the code-free presentation theme model without adding features or redesigning the theme system.

- Removed leftover theme-layout wording from the admin theme screens.
- Updated theme manager, theme details, theme settings, and theme install copy to describe themes as presentation packages.
- Removed obsolete layout-control fields from Midnight Ledger's example `theme.json`.
- Kept the current code-free theme model: core renders the public site, themes supply metadata, settings, CSS, images, fonts, screenshots, and docs.
- Updated package metadata, focused theme wording, and release manifest.

## 0.4.2 - Midnight Ledger Reference Theme Pass

Bonumark Stream v0.4.2 makes Midnight Ledger the working example for code-free Bonumark Stream themes.

- Made the bundled theme folder self-contained with `theme.json`, README files, screenshot, and `assets/css/theme.css`.
- Mirrored Midnight Ledger assets to the public theme asset directory used by core rendering.
- Updated `theme.json` so asset paths match the copyable theme folder structure.
- Added clear `--bms-*` design tokens at the top of the CSS while keeping Midnight Ledger aliases contained.
- Cleaned development-era comments from the reference theme CSS.
- Kept themes code-free, with no PHP, JavaScript, HTML files, or executable code in the theme package.
- Updated package metadata, theme docs, and release manifest.

## 0.4.1 - Code-Free Theme Installer Pass

Bonumark Stream v0.4.1 restores installable themes without allowing theme packages to run code.

- Re-enabled theme ZIP installation for code-free presentation packages.
- Theme packages may include `theme.json`, documentation, CSS, images, fonts, screenshots, supports, and editable settings.
- Theme packages may not include PHP, JavaScript, HTML files, route handlers, server config files, symlinks, or executable code.
- Core-owned public views remain responsible for rendering and behavior.
- Active theme metadata, settings, and assets now apply while core renders the view layer.
- Theme validation now rejects executable files in private theme metadata and public theme assets.
- Updated admin theme screens, theme install flow, theme docs, package metadata, and release manifest.

## 0.4.0 - Foundation Reset Pass

Bonumark Stream v0.4.0 is the clean public foundation reset.

- Fresh-install only baseline.
- Standardized the internal function prefix on `bms_`.
- Collapsed old development migrations into one clean v0.4.0 baseline schema.
- Confirmed the database as the source of truth.
- Kept Markdown for import, export, backup, and portability only.
- Reset the upgrader to support v0.4.0 and newer only.
- Kept Midnight Ledger as the only bundled public release theme.
- Moved Midnight Ledger rendering code into core-owned views so theme packages remain code-free.
- Disabled third-party theme ZIP installation until the declarative code-free theme format is finalized.
- Kept normal operation dynamic and database-first.
- Kept Static Site Export as optional portability tooling.
- Kept accounts, profiles, comments, public likes, and all importers.
- Kept `/stream/` as an alias while `/` remains the real stream home.
- Removed development-only route cleanup rules.
- Removed sample public content from the install baseline.
- Updated public documentation for the v0.4.0 foundation.

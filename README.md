# Bonumark Stream

**Bonumark Stream is a self-hosted microblog CMS for publishing short-form posts on a site you control.**

It is built for people who want the speed and simplicity of a personal stream without handing their words, media, and history to a platform they do not own.

- Homepage: https://bonumark.org
- Demo: https://demo.bonumark.org
- Repository: https://github.com/jimlunsford/bonumarkstream
- Current version: **0.5.76**

## v0.5.76 Public Release Hardening Pass

Bonumark Stream v0.5.76 hardens the package for the next public GitHub release. The upgrader no longer treats the packaged `media/.gitkeep` as a reason to duplicate the entire live media library inside every private upgrade backup. Media and uploads remain preserved in place while package-managed software is backed up and replaced normally.

The pass also completes top-level obsolete-file cleanup coverage for `CHANGELOG.md` and `analytics.php`, updates stale installation, upgrade, API, Remote Posting, and OpenAPI version references, restores upgrade notes for v0.5.60 through v0.5.75, and adds regression checks for those boundaries. No database migration runs, and config, database records, content, users, comments, media, uploads, custom themes, settings, analytics, API tokens, scheduled tasks, cron history, Local Places, and other owner data remain unchanged.

## v0.5.75 Legacy Admin CSS Consolidation Pass 3

Bonumark Stream v0.5.75 continues the controlled cleanup of the legacy Admin stylesheet. This pass removes 104 declarations across 36 admin-only rule groups where the later Admin shell, editor workflow, operations, or places component stylesheet already owns the effective selector and property.

The cleanup removes 14 rule blocks that became empty and reduces `assets/admin.css` from 7,258 lines to 7,117 lines. All 8,719 final effective Admin selector-property winners remain unchanged. Generic rules used by the standalone installer, login screen, or public preview were deliberately retained, including panels, form controls, shared buttons, field help, the Admin footer, and the public Local Places dialog. No database migration runs, and routes, publishing, pages, comments, accounts, registration, themes, settings, Local Places data, tools, upgrades, permissions, and stored data remain unchanged.

## v0.5.74 Legacy Admin CSS Consolidation Pass 2

Bonumark Stream v0.5.74 continues the controlled cleanup of the legacy Admin stylesheet. This pass removes 127 declarations only where the newer Admin shell, editor workflow, or operations component styles use the same selector and responsive context and already own the same property.

The cleanup removes 12 rule blocks that became empty and reduces `assets/admin.css` from 7,429 lines to 7,258 lines. The final Admin selector-property cascade remains identical across 9,090 results before and after the change. Login, installation, and public preview styling remain intact because every removed declaration is scoped to `body.bonumark-admin`, which is emitted through the shared Admin layout that loads the replacement component styles. No database migration runs, and routes, publishing, pages, comments, accounts, registration, themes, settings, Local Places, tools, upgrades, permissions, and stored data remain unchanged.

## v0.5.73 Legacy Admin CSS Consolidation Pass 1

Bonumark Stream v0.5.73 begins the controlled cleanup of the legacy Admin stylesheet. This pass removes 38 rule blocks and 76 declarations only where the newer component styles use the same selector and responsive context and fully replace every removed property.

The result reduces `assets/admin.css` from 7,601 lines to 7,429 lines without changing the final Admin cascade. The pass also restores the missing v0.5.71 release history and aligns current package metadata. No database migration runs, and routes, publishing, pages, comments, accounts, registration, themes, settings, Local Places, tools, upgrades, permissions, login, previews, and stored data remain unchanged.

## v0.5.72 Operations Label Pill Hotfix

Bonumark Stream v0.5.72 corrects the final visual fit issue left in the operations redesign. The Administrator tools and Software replacement labels now stay centered and fully contained inside their pills on desktop, while smaller screens still allow safe wrapping when needed.

The correction is isolated to operational Admin markup, the shared `admin-operations.css` component layer, release metadata, and regression coverage. No database migration runs, and import, export, upgrade, recovery, diagnostics, analytics, scheduled tasks, API access, security, Help, permissions, and stored data remain unchanged.

## v0.5.71 Tools and System Polish Hotfix

Bonumark Stream v0.5.71 polished the redesigned Tools, System Check, and Upgrade workflows after desktop and phone acceptance testing. Long diagnostics remain contained, operational cards use tighter rhythm, Upgrade history sits directly beneath package staging on desktop, and phone history records scan faster through compact version transitions.

## v0.5.70 Tools and System Operations Pass

Bonumark Stream v0.5.70 rebuilds the operational side of the Admin interface so imports, private exports, upgrades, diagnostics, automation, analytics, API access, and Help no longer look like ordinary settings forms. Each workflow now identifies whether an action is preview-only, sensitive, diagnostic, high-risk, or irreversible before the user acts.

## v0.5.69 Local Places Preview Polish Hotfix

Bonumark Stream v0.5.69 completes the Local Places acceptance polish by making the empty public-label preview read like guidance instead of a finished location. The location marker stays hidden until the chosen display mode produces a real label, then appears as a consistent location-pin icon beside the live preview.

The correction is isolated to the Local Places editor markup, interaction script, and component stylesheet. No database migration runs, and saved places, private coordinates, display modes, privacy behavior, Stream Post location snapshots, public output, permissions, and stored data remain unchanged.

## v0.5.68 Local Places Workflow Pass

Bonumark Stream v0.5.68 continues the Admin redesign across the complete Local Places workflow. Saved places now use a searchable responsive directory, category filtering, nearby saved-place discovery, structured desktop records, and deliberate phone cards instead of remaining a visually separate utility.

Add Place and Edit Place now organize public labels, private coordinates, current-location capture, nearby duplicate checks, live public display previews, save actions, and deletion as one coherent workflow. A dedicated typed-name delete screen protects saved place records while existing Stream Posts keep their stored public location snapshots. The new work is isolated in `admin-places.css` and `admin-places.js`; no database migration runs and no place data, coordinates, permissions, post snapshots, or public routes are changed.

## v0.5.67 Settings URL Overflow Hotfix

Bonumark Stream v0.5.67 completes the Settings acceptance hotfix by keeping long technical values inside their panels. API routes, sitemap and robots URLs, installed-app paths, web-cron endpoints, installation paths, and responsive Settings records now shrink and wrap without clipping or hiding their full values.

The correction is isolated to the shared `admin-settings.css` component layer and semantic technical-value markup. No database migration runs, and Settings values, API behavior, tokens, scheduled tasks, sitemap output, permissions, content, and stored data remain unchanged.

## v0.5.66 Settings Workflow Pass

Bonumark Stream v0.5.66 continues the Admin redesign across the full Settings workflow. General, Reading, Writing, Security, Mail, Remote Posting, and Scheduled Tasks now share one hierarchy of current-state summaries, grouped controls, clear save actions, responsive records, and purpose-built phone layouts.

Security is now a real settings hub instead of a redirect. Mail history, API tokens, API audit activity, and scheduled-task runs no longer rely on legacy tables, and expired API credentials are no longer counted as active in the Admin summary. A dedicated `admin-settings.css` component layer provides the new workflow without extending the legacy 7,601-line Admin stylesheet. No database migration runs, and all existing settings, credentials, tokens, histories, permissions, routes, content, and stored data remain unchanged.

## v0.5.65 Appearance Metadata Polish Hotfix

Bonumark Stream v0.5.65 completes the Appearance acceptance polish by correcting the desktop active-theme metadata card in Theme Settings. The Slug now receives a full row, while Version, Author, and Fields remain readable in a stable row instead of wrapping one character per line.

The correction applies above the phone breakpoint, preserving the accepted phone layout. No database migration runs, and theme settings, activation, Site Identity, Navigation, public rendering, permissions, content, and stored data remain unchanged.

## v0.5.64 Appearance and Site Design Pass

Bonumark Stream v0.5.64 continues the Admin redesign across the full Appearance workflow. Themes now acts as the Site Design hub, active theme settings are separated from theme activation, and theme details, installation, and deletion share one package-focused interface.

Site Identity now separates public framing from favicon and installed-app identity, while Navigation is organized around menu state, automatic account links, ordered menu records, published pages, and custom links. A dedicated `admin-appearance.css` component layer provides desktop and phone behavior without extending the legacy Admin stylesheet. No database migration runs, and themes, identity values, favicons, navigation records, public rendering, permissions, content, and stored data remain unchanged.

## v0.5.63 Registration State Polish Hotfix

Bonumark Stream v0.5.63 completes the Registration acceptance polish by keeping the Mail readiness warning quiet while public registration is disabled. A site can prepare email verification rules in advance without the closed Registration screen looking like it has an active failure.

When registration is Open or Invite only, the strong Mail warning still appears whenever email verification is required and Mail is unavailable. Registration summaries now make clear that configured verification and approval rules apply when registration is enabled. No database migration runs, and registration settings, invite codes, accounts, Mail configuration, permissions, public account routes, and stored content remain unchanged.

## v0.5.62 Registration Workflow Pass

Bonumark Stream v0.5.62 continues the Admin redesign by rebuilding Commenter Registration as one coherent account-access workflow. Registration mode, verification, approval, pending-account attention, public account behavior, invite creation, and invite history now share one clear hierarchy across desktop and phones.

Invite codes now use responsive records instead of the legacy table, while registration rules use focused option cards and a current-state summary. No database migration runs, and existing accounts, invite hashes, registration settings, public account routes, permissions, and stored content remain unchanged.

## v0.5.61 Comments and Accounts Polish Hotfix

Bonumark Stream v0.5.61 completes the Comments and Accounts acceptance polish after desktop and phone testing. Status-filter labels and counts are now separated with one shared compact count treatment, so values such as Approved 1 and Active 1 read clearly instead of running together.

On phones, Total accounts now spans the full second summary row instead of leaving an empty grid cell beside it. No database migration runs, and moderation behavior, account records, permissions, public profiles, and public comment behavior remain unchanged.

## v0.5.60 Comments and Accounts Correction Pass

Bonumark Stream v0.5.60 corrects the desktop acceptance failures found in v0.5.59. Comment moderation no longer triggers an undefined-variable warning, and its bulk controls now stay together in one compact desktop row with the result summary instead of creating a tall empty control area.

Accounts now uses responsive records and one Actions menu per account instead of a crowded nine-column table, separate Quick Update controls, and a full creation form below the list. Add Commenter is now a dedicated screen with empty account-specific fields and autofill-resistant attributes. No database migration runs, and account records, comment records, permissions, public profiles, and public comment behavior remain unchanged.

## v0.5.59 Comments and Moderation Pass

Bonumark Stream v0.5.59 continues the Admin redesign by rebuilding Comments as a responsive moderation workflow instead of a dense legacy table. Approved, Pending, and Trash now show live counts, and moderators can search comment text, authors, usernames, Stream Post titles, or slugs without losing the current status view.

Desktop uses structured moderation records, while phones use purpose-built cards with the full comment, commenter identity, related Stream Post, date, status, and one Actions menu. Scoped bulk actions handle approval, holding, Trash, restoration, and permanent deletion. Permanent deletion is now enforced at the data layer for Trash comments only. No database migration runs, and public comment behavior, commenter accounts, posts, permissions, and stored comment records remain unchanged.

## v0.5.58 Pages Workflow Polish Hotfix

Bonumark Stream v0.5.58 completes the first Pages workflow acceptance correction after real desktop and phone testing. Phone status filters now use a two-column grid, keeping All, Drafts, Published, and Trash visible without clipping or hidden horizontal scrolling.

The Page editor preview note now uses page-specific language. The shared mobile action dock remains anchored to the visual viewport, reserves safe scroll space, and continues to hide around the Publish card, editor toolbars, and on-screen keyboard. No database migration runs, and page records, URLs, rendering, navigation, permissions, and stored data are unchanged.

## v0.5.57 Pages Workflow Pass

Bonumark Stream v0.5.57 continues the Admin redesign by rebuilding Pages as one coherent responsive workflow. The Pages list now uses the same modern record system proven on Stream Posts, with concise desktop rows, useful mobile cards, compact page metadata, and one Actions menu instead of a legacy table.

The page editor now uses page-aware Screen Controls, collapsible Page URL and Page Settings cards, the shared sticky publishing hierarchy, and the viewport-safe mobile Save, Publish Page, View Page, and Options dock. Page records, slugs, public URLs, navigation, dynamic rendering, permissions, and database structure are unchanged.

## v0.5.56 Media Library Polish Pass

Bonumark Stream v0.5.56 polishes the browsing-first Media Library after real desktop and phone testing. Media cards now use consistent heights, larger selection targets, two-line filename clamping, and a full-width View details footer instead of a small action floating beside the privacy badge.

The shared details experience now uses a fixed header, one internally scrolling content region, and a fixed action footer. Desktop receives a cleaner preview and metadata layout, while phones use a true bottom sheet that locks the page behind it, respects safe-area space, and keeps Copy Markdown, Edit media, and Open file reachable. The floating file-type badge exposed beside real images is corrected. No database migration runs.

## v0.5.55 Media Library Pass

Bonumark Stream v0.5.55 continues the Admin redesign by rebuilding Media as a browsing surface first. The library now uses a compact responsive thumbnail grid, keeps filenames and essential status visible, and moves captions, privacy notes, file details, Markdown, Edit, and Open controls into one shared details dialog.

Phones use a true two-column thumbnail grid and a bottom-sheet details view instead of one full-width administrative card per file. Bulk selection, search, Library and Trash views, editing, privacy status, optimized media, Markdown generation, restore, trash, and permanent deletion keep their existing routes and behavior. No database migration runs.

## v0.5.54 Mobile Editor Action Bar Hotfix

Bonumark Stream v0.5.54 corrects the phone editor dock exposed during real-device testing. The Save, Post Now or View, and Options controls are anchored to the true visual-viewport bottom with safe-area spacing, while the editor reserves enough scroll room to move controls and cards fully above the dock.

The dock now gets out of the way when it would cover the editor command bar or formatting toolbar, when the full Publish card is already visible, or when the on-screen keyboard is open. Publishing routes, permissions, form actions, stored posts, revisions, media, Local Places, and database data are unchanged.

## v0.5.53 Editor Scroll and Disclosure Correction Pass

Bonumark Stream v0.5.53 returned the editor metadata rail to normal browser scrolling, kept only the Publish card sticky on wide desktop layouts, reduced the mobile writing-surface baseline, and moved Location onto the same collapsible card component as Post URL, Stream Post, Media, and Revisions.

## v0.5.52 Editor Workflow Pass

Bonumark Stream v0.5.52 continues the Admin redesign by rebuilding the full Stream Post editor around the work being done. Short posts now use a compact content-driven writing surface instead of being stretched to the viewport or the full height of the metadata rail.

Publish remains visible in a sticky desktop rail, secondary URL, search, location, media, and revision controls begin collapsed, and uncommon or destructive actions move behind deliberate disclosure. Mobile adds a fixed Save, Post Now or View, and Options bar so publishing controls stay reachable without a full-page scroll. No database migration runs, and post storage, permissions, routes, revisions, media, themes, settings, users, and stored data remain unchanged.

## v0.5.51 Content List Responsive Pass

Bonumark Stream v0.5.51 continues the Admin redesign by replacing the Stream Posts table with a compact responsive record list. Desktop keeps only the information needed for scanning and decision-making, while mobile uses purpose-built post cards instead of stacked table cells.

Post actions now live in an accessible Actions menu, status filters scroll cleanly on narrow screens, and search, sorting, selection, and bulk actions share one responsive control system. No database migration runs, and publishing routes, permissions, content, media, settings, themes, users, and stored data remain unchanged.

## v0.5.50 Admin Shell Foundation Pass

Bonumark Stream v0.5.50 begins a system-level Admin redesign. The shared shell now uses task-oriented navigation, active states, a cleaner desktop header, consistent page headings and actions, larger touch targets, and a dedicated mobile drawer instead of stacking the full sidebar above every page.

The Dashboard removes repeated shortcuts and duplicate notes while preserving its publishing counts, attention items, recent content, analytics, and system status information. This is a presentation and navigation foundation pass. It does not change the database, publishing routes, permissions, stored settings, content, media, themes, or user data.

## v0.5.49 Front-End Move to Trash Pass

Bonumark Stream v0.5.49 adds a recoverable **Move to trash** action to the existing three-dot menu on published Stream Posts. Authorized users can remove a post from the public stream without entering Admin, while permanent deletion remains limited to the Admin Trash workflow.

The action requires confirmation, login, post-specific edit permission, CSRF validation, and a stale-content check. With JavaScript available, the post disappears from the stream immediately. Without JavaScript, the same action safely returns to the stream after moving the post. The post remains restorable from Admin → Stream Posts → Trash.

## What Bonumark Stream is

Bonumark Stream is a lightweight PHP/MySQL publishing system for short-form posts, pages, media, comments, profiles, likes, feeds, imports, exports, and code-free presentation themes.

It is not a social network. It is not a multi-author publishing platform. It is not a WordPress theme.

Bonumark Stream is for running your own microblog on your own site.

## Who it is for

Bonumark Stream is for people who want:

- A self-hosted place for short posts, notes, updates, links, photos, and public thoughts
- Ownership of their posts, media, comments, and publishing history
- A smaller publishing system that can run on normal shared hosting
- A site where the owner remains the only publisher
- Optional commenter participation without turning the site into a multiuser publishing platform
- A code-free theme system where themes control presentation, not application behavior

## Current foundation

Bonumark Stream v0.5.76 is a public development release built on the clean-break v0.4.0+ foundation. This release preserves legacy post timestamp interpretation from before the v0.5.23 timezone pass, while retaining canonical UTC handling for new timestamps. It does not rewrite existing content or post records.

The current model is:

- One Admin account
- Optional Commenter accounts
- Admin is the sole publisher
- Commenters can participate through comments and profile/account features when enabled
- Database-first normal operation
- Markdown for import, export, backup, and portability
- Dynamic rendering by default
- Static export as optional tooling
- Code-free presentation themes
- Midnight Ledger as the bundled reference theme

## Major features

Bonumark Stream currently includes:

- Stream posts
- Private Local Places directory and optional location check-ins with no external places API
- Drafts, scheduled posts, published posts, pinned posts, trash, revisions, and previews
- Optional cookieless, self-hosted Privacy-First Analytics with aggregate reporting, CSV export, retention controls, and no visitor identity layer
- Basic pages
- Media library, validated uploads, and responsive one-to-four-photo post galleries
- Public comments and comment moderation
- Public likes with rate limiting
- Admin dashboard and publishing tools
- Admin-only imports and exports
- RSS/feed support
- Sitemap and robots.txt handling
- Public profiles and optional commenter accounts
- Password reset and verification flows
- Code-free theme installation and management
- Dynamic database-first rendering
- Optional static export
- Remote API with a token-scoped read-only Stream catalog and remote posting for trusted external clients
- Basic PWA install metadata and conservative service worker support
- Mobile share-target flow for loading shared text and URLs into the front-end composer
- New-post scheduling from the stream composer, with rescheduling and schedule management in the full editor
- Shared Scheduled Tasks runner with server cron, protected web cron, task health, and execution history


## Install as app and mobile share

Bonumark Stream includes a clean PWA layer and routes mobile shares into the front-end composer.

When enabled in **Admin → Settings → Stream**, supported browsers can install the site as a basic app on mobile or desktop. Bonumark Stream adds a web app manifest, mobile app metadata, app icons, and a conservative service worker. When a Site Identity favicon is selected, Bonumark generates versioned 192 × 192 and 512 × 512 PNG install icons when the server supports GD or Imagick. On servers without either extension, it uses the selected favicon directly, with its real image type and dimensions, rather than reverting to the Bonumark B. Use a square 512 × 512 PNG for the strongest install-icon result. The bundled B remains the fallback only when no valid Site Identity favicon exists.

The service worker caches only safe static assets such as core CSS and JavaScript. Site Identity PWA icon URLs are versioned so a changed favicon can replace the installed app icon without stale service-worker icon entries. The service worker does not cache admin pages, draft pages, account pages, CSRF forms, API responses, private files, user-specific content, or the selected favicon media path.

Bonumark Stream also exposes a Web Share Target for supported mobile browsers. Shared text, titles, and URLs enter through the secure share-target intake route, then the user is sent back to the public stream with the front-end composer prefilled.

The user still has to review the content and choose **Post**, **Schedule**, **Save draft**, or **Continue in full editor**. Shared content never publishes automatically.

Image/file sharing through the Web Share Target is intentionally deferred. Browser support and upload handoff behavior vary, and this release keeps the first mobile share layer focused on safe text and URL composer handoff.

Browser support varies. Some browsers support installable apps but not Web Share Target. Some desktop browsers may ignore share-target metadata entirely.

## Remote Posting API

Bonumark Stream includes an optional Remote Posting API for trusted external tools.

The API includes:

- Disabled-by-default API setting
- Admin-created scoped API tokens
- Hashed token storage
- Token revocation
- API audit logging
- API rate limiting
- `GET /api/v1/status` status endpoint
- `POST /api/v1/stream/posts` stream post endpoint
- Draft creation by default
- Optional direct publishing
- Optional scheduled publishing through `scheduled_at` for trusted API clients
- `stream:publish` token scope
- Default remote status setting
- Publish confirmation behavior
- Idempotency keys to prevent duplicate posts
- Edit URL returned after remote creation
- Public URL returned after direct publish
- OpenAPI schema and ChatGPT Actions documentation
- Remote Posting client examples for PowerShell, curl, Python, GitHub Actions, Apple Shortcuts, Zapier, Make, IFTTT, and generic no-code tools
- Optional remote image uploads through `POST /api/v1/media`
- `media:upload` token scope
- Remote media audit logging
- Returned media URL and Markdown image embed
- Safe remote image import through `POST /api/v1/media/import`
- Guardrails that reject known fake placeholder media uploads
- Stream post requests can embed existing media by media ID or media URL
- Stream post requests can upload image media and embed it inline or store one to four images as a structured gallery
- Remote post responses include embedded media details
- Media embedding persistence so media IDs and media URLs are written into the saved post body
- Imported media rendering protection so responsive image metadata does not appear as post text
- GPT Actions-compatible OpenAPI schema cleanup

Remote posting is disabled by default. Site owners must create scoped tokens and enable the API from the admin area before external clients can post.

## Documentation

Package documentation is included under `docs/`:

- `docs/INSTALL.md` for installation
- `docs/UPGRADING.md` for supported upgrades
- `docs/API.md` for Remote Posting API endpoint details
- `docs/REMOTE-POSTING.md` for Remote Posting API setup and security notes
- `docs/REMOTE-POSTING-CLIENTS.md` for PowerShell, curl, Python, GitHub Actions, Apple Shortcuts, Zapier, Make, IFTTT, and generic no-code client examples
- `docs/CHATGPT-ACTIONS.md` for ChatGPT Actions setup
- `docs/IMPORTERS.md` for importer behavior
- `docs/THEMING.md` for code-free theme development
- `docs/ARCHITECTURE.md` for system architecture notes
- `docs/SCHEDULED-TASKS.md` for server cron, web cron, task health, and fallback setup
- `docs/ANALYTICS.md` for Privacy-First Analytics behavior, data boundaries, retention, export, and deletion
- `docs/MEDIA-PRIVACY.md` for randomized filenames, image metadata cleanup, media privacy modes, and shared-hosting behavior
- `CHANGELOG.md` for the public release summary and `_bonumark_stream/CHANGELOG.md` for detailed package history
- `SECURITY.md` for vulnerability reporting and security boundaries
- `CONTRIBUTING.md` for contribution rules and verification expectations

## Important upgrade notice

Bonumark Stream v0.5.76 continues the v0.4.0+ clean-break upgrade line.

The built-in upgrader supports Bonumark Stream v0.4.0 and newer.

Direct upgrades from older development packages, including v0.1.x, v0.2.x, and v0.3.x, are not supported.

If you are using an older development package, install Bonumark Stream v0.5.76 as a fresh installation.

## Requirements

Bonumark Stream is designed for standard shared hosting.

Minimum requirements:

- PHP 8.1 or newer
- MySQL or MariaDB
- PDO MySQL extension
- ZIP extension for package/theme handling
- Apache or LiteSpeed recommended for included `.htaccess` rules

Recommended:

- PHP 8.2 or newer
- HTTPS enabled
- Regular database and file backups
- A hosting account that allows writable application directories

## Installation

1. Download the latest release ZIP from GitHub.
2. Upload the package files to your web server.
3. Visit `install.php` in your browser.
4. Enter your database details.
5. Create the first Admin account.
6. Complete installation.
7. Remove or lock the installer when prompted.

After installation, the stream is available at the site root.

Example:

```text
https://example.com/
```

The `/stream/` path remains supported as an alias.

Example:

```text
https://example.com/stream/
```

## Fresh install behavior

A new Bonumark Stream install starts clean.

By default:

- No sample posts are created
- No sample pages are created
- No public demo content is installed
- One Admin account is created during installation
- Registration is disabled or controlled by settings
- Commenter accounts are optional
- Midnight Ledger is the active bundled theme

## Admin account

The Admin account is the site owner and sole publisher.

The Admin can:

- Publish posts
- Create pages
- Upload and manage media
- Manage comments
- Manage commenter accounts
- Configure site settings
- Manage themes
- Run imports and exports
- Run supported upgrades

Bonumark Stream does not include editor or author roles.

## Commenter accounts

Commenter accounts are for participation, not publishing.

Commenters may be able to:

- Register, if registration is enabled
- Log in
- Manage basic profile/account details
- Comment, if comments are enabled
- Use password reset and verification flows

Commenters cannot:

- Publish posts
- Create pages
- Upload media
- Access publishing tools
- Access site settings
- Manage themes
- Run imports or upgrades

## Publishing

Bonumark Stream is designed for short-form publishing.

Posts are stored in the database and rendered dynamically. Markdown is available for import, export, backup, and portability, but Markdown files are not the runtime source of truth.

The Admin creates Stream Posts from the front-end composer and manages saved content in the admin area. Trusted Remote Posting API clients can also publish when enabled.

## Scheduled posts

The Admin can schedule new Stream Posts from the front-end composer and reschedule saved posts from the full editor. The default behavior remains normal posting. Scheduling only happens when the user chooses the schedule action and provides a future time. In the back-end editor, scheduling is intentionally quiet: Save Draft and Post Now remain the main visible actions, and the schedule date/time field appears only after choosing Schedule for later or Reschedule.

User-facing schedule fields and normal Stream post dates use the saved site timezone setting. Bonumark keeps canonical database timestamps in UTC, then displays them back in the site timezone. The General Settings timezone is applied to the PHP runtime after installation, so it remains authoritative even when `config.php` contains an older install-time timezone. If no site timezone is configured, Bonumark falls back safely to UTC.

Scheduled posts stay out of the public timeline, single post routes, RSS/feed output, search, sitemap, author/profile output, and static export until they are published. Guessing the URL of a scheduled post does not expose it early because public routes only receive published records.

Bonumark Stream runs scheduled work through one reusable **Scheduled Tasks** runner. Server cron is the recommended option because it runs independently of site traffic. Shared-hosting and external services can use protected web cron. Safe public traffic and signed-in browser heartbeats remain configurable fallback checks, and an admin can run tasks manually from **Admin → Settings → Scheduled Tasks**.

The Scheduled Tasks screen shows task health, the last run, execution source, server cron instructions, web cron setup, and retained manual/cron run history. It also controls whether public traffic and signed-in browser heartbeats remain active as fallback paths. See `docs/SCHEDULED-TASKS.md` for setup details.

Scheduled posts can be edited, rescheduled, canceled back to draft, moved to trash, restored, or published immediately by authorized users.

## Pages

Bonumark Stream includes basic page support for static content such as:

- About
- Contact
- Uses
- Now
- Project notes

Pages are managed by the Admin.

## Local Places

Local Places is a self-contained location feature stored entirely on the Bonumark Stream instance.

- Location access starts only after the signed-in owner presses **Find nearby** or **Use current location**.
- The browser supplies coordinates with permission. Bonumark Stream does not perform background location tracking.
- Nearby matching searches only the instance's saved place directory.
- The stream composer and full editor use a compact saved-place picker with nearby search.
- New places created from either writing surface ask only for a place name and optional public location label.
- Detailed categories, address fields, coordinates, and display defaults are managed under **Admin → Local Places**.
- Public posts use the saved place's default display automatically.
- Coordinates stay private and are not rendered in public HTML, feeds, or post text.
- Local Places continues to work with saved places when browser location permission is denied.
- No external places service, paid API, or shared directory is required.

Location snapshots are stored with the post in database front matter and included in Markdown exports for portability. Deleting a saved place does not remove the public location text already stored with existing posts.


## Media

The Admin can upload and manage media through the media library.

Bonumark Stream supports media attachments for posts and pages, with validation handled by the core application.

Commenters cannot upload media.

## Comments

Bonumark Stream supports comments when enabled.

The Admin can moderate comments and manage commenter participation. Commenter accounts can be used to support more controlled participation while keeping publishing authority with the Admin.

## Likes

Public likes are supported and rate-limited.

Likes do not require commenter accounts by default.

## Pinned posts

Published stream posts can be pinned from the three-dot **Post options** menu on the front end, the back-end editor, or **Admin → Stream Posts**. The same menu also holds the front-end Edit action, keeping reader actions separate from Admin controls. Only the Admin publishing role can pin or unpin posts.

- More than one published stream post can be pinned.
- Pinned posts appear in a quiet **Pinned** area above the homepage timeline.
- Pinned posts are ordered by the most recently pinned first.
- A pinned post is removed from the normal page-one timeline so it is not shown twice on the same page.
- Pinning again refreshes the pin time and moves the post to the top of the pinned area.
- Pinning does not change the post URL, original publish date, RSS/feed order, sitemap behavior, search results, archive behavior, static export output, or Remote Posting API behavior.
- Drafts, scheduled posts, private/unpublished posts, and trashed posts cannot appear in the pinned area. Moving a pinned post out of published status clears its pin state.

## Themes

Bonumark Stream themes are code-free presentation packages.

Themes can provide:

- `theme.json`
- CSS
- Images
- Fonts
- Screenshots
- Theme metadata
- Theme settings
- Documentation files

Themes cannot provide:

- PHP files
- JavaScript files
- HTML templates
- Routes
- Database logic
- Permission logic
- Publishing behavior
- Application code

Bonumark Stream core handles rendering and application behavior. Themes control presentation.

The bundled default theme is **Midnight Ledger**. It is also the reference example for how Bonumark Stream themes should be structured.

## Import and export

Bonumark Stream includes import and export tools to support content ownership and portability.

Supported tooling includes:

- Bonumark import/export
- Markdown import/export
- Static export
- Supported external importers included in the package

Export tools are intended to help you keep control of your content and move or back up your work.

## Static export

Normal Bonumark Stream operation is dynamic and database-first.

Static export is optional tooling for portability, backup, or deployment workflows. It is not required for normal publishing.

## Feeds and sitemap

Bonumark Stream includes:

- RSS/feed support
- Sitemap support
- Robots.txt handling

These are handled by core.

## Security

Bonumark Stream includes protections for:

- Admin authentication
- CSRF-protected admin actions
- Upload validation
- Private application folders
- Theme package validation
- Rate-limited public interactions
- Protected configuration files
- Scoped and hashed API tokens for Remote Posting

Apache/LiteSpeed protections are included through `.htaccess`.

If you run Bonumark Stream on Nginx or another server stack, you must configure equivalent private-folder and routing protections yourself.

## Backups

Before upgrading or making major changes, back up:

- Database
- Uploaded media
- Configuration files
- Theme files
- Exported content, if applicable

Do not rely on hosting alone. Keep your own backups.

## Upgrading

The built-in upgrader supports Bonumark Stream v0.4.0 and newer.

Older development packages are not supported upgrade sources.

For v0.1.x, v0.2.x, or v0.3.x packages, use a fresh v0.5.76 install.

## Project status

Bonumark Stream is under active development.

The v0.5.x line is the current public development release line. APIs, internals, installer behavior, and other pre-1.0 details may still change before a stable 1.0 release.

Use caution before running it on mission-critical sites.

## Contributing

Contributions, issue reports, testing notes, and thoughtful feedback are welcome through the GitHub repository:

https://github.com/jimlunsford/bonumarkstream

Before contributing, please keep the project direction in mind:

- Self-hosted
- Database-first
- Short-form publishing
- One Admin publisher
- Optional commenter participation
- Code-free themes
- Shared-hosting compatibility
- Ownership and portability

## License

See `LICENSE` for license information.

## App login persistence

Bonumark Stream includes a Remember this device login option for app-style use. It uses rotating persistent device tokens, not a longer normal PHP session, and remembered devices are revoked on logout, password changes, password resets, and admin password resets.

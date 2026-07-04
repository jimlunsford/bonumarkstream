# Bonumark Stream

**Bonumark Stream is a self-hosted microblog CMS for publishing short-form posts on a site you control.**

It is built for people who want the speed and simplicity of a personal stream without handing their words, media, and history to a platform they do not own.

- Homepage: https://bonumark.org
- Demo: https://demo.bonumark.org
- Repository: https://github.com/jimlunsford/bonumarkstream
- Current version: **0.5.30**

## v0.5.30 GitHub release hardening pass

This release prepares Bonumark Stream for public GitHub distribution. It aligns package metadata, documentation, release notes, security reporting guidance, and the deployable ZIP structure without adding features, changing publishing behavior, rewriting content, or adding a database migration.

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

Bonumark Stream v0.5.30 is a public development release built on the clean-break v0.4.0+ foundation. This release preserves legacy post timestamp interpretation from before the v0.5.23 timezone pass, while retaining canonical UTC handling for new timestamps. It does not rewrite existing content or post records.

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
- Drafts, scheduled posts, published posts, pinned posts, trash, revisions, and previews
- Basic pages
- Media library and validated media uploads
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
- Remote Posting API for trusted external clients
- Basic PWA install metadata and conservative service worker support
- Mobile share-target flow for loading shared text and URLs into the front-end composer
- Scheduled posts from both the front-end composer and back-end editor
- Shared Scheduled Tasks runner with server cron, protected web cron, task health, and execution history


## Install as app and mobile share

Bonumark Stream includes a clean PWA layer and routes mobile shares into the front-end composer.

When enabled in **Admin → Settings → Stream**, supported browsers can install the site as a basic app on mobile or desktop. Bonumark Stream adds a web app manifest, mobile app metadata, app icons, and a conservative service worker. When a Site Identity favicon is selected, Bonumark generates versioned 192 × 192 and 512 × 512 PNG install icons when the server supports GD or Imagick. On servers without either extension, it uses the selected favicon directly, with its real image type and dimensions, rather than reverting to the Bonumark B. Use a square 512 × 512 PNG for the strongest install-icon result. The bundled B remains the fallback only when no valid Site Identity favicon exists.

The service worker caches only safe static assets such as core CSS and JavaScript. Site Identity PWA icon URLs are versioned so a changed favicon can replace the installed app icon without stale service-worker icon entries. The service worker does not cache admin pages, draft pages, account pages, CSRF forms, API responses, private files, user-specific content, or the selected favicon media path.

Bonumark Stream also exposes a Web Share Target for supported mobile browsers. Shared text, titles, and URLs enter through the secure share-target intake route, then the user is sent back to the public stream with the front-end composer prefilled.

The user still has to review the content, edit it if needed, and press **Post**. Shared content never publishes automatically and it no longer gets forced into the backend draft editor first.

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
- Stream post requests can upload image media and embed it in the same request
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
- `CHANGELOG.md` for the public release summary and `_bonumark_stream/CHANGELOG.md` for detailed package history
- `SECURITY.md` for vulnerability reporting and security boundaries
- `CONTRIBUTING.md` for contribution rules and verification expectations

## Important upgrade notice

Bonumark Stream v0.5.30 continues the v0.4.0+ clean-break upgrade line.

The built-in upgrader supports Bonumark Stream v0.4.0 and newer.

Direct upgrades from older development packages, including v0.1.x, v0.2.x, and v0.3.x, are not supported.

If you are using an older development package, install Bonumark Stream v0.5.30 as a fresh installation.

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

The Admin can publish from the front-end composer, the admin area, and trusted Remote Posting API clients when enabled.

## Scheduled posts

The Admin can schedule stream posts for a future date and time from the front-end composer or the back-end editor. The default behavior remains normal posting. Scheduling only happens when the user chooses the schedule action and provides a future time. In the back-end editor, scheduling is intentionally quiet: Save Draft and Post Now remain the main visible actions, and the schedule date/time field appears only after choosing Schedule for later or Reschedule.

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

For v0.1.x, v0.2.x, or v0.3.x packages, use a fresh v0.5.30 install.

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

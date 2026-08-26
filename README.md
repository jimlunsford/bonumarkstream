# Bonumark Stream

**Bonumark Stream is a self-hosted microblog CMS for publishing short-form posts on a site you control.**

It is built for people who want the speed of a personal stream without handing their writing, media, identity, and publishing history to a social platform.

- Homepage: https://bonumark.org
- Demo: https://demo.bonumark.org
- Repository: https://github.com/jimlunsford/bonumarkstream
- Current version: **0.7.1**

Release history is maintained in [CHANGELOG.md](CHANGELOG.md).

## What Bonumark Stream is

Bonumark Stream is a lightweight PHP/MySQL publishing system for short posts, notes, links, photos, pages, comments, profiles, likes, feeds, imports, exports, and owner-controlled site presentation.

The normal runtime is database-first and dynamically rendered. Markdown remains part of the system for import, export, backup, and portability, but it is not the runtime source of truth.

Bonumark Stream is intentionally not a social network and not a multi-author publishing platform. The Admin account owns publishing. Optional Commenter accounts can participate through comments and profile/account features when enabled.

## Why it exists

Bonumark Stream is designed around a simple idea: short-form publishing should be as easy as posting to a social platform while the site owner keeps control of the domain, database, media, presentation, and archive.

That means the project favors:

- Self-hosting and content ownership
- Fast short-form publishing
- A single owner/publisher model
- Shared-hosting compatibility
- Portable data and media
- Privacy-conscious defaults
- Code-free themes with a strict application boundary
- Upgrade paths that preserve owner data

## What's new in v0.7.1

v0.7.1 is the next intended public release after v0.6.0. It supersedes the unreleased v0.7.0 candidate and brings together the hosting-portability, deployment, upgrade, and compatibility work completed across the v0.6.1 through v0.6.8 development builds. The v0.7.1 correction also makes the repository compatibility matrix test a clean tracked source tree and restores the Remote Stream Posts API route that was accidentally omitted from the v0.7.0 GitHub branch.

Compared with v0.6.0, v0.7.1 adds no new database migration. Installations older than v0.6.0 may still have earlier pending migrations when they move directly to the current release.

### Locked-down hosting is a supported operating model

Bonumark Stream now treats runtime writability and application-code replacement as separate capabilities. A site can publish, upload media, import content, run scheduled work, and use normal runtime storage even when the web/PHP process is intentionally unable to replace package-managed application files.

System Check reports optional capabilities such as web-based upgrades, theme ZIP installation, cURL, ZipArchive, GD/Imagick, upload ceilings, and web-server detection without turning every unavailable convenience into a core application failure.

### Owner-run upgrades use the same core upgrade engine

On hosts where PHP can safely replace application code, **Admin → Upgrade** remains the normal ZIP upgrade path. Locked-down servers with shell access can instead run `php scripts/deploy-update.php /path/to/release.zip` as the application owner.

Both paths use the same core upgrade engine for release-manifest validation, owner-data preservation, private software backups, selective pre-migration rollback, obsolete package-file cleanup, migration recovery, and upgrade-history recording. The CLI helper does not invoke `sudo`, install a privileged daemon, use setuid behavior, or grant the web runtime additional filesystem rights.

For an older locked-down installation that does not yet contain the helper, an extracted newer release can target the live installation with `--site-root=/path/to/live/site` for the first transition.

### Deployment verification is part of the workflow

The release adds and hardens read-only deployment checks for package-managed file integrity, obsolete package files, runtime-directory presence, database compatibility, pending migrations, and migration-recovery state. Admin → System Check remains authoritative for checks that depend on the web/PHP identity and live HTTP behavior.

Public URL mode now performs a real read-only request to Bonumark's clean `/api/v1/status` route. Private-folder protection is checked with a known private marker instead of writing a temporary probe file. Shipped top-level PHP tools under `/scripts` are CLI-only.

### Broader hosting and database compatibility

Bonumark Stream documents PHP 8.1+, MySQL 8.0+, and MariaDB 10.6+ as the current floors, with optional capabilities detected separately. Nginx now has maintained deployment guidance and a reference configuration while Apache and LiteSpeed continue to use the shipped `.htaccess` rules.

The repository compatibility workflow exercises PHP 8.1 and 8.3 across MySQL 8.0/8.4 and MariaDB 10.6/11.4. Each job first creates a clean tracked source snapshot, excluding Git checkout metadata, and then runs PHP lint, the clean-package smoke test, migration/schema testing, and Remote Posting API database testing.

### Safer upgrade and recovery boundaries

Owner data remains outside normal package replacement. Configuration, installed state, database content, runtime data, media, uploads, backups, imports, content versions, settings, and custom themes remain protected by the upgrade contract.

If a file replacement fails before migrations begin, rollback is limited to the software actually changed by that attempt. Once migration work may have begun, recovery continues toward the same target release instead of casually rolling application code backward against a newer schema state.

## Major features

### Publishing

- Front-end Stream composer as the primary creation surface
- Post now, schedule, save draft, or continue in the full editor
- Drafts, scheduled posts, published posts, revisions, previews, trash, and restoration
- Pinned posts
- Inline Markdown Quick edit for published Stream Posts
- Stale-content conflict protection
- One-to-four-photo galleries with preview, ordering, responsive layouts, and full-size viewing
- Link previews
- Local Places check-ins
- Search and indexing controls

### Media

- Media Library with validated uploads
- Randomized public filenames for new uploads
- Best-effort image metadata removal with optional strict privacy mode
- Responsive image derivatives where supported
- Profile cover and gallery delivery optimized independently from normal Stream media

### Profiles and participation

- Public owner Profile with headline, About, Now, location, interests, and flexible links
- Featured Work for published Stream Posts, Pages, and validated external URLs
- Profile cover media and photo gallery
- Canonical Profile URLs plus Open Graph, Twitter, and structured identity metadata
- Optional Commenter accounts
- Public comments with moderation
- Public likes with rate limiting
- Password reset and verification flows
- Remember-this-device login persistence
- Profile portability export to JSON, Markdown, and original local Profile media

### Site and discovery

- Pages
- RSS/feed support
- Sitemap and robots.txt handling
- Site Identity and navigation controls
- Optional cookieless Privacy-First Analytics
- Basic installable PWA metadata and conservative service worker
- Mobile Web Share Target for text and URLs

### Automation and integrations

- Scheduled Tasks runner with server cron, protected web cron, health reporting, and history
- Optional token-scoped Remote Posting API
- Read-only Stream API catalog
- Remote draft, publish, schedule, media upload, media import, and gallery workflows
- OpenAPI schema and client examples

### Ownership and portability

- Database-first runtime
- Markdown import/export
- Bonumark import/export tooling
- Optional static export
- Owner-controlled Profile export
- Upgrade backups and recovery boundaries
- Managed and locked-down deployment models
- Admin and owner-run CLI upgrades through one shared upgrade engine
- Read-only System Check and installed-site deployment verification


## Pinned posts

Published Stream Posts can be pinned from the front-end Post options menu, the full editor, or Admin > Stream Posts. Multiple pinned posts are ordered by their most recent pin time and appear above the normal homepage timeline without being duplicated in the page-one feed.

Pinning does not change the post URL, original publish date, RSS/feed order, sitemap behavior, search results, archive ordering, static export output, or Remote Posting API behavior. Moving a pinned post out of published status clears its pin state.

## Account model

Bonumark Stream uses two account types.

**Admin** is the site owner and sole publisher. The Admin can publish posts, create pages, manage media and comments, configure the site, manage themes, run imports/exports, manage integrations, and run supported upgrades.

**Commenter** is a participation account. Commenters may manage their basic account/Profile details and comment when those features are enabled. Commenters cannot publish Stream Posts, create Pages, upload media, manage themes, or access publishing/system administration.

## Themes

Bonumark Stream themes are presentation packages, not plugins.

Themes may include:

- `theme.json`
- CSS
- Images
- Fonts
- Screenshots
- Theme settings
- Documentation
- Validated private Layout Schema JSON for supported declarative surfaces

Themes may not include:

- PHP
- JavaScript
- Arbitrary HTML templates
- SQL
- Routes
- Database access
- Permission logic
- Publishing behavior
- Callbacks or expressions
- Other executable application code

Core renders the site and owns application behavior. Themes control presentation and validated composition.

The bundled default and reference theme is **Midnight Ledger**.

See [docs/THEMING.md](docs/THEMING.md) and [docs/DECLARATIVE-LAYOUTS.md](docs/DECLARATIVE-LAYOUTS.md).

## Requirements

Core requirements:

- PHP 8.1 or newer
- MySQL 8.0+ or MariaDB 10.6+
- PDO MySQL extension
- Writable runtime storage for normal operation
- Web-server routing for Bonumark clean URLs
- Web-server protection that blocks `_bonumark_stream/` and `scripts/` from public HTTP access

Recommended:

- PHP 8.2 or newer
- HTTPS
- Regular file and database backups
- GD or Imagick for stronger image processing and generated variants

Feature capabilities:

- PHP cURL enables safe link previews, remote media import, and the preferred read-only HTTP diagnostic transport.
- ZipArchive enables Admin ZIP upgrades, Admin theme ZIP installation, and ZIP-based export features. Core publishing does not require ZipArchive.
- Fileinfo improves MIME validation; Bonumark retains image-validation fallbacks when it is unavailable.
- mbstring improves Unicode-aware text operations when available; Bonumark includes core fallbacks and does not require mbstring for normal operation.
- Admin theme ZIP installation additionally requires PHP write access to `_bonumark_stream/themes/` and `assets/themes/`. A locked-down installation can manage optional themes externally instead.
- Admin ZIP upgrades require the web/PHP process to replace package-managed application files. Locked-down code trees remain valid; with shell access, run `php scripts/deploy-update.php /path/to/release.zip` as the application owner. Use the documented manual/hosting-layer workflow when shell access is unavailable.

Apache and LiteSpeed use the included `.htaccess` rules. Nginx deployments can use the maintained example under [`docs/server/`](docs/server/NGINX.md). Other web servers need equivalent routing and private-path protections. For production, use a database release that still receives vendor security updates even when it is above Bonumark's compatibility floor.

## Installation

1. Download the latest release ZIP.
2. Upload the package contents to the target web root or subdirectory.
3. Visit `install.php`.
4. Confirm the server checks and enter the database connection details.
5. Create the first Admin account.
6. Complete installation and sign in at `/admin/`.

A fresh install starts empty. Bonumark Stream does not create sample posts or Pages.

Full instructions: [docs/INSTALL.md](docs/INSTALL.md).

## Upgrading

Bonumark Stream supports two first-class ZIP upgrade paths for v0.4.0 and newer. Use Admin → Upgrade when the web/PHP process can safely replace package-managed application files. On a locked-down server with shell access, keep the code tree locked to PHP and run `php scripts/deploy-update.php /path/to/release.zip` as the application owner. The CLI path validates the package, creates the same private software backup, applies the same preservation/rollback rules, handles migrations through the shared upgrade engine, removes obsolete package-managed files, and runs deployment verification automatically.

For the first transition from an older release that does not yet contain `scripts/deploy-update.php`, extract the newer release outside the live site and run its helper with `--site-root=/path/to/live/site`. After that upgrade, the helper is installed in the live site for normal future use.

Back up the database before production upgrades that contain migrations. The owner-run CLI requires explicit database-backup confirmation before applying pending migrations. Hosts without shell access can use the documented manual/hosting-layer fallback workflow.

Direct upgrades from v0.1.x, v0.2.x, and v0.3.x development packages are not supported. Use a fresh current release install for those older development builds.

Full instructions: [docs/UPGRADING.md](docs/UPGRADING.md).

## Security model

Bonumark Stream includes:

- Authenticated and capability-checked Admin routes
- CSRF protection for mutating Admin actions and public comments
- Rate-limited public likes
- Validated media uploads with SVG uploads blocked
- Hashed Remote Posting API tokens
- Code-free theme package validation
- Private-directory protection for Apache/LiteSpeed plus a maintained Nginx configuration example
- Conservative service-worker caching that excludes private and user-specific content

See [SECURITY.md](SECURITY.md) for reporting and deployment boundaries.

## Documentation

Project documentation is included under `docs/`:

- [Installation](docs/INSTALL.md)
- [Nginx deployment](docs/server/NGINX.md)
- [Manual software deployment](docs/server/MANUAL-DEPLOYMENT.md)
- [Manual theme deployment](docs/server/MANUAL-THEME-DEPLOYMENT.md)
- [Upgrading](docs/UPGRADING.md)
- [Compatibility](docs/COMPATIBILITY.md)
- [Architecture](docs/ARCHITECTURE.md)
- [Theming](docs/THEMING.md)
- [Declarative Layouts](docs/DECLARATIVE-LAYOUTS.md)
- [Admin UI Guidelines](docs/ADMIN-UI-GUIDELINES.md)
- [Remote Posting API](docs/REMOTE-POSTING.md)
- [API reference](docs/API.md)
- [Remote Posting clients](docs/REMOTE-POSTING-CLIENTS.md)
- [ChatGPT Actions](docs/CHATGPT-ACTIONS.md)
- [Importers](docs/IMPORTERS.md)
- [Scheduled Tasks](docs/SCHEDULED-TASKS.md)
- [Privacy-First Analytics](docs/ANALYTICS.md)
- [Media Privacy](docs/MEDIA-PRIVACY.md)

Detailed package-by-package development history is kept in `_bonumark_stream/CHANGELOG.md`. The root [CHANGELOG.md](CHANGELOG.md) is intentionally limited to public GitHub release summaries.

## Development and verification

Before proposing changes:

- Run PHP lint on changed PHP files
- Run JavaScript syntax checks on changed JavaScript files
- Validate changed JSON files
- From a clean source/release tree, run the package-only `php scripts/smoke-test.php` (installed sites should use Admin > System Check)
- Run the disposable MySQL/MariaDB database smoke test for installer, migration, or upgrade changes

See [CONTRIBUTING.md](CONTRIBUTING.md) for project rules and the release verification contract.

## Project status

Bonumark Stream is under active pre-1.0 development. v0.7.x is the current development line, so APIs, internals, installer behavior, and other pre-1.0 details may still change before 1.0.

Use appropriate backups and testing before running pre-1.0 software on a mission-critical site.

## License

Bonumark Stream is licensed under **AGPL-3.0-or-later**. See [LICENSE](LICENSE).

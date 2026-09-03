# Bonumark Stream

**Bonumark Stream is a self-hosted microblog CMS for publishing short-form posts on a site you control.**

It is built for people who want the speed of a personal stream without handing their writing, media, identity, and publishing history to a social platform.

- Homepage: https://bonumark.org
- Demo: https://demo.bonumark.org
- Repository: https://github.com/jimlunsford/bonumarkstream
- Current version: **0.8.0**

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

## What's new in v0.8.0

Bonumark Stream can now remain the owner's publishing home while also participating in the fediverse. ActivityPub is optional and disabled by default. When enabled, normal Bonumark publication transitions can reach followers on Mastodon, GoToSocial, Misskey, and other compatible platforms without making remote delivery part of the local save transaction.

The owner can follow remote accounts, read a private chronological Following timeline, open private conversation views, reply with normal Bonumark posts, and Like, Unlike, Boost, or Unboost remote posts. Inbound replies, Likes, and boosts remain separate from local accounts and local anonymous Likes, with moderation and blocking under the owner's control.

Bonumark preserves durable local post identity while giving each federated publication lifetime its own ActivityPub object identity. After a federated object is deleted, that identity remains permanently Tombstoned. Republishing keeps the local post but creates a new ActivityPub generation instead of resurrecting a retired object.

Federation delivery is asynchronous, signed, retryable, and isolated from local publishing. The release adds encrypted signing-key storage and rotation, legacy RSA and RFC 9421 signature support, replay and digest protection, SSRF-safe remote fetching, queue repair, pause, delivery suspension, and irreversible permanent Actor Delete.

ActivityPub requires a root-level HTTPS site, domain-root WebFinger routing, OpenSSL, cURL, protected private storage, and dependable server cron or protected web cron. See the [ActivityPub guide](docs/ACTIVITYPUB.md) and [v0.8.0 release notes](docs/releases/v0.8.0.md) before enabling federation.

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

### Optional ActivityPub federation

- Single-owner WebFinger, actor, outbox, and object discovery
- Signed Create, Update, Delete, Follow, Accept, Reject, Undo, Like, and Announce behavior
- Generation-aware republishing that never resurrects deleted object identities
- Multiple-image ActivityStreams attachments with alt text
- Inbound reply moderation plus separate inbound Like and boost state
- Private owner-only Following timeline and conversation views
- Chronological, non-algorithmic remote-post presentation
- Owner Follow, Unfollow, Reply, Like, Unlike, Boost, and Unboost actions
- Actor and domain blocking
- Encrypted signing keys, key rotation, delivery retries, dead letters, and queue repair
- Reversible pause and delivery suspension
- Irreversible permanent Actor Delete

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
- [ActivityPub](docs/ACTIVITYPUB.md)
- [v0.8.0 release notes](docs/releases/v0.8.0.md)
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

Bonumark Stream is under active pre-1.0 development. v0.8.x is the current development line, so APIs, internals, installer behavior, and other pre-1.0 details may still change before 1.0.

Use appropriate backups and testing before running pre-1.0 software on a mission-critical site.

## License

Bonumark Stream is licensed under **AGPL-3.0-or-later**. See [LICENSE](LICENSE).

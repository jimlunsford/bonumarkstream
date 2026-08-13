# Bonumark Stream

**Bonumark Stream is a self-hosted microblog CMS for publishing short-form posts on a site you control.**

It is built for people who want the speed of a personal stream without handing their writing, media, identity, and publishing history to a social platform.

- Homepage: https://bonumark.org
- Demo: https://demo.bonumark.org
- Repository: https://github.com/jimlunsford/bonumarkstream
- Current version: **0.6.0**

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

## Release highlights

v0.6.0 is the first public release after v0.5.77. It consolidates the development work completed since that release into a smaller set of user-facing milestones instead of exposing every internal build pass.

### First-class Profiles

Profiles are now a full public identity surface rather than a basic account page. The Profile system supports:

- Headline, About, Now, location, interests, and flexible links
- Featured Work that can point to published Stream Posts, Pages, or validated external URLs
- Profile cover images and a photo gallery
- Optional public activity details
- Canonical Profile URLs and social/structured identity metadata
- Owner-controlled Profile export to JSON, Markdown, and original local Profile media

### Theme Architecture 2.0

Bonumark Stream themes remain code-free, but they can now control validated composition as well as CSS presentation.

Layout Schema 1 supports declarative composition for four public surfaces:

- Profile
- Stream Card
- Site Header
- Home

Themes arrange registered core components through private JSON layout files. Core still owns behavior, data, routing, permissions, forms, publishing, media, comments, likes, SEO, accessibility semantics, and rendering logic. There is no expression language and themes cannot ship executable application code.

CSS-only themes remain supported through legacy composition fallback.

### Midnight Ledger rebuilt as the reference theme

Midnight Ledger is the single bundled/default theme and now exercises the complete Theme Architecture 2.0 stack. The v0.6.0 package includes the responsive Home, Stream Card, Site Header, and Profile composition used as the reference implementation for third-party themes.

### Link-preview and SEO hardening

The release strengthens the boundary between local document SEO and reusable fragments such as external link previews. Remote titles remain remote titles, local site-name suffixes are not injected into external preview metadata, and fragment data is kept separate from full-document SEO processing.

### Upgrade and package hardening

The upgrader preserves configuration, the database, uploads, media, custom themes, backups, and owner data while replacing package-managed application files. v0.6.0 also retains the existing v0.4.0+ upgrade line and includes the Profile migrations needed by installations upgrading from v0.5.77.

### Profile image delivery

Profile cover and gallery delivery now use bounded responsive candidates. When the host supports WebP encoding, Profile media can expose modern-format picture sources while retaining safe JPEG/PNG fallbacks. The Profile cover receives high-priority loading treatment while below-fold gallery images remain lazy.

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

- Public owner Profile
- Optional Commenter accounts
- Public comments with moderation
- Public likes with rate limiting
- Password reset and verification flows
- Remember-this-device login persistence
- Profile portability export

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

Minimum:

- PHP 8.1 or newer
- MySQL or MariaDB
- PDO MySQL extension
- ZIP extension for package/theme handling
- A writable application environment

Recommended:

- PHP 8.2 or newer
- HTTPS
- Apache or LiteSpeed for the included `.htaccess` rules
- Regular file and database backups
- GD or Imagick for stronger image processing and generated variants

Nginx and other non-Apache servers need equivalent routing and deny rules for private application directories and CLI test scripts.

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

The built-in upgrader supports Bonumark Stream v0.4.0 and newer.

For the v0.6.0 release, an upgrade from the last public GitHub release, v0.5.77, runs the included Profile migrations and preserves existing posts, pages, drafts, scheduled posts, revisions, comments, accounts, media, uploads, settings, analytics, API tokens, scheduled-task history, Local Places, custom themes, and other owner data.

Back up the site files and database before upgrading a production installation.

Direct upgrades from v0.1.x, v0.2.x, and v0.3.x development packages are not supported. Use a fresh v0.6.0 install for those older builds.

Full instructions: [docs/UPGRADING.md](docs/UPGRADING.md).

## Security model

Bonumark Stream includes:

- Authenticated and capability-checked Admin routes
- CSRF protection for mutating Admin actions and public comments
- Rate-limited public likes
- Validated media uploads with SVG uploads blocked
- Hashed Remote Posting API tokens
- Code-free theme package validation
- Private-directory protection for Apache/LiteSpeed
- Conservative service-worker caching that excludes private and user-specific content

See [SECURITY.md](SECURITY.md) for reporting and deployment boundaries.

## Documentation

Project documentation is included under `docs/`:

- [Installation](docs/INSTALL.md)
- [Upgrading](docs/UPGRADING.md)
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
- Run `php scripts/smoke-test.php`
- Run the disposable MySQL/MariaDB database smoke test for installer, migration, or upgrade changes

See [CONTRIBUTING.md](CONTRIBUTING.md) for project rules and the release verification contract.

## Project status

Bonumark Stream is under active pre-1.0 development. v0.6.x is the current public development line, so APIs, internals, installer behavior, and other pre-1.0 details may still change before 1.0.

Use appropriate backups and testing before running pre-1.0 software on a mission-critical site.

## License

Bonumark Stream is licensed under **AGPL-3.0-or-later**. See [LICENSE](LICENSE).

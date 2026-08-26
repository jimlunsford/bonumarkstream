# Bonumark Stream Architecture

Bonumark Stream is a dynamic, database-first microblog CMS designed for normal shared hosting.

## Source of truth

The database is the runtime source of truth for posts, pages, accounts, Profiles, comments, media records, settings, likes, drafts, trash, revisions, scheduled work, Local Places, analytics, and registration data.

Markdown is used for import, export, backup, and portability. Runtime rendering does not depend on Markdown files as fallback storage.

## Account model

Bonumark Stream has two account types:

- **Admin**: sole publisher and site manager.
- **Commenter**: participation account for comments and Profile/account features when enabled.

The publishing model is intentionally owner-controlled rather than multi-author.

## Request flow

- `/` renders the Stream home.
- `/stream/` remains a supported alias.
- Clean public routes are handled by `index.php`.
- Admin routes live under `/admin/`.
- Public rendering is dynamic by default.
- Static Site Export reuses the normal core rendering path as optional tooling.

## Core boundary

Core owns:

- Routing and request handling
- Database access and migrations
- Authentication and permissions
- Forms and CSRF protection
- Publishing, drafts, scheduling, revisions, and trash
- Media processing and validation
- Comments and likes
- Profiles and Profile metadata
- Local Places
- Feeds, sitemap, search, and SEO
- Remote Posting API and Scheduled Tasks
- Public component markup and accessibility semantics
- Theme validation and rendering execution
- Install, upgrade, backup, and recovery behavior

## Theme boundary

Theme packages are presentation-only.

A theme may provide metadata, settings, screenshots, CSS, images, fonts, documentation, and validated private declarative layout JSON.

A theme may not provide PHP, JavaScript, HTML templates, SQL, routes, database writes, permission logic, callbacks, expressions, server configuration, or application behavior.

## Theme Architecture 2.0

Layout Schema 1 supports declarative composition for four public surfaces:

- `profile`
- `stream-card`
- `site-header`
- `home`

Layout files contain only validated `group` and registered `component` nodes. Core renders every component and owns the outer semantic/application boundaries.

A theme may adopt any supported subset. Undeclared surfaces continue through the fixed legacy composition, so CSS-only themes remain compatible.

Midnight Ledger is the single bundled/default reference theme and uses all four current declarative surfaces. Materially different proof compositions remain under `scripts/fixtures/declarative-themes/` for regression testing only.

See [DECLARATIVE-LAYOUTS.md](DECLARATIVE-LAYOUTS.md) for the public Layout Schema contract.

## Public template SEO boundary

Document SEO processing applies only to complete public documents such as Home, archives, single Stream Posts, Pages, Profiles, accounts, and search.

Reusable fragments such as link previews, cards, media, comments, pagination, and composer output must preserve the domain data supplied by their owning renderer. In particular, a remote link-preview title must not be converted into a local Bonumark document title or receive the local site-name suffix.

## Media boundary

Upload validation, privacy handling, gallery ordering, responsive derivative generation, media dimensions, loading behavior, viewer behavior, and accessibility labels are core responsibilities.

Themes style stable media contracts but do not implement media behavior.

Profile covers and Profile photos use dedicated responsive candidate sets because their rendered slots differ from normal Stream media. Original uploads remain the source of truth.

## Upgrade boundary

The upgrader treats configuration, the database, media/uploads, backups, data, and custom themes as protected owner data. Package-managed application files and the bundled theme can be replaced by a validated release package.

Supported upgrades begin at v0.4.0. Earlier v0.1.x, v0.2.x, and v0.3.x development builds require a fresh install.

# Bonumark Stream Architecture

Bonumark Stream v0.3.11 is the public GitHub fresh-install foundation baseline.

The architecture is now intentionally simple:

```text
Database = live source of truth
Dynamic PHP rendering = normal public site
Markdown = import/export ownership format
Static HTML = optional site export artifact
```

## Core responsibility

Core owns:

- installation and configuration
- users, roles, sessions, and permissions
- database-first Stream Posts and Pages
- comments, profiles, likes, and registration
- media records and uploads
- imports and exports
- admin screens
- dynamic public routing
- optional Static Site Export
- migrations and future upgrade support

## Theme responsibility

Themes own public presentation only:

- homepage stream markup
- single post markup
- Page presentation
- profile, account, comments, search, and author presentation
- public header and footer presentation
- theme CSS and theme JavaScript

Themes should not own database writes, authentication, imports, upgrade logic, admin behavior, or content storage.

## Internal PHP namespace

Bonumark Stream still uses the `mp_` function prefix internally during the v0.x line. This is a private implementation namespace, not a public extension API or compatibility promise. Public GitHub users should build themes through documented theme templates and manifests instead of calling internal `mp_` functions directly. A future namespace cleanup should be handled as a dedicated breaking-change pass, not hidden inside a patch release.

## Important paths

```text
/                         public front controller
/admin/                   admin screens and admin endpoints
/assets/                  core public assets and bundled theme assets
/_bonumark_stream/app/    core application code
/_bonumark_stream/content/ legacy Markdown import and export staging
/_bonumark_stream/data/   private runtime data
/_bonumark_stream/themes/ bundled and installed public themes
/_bonumark_stream/migrations/ database migrations for the fresh baseline and future changes
/_bonumark_stream/backups/ private backups and package backups
```

## Content model

Published Stream Posts, drafts, and Pages are database content records. The database record is the live version.

Markdown is not the live storage authority. Markdown exists for:

- readable content export
- imported legacy Markdown files
- controlled import of older Markdown files into database records


## Stream slug generation

Generated Stream Post slugs prefer user intent in this order:

1. manually entered slug
2. manually entered title
3. first Markdown H1 heading
4. first meaningful sentence
5. attached media filename
6. `stream-post` prepared content shape

Generated slugs strip Markdown syntax, cap length, and use numeric suffixes such as `-2` or `-3` only when a duplicate exists. Markdown `.md` filenames remain internal storage/export identifiers and should not be exposed as public URLs.

## Public rendering

Public routes are resolved through lightweight front controllers and rewrite rules. Core loads database records, then passes that data to the active theme template.

Generated HTML does not power the live site. Normal saves, settings changes, theme changes, navigation changes, imports, and package changes do not require generated public files to be rebuilt.


## Navigation model

Public navigation is managed only from Admin → Navigation. Pages do not decide whether they appear in the menu, and page saves do not add, remove, or reorder menu items.

Fresh installs store Home as the only default menu item and keep public navigation hidden until the administrator turns it on. Users can add published Pages and custom links manually, then reorder items with Move up and Move down controls.

The navigation manager is intentionally flat and admin-owned. Its interface uses stacked item cards so label, URL, open behavior, item preview, and movement controls stay readable on desktop and mobile.

## Static Site Export

Static Site Export is an Export-screen artifact. It generates a portable downloadable HTML copy for backup, portability, or publishing elsewhere. It is not a cache layer and it is not part of normal publishing. Static exports include `sitemap.xml` and `robots.txt` when sitemap support is enabled.

## XML sitemap and robots.txt

The live site serves `/sitemap.xml` dynamically from published database content and `/robots.txt` with a `Sitemap:` reference. The sitemap includes the homepage, stream archive, published stream posts, and published pages by default. It excludes search, account, admin, draft, trash, pending, and noindex content. Public profile URLs are optional from Reading Settings.

## Migrations

The v0.3.11 line keeps the database-first install schema, dynamic discovery routes, styled sitemap output, dashboard order polish, dashboard layout polish, public release audit repairs, mobile text overflow repair, admin form input styling repairs, upgrade action button alignment, admin autofill input color repair, upgrade screen simplification, admin user management actions, comment account link cleanup, account registration kicker cleanup, public navigation account links and account-link toggle, public page Markdown presentation repair, desktop no-image link-preview repair, mobile public page containment repair, Load More archive routing repair, WordPress featured media import repair, Site Identity favicon support, theme-independent favicon output, external theme upgrade preservation, migration release integrity cleanup, and the retained v0.2.x hardening migrations needed to preserve the stable upgrade path. The old v0.1.x compatibility line is not preserved. Future schema changes should be added as new migrations after this baseline.

## Upgrade stance

v0.3.11 is not intended as an upgrade bridge from old v0.1.x test installs. Install it fresh. Future upgrades should target the v0.3.11 baseline and later.


## Private backup warning

Database and full export ZIPs are private backups. They may include password hashes, email addresses, account records, invite/reset records, moderation data, and security logs. Markdown and Static Site Export output are portability artifacts, but database/full exports should never be published.


## Author route behavior

Clean `/author/{username}/` routes render the public author/profile surface directly through the dynamic route dispatcher. Query-based profile routes remain available for account and profile workflows.


## Media performance safety

v0.2.32 keeps public media rendering display-first while adding safe responsive image output. Bonumark keeps the original image as the fallback src and only emits srcset/sizes when derivative metadata exists and the generated files are confirmed on disk. It does not generate variants during public rendering, invent derivative URLs, convert formats, or batch-regenerate old media. Imported media remains on the original source path unless verified variants exist.


The Media Edit screen exposes derivative diagnostics and a one-item regenerate action. This troubleshooting layer helps site owners verify host support before responsive image output is ever enabled.

## Image optimization model

Bonumark Stream preserves original media files and stores generated image variants as optional metadata. Public responsive output only uses generated files that exist on disk and are recorded in the media record. Existing media regeneration is manual and batched for shared-hosting safety.

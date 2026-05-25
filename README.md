# Bonumark Stream

Bonumark Stream is a self-hosted microblog and lightweight publishing system for people who want to own their short-form content, publish quickly, and run their own space on the web.

It gives you a public timeline, pages, media, comments, profiles, themes, imports, exports, and upgrade tools in one shared-hosting friendly PHP application.

**Demo:** [https://demo.bonumark.org](https://demo.bonumark.org)  
Current version: **0.3.2**  
**License:** AGPL-3.0-or-later

## What Bonumark Stream Is

Bonumark Stream is built for owned short-form publishing.

It is not trying to replace every full content management system. It is focused on something simpler and sharper:

- post updates quickly
- keep your content on your own site
- publish a timeline instead of depending on a rented social feed
- create simple pages when a post is not enough
- export your content in portable formats
- manage media, comments, profiles, and users from one admin area

The live site renders from the database. Markdown remains an ownership, import, and export format. Static HTML export is available when you want a portable copy of the public site.

## Why It Exists

A lot of useful writing does not need to become a full article.

Project notes, field updates, release logs, quick thoughts, images, links, and small announcements often need a clean place to live. Social platforms make that easy, but they also control the feed, the reach, the layout, the rules, and sometimes the future of the account.

Bonumark Stream is for people who want that short-form workflow without giving up control of the space.

## Demo

A public demo is available here:

[https://demo.bonumark.org](https://demo.bonumark.org)

The demo shows the public side of Bonumark Stream, including:

- the public timeline
- individual stream posts
- link previews
- pages
- profile surfaces
- comments
- the bundled Midnight Ledger theme

## Features

### Publishing

- Stream posts
- Pages
- Drafts
- Publishing controls
- Generated slugs
- SEO title and description fields
- Markdown-based import and export
- Rich public rendering for headings, lists, tables, quotes, links, and code blocks

### Public Site

- Public stream timeline
- Single post pages
- Public pages
- Author and profile pages
- Search
- RSS feed
- XML sitemap
- Public likes
- Public comments
- Optional public navigation
- Automatic account links in navigation, with an admin toggle

### Admin

- Dashboard overview
- Stream post manager
- Page manager
- Media library
- Comment moderation
- User management
- Site identity settings
- Reading and writing settings
- Mail settings
- Registration settings
- Theme management
- Import and export tools
- System check
- Built-in package upgrade screen

### Media

- Media uploads
- Media records
- Avatar upload support
- Image metadata
- Optimized image variants when GD or Imagick is available
- Responsive image output when verified variants exist
- Manual media regeneration tools

### Accounts and Registration

- Admin, User, and Commenter roles
- Public account page
- Public profile editing
- Invite-only registration option
- Email verification support
- Admin approval controls
- Password recovery
- Admin user management
- Safe user deletion and owned-record reassignment

### Import and Export

Bonumark Stream supports imports from:

- Markdown
- Generic JSON
- WordPress WXR/XML
- Bonumark Stream export ZIPs
- Twitter/X archive ZIPs
- Bluesky/AT Protocol CAR archives

Export options include:

- Markdown export
- full private backup/export
- Static Site Export for a portable generated HTML copy

Private exports may include sensitive data such as password hashes, account metadata, reset records, invite records, moderation data, and email addresses. Do not publish private backup ZIP files.

## Requirements

Bonumark Stream is designed for common shared hosting environments.

Required:

- PHP 8.2 or newer
- MySQL or MariaDB
- PDO MySQL driver
- writable private directories under `_bonumark_stream/`

Recommended:

- ZipArchive for exports, upgrades, and theme ZIP uploads
- cURL for link previews and remote media imports
- GD or Imagick for image metadata, avatar optimization, and generated image variants
- Apache with `.htaccess` support, or equivalent private-directory rules on Nginx, IIS, or custom hosting panels

## Quick Start

1. Download the latest Bonumark Stream release ZIP.
2. Upload the package contents to your PHP/MySQL hosting account.
3. Visit `install.php` in your browser.
4. Enter your database settings.
5. Create the first administrator account.
6. Remove or lock the installer when setup is complete.
7. Sign in at `/admin/`.
8. Review Site Identity, Reading, Writing, Mail, Registration, Themes, and System Check.
9. Create a test post and a test page.

For full setup instructions, see:

- [`docs/INSTALL.md`](docs/INSTALL.md)

## Upgrading

Bonumark Stream includes an admin upgrade screen for release ZIP packages.

The upgrader checks the package manifest, verifies file hashes, protects private configuration and user data, runs pending migrations, and records upgrade history.

Before upgrading a production site:

1. Back up your files.
2. Back up your database.
3. Upload only release ZIP files you created or trust.
4. Review the upgrade check screen before running the upgrade.

For full upgrade instructions, see:

- [`docs/UPGRADING.md`](docs/UPGRADING.md)

## Documentation

Project documentation lives in the `docs/` directory:

- [`docs/INSTALL.md`](docs/INSTALL.md), installation guide
- [`docs/UPGRADING.md`](docs/UPGRADING.md), upgrade guide
- [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md), technical architecture
- [`docs/THEMING.md`](docs/THEMING.md), theme system notes
- [`docs/IMPORTERS.md`](docs/IMPORTERS.md), importer details

## Architecture

Bonumark Stream follows a simple foundation:

```text
Database = live source of truth
Dynamic PHP rendering = normal public site
Markdown = import/export ownership format
Static HTML = optional downloadable site export artifact
```

The live public site does not depend on generated HTML files. Public routes render from database content records. Static Site Export is available as a tool-generated artifact when you want a portable HTML copy.

Core handles:

- data storage
- authentication
- permissions
- publishing
- imports
- exports
- admin workflows
- dynamic routing

Themes handle:

- public presentation
- layout
- public templates
- theme assets
- theme settings

## Public Routes

Common public routes include:

```text
/
/stream/
/stream/page/2/
/stream/{slug}/
/pages/{slug}/
/author/{username}/
/profile.php?user={username}
/account.php
/comments.php
/search.php
/feed.xml
/sitemap.xml
/robots.txt
```

## Admin Areas

Common admin areas include:

- Dashboard
- Stream Posts
- Pages
- Media
- Comments
- Appearance
- Settings
- Tools
- Account
- Users
- System Check
- Upgrade

## Bundled Theme

The release package includes **Midnight Ledger** as the default first-party public theme.

Additional themes can be installed later through the theme installer. Themes are trusted PHP code and should only be installed from sources you control.

## Security

Bonumark Stream uses:

- sessions
- CSRF checks
- role-based permissions
- upload validation
- login rate limiting
- private storage protections
- explicit admin route capabilities
- system checks

The private `_bonumark_stream/` directory must not be publicly browseable.

Apache `.htaccess` denial files are bundled, but Nginx, IIS, and custom hosting panels must be configured with equivalent denial rules.

For security notes, see:

- [`SECURITY.md`](SECURITY.md)

## Project Status

Bonumark Stream is pre-1.0 software.

The v0.3.x line is the first public GitHub release line and the current clean database-first baseline. It is suitable for testing, demos, development, and early self-hosted use, but you should still keep backups before running it in production.

## License

Bonumark Stream is free and open-source software licensed under:

`AGPL-3.0-or-later`

See [`LICENSE`](LICENSE) for the full license text.

## Repository

GitHub repository:

[https://github.com/jimlunsford/bonumarkstream](https://github.com/jimlunsford/bonumarkstream)

## Changelog

For release history, see:

- [`_bonumark_stream/CHANGELOG.md`](_bonumark_stream/CHANGELOG.md)

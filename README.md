# Bonumark Stream

**Bonumark Stream is a self-hosted microblog CMS for publishing short-form posts on a site you control.**

It is built for people who want the speed and simplicity of a personal stream without handing their words, media, and history to a platform they do not own.

- Homepage: https://bonumark.org
- Demo: https://demo.bonumark.org
- Repository: https://github.com/jimlunsford/bonumarkstream
- Current version: **0.4.5**

## What Bonumark Stream is

Bonumark Stream is a lightweight PHP/MySQL publishing system for short-form posts, pages, media, comments, profiles, likes, feeds, imports, exports, and code-free presentation themes.

It is not a social network. It is not a multi-author publishing platform. It is not a WordPress theme.

Bonumark Stream is for running your own microblog on your own site.

## Current foundation

Bonumark Stream v0.4.x is a clean-break public foundation.

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

## Important upgrade notice

Bonumark Stream v0.4.x is a clean-break release line.

Direct upgrades from older development packages, including v0.1.x, v0.2.x, and v0.3.x, are not supported.

If you are using an older development package, install Bonumark Stream v0.4.x as a fresh installation.

The built-in upgrader is intended for Bonumark Stream v0.4.0 and newer going forward.

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

The Admin can publish from the admin area and manage the stream through the included dashboard and editor tools.

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

For v0.1.x, v0.2.x, or v0.3.x packages, use a fresh v0.4.x install.

## Project status

Bonumark Stream is under active development.

The v0.4.x line is the clean public foundation. APIs, internals, theme structure, and installer behavior may still change before a stable 1.0 release.

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

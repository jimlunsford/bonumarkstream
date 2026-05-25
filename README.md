# Bonumark Stream

Bonumark Stream is a self-hosted PHP and MySQL microblog CMS for owning short-form publishing on your own site.

Current version: **0.3.2**

This is the **Mobile Public Page Containment Repair Pass**. It keeps the v0.3.x public baseline intact while repairing mobile public page width containment so pages cannot force horizontal scrolling on phones.

## Important install note

Bonumark Stream v0.3.2 is intended as the public **fresh install baseline** and current upgrade target from the stable v0.2.x database-first line. Do not use it as an upgrade bridge from the old v0.1.x development line unless you are intentionally testing upgrade failure paths. For the clean foundation, install v0.3.2 fresh.

## Quick start

1. Upload the package contents to a PHP/MySQL hosting account.
2. Visit `install.php` in your browser.
3. Enter database settings and create the first administrator account.
4. Remove or lock the installer when setup is complete.
5. Sign in at `/admin/`.
6. Review Site Identity, Reading, Writing, Mail, Registration, Themes, and System Check.
7. Create a test post, upload a test media item, and use Tools > Export > Static Site Export only if you want a portable HTML copy.

For more detail, see:

- `docs/INSTALL.md`
- `docs/UPGRADING.md`
- `docs/ARCHITECTURE.md`
- `docs/THEMING.md`
- `docs/IMPORTERS.md`


## XML sitemap

Bonumark Stream publishes a dynamic XML sitemap at `/sitemap.xml` when sitemap support is enabled. It also serves `/robots.txt` with a `Sitemap:` reference.

The sitemap includes the homepage, stream archive, published stream posts, and published pages by default. It excludes search pages, account pages, admin pages, drafts, trash, pending content, and noindex content. Public profile URLs can be added from Reading Settings when you want profile pages indexed. The XML references `/sitemap.xsl`, and the transformed browser view loads `/assets/sitemap.css` so humans see a Bonumark-styled URL table while crawlers still receive standard sitemap XML.

## Repository status

Bonumark Stream is pre-1.0 software. The v0.3.2 line is the current clean database-first public GitHub release baseline. The software is free and open-source under `AGPL-3.0-or-later`. See `LICENSE` for the full terms before redistributing, modifying, hosting, or building services with this code.

## Bundled theme note

The shared-hosting release package bundles **Midnight Ledger** as the default first-party public theme. Optional themes can be installed later through the theme installer.

## Architecture

Bonumark Stream now follows a simple foundation:

```text
Database = live source of truth
Dynamic PHP rendering = normal public site
Markdown = import/export ownership format
Static HTML = optional downloadable site export artifact
```

The live site does not depend on generated HTML. Public routes render from database content records by default, and Static Site Export writes to temporary export folders before producing a ZIP.

## What it does

Bonumark Stream provides:

- database-first Stream Posts and Pages
- dynamic public rendering
- public themes and theme settings
- media uploads and media records
- comments and moderation
- public likes
- public profiles and account dashboards
- controlled multiuser publishing
- registration, invite, approval, and password recovery tools
- mail delivery settings and test email support
- content import tools
- admin dashboard overview with site snapshot, attention items, and system status
- Markdown export
- Static Site Export for a portable downloadable HTML copy
- admin tools, system checks, and future package upgrade support

Core handles data, authentication, permissions, publishing, imports, exports, admin behavior, and dynamic routing. Themes handle public presentation only.

## Requirements

- PHP 8.2 or newer
- PDO MySQL driver
- MySQL or MariaDB
- ZipArchive extension for exports, admin upgrades, and theme ZIP uploads
- cURL recommended for safe link previews and remote media imports
- GD or Imagick recommended for image metadata, avatar optimization, and upload image derivative generation
- Writable private directories under `_bonumark_stream/`

## Public routes

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
```

Author routes render the public author/profile surface for the requested user. Profile query routes remain available for direct profile links and account workflows.

## Admin areas

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
- System Check

## Importing and exporting

Bonumark Stream supports imports from Markdown, generic JSON, WordPress WXR/XML, Bonumark Stream export ZIPs, Twitter/X archive ZIPs, and Bluesky/AT Protocol CAR archives.

Exports are split by purpose:

- Markdown export for readable content ownership
- full private backup/export for system data, media, and all Bonumark Stream database tables
- Static Site Export for portable generated HTML

Database and full exports are private backups. They may include password hashes, emails, account metadata, reset/invite records, moderation logs, and other sensitive data. Do not publish or share those ZIP files.

## Pages

Pages are first-class database content records intended for stable public content such as About, Contact, Privacy, coaching, speaking, services, or links. They render dynamically under `/pages/{slug}/` and use page templates from the active public theme.

## Multiuser publishing

Administrators can choose whether standard User accounts publish directly or submit drafts for review. Administrators can always publish. User-submitted drafts can be managed through the Review Queue.

## Security model

Bonumark Stream uses sessions, CSRF checks, role-based permissions, upload validation, private storage protections, system checks, login rate limiting, and explicit admin route capabilities. The private `_bonumark_stream/` directory must not be publicly browseable. Apache `.htaccess` denial files are bundled, but Nginx, IIS, and custom control panels must be configured with equivalent denial rules. Themes are trusted PHP code and should only be installed from sources you control.

## Current release

**0.3.2, Mobile Public Page Containment Repair Pass** repairs mobile public page width containment so page cards, masthead text, headings, and rich page content stay inside the phone viewport.

**0.3.1, Public Rendering Regression Repair Pass** repairs public page Markdown presentation and desktop link previews without images while preserving the v0.3.x database-first public GitHub baseline.


**0.2.58, Public Navigation Account Links Toggle Pass** adds an admin toggle for automatic account links in the public navigation menu. The links stay on by default, but admins can turn them off and manage every menu item manually.

**0.2.51, Upgrade Screen Simplification Pass** keeps the admin Upgrade screen focused on the essential upload/check/run workflow and moves technical upgrade details into collapsible sections.

**0.2.50, Admin Autofill Input Color Repair Pass** keeps Chrome, Edge, and mobile browser autofilled admin inputs in Bonumark's dark form style instead of letting saved credentials force a pale field background.

**0.2.49, Upgrade Action Button Alignment Pass** aligns the Run Upgrade and Cancel buttons in the admin upgrader so they sit on the same baseline when a package is ready to run.

**0.2.48, Admin Form Input Style Repair Pass** repairs admin form fields that could fall back to browser-default styling when an input omitted an explicit `type` attribute, and normalizes the Users screen Add Account fields.

**0.2.47, Mobile Text Overflow Repair Pass** repairs mobile text containment in the bundled Midnight Ledger theme so stream cards, single post pages, page content, and rich body content wrap inside the viewport instead of clipping on the right edge.

**0.2.46, Public Release Audit Repair Pass** hardens failed-upgrade rollback so newly copied package files are removed during restore, removes stale managed `.github` upgrade handling, tightens safe remote fetch behavior for link previews and imported media, normalizes public route error messages, adds database smoke-test coverage, clarifies trusted theme and server protection requirements, and updates release metadata.

**0.2.45, Admin Dashboard Column Flow Polish Pass** corrects the dashboard body by replacing row-locked dashboard sections with independent stacked columns, removing the large dead spaces between uneven cards.


**0.2.44, Admin Dashboard Layout Polish Pass** aligned the lower cards with the dashboard rows and removed forced equal-height stretching, but was superseded by v0.2.45's independent column flow.

**0.2.42, Admin Dashboard Overview Pass** rebuilds the admin dashboard into a stronger site overview with publishing metrics, attention items, system status, recent activity, and faster action paths.

**0.2.40, Sitemap Presentation Polish Pass** improves the `/sitemap.xml` browser view with a dedicated external Bonumark stylesheet, responsive layout, URL type labels, and cleaner table presentation while keeping crawler-facing XML valid.

**0.2.39, Styled XML Sitemap Pass** adds a human-readable XSL stylesheet for sitemap.xml, improves robots.txt spacing, and includes sitemap.xsl in Static Site Export.

**0.2.38, XML Sitemap Pass** adds dynamic XML sitemap and robots.txt discovery routes, Reading settings for sitemap inclusion, and sitemap/robots output for Static Site Export.

**0.2.37, Public Release Polish and Upgrade Cleanup Pass** cleans stale release documentation, removes a duplicate stream-like selector, and adds targeted retired bundled theme cleanup for pre-release development upgrades while preserving custom installed themes.

**0.2.36, Public Release Cleanup and Theme Separation Pass** removes the optional Microblog Stream theme from the shared-hosting package, keeps Midnight Ledger as the bundled public theme, hardens remote media import URL validation, fixes public edit-link permission checks, and cleans stale theme-era class names.

**0.2.35, Media Edit Button Alignment Hotfix** restores consistent Media Edit action button colors and removes the inherited top margin that pushed Save Media lower than Copy Markdown and Copy URL.

**0.2.32, LCP Image Priority Correction Pass** prioritizes the first actual image in the rendered stream card DOM instead of assuming the first card media attachment is the LCP image. It leaves image URLs, responsive `srcset`, derivatives, compression, and format handling unchanged.

**0.2.31, Stream Card Avatar Variant Correction Pass** ensures compact stream-card, comment, and admin-header avatar contexts use the 96w avatar variant when available or generate it safely from the original avatar. It keeps 192w avatars for larger profile/account displays and does not change normal post image handling.

**0.2.30, Avatar Size Selection Cleanup Pass** uses compact 96w avatar variants in stream cards and reserves 192w avatar variants for larger profile and account header displays. It does not change normal post image handling.

**0.2.29, Existing Media Regeneration Tool Pass** added a manual, batched media optimization screen so existing uploaded images can regenerate optimized variants safely on shared hosting. Public responsive output still only uses variants that exist and are verified.

**0.2.25, Avatar Optimization Pass** uses small generated avatar variants when available while preserving original avatar files and leaving normal stream media output unchanged.

**0.2.22, Footer Link and Navigation Cleanup Pass** removes public navigation item output from bundled footers and adds safe link support for custom footer text.

**0.2.19, Public Profile Social Links Pass** adds optional social/profile links to user profiles and displays filled links as public profile pills beside Website.

**0.2.12, Navigation Manager Rebuild Pass** moves public menu control into Admin → Navigation, makes public navigation optional, uses Home as the only default item, lets users add pages and custom links manually, removes page-level menu controls, replaces typed order numbers with Move up and Move down controls, and removes the small visible item limit.

**0.2.11, Page Title Spacing and Updated Date Default Pass** tightens public Page title spacing in both bundled themes and makes the visible Page updated date setting off by default.

**0.2.10, Midnight Ledger Avatar Alignment Pass** fixes fallback user initials so they center correctly inside Midnight Ledger avatar circles on stream cards, comments, profiles, and account views.

**0.2.9, Public Release Audit Repair** fixes the follow-up public-release audit findings before release: declared theme template installation, upgrade cleanup of obsolete package-managed files, no-migration upgrade history, safer public like errors, and stale editor wording.

**0.2.5, Preinstall Version Alignment Repair** fixes stale version and baseline references found during the final pre-install audit. The default config, fresh-install seed path, migration settings, docs, and issue template now align with the packaged release.

**0.2.4, Dynamic Feed Route Repair** fixes RSS feed routing after the dynamic-rendering and static-export isolation reset. Live RSS feeds now render dynamically from database content instead of relying on generated `feed.xml` files.

**v0.2.3, Static Export Isolation Repair**

- Kept that release in the fresh-install database-first foundation line.
- Repairs the Bonumark export importer so v0.2.x Markdown export ZIPs restore from `markdown/posts` and `markdown/pages`.
- Adds import item content-type support so stream posts and pages can be restored accurately from Bonumark export packages.
- Removes stale static-cache wording discovered during the stricter pre-install audit.
- Keeps dynamic database rendering as the live site path.
- Keeps Markdown as an import/export ownership format only.
- Keeps Static Site Export as a Tools-only artifact.
- Updates README, architecture, upgrading, package metadata, version files, changelog, and release manifest.


### Optimized image variants

Bonumark Stream can attempt to create optimized image variants on hosts with GD or Imagick. Media Edit shows variant status and a one-item refresh action, with server support checks tucked into troubleshooting details. Public rendering keeps the original image as the fallback `src` and only adds responsive `srcset` output when verified generated variants exist on disk.

### Media optimization

Bonumark Stream can generate optimized image variants for uploaded images when the host provides GD or Imagick support. Existing images can be processed manually from the Media optimization screen in small batches so shared-hosting installs are not overloaded.

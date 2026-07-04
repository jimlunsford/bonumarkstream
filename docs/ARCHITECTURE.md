# Bonumark Stream Architecture

Bonumark Stream is a dynamic database-first microblog CMS.

## Source of truth

The database is the source of truth for posts, pages, accounts, profiles, comments, media records, settings, likes, drafts, trash, revisions, and registration data. The account model has two types: Admin, the sole publisher and site manager, and Commenter, for comment participation and profile/account features.

Markdown exists for import, export, backup, and portability. Runtime rendering does not depend on Markdown files as fallback storage.

## Request flow

- `/` renders the stream home.
- `/stream/` is a supported alias.
- Clean public routes are sent to `index.php`.
- Admin routes live under `/admin/`.
- Public rendering is dynamic.

## Theme boundary

Bonumark Stream core owns routing, data preparation, permissions, database writes, rendering execution, forms, comments, media, and imports.

Theme packages are presentation-only. A theme can provide metadata, settings, screenshots, CSS, images, fonts, and documentation. It cannot provide PHP, JavaScript, HTML files, route handlers, database writes, permission logic, server config files, or application behavior.

The bundled Midnight Ledger presentation theme is the reference package. Bonumark Stream core renders the public site; themes supply presentation assets and settings only.

## Pinned posts

Pinned posts are core behavior, not theme behavior. Core stores pin state on published stream records, orders the pinned group by `pinned_at` descending, renders the dedicated homepage pinned area, and removes those same records from the regular page-one timeline. Core also provides authorized front-end post actions through one compact three-dot menu. Themes receive the already-rendered core markup and only add presentation CSS.

Pinning does not alter original publish time, public URLs, RSS/feed order, sitemap output, search results, normal archive ordering, static export output, or Remote Posting API behavior. A post is pin-eligible only while it is a published stream post. Moving it to draft, scheduled, or trash clears pin state.

## Static export

Static Site Export is optional portability/deployment tooling. It does not replace dynamic database-first operation.

## Scheduled publishing

Scheduled publishing is core behavior, not theme behavior. Scheduled records use their own `scheduled` status and UTC `scheduled_at` value. Public queries, feeds, sitemap, search, static export, and single-post routing receive published records only, so scheduled posts are not exposed before they are due.

The persisted General Settings timezone controls PHP runtime date formatting and local authoring defaults. Database session timestamps are canonical UTC, and public timestamp rendering converts them explicitly back to the saved site timezone.

Scheduled work now runs through one core task runner with a global lock. The runner can be invoked by server cron, protected web cron, manual admin execution, safe public traffic, or signed-in browser heartbeats. Server cron is the recommended dependable path. Public traffic and browser heartbeats are configurable fallbacks, not replacements for cron. The runner records health state and manual/cron history, and provides the base for future features that need scheduled execution without adding theme responsibilities.

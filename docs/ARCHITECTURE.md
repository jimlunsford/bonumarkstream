# Bonumark Stream Architecture

Bonumark Stream v0.4.5 is a dynamic database-first microblog CMS.

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

## Static export

Static Site Export is optional portability/deployment tooling. It does not replace dynamic database-first operation.

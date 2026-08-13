# Midnight Ledger

Midnight Ledger is the bundled reference theme for Bonumark Stream.

To make your own theme, copy this folder, rename it, update `theme.json`, replace `assets/images/screenshot.svg`, edit `assets/css/theme.css`, zip the folder, and upload it from Appearance.

Themes are presentation only. They may include metadata, settings, CSS, images, fonts, screenshots, and docs. They may not include PHP, JavaScript, HTML files, routes, database writes, or application logic.

## Photo galleries

Bonumark Stream core renders one-to-four-photo galleries and provides the performance and accessibility behavior. Midnight Ledger styles the documented `.stream-media-gallery*` classes. Custom themes may override the same classes and `--bms-media-gallery-*` variables with CSS only.
## Profile photos

Profile photos are core identity data. Core supplies ordered semantic `.profile-photo-*` markup, responsive image behavior, accessibility text, full-image viewing, and a usable fallback grid for one to four photos. Midnight Ledger only styles that contract. Custom themes may present the same Profile photo data differently without adding PHP, JavaScript, routes, or storage logic.

## Declarative composition

Midnight Ledger uses Layout Schema 1 for all four current public declarative surfaces: `profile`, `stream-card`, `site-header`, and `home`. Its Home composition is intentionally publish-first, using core notices, the atomic composer, then pinned posts, feed, and pagination. Third-party themes may still adopt supported surfaces independently; declarative layouts remain opt-in per surface.

The private layout files are `layouts/profile.json` and `layouts/stream-card.json`. Themes remain code-free, and core still owns every component renderer and all application behavior.


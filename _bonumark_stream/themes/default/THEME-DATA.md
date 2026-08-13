# Midnight Ledger Theme Data

This folder is the theme package. Bonumark Stream core renders the public pages. This theme supplies the manifest, settings, screenshot, and CSS.

Start with `theme.json`, then edit the design tokens at the top of `assets/css/theme.css`.

## Photo galleries

Midnight Ledger intentionally supports the core one-to-four-photo gallery contract. Core supplies the markup, responsive image variants, accessibility, loading behavior, and fallback layout. This theme adjusts spacing, radius, aspect ratio, and crop behavior through the documented `.stream-media-gallery*` classes and `--bms-media-gallery-*` variables.

Custom themes do not need to copy application logic. Older themes inherit the core fallback layout automatically.

## Profile featured work

Featured work is core Profile data. Core resolves up to four deliberate featured items into public URLs, titles, and optional descriptions. Midnight Ledger only styles the semantic `.profile-featured-*` markup. Custom themes are free to present the same items as cards, rows, buttons, or another accessible treatment without changing Profile storage or behavior.
## Profile photos

Profile photos are core identity data. Core supplies ordered semantic `.profile-photo-*` markup, responsive image behavior, accessibility text, full-image viewing, and a usable fallback grid for one to four photos. Midnight Ledger only styles that contract. Custom themes may present the same Profile photo data differently without adding PHP, JavaScript, routes, or storage logic.


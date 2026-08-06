# Midnight Ledger Theme Data

This folder is the theme package. Bonumark Stream core renders the public pages. This theme supplies the manifest, settings, screenshot, and CSS.

Start with `theme.json`, then edit the design tokens at the top of `assets/css/theme.css`.

## Photo galleries

Midnight Ledger intentionally supports the core one-to-four-photo gallery contract. Core supplies the markup, responsive image variants, accessibility, loading behavior, and fallback layout. This theme adjusts spacing, radius, aspect ratio, and crop behavior through the documented `.stream-media-gallery*` classes and `--bms-media-gallery-*` variables.

Custom themes do not need to copy application logic. Older themes inherit the core fallback layout automatically.

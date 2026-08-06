# Bonumark Stream Themes

Bonumark Stream uses Midnight Ledger as the working example for code-free presentation themes.


## Copying Midnight Ledger

To create a theme, copy `_bonumark_stream/themes/default/`, rename the folder and slug, update `theme.json`, replace `assets/images/screenshot.svg`, edit the design tokens at the top of `assets/css/theme.css`, zip the folder, and upload it from Appearance.

## Rule

Core runs the code. Themes provide presentation.

Bonumark Stream core owns routing, data preparation, permissions, database writes, rendering execution, forms, comments, media, importers, feeds, sitemaps, static export, and upgrades.

A theme package may include:

- `theme.json`
- metadata
- supports declarations
- editable settings schema
- screenshot
- CSS assets
- image assets
- font assets
- documentation

A theme package may not include:

- PHP files
- JavaScript files
- HTML files
- route handlers
- database writes
- permission logic
- business logic
- server config files
- symlinks
- arbitrary executable code

## Rendering boundary

Themes do not provide public markup files or rendering logic. Bonumark Stream core renders the public site, and the active theme supplies presentation assets and settings.

Midnight Ledger is the reference package for the current code-free theme format. Copy it, rename it, update the manifest, and edit the CSS.

## Photo gallery presentation

Bonumark Stream core owns photo gallery storage, upload validation, responsive image attributes, accessibility, and public markup. A code-free theme only styles the stable gallery contract.

Posts may contain one to four gallery photos. Existing single-image posts continue to use the same featured media behavior. Core provides a usable responsive fallback layout even when a custom theme does not declare gallery support.

Core renders gallery markup with these stable classes:

```html
<div class="stream-card-media stream-media-gallery stream-media-gallery-count-3 stream-media-gallery-layout-trio" data-media-count="3" role="group">
  <a class="stream-media-gallery-item stream-media-gallery-item-1" data-stream-media-viewer>…</a>
  <a class="stream-media-gallery-item stream-media-gallery-item-2" data-stream-media-viewer>…</a>
  <a class="stream-media-gallery-item stream-media-gallery-item-3" data-stream-media-viewer>…</a>
</div>
```

Themes may style:

- `.stream-media-gallery`
- `.stream-media-gallery-count-1` through `.stream-media-gallery-count-4`
- `.stream-media-gallery-layout-single`
- `.stream-media-gallery-layout-pair`
- `.stream-media-gallery-layout-trio`
- `.stream-media-gallery-layout-grid`
- `.stream-media-gallery-item`
- `.stream-media-gallery-item-1` through `.stream-media-gallery-item-4`
- `.stream-media-gallery-image`

Core exposes these CSS custom properties as safe presentation hooks:

```css
:root {
  --bms-media-gallery-gap: 0.5rem;
  --bms-media-gallery-radius: 0.75rem;
  --bms-media-gallery-item-aspect-ratio: 1 / 1;
  --bms-media-gallery-feature-aspect-ratio: 16 / 9;
  --bms-media-gallery-object-fit: cover;
}
```

A theme may override those variables or the stable classes with CSS. It must not replace gallery markup, upload handling, image ordering, responsive sources, loading priorities, or permission logic.

Core also owns the full-size photo viewer. Image links carrying `data-stream-media-viewer` open in an accessible overlay with a close button, Escape-key support, and previous/next controls for galleries. Theme packages should not remove that attribute or replace the viewer behavior. The viewer CSS lives in core so older themes receive the fix automatically.

The optional `supports.media_galleries` declaration documents that the theme intentionally styles galleries:

```json
{
  "supports": {
    "media_galleries": true
  }
}
```

This declaration is informational. Older themes remain compatible and inherit the core fallback gallery layout.

## Pinned-post presentation

Pinned-post queries, permissions, ordering, visibility, and duplicate prevention belong to Bonumark Stream core. Themes do not implement pinning logic.

When one or more posts are pinned, core places this stable markup inside the existing stream feed output on the homepage:

```html
<section class="stream-pinned-posts">
  <div class="stream-pinned-heading">
    <span class="stream-pinned-label">Pinned</span>
  </div>
  <div class="stream-pinned-feed">
    <article class="stream-card stream-card-pinned">…</article>
  </div>
</section>
```

Core includes usable fallback styling in `assets/style.css`. A theme may refine `.stream-pinned-posts`, `.stream-pinned-heading`, `.stream-pinned-label`, `.stream-pinned-feed`, and `.stream-card-pinned` with CSS only. Do not add a second pinned query, change public visibility rules, or duplicate pinned posts in a theme.

Authorized front-end controls are also core markup. The compact post options menu uses `.stream-post-actions-menu`, `.stream-post-actions-toggle`, `.stream-post-actions-popover`, and `.stream-post-action-item`. A theme may style those classes, but it must preserve one consistent action-item alignment for links and buttons and must not add its own Edit or Pin logic, permission checks, or pin form handling.

## Reference theme structure

```text
_bonumark_stream/themes/default/
  theme.json
  README.md
  THEME-DATA.md
  assets/
    css/theme.css
    images/screenshot.svg
```

## theme.json example

```json
{
  "name": "My Theme",
  "slug": "my-theme",
  "version": "1.0.0",
  "author": "Theme Author",
  "description": "A code-free Bonumark Stream presentation theme.",
  "screenshot": "assets/images/screenshot.svg",
  "assets": {
    "css": ["assets/css/theme.css"],
    "images": ["assets/images/screenshot.svg"]
  },
  "settings": {
    "accent": {
      "type": "select",
      "label": "Accent",
      "default": "blue",
      "options": {
        "blue": "Blue",
        "green": "Green"
      }
    }
  }
}
```

## Installation

Theme ZIP installation is enabled for code-free presentation themes. Upload one theme at a time from **Admin → Themes → Install Theme**.

Bonumark Stream validates the ZIP before installation and rejects packages with PHP, JavaScript, HTML files, server configuration files, symlinks, unsafe paths, missing declared assets, invalid manifests, or protected bundled slugs.

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

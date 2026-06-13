# Bonumark Stream Themes

Bonumark Stream v0.5.0 uses Midnight Ledger as the working example for code-free presentation themes.


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

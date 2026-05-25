# Bonumark Stream Public Themes

Bonumark Stream themes control public presentation only.

Core handles content, users, media, comments, publishing, feeds, security, admin screens, sessions, permissions, upgrades, database behavior, and prepared view data. Themes receive prepared data and decide how the public site looks.

A theme does **not** need to copy the bundled theme, Midnight Ledger, its header chips, card styling, spacing rhythm, button shapes, or any other visual pattern. The required files are rendering entry points, not design rules.

## Theme package structure

```text
_bonumark_stream/themes/{theme-slug}/
  theme.json
  README.md
  templates/
    layout.php
    header.php
    footer.php
    home.php
    archive.php
    single.php
    page.php
    profile.php
    account.php
    comments.php
    comments-mount.php
    search.php
    card.php
    link-preview.php
    media.php
    composer.php
    pagination.php
    empty.php

assets/themes/{theme-slug}/
  css/
  js/
  images/
```

The template names are Bonumark view hooks. For example, `card.php` renders one stream item, but it does not have to look like a card. A theme can render a timeline row, compact note, magazine item, photo block, or any other safe public structure.

## Activation validation

Bonumark validates a public theme before it can be activated. A theme must have:

- a readable `theme.json` file
- a manifest slug that matches the folder name
- required metadata: `name`, `slug`, `version`, `author`, and `description`
- all required templates listed above
- declared CSS and JavaScript assets that exist under `assets/themes/{theme-slug}/`
- a declared screenshot that exists when `screenshot` is set
- safe relative asset paths with no external URLs and no parent-directory traversal

Bonumark does not validate for a specific visual design. It does not require status chips, post-count chips, Midnight Ledger header structure, card borders, dark mode, a specific color palette, or any bundled-theme layout pattern.

The Themes screen and Theme Details screen show whether each theme is safe to activate and list missing files, warnings, metadata, templates, assets, settings, and support flags.

## Required manifest

```json
{
  "name": "Example Theme",
  "slug": "example-theme",
  "version": "1.0.0",
  "author": "Theme Author",
  "description": "A Bonumark Stream public theme.",
  "screenshot": "images/screenshot.svg",
  "assets": {
    "css": ["css/theme.css"],
    "js": ["js/theme.js"]
  },
  "supports": {
    "profiles": true,
    "comments": true,
    "avatars": true,
    "media": true,
    "navigation": true,
    "rss": true,
    "theme_settings": true
  },
  "settings": {
    "accent": {
      "type": "select",
      "label": "Accent",
      "description": "An optional design value used only by this theme.",
      "default": "neutral",
      "options": {
        "neutral": "Neutral",
        "warm": "Warm",
        "cool": "Cool"
      }
    },
    "footer_text": {
      "type": "text",
      "label": "Footer text",
      "default": "Published with Bonumark Stream"
    }
  },
  "templates": ["layout", "header", "footer", "home", "archive", "single", "page", "profile", "account", "comments", "comments-mount", "search", "card", "link-preview", "media", "composer", "pagination", "empty"]
}
```

## Theme settings rule

Theme-owned design values belong in `theme.json` settings and are stored per theme. A theme should only expose settings it actually supports in its own templates and assets.

Header chips, post-count labels, density controls, accent choices, footer text, profile layout choices, and similar fields are optional theme choices. They are not Bonumark requirements.

Bonumark core does not force a shared dark/light mode or any other shared design control onto every theme.

## Template data rule

Core prepares the data. Themes render it. Required public views are template-owned, not core-fallback-owned. If the active theme is missing a required template, Bonumark falls back to the bundled `default` theme for that view or throws a clear error when no safe template exists.

A theme template receives `$mp_theme_data`, an array of prepared values for that template. Templates should escape plain text before output and should only output trusted HTML fields that core intentionally prepared. Common trusted HTML fields include `body_html`, `tagline_html`, `avatar_html`, `media_html`, `header_html`, `footer_html`, `card_html`, `comments_html`, `items_html`, and `pagination_html`.

## JavaScript boundary

`assets/stream.js` is core public behavior. It handles safe endpoint interactions such as likes, comment loading, composer behavior, and shared stream controls.

Theme JavaScript under `assets/themes/{theme-slug}/js/` should handle presentation-only behavior such as theme-specific toggles, small visual enhancements, and theme-specific UI polish. It should not replace permissions, sessions, publishing, upload validation, comment moderation, or security behavior.

## Boundary rules

Themes should not touch or replace:

- database schema
- users, roles, or permissions
- publishing logic
- comment moderation logic
- upload validation
- login/session behavior
- security headers
- feed generation logic
- admin screens
- upgrade behavior

Themes may control:

- document layout
- public header and footer
- homepage stream
- archive pages
- single post pages
- profile pages
- account pages
- search page presentation
- comments display
- comments mount presentation
- stream item presentation
- media blocks
- composer markup
- pagination
- empty states
- public theme CSS and JavaScript

## Theme ZIP installation

Custom themes can be installed from **Appearance → Install Theme** in the admin.

The uploader accepts one theme per ZIP. A simple installable ZIP should contain:

```text
theme-slug/
  theme.json
  templates/
    layout.php
    header.php
    footer.php
    home.php
    archive.php
    single.php
    page.php
    profile.php
    account.php
    comments.php
    comments-mount.php
    search.php
    card.php
    link-preview.php
    media.php
    composer.php
    pagination.php
    empty.php
  assets/
    css/theme.css
    js/theme.js
    images/screenshot.svg
  README.md
```

During install, Bonumark copies private theme files into `_bonumark_stream/themes/{theme-slug}/`, copies every declared template from `theme.json`, and copies declared public assets into `assets/themes/{theme-slug}/`.

The uploader rejects unsafe packages before activation. It blocks parent-directory traversal, absolute paths, symbolic links, unsupported file types, PHP files outside `templates/`, missing required templates, missing declared assets, invalid manifests, and attempts to replace the bundled `default` theme.

Existing custom themes can be replaced only when the replacement checkbox is selected and the uploaded package uses the same slug.

## Theme management lifecycle

Admin users manage public themes from **Appearance → Themes**. The manager supports the complete Bonumark theme lifecycle:

- inspect installed themes
- view screenshots and metadata
- check required templates
- check declared assets
- check declared settings
- review validation warnings and errors
- activate only valid themes
- configure the active theme
- install custom theme ZIPs
- delete removable custom themes

The bundled `default` theme cannot be deleted. The active theme is protected from deletion. Optional installed themes can be removed when they are not active. Custom themes must validate before activation.

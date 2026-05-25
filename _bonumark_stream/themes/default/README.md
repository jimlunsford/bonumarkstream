# Midnight Ledger

Midnight Ledger is the default public theme for Bonumark Stream and the reference theme for people who want to build their own public theme.

It is a restrained dark editorial design for readable short-form publishing, stable pages, public profiles, comments, account views, search, and navigation.

## What this theme demonstrates

Midnight Ledger shows the expected Bonumark theme pattern:

- `theme.json` declares metadata, supported features, settings, assets, and templates.
- templates receive prepared `$mp_theme_data` from core.
- templates render HTML only.
- public CSS and JavaScript live in `assets/themes/default/`.
- theme-owned classes use the `ledger-*` prefix.
- stream-facing classes use Bonumark Stream naming so the default theme is not tied to an old theme-specific class vocabulary.
- `data-*` attributes are treated as core behavior hooks and should not be renamed casually.

## File layout

```text
_bonumark_stream/themes/default/
  theme.json
  README.md
  THEME-DATA.md
  templates/
    _helpers.php
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
    media.php
    composer.php
    pagination.php
    empty.php

assets/themes/default/
  css/midnight-ledger.css
  js/midnight-ledger.js
  images/screenshot.svg
```


## Typography pattern

Midnight Ledger intentionally uses a portable system font stack so the default theme works on ordinary shared hosting without external font requests. The primary UI and display stack is exposed through `--ledger-font` and `--ledger-display`. The public site title, page headings, profile headings, search headings, account headings, and empty states use the display stack so the theme feels like one coherent interface. The site title keeps mild negative tracking only, because overly tight tracking made shorter site names look cramped.

The serif variable remains available as `--ledger-serif` for selective editorial accents, but the site title no longer uses the serif stack because it looked visually disconnected from the rest of the public interface on common desktop systems.

## Layout pattern

`layout.php` is the generic layout fallback. Most main public views use their own full-page template because each view has different SEO, Open Graph, feed, and main-content needs.

To reduce repeated setup, Midnight Ledger uses `templates/_helpers.php` for common escaping, body classes, document head output, and shell opening/closing.

A custom theme can follow either pattern:

1. Use a shared helper or layout file for repeated document structure.
2. Render full-page templates directly when that is clearer.

The important rule is consistency. Do not put publishing logic, database access, user permissions, or moderation logic inside the theme.

## Theme-owned vs core-owned

Theme-owned:

- visual HTML structure
- CSS class names such as `ledger-*`
- spacing, typography, colors, borders, and panel layout
- optional header chips and presentation choices
- page, profile, account, search, and empty-state styling

Core-owned:

- routing
- prepared data arrays
- publishing and revisions
- users, roles, sessions, and permissions
- media validation and uploads
- comments moderation
- likes and interaction endpoints
- feeds
- static generation
- upgrades and migrations

Compatibility hooks:

- `data-stream-card`
- `data-stream-url`
- `data-stream-like`
- `data-stream-form`
- `data-stream-body`
- `data-stream-menu-toggle`
- `data-comment-form`
- other `data-*` attributes passed by core templates

Do not rename those hooks unless core JavaScript is updated too.

## Required templates

Bonumark currently expects these templates:

- `layout`
- `header`
- `footer`
- `home`
- `archive`
- `single`
- `page`
- `profile`
- `account`
- `comments`
- `comments-mount`
- `search`
- `card`
- `link-preview`
- `media`
- `composer`
- `pagination`
- `empty`

## Theme settings

Midnight Ledger includes these settings as examples:

- accent
- density
- content width
- header status chip
- header status label
- post count chip
- menu button label

These are not required Bonumark settings. They belong to this theme.

## Boundary

Midnight Ledger only controls public presentation. It does not touch publishing, users, roles, uploads, comments moderation, feeds, security, sessions, database schema, or upgrades.

## Link previews

Midnight Ledger includes `templates/link-preview.php` as the reference implementation for Stream Post link cards. Core extracts and stores preview metadata with the database-first content record when a user chooses to keep a preview from the front-end composer. The theme only renders that prepared data.

Custom themes should not fetch remote preview data themselves. Keep network fetching in core, keep templates focused on presentation, and treat `data-*` attributes used by the composer as behavior hooks rather than decoration.

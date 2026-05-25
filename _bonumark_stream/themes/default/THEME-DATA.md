# Midnight Ledger Theme Data Guide

Bonumark core prepares `$mp_theme_data` before rendering a template. Themes should read from that array and output HTML.

This guide is intentionally small. It documents the common data shape a custom theme author will see while studying Midnight Ledger.

## Common keys

Most full-page templates may receive:

- `title`
- `site_name`
- `description`
- `canonical`
- `body_class`
- `style_url`
- `script_url`
- `theme_stylesheet_links`
- `theme_script_tags`
- `theme_settings`
- `header_html`
- `footer_html`

## Stream views

`home.php`, `archive.php`, `single.php`, and `search.php` may receive:

- `composer_html`
- `items_html`
- `pagination_html`
- `card_html`
- `comments_html`
- `feed_title`
- `feed_url`

`card.php` receives prepared stream-card data such as:

- `classes`
- `page_url`
- `body_html`
- `media_html`
- `author_name`
- `author_profile_url`
- `avatar_html`
- `date_iso`
- `date_label`
- `like`
- `comments`
- `edit_url`

## Pages

`page.php` receives:

- `page`
- `page_title`
- `body_html`
- `edit_url`
- `robots_meta`

Pages are stable site content. They should display their title publicly. Stream posts should not display generated SEO/admin titles publicly.

## Profiles

`profile.php` may receive:

- `user`
- `display_name`
- `username`
- `bio`
- `website`
- `profile_links`
- `avatar_markup`
- `post_count`
- `comment_count`
- `member_since`
- `profile_url`
- `recent_posts`

## Account and comments

`account.php` and `comments.php` receive form URLs, CSRF tokens, account data, and prepared comment data. Themes can render the forms, but core handles validation, permissions, moderation, and storage.

## Safety rule

Treat `$mp_theme_data` as prepared display data. Escape plain text with `htmlspecialchars()` or the Midnight Ledger helper `ml_h()`. Only output trusted prepared HTML keys such as `header_html`, `footer_html`, `items_html`, `body_html`, `card_html`, `comments_html`, and `avatar_markup` where core has already prepared the markup.

## Link preview template data

The optional `link-preview.php` template receives metadata prepared by core. Themes should only render the provided data. They should not fetch remote URLs directly.

Common keys:

- `url`, validated `http://` or `https://` URL
- `title`, preview title
- `description`, preview description
- `image`, optional preview image URL
- `site_name`, optional source label
- `page`, original Stream Post array

Required behavior hooks:

- Keep the outer link as a normal anchor to `url`.
- Escape all text values.
- Use `rel="noopener nofollow"` or stricter when opening external links.
- Do not perform network requests inside a theme template.

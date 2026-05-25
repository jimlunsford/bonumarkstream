# Bonumark Stream Theming

Bonumark Stream separates public presentation from core application behavior. Themes control the public-facing experience. Core controls data, authentication, publishing, imports, upgrades, and admin behavior.

## Theme location

Bundled and installed themes live under:

```text
_bonumark_stream/themes/{theme-name}/
```

Theme assets may be served from:

```text
assets/themes/{theme-name}/
```

## Required theme files

A public theme should include a `theme.json` manifest and the templates required by the current theme system. Current required templates are:

- `layout.php`
- `header.php`
- `footer.php`
- `home.php`
- `archive.php`
- `single.php`
- `page.php`
- `profile.php`
- `account.php`
- `comments.php`
- `comments-mount.php`
- `search.php`
- `card.php`
- `link-preview.php`
- `media.php`
- `composer.php`
- `pagination.php`
- `empty.php`

## Theme manifest

The manifest identifies the theme, version, templates, assets, screenshot, supported features, and optional settings. The theme installer validates manifests, copies every declared template, and refuses activation when required templates or declared assets are missing.

## Navigation

Themes render navigation only when core provides navigation data. They should not create forced Home, RSS, page, sign-in, registration, dashboard, profile, or sign-out links on their own. Admin → Navigation controls whether public header navigation is displayed and which custom items appear. When public navigation is enabled, core can append account-aware links for the current visitor state. Admin → Navigation includes a separate toggle for those automatic account links. Public footers do not render the navigation menu. Site Identity tagline and footer text links are sanitized by core and provided to bundled templates as trusted HTML.

## Trusted theme code

Bonumark Stream themes are PHP templates. Treat every installed theme as trusted server-side code, not as untrusted user content. Only administrators should install themes, and themes should come from sources you control or have reviewed. Manifest validation confirms required files and declared assets, but it does not sandbox PHP.

## Theme rules

Themes should:

- escape public output
- avoid database writes
- avoid authentication logic
- avoid admin-only behavior
- use data provided by core
- keep public layout and public assets theme-owned

Themes should not:

- replace core routes
- bypass core permission checks
- execute arbitrary uploaded PHP outside approved templates
- assume a specific install path
- depend on private runtime files

## Bundled themes

Bonumark Stream currently ships with the default first-party public theme, Midnight Ledger. Optional themes can be installed from validated theme ZIP packages and are not bundled into the shared-hosting release package.


## Bundled Page date setting

Bundled themes may expose `show_page_updated_date` as a checkbox setting. The bundled default is off. When enabled, public Page templates display the updated date under the Page title. When disabled, the public Page keeps the same title, body, SEO metadata, canonical URL, and Open Graph data, but hides the visible updated date line.

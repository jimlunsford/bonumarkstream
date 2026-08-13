# Bonumark Stream Themes

Bonumark Stream uses **Midnight Ledger** as the bundled/default reference for code-free presentation themes.

## Core rule

Core runs the application. Themes provide presentation and validated composition.

Core owns routing, data preparation, permissions, database access, publishing, forms, comments, likes, media processing, Profiles, feeds, sitemaps, imports, exports, static export, scheduled work, APIs, SEO, accessibility semantics, and upgrades.

A theme package may include:

- `theme.json`
- Metadata
- Supports declarations
- Editable settings schema
- Screenshot
- CSS assets
- Image assets
- Font assets
- Documentation
- Private declarative Layout Schema JSON for supported surfaces

A theme package may not include:

- PHP
- JavaScript
- HTML templates
- SQL
- Route handlers
- Database writes
- Permission logic
- Business logic
- Callbacks or expressions
- Server configuration files
- Symlinks
- Arbitrary executable code

## Copying Midnight Ledger

To create a theme:

1. Copy `_bonumark_stream/themes/default/`.
2. Rename the folder and theme slug.
3. Update `theme.json` metadata and version.
4. Replace `assets/images/screenshot.svg`.
5. Edit the design tokens and presentation rules in `assets/css/theme.css`.
6. Adjust or remove declarative layouts as needed.
7. Zip the theme folder.
8. Upload it through **Admin > Appearance**.
9. Run Theme Health before activation.

## Rendering boundary

Themes do not provide public markup files or rendering logic. Bonumark Stream core renders the site and the active theme supplies presentation assets, settings, and optional validated composition.

Theme CSS should use stable public hooks instead of depending on accidental DOM ancestry.

## Theme manifest

A typical theme manifest can declare metadata, assets, settings, supports, and optional Layout Schema surfaces.

Example:

```json
{
  "name": "Example Theme",
  "slug": "example-theme",
  "version": "1.0.0",
  "layout_schema": 1,
  "layouts": {
    "profile": "layouts/profile.json",
    "stream-card": "layouts/stream-card.json",
    "site-header": "layouts/site-header.json",
    "home": "layouts/home.json"
  }
}
```

Only declared private layout files are installed. Layout JSON is never copied into public theme assets.

## Declarative Layout Themes

Theme Architecture 2.0 lets a code-free theme arrange registered core components through Layout Schema 1.

The four supported v0.6.0 surfaces are:

- `profile`
- `stream-card`
- `site-header`
- `home`

Each layout contains only `group` and `component` nodes. The validator rejects unsupported properties, components, surfaces, paths, nesting, occurrence counts, and malformed documents.

There is no expression language. A theme cannot query data, inspect permissions, run conditionals, or recreate application behavior. Optional state is handled by the core component itself.

A theme may declare any supported subset. Older themes remain compatible because a surface without a declarative layout continues through the fixed legacy composition.

See [DECLARATIVE-LAYOUTS.md](DECLARATIVE-LAYOUTS.md) for the complete component registry and schema rules.

## Stable hooks

Declarative output provides stable hooks such as:

- `[data-bms-layout="profile"]`
- `[data-bms-layout="stream-card"]`
- `[data-bms-layout="site-header"]`
- `[data-bms-layout="home"]`
- `[data-bms-layout-group="..."]`
- `[data-bms-component="..."]`

Themes should allow grid/flex children to shrink with `min-width: 0`, constrain composed regions to the available width, and allow long user-controlled text to wrap.

## Theme Health

Theme Health validates installed theme metadata and every declared layout before activation.

A declared but missing, malformed, unsupported, or structurally invalid layout must fail validation. Bonumark Stream does not execute or interpret unvalidated theme input.

## Theme asset versioning

Theme assets use the theme manifest `version` as their cache revision. Bump the theme version whenever CSS, images, fonts, screenshots, or layout JSON changes.

## Profile presentation

Profile data, visibility, owner controls, Profile metadata, canonical URLs, social metadata, JSON-LD, media behavior, and component markup are core-owned.

A declarative theme can arrange the registered Profile components but cannot change Profile data access or application behavior.

Profile cover and photo-gallery image delivery is also core-owned. Themes control the visual slot and cropping presentation around the generated media contract.

## Stream Card presentation

Core owns the outer Stream Post `<article>`, prepared post data, Quick edit, likes, comments, Post Options, pin/trash/editor actions, media, interaction hooks, and accessibility.

Themes may arrange the registered Stream Card components and style them. Static Site Export and dynamic output use the same core Stream Card renderer.

## Site Header presentation

Core owns the semantic header shell, site identity data, navigation URLs, active state, authenticated account destinations, menu behavior, heading semantics, and accessibility.

A declarative theme may arrange site identity, primary navigation, optional menu toggle, and optional published Stream count components.

## Home presentation

Core owns the Home document shell and `<main>`.

The Home declarative surface consists of notices, the atomic composer, pinned posts, the feed/empty state, and pagination. Pinned and normal posts are already rendered through the active Stream Card composition before Home receives them.

## Photo gallery presentation

Photo galleries are core behavior, not theme behavior. A Stream Post may store an ordered `media_gallery` list containing one to four image paths. The first item is also stored as `featured_media` for compatibility with existing posts, feeds, metadata, and integrations.

Core owns upload validation, ordering, responsive derivatives, dimensions, `srcset`, `sizes`, loading priority, accessibility labels, and gallery markup. Core also owns the full-size photo viewer and its keyboard/click-outside behavior.

Themes style the stable `.stream-media-gallery*` contract and `--bms-media-gallery-*` variables. Older themes remain compatible through the core fallback gallery layout.

## Pinned posts

Pinned posts are core behavior. Core stores pin state, orders the pinned group, removes duplicates from the normal page-one timeline, and provides authorized post actions.

Themes receive rendered markup and style it. Pinning does not change original publish time, URLs, RSS/feed order, sitemap output, search, archives, or static export.

## Scheduled publishing

Scheduled publishing is core behavior. Scheduled records remain outside public queries, feeds, sitemap, search, static export, and single-post routing until they are published.

The saved site timezone controls authoring/display while canonical database scheduling uses UTC. Scheduled work runs through the shared Scheduled Tasks runner.

## Static export

Static Site Export is optional portability/deployment tooling. It reuses normal core public rendering and does not replace dynamic database-first operation.

## Compatibility promise

Layout Schema 1 component identifiers documented in [DECLARATIVE-LAYOUTS.md](DECLARATIVE-LAYOUTS.md) are public theme-author APIs for the schema. Bonumark Stream may add new surfaces or components in later releases, but an existing valid theme is not required to adopt them. Undeclared surfaces continue through legacy fallback.

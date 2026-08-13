# Declarative Layout Themes

Bonumark Stream Theme Architecture 2.0 lets code-free themes control validated public composition while Bonumark Stream core keeps control of the application.

This document defines the stable Layout Schema 1 contract available in Bonumark Stream v0.6.0.

## Core rule

Core owns behavior, data, routing, authentication, security, permissions, forms, validation, database access, media processing, publishing, comments, likes, APIs, SEO/metadata behavior, accessibility semantics, and every component renderer.

Themes own presentation and validated composition only.

A declarative theme still may not contain PHP, JavaScript, arbitrary HTML, SQL, callbacks, expressions, routes, database access, permission logic, or application behavior.

## Manifest contract

A theme opts into one or more declarative surfaces in `theme.json`:

```json
{
  "layout_schema": 1,
  "layouts": {
    "profile": "layouts/profile.json",
    "stream-card": "layouts/stream-card.json",
    "site-header": "layouts/site-header.json",
    "home": "layouts/home.json"
  }
}
```

All layout files are private JSON documents stored under the theme's `layouts/` directory. Only files explicitly declared in `theme.json` are installed. Layout JSON is never copied to public theme assets.

A theme may declare any supported subset. A surface the theme does not declare continues through that surface's fixed legacy composition.

## Schema 1 nodes

Schema 1 supports only `group` and `component` nodes.

```json
{
  "surface": "home",
  "root": {
    "type": "group",
    "name": "example-home",
    "children": [
      {"type": "component", "name": "home.notices"},
      {"type": "component", "name": "home.composer"},
      {"type": "component", "name": "home.pinned-posts"},
      {"type": "component", "name": "home.feed"},
      {"type": "component", "name": "home.pagination"}
    ]
  }
}
```

Group names become stable presentation hooks through `data-bms-layout-group` and generated `bms-layout-group-*` classes. Component nodes receive stable `data-bms-component` hooks. Themes do not provide component markup.

The validator rejects unknown properties, unsupported surfaces, unregistered components, invalid layout paths, excessive nesting, excessive node counts, invalid group names, missing required components, and components that exceed their allowed occurrence count.

There is no expression language. Optional application state is handled inside core components. Empty components and empty nested groups collapse safely when core has nothing to render.

## Supported surfaces

### `profile`

All ten Profile components are required exactly once:

- `profile.cover`
- `profile.avatar`
- `profile.identity`
- `profile.about`
- `profile.featured`
- `profile.photos`
- `profile.now`
- `profile.interests`
- `profile.links`
- `profile.details`

Core owns Profile lookup, visibility, identity data, edit controls, media behavior, headings, accessibility, SEO/identity metadata, and component markup.

### `stream-card`

All seven Stream Card components are required exactly once:

- `stream-card.avatar`
- `stream-card.header`
- `stream-card.body`
- `stream-card.location`
- `stream-card.link-preview`
- `stream-card.media`
- `stream-card.actions`

Core owns the outer `<article>`, prepared post data, Quick edit, likes, comments, Post Options, pin/trash/editor actions, CSRF, media, interaction hooks, and accessibility. Static Site Export and dynamic output use the same card renderer.

### `site-header`

Required exactly once:

- `site-header.site-identity`
- `site-header.primary-navigation`

Optional, at most once:

- `site-header.menu-toggle`
- `site-header.stream-count`

Core owns the outer semantic `<header>`, the Home-versus-non-Home title heading choice, navigation records, URLs, active state, authenticated account destinations, navigation semantics, menu JavaScript, `aria-current`, and menu accessibility behavior.

A theme that omits `site-header.menu-toggle` receives always-present navigation. A theme that includes it receives Bonumark's existing collapsible-menu behavior. Themes do not implement that behavior themselves.

### `home`

All five Home components are required exactly once:

- `home.notices`
- `home.composer`
- `home.pinned-posts`
- `home.feed`
- `home.pagination`

Core owns the document shell and `<main>`. `home.composer` is the complete atomic publishing surface. `home.pinned-posts` and `home.feed` receive posts already rendered through the active `stream-card` composition. `home.feed` also owns the core empty state, so themes do not need conditionals. Pagination and Load More behavior remain core-owned.

## Composition nesting

Declarative surfaces may contain output from another surface only when core intentionally composes them that way.

The important current example is Home:

```text
home
├── home.notices
├── home.composer
├── home.pinned-posts
│   └── stream-card × N
├── home.feed
│   └── stream-card × N, or the core empty state
└── home.pagination
```

The Home layout never receives raw post records and never recreates post composition. It receives already-rendered Stream Cards.

## Legacy fallback

Declarative layouts are additive and opt-in. A CSS-only theme with no `layout_schema` or `layouts` fields remains valid. A declarative theme may also omit any supported surface and use the fixed legacy composition for that surface.

Midnight Ledger is the single bundled/default reference theme and uses Declarative Layouts for Profile, Stream Card, Site Header, and Home. Declarative Layout support is also a general third-party theme API. Bonumark keeps materially different Editorial and Split compositions only as internal regression fixtures under `scripts/fixtures/declarative-themes/`; they are not bundled site-owner theme choices.

## Theme Health and installation

Theme installation validates the manifest and every declared private layout before installation completes. Theme Health revalidates installed layouts before activation and reports the renderer mode, schema version, and declared surfaces.

A declared but missing, malformed, unsupported, or structurally invalid layout must fail validation. Bonumark does not execute or interpret unvalidated theme input.

## CSS and responsive requirements

Declarative themes should style stable layout hooks rather than depend on accidental DOM ancestry.

Useful hooks include:

- `[data-bms-layout="profile"]`
- `[data-bms-layout="stream-card"]`
- `[data-bms-layout="site-header"]`
- `[data-bms-layout="home"]`
- `[data-bms-layout-group="..."]`
- `[data-bms-component="..."]`

Custom layouts should allow grid/flex children to shrink with `min-width: 0`, constrain composed regions to the available width, and allow long user-controlled text and navigation labels to wrap safely. Do not use theme CSS to hide required application behavior or to recreate application state.

## Theme versioning

Theme assets use the theme manifest `version` as their cache revision. Bump the theme version whenever CSS, images, fonts, screenshots, or layout JSON changes.

## Compatibility promise for Schema 1

Component identifiers documented here are public theme-author APIs for Layout Schema 1. Bonumark Stream may add new surfaces or components in later releases, but a valid existing theme is not required to adopt them. Existing undeclared surfaces continue through their legacy fallback.

Schema 1 remains intentionally small. New functionality should be added as a core application capability first, then exposed as a stable component only when it represents a legitimate reusable theming boundary.

## Partial surface adoption

A layout-aware theme does not need to declare every supported surface. Midnight Ledger uses all four current surfaces declaratively in v0.6.0, but partial surface adoption remains a supported compatibility pattern for third-party themes: any undeclared supported surface continues through its legacy fallback.


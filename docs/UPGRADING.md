# Upgrading Bonumark Stream

Bonumark Stream v0.6.0 continues the v0.4.0+ clean-break upgrade foundation.

## v0.6.0 - Profiles & Theme Architecture 2.0

v0.6.0 is the next public release after v0.5.77. The built-in upgrader supports v0.4.0 and newer, including a direct upgrade from v0.5.77.

### Before upgrading

1. Back up the database.
2. Back up the complete site files, especially `_bonumark_stream/config.php`, uploads/media, and custom themes.
3. Confirm the Admin > Upgrade screen reports the package as valid before running the upgrade.
4. Do not interrupt the upgrade while package replacement or migrations are running.

### Database changes from v0.5.77

The v0.6.0 package adds three Profile migrations:

- `0015_profile_identity_foundation.php` creates `user_profiles` and seeds existing users with empty Profile records.
- `0016_profile_featured_work.php` adds Featured Work storage.
- `0017_profile_photos.php` adds Profile photo-gallery storage.

Existing Stream Posts, Pages, comments, media records, analytics, API records, scheduled-task history, Local Places, and settings are not rewritten by these migrations.

### Theme compatibility

Theme Architecture 2.0 is additive. Existing CSS-only themes remain valid and continue through the fixed legacy composition for surfaces they do not declare. Layout-aware themes may opt into Layout Schema 1 for Profile, Stream Card, Site Header, and Home.

Custom installed themes remain owner data and are preserved by the upgrader. The package manages only the bundled Midnight Ledger theme and explicitly identified retired bundled proof themes.

### After upgrading

- Open the public stream and a single Stream Post.
- Open the public Profile and Profile editor.
- Open Admin > Appearance and confirm Theme Health for the active theme.
- Confirm comments, likes, media, and the Stream composer work normally.
- If the Remote Posting API is enabled, confirm Admin > Remote Posting and the status endpoint.
- Review Admin > System Check if the host changed PHP extensions or image-processing support.

### Detailed development notes

The sections below preserve internal v0.5.x development-build upgrade notes for maintainers and regression traceability. Normal site owners upgrading to v0.6.0 do not need to work through them individually.

## v0.5.120 - Profile Modern Image Delivery Pass

v0.5.120 makes no database changes and stays scoped to Profile image delivery. Profile cover and gallery images now prefer Profile-only WebP `<picture>` sources when the server can encode WebP, while retaining the v0.5.119 responsive JPEG/PNG/WebP fallback `srcset`. The Profile cover receives an explicit responsive `<link rel="preload" as="image">` in the document head plus eager high fetch priority, and below-fold Profile photos remain lazy with low fetch priority. New uploads generate the modern Profile derivatives at upload time; upgraded installs can create missing modern derivatives from the already-bounded Profile fallback images when supported. Original uploads remain untouched. Stream/Home media rendering, Midnight Ledger layout JSON, Profile composition, Theme Architecture 2.0, publishing, comments, likes, routes, APIs, and database schema are unchanged.

## v0.5.119 - Profile Image Delivery Optimization Pass

v0.5.119 makes no database changes and does not alter Declarative Layout Schema 1 or the finished Midnight Ledger Profile composition. Core Profile media delivery now exposes verified cover derivatives directly even though Profile covers are stored outside the normal Media Library record path, keeps the cover eager with `fetchpriority="high"`, adds 240px and 360px Profile-photo derivatives for smaller gallery slots, and uses `sizes="auto"` for lazy gallery images with safe fallback lengths. Existing Profile covers and photos may generate a small bounded set of missing responsive derivatives the first time they are rendered after upgrade when a server-side image resize engine is available. Original uploads remain untouched.

## v0.5.118 - Midnight Ledger Profile Content Resilience Pass

v0.5.118 makes no database changes and does not alter Declarative Layout Schema 1. Midnight Ledger recomposes only its Profile layout: About and the flexible Now/Interests/Links/Details rail share the upper desktop row, while Featured and Photos begin below whichever upper column is taller and span the full 1040px identity canvas. The mobile order remains predictable as About, Featured, Photos, then supporting information. Link pills continue to wrap naturally, longer Now text grows the rail, and the wider Photos section uses a responsive count-aware desktop grid. Header, Home, Stream Card, publishing, comments, likes, permissions, routes, media behavior, APIs, metadata, and core application behavior are unchanged.


## v0.5.117 - Midnight Ledger Profile Banner Alignment Pass

v0.5.117 makes no database changes and does not alter Declarative Layout Schema 1. Midnight Ledger keeps the same four declarative layouts while explicitly aligning the Profile cover wrapper, panel, and image to the centered 1040px identity canvas. The cover image crop is explicitly centered, and the older 980px Profile shell limit in the theme stylesheet is normalized to the current 1040px canvas. Header, Home, Stream Card, Profile component order, publishing, comments, likes, permissions, routes, media processing, APIs, metadata, and core application behavior are unchanged.

## v0.5.116 - Midnight Ledger Canvas Alignment Pass

v0.5.116 makes no database changes and does not alter any Declarative Layout Schema 1 contract. Midnight Ledger keeps the same Site Header composition but aligns the masthead to the intentional content canvas of the active public surface: Home, single Stream posts, Stream archives, and search use the focused 860px reading canvas, while Profile uses the wider 1040px identity canvas. On small screens both continue to collapse naturally to the viewport. All four Midnight Ledger layout JSON files, core templates, publishing behavior, comments, likes, permissions, routes, media processing, APIs, and metadata remain unchanged.

## v0.5.115 - Midnight Ledger Responsive Polish Pass

v0.5.115 makes no database changes and does not alter the four-surface Layout Schema 1 contract. It keeps Midnight Ledger fully declarative while polishing responsive behavior: the masthead uses a compact one-line title through common medium phone/tablet widths when space permits, single-post body text is restrained in that same range, mobile comment textareas use an explicit compact default height, and the post-to-comments transition is tightened. Profile, Home, Stream Card, and Site Header layout JSON are unchanged. Publishing, comments logic, likes, permissions, routes, media processing, APIs, metadata, and core application behavior are unchanged.


## v0.5.114 - Midnight Ledger Home Composition Pass

v0.5.114 makes no database changes. Midnight Ledger moves `home` onto Layout Schema 1 using the existing core `home.notices`, `home.composer`, `home.pinned-posts`, `home.feed`, and `home.pagination` components. The theme keeps a single-column publish-first workflow, then the normal pinned/feed/pagination timeline. This completes Midnight Ledger declarative adoption across Profile, Stream Card, Site Header, and Home. The pass also refines medium-width masthead title wrapping and tightens the single-post-to-comments transition. Publishing, comments logic, likes, permissions, routes, media processing, APIs, metadata, and core application behavior are unchanged.

## v0.5.113 - Midnight Ledger Stream & Mobile Refinement Pass

v0.5.113 makes no database changes. Midnight Ledger moves `site-header` onto Layout Schema 1 using only the core site identity, Menu toggle, and primary navigation components; the old theme-specific Live microblog and published-post-count controls are removed from Midnight Ledger. The pass also compacts link previews on mobile, reduces per-post action chrome, flattens the comments region into the single-post reading flow, and tightens small-screen typography and spacing. Profile and Stream Card remain declarative, while Home remains on the legacy composition path. Application behavior, publishing, comments logic, likes, permissions, routes, media processing, APIs, and metadata are unchanged.

## v0.5.112 - Midnight Ledger Visual Foundation Pass

v0.5.112 makes no database changes. It begins the intentional redesign of the single bundled Midnight Ledger theme. Profile remains on Layout Schema 1 but now uses a more deliberate cover/identity/content-grid composition, while Stream Card keeps the same core component order with a refined visual system. Header and Home remain on their existing legacy composition paths in this pass. The upgrade changes only presentation/layout JSON, theme CSS, release metadata, and regression coverage; publishing, comments, likes, permissions, routes, media, APIs, metadata, and application behavior remain unchanged.

## v0.5.111 Midnight Ledger Declarative Baseline Pass

v0.5.111 keeps Midnight Ledger as the only bundled/default theme and converts only its `profile` and `stream-card` surfaces to validated Layout Schema 1 composition. `site-header` and `home` remain on the legacy renderer in this release. No database migration or owner-data transformation is required. Existing third-party themes remain unchanged.

## v0.5.110 - Theme Consolidation Pass

v0.5.110 makes no database changes and keeps all four Theme Architecture 2.0 Schema 1 surfaces intact. Midnight Ledger becomes the only bundled/default theme. Editorial Profile and Split Profile are removed from the installable theme collection and retained only as internal regression fixtures. During an upgrade, Bonumark removes old Editorial/Split copies only when their installed `theme.json` explicitly identifies them as Bonumark bundled proof themes; a custom or user-replaced theme with the same slug is preserved. If a removed proof theme had been selected previously, normal active-theme resolution safely falls back to Midnight Ledger. Third-party CSS-only and declarative themes remain supported.

## v0.5.109 - Site Composition Proof & Hardening Pass

v0.5.109 makes no database changes and adds no new declarative surface. It hardens and accepts the existing Schema 1 `profile`, `stream-card`, `site-header`, and `home` stack as one composition system. Editorial Profile and Split Profile advance to theme version 1.3.1 with Header/Home containment rules for narrow widths and long content, while Midnight Ledger remains on the fixed legacy fallback for all four surfaces. The package also adds an integrated nested-composition regression and a dedicated `docs/DECLARATIVE-LAYOUTS.md` theme-author contract.

## v0.5.108 - Declarative Home Composition Pass

v0.5.108 makes no database changes. It enables `home` as the fourth Schema 1 declarative surface using the five core-owned Home components established in v0.5.107: notices, atomic composer, pinned posts, feed/empty state, and pagination. The document shell and `<main>` remain core-owned, each post still renders through `stream-card`, and themes without `layouts.home` continue through the complete fixed legacy Home arrangement unchanged. Editorial Profile and Split Profile now provide different Home compositions and are bumped to theme version 1.3.0.


## v0.5.107 - Home Composition Foundation Pass

v0.5.107 makes no database changes and does not enable declarative Home composition yet. It separates the current Home rendering preparation into notices, composer, pinned posts, normal feed/empty state, and pagination boundaries and registers five core-owned Home components. The existing `home.php` layout remains unchanged and still consumes the reconstructed legacy `items_html` output. Themes that attempt to declare `layouts.home` are rejected in this release. The Stream composer remains atomic, and Home post rendering continues to reuse the existing `stream-card` surface.

## v0.5.106 - Declarative Site Header Composition Pass

v0.5.106 makes no database changes. It enables `site-header` as the third Schema 1 declarative surface while keeping the outer semantic public Header shell, site-title heading choice, navigation data/URLs, account-state decisions, menu behavior, and accessibility in core. Themes may arrange only the four registered Site Header components through private validated JSON. Themes without `layouts.site-header`, including Midnight Ledger, continue through the complete fixed Header composition unchanged. Editorial Profile and Split Profile now provide different Header compositions as bundled proof themes and are bumped to theme version 1.2.0.

## v0.5.105 - Site Header Component Foundation Pass

v0.5.105 makes no database changes and does not enable declarative Site Header composition yet. It extracts the stable public Header concepts into four core-owned components: `site-header.site-identity`, `site-header.primary-navigation`, optional `site-header.menu-toggle`, and optional `site-header.stream-count`. The existing `header.php` composition remains the active renderer for all themes in this release. Core continues to own navigation records, URLs, active state, authenticated account decisions, menu behavior, heading semantics, accessibility, and application logic. Static Site Export now suppresses session-specific account destinations while generating the export artifact so an authenticated admin session is not baked into exported navigation.


## v0.5.104 - Runtime Diagnostic Cleanup Pass

v0.5.104 makes no database changes. It removes the temporary user-facing PHP Runtime Cache diagnostic that was introduced while investigating the link-preview title bug. Bonumark still handles PHP runtime cache reliability automatically during admin ZIP upgrades by invalidating replaced PHP files when supported and requesting an OPcache reset after software replacement completes. The v0.5.102 Fragment SEO Boundary Fix and its regression coverage remain intact.

## v0.5.103 - Link Preview Metadata Integrity Pass

v0.5.103 makes no database changes. It hardens external link-preview metadata when a fetched remote title incorrectly contains the local Bonumark Stream site name as its suffix even though the remote page reports a different site name. The shared preview sanitizer now replaces only that suspicious local suffix with the remote site name, leaves unrelated titles alone, corrects future composer previews before storage, and also normalizes already-saved preview metadata at render time.

## v0.5.96 - Dual Stream Card Layout Proof Pass

v0.5.96 makes no database changes. It extends the bundled Editorial Profile and Split Profile proof themes to the Schema 1 `stream-card` surface. Editorial Profile renders post body/media/link preview first and collects avatar, author/date, location, and actions into a byline footer. Split Profile renders a desktop author/meta/action rail beside the main post body/media/link preview column, then collapses to a safe one-column mobile flow. Both use the same seven core-owned Stream Card components and the same prepared card data. Layout JSON remains private, theme CSS remains presentation-only, and the core article shell, Quick edit, likes, comments, Post Options, pin/trash/editor actions, CSRF, accessibility, routing, and static/dynamic shared rendering remain core-controlled. Midnight Ledger continues on the fixed legacy Stream Card composition.

## v0.5.95 - Declarative Stream Card Composition Pass

v0.5.95 makes no database changes. It enables `stream-card` as the second Schema 1 declarative surface and allows a theme to arrange the seven v0.5.94 core-owned Stream Card components through private `layouts/stream-card.json`. Bonumark Stream still owns the outer article, prepared card data, Quick edit, likes, comments, pin/trash/editor actions, CSRF, accessibility and interaction hooks. Themes that do not declare a Stream Card layout continue through the exact fixed `stream-card-inner` / `stream-card-main` composition, and the three bundled themes remain on that legacy card composition in this pass. Dynamic rendering and Static Site Export continue to share the same card renderer, so a future theme that declares Stream Card composition uses the same composition system in both paths.


## v0.5.94 - Stream Card Component Extraction Pass

v0.5.94 makes no database changes and does not enable declarative Stream Card composition. It extracts the current Stream card interior into seven core-owned component files for avatar, header, body/Quick edit, location, link preview, media, and actions while preserving the existing article/inner/main shell and all prepared renderer data, permissions, interaction hooks, and markup behavior. `stream-card` is registered as a core component family but remains outside the supported declarative surface list, so existing themes and Stream output continue through the fixed legacy card composition unchanged.


## v0.5.93 - Declarative Profile Responsive Hardening Pass

v0.5.93 makes no database changes. It hardens the two bundled Declarative Layout Profile proof themes after real-device desktop/mobile testing exposed horizontal clipping in Editorial Profile. Declarative Profile wrappers now include explicit `min-width: 0` and text-containment rules, Editorial Profile uses viewport-safe calculated widths instead of relying on grid stretch plus side margins, and an additional small-phone breakpoint protects the identity and narrative flow. Editorial Profile and Split Profile both advance to theme version 1.0.1 so the corrected CSS receives a new theme-owned cache revision. Profile data, the Schema 1 layout contract, core Profile component markup, Midnight Ledger, legacy rendering, and Stream cards are unchanged.

## v0.5.92 - Dual Profile Layout Proof Pass

v0.5.92 makes no database changes. It adds two protected bundled Declarative Layout proof themes, Editorial Profile and Split Profile, that render identical prepared Profile data through the same ten core-owned components but materially different Schema 1 compositions. Both include explicit responsive CSS for mobile collapse. Midnight Ledger remains on the Legacy Core Renderer, and existing user-installed themes are not rewritten or migrated.

## v0.5.91 - Declarative Profile Composition Pass

v0.5.91 makes no database changes. It enables validated Schema 1 declarative composition for the public Profile interior when a theme explicitly declares `layouts.profile`, while preserving the complete fixed Profile composition for legacy CSS-only themes. Core continues to render all ten Profile components and own data, semantics, accessibility, behavior, security, routes, and application actions.

## v0.5.90 - Profile Component Extraction Pass

v0.5.90 makes no database changes and does not yet enable declarative Profile composition. It extracts the current Profile interior into ten core-owned component files and maps those components through the Theme Architecture 2.0 registry while preserving the existing Profile order, hero wrapper, markup behavior, renderer path, and legacy theme compatibility.

## v0.5.89 - Declarative Theme Integration Pass

v0.5.89 makes no database changes and leaves public Profile composition on the existing renderer. It integrates the v0.5.88 Declarative Layout Themes foundation with theme installation, activation-time Theme Health validation, Theme Manager reporting, and theme-version asset cache revisions.

Layout-aware theme updates may now include explicitly declared private `layouts/*.json` files. The upgrader does not migrate or rewrite existing themes. Legacy CSS-only themes that omit `layout_schema` and `layouts` remain valid and continue through the Legacy Core Renderer.

## v0.5.88 - Declarative Layout Foundation Pass

v0.5.88 makes no database changes and does not change public rendering. It establishes the first inert Theme Architecture 2.0 layer: optional `layout_schema` and `layouts` manifest fields, a core-owned Profile component registry, private `layouts/*.json` path rules, strict `group` and `component` node validation, node/depth limits, required component cardinality, and rejection of unknown properties or unregistered components.

Existing CSS-only themes remain valid without adding any layout fields and continue through the existing default core renderer. The bundled Midnight Ledger theme remains a legacy CSS-only theme in this pass. Theme installer copying, Theme Manager layout reporting, Profile component extraction, and declarative Profile composition are intentionally deferred to later passes so the new contract can be validated before it affects output.

## v0.5.87 - Profile Gallery Pass

v0.5.87 adds `profile_photos_json` to the existing `user_profiles` table. Existing Profiles begin with no Profile photos, and no posts, Pages, post media, Media Library records, links, Featured Work, metadata, accounts, themes, settings, credentials, or existing Profile media are rewritten.

Profile owners may upload and order up to four JPG, PNG, or WebP photos with optional alt text and captions. The files live under the dedicated `media/profile-photos/{user}/` path, use Bonumark's existing image validation, configured media upload limit, privacy cleaning, and responsive derivative generation, and remain independent from Stream publishing and the Media Library. Removing or replacing a Profile photo cleans up its original file and generated variants. Profile exports now include original Profile photos and their portable metadata.

## v0.5.86 - Profile Portability Pass

v0.5.86 makes no schema changes. It adds an owner-controlled Profile ZIP export to the existing Profile editor and a dedicated CSRF-protected download endpoint. The package contains structured `profile.json`, readable `profile.md`, and original local Profile media when available. It exports identity, links, interests, Featured Work references, visibility, and optional-public-detail preferences while excluding email, credentials, roles, activity counts, security records, post/comment contents, API data, generated image variants, and theme presentation state. Existing Profiles, metadata, featured items, posts, Pages, media, accounts, themes, settings, and credentials are preserved.

## v0.5.85 - Profile Identity Metadata Pass

v0.5.85 makes no schema changes. It keeps the accepted Profile identity, Featured Work, editor, Stream, and theme presentation unchanged while adding Profile-specific search/share metadata, cover/avatar social images, ProfilePage/Person structured data, base-path-safe canonical Profile URLs, sitemap Profile last-modified handling, and public Stream article-author Profile references. Private Profiles remain excluded from public sitemap output and structured identity data, and owner/admin views of private Profiles receive `noindex,nofollow`. Existing Profiles, featured items, posts, Pages, media, accounts, themes, settings, and credentials are preserved.

## v0.5.84 - Profile Featured Polish and Empty Stream Layout Fix

v0.5.84 makes no schema changes. It keeps v0.5.83 Featured Work data and Profile behavior intact while polishing the bundled Midnight Ledger theme and correcting short public-page layout. The outer public flex wrapper now top-aligns the site grid so empty or short pages do not distribute grid rows across the viewport height. Featured cards also handle one-to-four item sets and long text more cleanly. Existing Profiles, featured items, posts, Pages, media, accounts, themes, settings, and credentials are preserved.

## v0.5.83 - Profile Featured Work Pass

v0.5.83 adds `featured_items_json` to the existing `user_profiles` table. The migration does not move or rewrite existing Profile identity, posts, Pages, media, accounts, links, interests, settings, themes, or credentials. Existing Profiles simply begin with no featured work selected.

Featured work is deliberate curation only. A Profile owner may save up to four references to published Stream posts, published Pages, or validated external URLs. No recent posts are added automatically, no per-profile Stream or feed is created, and an internal item disappears from the public Featured section if its referenced content is no longer published.

## v0.5.82 - Profile Foundation Cleanup Pass

v0.5.82 makes no schema changes. It keeps the Profile Identity Foundation data model, routes, and public Profile unchanged while finishing the tested editor cleanup: saved Profiles show only saved link rows, empty Profiles keep one first-link starter row, Add Link does not duplicate an untouched starter row, image-removal actions are visually contained, and the About editor opens at a more practical height. Existing Profile identity data, media, accounts, posts, comments, themes, settings, and credentials are preserved.

## v0.5.81 - Profile Foundation Editor Polish Pass

v0.5.81 makes no schema changes. It keeps the v0.5.80 Profile Identity Foundation data model and routes intact while polishing the Profile editor: compact link rows with Add Link and Remove controls, Profile visibility and optional details grouped under Profile settings, improved image controls, and corrected checkbox alignment. Existing Profile identity data, media, accounts, posts, comments, themes, settings, and credentials are preserved.

## v0.5.80 - Profile Identity Foundation Pass

v0.5.80 adds the `user_profiles` identity table. The migration creates one row for each existing account and carries the existing ordered social-link JSON forward as the starting flexible Profile links. Existing usernames, display names, short bios, websites, visibility, avatars, posts, comments, media, themes, settings, and account credentials remain in place.

The public Profile now uses the unique username as its canonical route. Old numeric `profile.php?id=` links remain readable as a compatibility fallback, but display names are not routing identifiers. No per-profile Stream route, feed, or duplicate publishing surface is added.

Profile presentation remains theme-owned. Core supplies semantic Profile data and markup, while the bundled Midnight Ledger theme styles the new sections. The migration adds no Profile layout or Profile accent setting to core.

## v0.5.79 - Upgrade Protected Data Layout Hotfix

v0.5.79 changes no upgrade behavior or database schema. It corrects the desktop presentation of the Upgrade page's protected-data explanation so its labels and values remain readable inside the narrow operations rail. Existing config, database records, posts, pages, drafts, revisions, comments, users, media, uploads, custom themes, settings, analytics, API tokens, scheduled tasks, cron history, Local Places, and all other owner data are unchanged.


## v0.5.78 - Admin UI Contract

v0.5.78 changes no application behavior or database schema. It adds `docs/ADMIN-UI-GUIDELINES.md` as the repository-level contract for future Admin work, links the standard from `README.md` and `CONTRIBUTING.md`, and adds smoke-test coverage for the documentation boundary. Existing config, database records, posts, pages, drafts, revisions, comments, users, media, uploads, custom themes, settings, analytics, API tokens, scheduled tasks, cron history, Local Places, and all other owner data are unchanged.


## v0.5.77 - GitHub README Cleanup

v0.5.77 changes no application behavior or database schema. It restores the root README as a product-first GitHub landing page, removes embedded release-by-release notes from the README, and directs readers to `CHANGELOG.md` for complete release history. Existing config, database records, posts, pages, drafts, revisions, comments, users, media, uploads, custom themes, settings, analytics, API tokens, scheduled tasks, cron history, Local Places, and all other owner data are unchanged.

## v0.5.76 - Public Release Hardening Pass

v0.5.76 changes no database schema. It prevents the upgrader from duplicating the live `media/` directory into every private upgrade backup when the package contains `media/.gitkeep`, keeps media and uploads preserved in place, completes top-level obsolete-file cleanup coverage for `CHANGELOG.md` and `analytics.php`, and aligns public release documentation. Existing config, database records, posts, pages, drafts, revisions, comments, users, media, uploads, custom themes, settings, analytics, API tokens, scheduled tasks, cron history, Local Places, and all other owner data are preserved.

## v0.5.75 - Legacy Admin CSS Consolidation Pass 3

v0.5.75 removes 104 superseded declarations and 14 empty rule blocks from the legacy Admin stylesheet while preserving the final effective Admin cascade. No database migration runs and no stored data or workflow behavior changes.

## v0.5.74 - Legacy Admin CSS Consolidation Pass 2

v0.5.74 removes 127 superseded declarations and 12 empty rule blocks from the legacy Admin stylesheet while preserving the final Admin selector-property cascade. No database migration runs and no stored data or workflow behavior changes.

## v0.5.73 - Legacy Admin CSS Consolidation Pass 1

v0.5.73 begins controlled legacy Admin CSS removal, restores the omitted v0.5.71 release history, and aligns package metadata without changing the final Admin cascade. No database migration runs and no stored data or workflow behavior changes.

## v0.5.72 - Operations Label Pill Hotfix

v0.5.72 corrects the Administrator tools and Software replacement label fit in the operations interface. No database migration runs and no operational behavior or stored data changes.

## v0.5.71 - Tools and System Polish Hotfix

v0.5.71 contains long diagnostic values, tightens Tools card rhythm, and improves Upgrade history placement and phone scanning. No database migration runs and no import, export, upgrade, analytics, scheduled-task, API, security, permission, or stored-data behavior changes.

## v0.5.70 - Tools and System Operations Pass

v0.5.70 rebuilds Tools, Import, Export, Upgrade, System Check, Analytics, Scheduled Tasks, Remote Posting, Security, and Help around explicit operation risk and recovery boundaries. No database migration runs and existing runtime data and owner content remain preserved.

## v0.5.69 - Local Places Preview Polish Hotfix

v0.5.69 corrects the empty Local Places public-label preview and marker behavior. No database migration runs and saved places, private coordinates, post snapshots, permissions, and stored data remain unchanged.

## v0.5.68 - Local Places Workflow Pass

v0.5.68 rebuilds the Local Places Admin directory, editor, nearby checks, public-label preview, and deletion confirmation without changing the Local Places schema or stored records. No database migration runs.

## v0.5.67 - Settings URL Overflow Hotfix

v0.5.67 keeps long API routes, cron endpoints, paths, and technical values contained inside Settings panels. No database migration runs and settings, credentials, tokens, content, and stored data remain unchanged.

## v0.5.66 - Settings Workflow Pass

v0.5.66 rebuilds General, Reading, Writing, Security, Mail, Remote Posting, and Scheduled Tasks around one responsive Settings system. No database migration runs and existing settings, credentials, tokens, histories, routes, content, and stored data remain unchanged.

## v0.5.65 - Appearance Metadata Polish Hotfix

v0.5.65 corrects active-theme metadata wrapping in Theme Settings. No database migration runs and themes, settings, public rendering, permissions, content, and stored data remain unchanged.

## v0.5.64 - Appearance and Site Design Pass

v0.5.64 rebuilds Themes, Theme Settings, theme details, installation, deletion, Site Identity, and Navigation around one responsive Appearance workflow. No database migration runs and existing themes, identity values, favicons, navigation records, content, and stored data remain unchanged.

## v0.5.63 - Registration State Polish Hotfix

v0.5.63 keeps the Mail readiness warning quiet while public registration is closed and retains the warning when registration and verification require Mail. No database migration runs and registration settings, invite codes, accounts, and stored data remain unchanged.

## v0.5.62 - Registration Workflow Pass

v0.5.62 rebuilds Commenter Registration, verification, approval, invite creation, and invite history without changing the registration schema or stored accounts. No database migration runs.

## v0.5.61 - Comments and Accounts Polish Hotfix

v0.5.61 separates filter labels from counts and corrects the phone account-summary grid. No database migration runs and comments, accounts, permissions, profiles, and stored data remain unchanged.

## v0.5.60 - Comments and Accounts Correction Pass

v0.5.60 fixes the Comments undefined-variable warning, compacts desktop bulk controls, and completes the responsive Accounts workflow. No database migration runs and comments, accounts, permissions, profiles, and stored data remain unchanged.

## v0.5.59 - Comments and Moderation Pass

v0.5.59 changes only the Admin Comments presentation and moderation controls. Comments gain responsive desktop records, phone cards, live status counts, search, scoped selection, bulk moderation, clearer author and Stream Post context, deliberate empty states, and accessible per-comment action menus. Permanent deletion is limited to comments already in Trash. No database migration runs. Existing config, database data, comments, commenter accounts, posts, pages, drafts, scheduled posts, revisions, media, uploads, likes, users, API tokens, themes, analytics, cron history, Local Places, permissions, public comment rendering, and all other site data are preserved.

## v0.5.58 - Pages Workflow Polish Hotfix

v0.5.58 changes only the Admin Pages phone filter presentation and page-specific preview guidance. All four Page status filters remain visible in a two-column phone grid, and the editor note now refers to unsaved page edits. The existing viewport-safe mobile action dock behavior is retained and covered by release smoke checks. No database migration runs. Existing config, database data, page records, slugs, public URLs, navigation, dynamic rendering, posts, drafts, scheduled posts, revisions, media, uploads, comments, likes, users, API tokens, themes, analytics, cron history, Local Places, and all other site data are preserved.

## v0.5.57 - Pages Workflow Pass

v0.5.57 changes only the Admin Pages list and page editor presentation. Pages gain a responsive record list, compact phone cards, action menus, collapsible URL and settings cards, page-aware Screen Controls, and the shared mobile action dock. No database migration runs. Existing config, database data, page records, slugs, public URLs, navigation, dynamic rendering, posts, drafts, scheduled posts, revisions, media, uploads, comments, likes, users, API tokens, themes, analytics, cron history, Local Places, and all other site data are preserved.

## v0.5.56 - Media Library Polish Pass

v0.5.56 changes only the Admin Media Library presentation and details interaction. Cards gain consistent height, larger selection and details targets, a full-width View details footer, and cleaner filename handling. The details dialog gains fixed header and action regions around one internally scrolling body, while phones use a true safe-area-aware bottom sheet with background scroll locking. No database migration runs. Existing config, database data, media records, uploaded files, optimized variants, posts, pages, drafts, scheduled posts, revisions, comments, likes, users, API tokens, themes, analytics, cron history, Local Places, and all other site data are preserved.

## v0.5.55 - Media Library Pass

v0.5.55 changes only the Admin Media Library presentation and interaction structure. Media is now browsed through a compact responsive thumbnail grid, while captions, privacy notes, file details, Markdown, Edit, and Open controls appear on demand in one shared details dialog. Phone widths use a two-column grid and bottom-sheet details view. No database migration runs. Existing config, database data, media records, uploaded files, optimized variants, posts, pages, drafts, scheduled posts, revisions, comments, likes, users, API tokens, themes, analytics, cron history, Local Places, and all other site data are preserved.

## v0.5.54 - Mobile Editor Action Bar Hotfix

v0.5.54 keeps the mobile editor action dock at the true visual-viewport bottom, reserves safe scroll space, and hides the dock when it would cover editor controls, duplicate the visible Publish card, or compete with the on-screen keyboard. No database migration runs. Existing config, database data, posts, pages, drafts, scheduled posts, revisions, media, uploads, comments, likes, users, API tokens, themes, analytics, cron history, Local Places, and all other site data are preserved.

## v0.5.53 - Editor Scroll and Disclosure Correction Pass

v0.5.53 corrects the v0.5.52 editor presentation after real desktop and mobile testing. The full metadata rail returns to normal browser scrolling, only Publish remains sticky on wide layouts, phone and tablet writing surfaces start shorter, and Location now uses the same shared collapsible side-card component as the other editor controls. No database migration runs. Existing config, database data, posts, pages, drafts, scheduled posts, revisions, media, uploads, comments, likes, users, API tokens, themes, analytics, cron history, Local Places, and all other site data are preserved.

## v0.5.52 - Editor Workflow Pass

v0.5.52 changes only the Admin editor presentation and interaction structure. The writing surface now grows from content rather than the viewport or metadata rail, Publish remains immediately available, secondary controls begin collapsed, and mobile gains fixed Save and publishing controls. No database migration runs. Existing config, database data, posts, pages, drafts, scheduled posts, revisions, media, uploads, comments, likes, users, API tokens, themes, analytics, cron history, Local Places, and all other site data are preserved.

## v0.5.51 - Content List Responsive Pass

v0.5.51 replaces the Stream Posts admin table with a responsive record-list component, compact metadata, accessible per-post action menus, and purpose-built mobile cards. Search, filters, sorting, selection, and bulk actions remain available. No database migration runs. Existing config, database data, posts, pages, drafts, scheduled posts, revisions, media, uploads, comments, likes, users, API tokens, themes, analytics, cron history, Local Places, and all other site data are preserved.

## v0.5.50 - Admin Shell Foundation Pass

v0.5.50 replaces the shared Admin shell, navigation hierarchy, responsive layout, and Dashboard presentation. Mobile Admin pages now use an off-canvas drawer instead of placing the full navigation before page content. No database migration runs. Existing config, database data, posts, pages, drafts, scheduled posts, revisions, media, uploads, comments, likes, users, API tokens, themes, analytics, cron history, Local Places, and all other site data are preserved.

## v0.5.49 - Front-End Move to Trash Pass

v0.5.49 adds a recoverable Move to trash action to the authorized front-end Stream Post menu. The action uses the existing Trash records and permissions, never permanently deletes from the front end, and requires confirmation, CSRF validation, post-specific edit access, and a stale-content check. No database migration runs. Existing posts, Trash records, revisions, URLs, media, Local Places, link previews, authorship, pins, likes, comments, config, uploads, themes, settings, and all other site data are preserved.

## v0.5.48 - Front-End Quick Edit Pass

v0.5.48 adds a body-only Quick edit action to the existing front-end post options menu for signed-in users who can edit that post. The old edit-link display toggle is retired from the current Settings screen, while its saved value remains untouched for compatibility. Changed saves create revisions and update only the post body, content hash, and modification time. No database migration runs, and URLs, status, dates, metadata, media, Local Places, link previews, authorship, pins, likes, comments, config, uploads, themes, settings, and all other site data are preserved.

## v0.5.47 - Compact Composer Controls Pass

v0.5.47 changes only the front-end composer presentation and interaction layout. Save draft, Continue in full editor, and Advanced options move into a compact More options menu, while media, scheduling, location, and Post remain visible in the main toolbar. No database migration runs, and the unified save routes introduced in v0.5.46 remain unchanged.

## v0.5.46 - Unified Stream Composer Pass

v0.5.46 changes the Stream Post creation workflow without changing the database schema. New posts begin in the public stream composer, which now supports publish, schedule, save-draft, advanced metadata, and continue-in-full-editor actions. Existing drafts, scheduled posts, published posts, revisions, Pages, API publishing, media, users, settings, and uploads remain in place. The upgrader may safely replace `admin/new.php` because it is now a compatibility redirect only.

## v0.5.45 - Gallery Preview and Viewer Fix Pass

v0.5.45 fixes browser-side gallery presentation without changing stored posts or media. The front-end composer now permits its efficient local `blob:` preview URLs through the core Content Security Policy and manages those temporary URLs until the preview is cleared or rebuilt.

Published image links now use a core full-size viewer with an on-screen close control, click-outside closing, Escape-key support, focus restoration, and previous/next controls for galleries. Older custom themes inherit the viewer because its behavior and fallback styling remain in core. No database migration runs, and all existing site data and user-owned files are preserved.

## v0.5.44 - Four-Photo Gallery Pass

v0.5.44 adds ordered one-to-four-photo galleries to both Stream Post composers. No database migration runs because gallery order is stored inside the existing post front-matter JSON. The first gallery image remains `featured_media`, so existing single-image posts, older custom themes, feeds, Open Graph metadata, exports, and integrations continue to work.

Core provides the gallery markup, responsive image sources, dimensions, loading behavior, accessibility, and fallback CSS. Custom themes may style the documented gallery classes and variables but do not need an update to remain functional. Existing config, database data, posts, pages, drafts, revisions, users, comments, likes, media, uploads, themes, settings, analytics, API tokens, cron history, scheduled posts, Local Places, coordinates, consent records, and post location snapshots are preserved.

## v0.5.43 - Token-Scoped Stream Read API

v0.5.43 adds a general-purpose published-content `GET /api/v1/stream/posts` endpoint protected by the new `stream:read` token scope. Existing `POST` behavior remains unchanged. No database migration runs. Existing API tokens continue to work with their current scopes and do not receive read access unless an Administrator explicitly grants `stream:read` or creates a new read token.

## v0.5.42 - GitHub Release Hardening Pass

v0.5.42 makes the fresh installer set the MySQL/MariaDB session to UTC before schema migrations and initial seed writes. This aligns fresh-install timestamps with the canonical UTC behavior used by normal runtime database connections.

The release also restores the missing v0.5.35 and v0.5.37 upgrade notes and corrects the older v0.5.24 timestamp-section label. No migration runs, and existing config, database data, posts, pages, drafts, revisions, users, comments, likes, media, uploads, themes, settings, analytics, API tokens, cron history, scheduled posts, Local Places, coordinates, consent records, and post location snapshots are preserved.

## v0.5.41 - Local Places Metadata Alignment Hotfix

v0.5.41 removes the floating category badge from Local Places cards and places Category beside Default public display in the labeled metadata row. This is a presentation-only change.

No migration runs. Existing Local Places, coordinates, posts, post location snapshots, settings, media, themes, uploads, and other site data are preserved.

## v0.5.40 - Local Places Admin Polish Pass

v0.5.40 replaces the wide Local Places admin table with a compact responsive directory. It improves place hierarchy, saved-place counts, privacy guidance, display information, action spacing, and mobile behavior without changing Local Places data or functionality.

No migration runs. Existing Local Places, coordinates, posts, post location snapshots, settings, media, themes, uploads, and other site data are preserved.

## v0.5.39 - Local Places Visual Consistency Hotfix

v0.5.39 aligns the Local Places composer panel and add-place dialog with the existing Schedule panel color system in the default theme. This is a presentation-only release with no database migration or Local Places behavior changes.

No migration runs. Existing Local Places, coordinates, posts, post location snapshots, settings, media, themes, uploads, and other site data are preserved.

## v0.5.38 - Local Places Geolocation Hotfix

v0.5.38 restores browser geolocation for Local Places. It changes the Permissions Policy from blocking geolocation to allowing it for the same Bonumark Stream origin, and moves the Admin **Use current location** behavior from blocked inline JavaScript into the existing external admin script.

No migration runs. Existing Local Places, coordinates, posts, post location snapshots, settings, media, themes, uploads, and other site data are preserved.

## v0.5.37 - Local Places Composer Simplification Pass

v0.5.37 reduces Local Places in both Stream Post composers to saved-place selection, nearby lookup, and a compact add-place dialog. Full place-detail editing remains under **Admin → Local Places**, and each saved place's default public display mode is used automatically.

No migration runs. Existing places, coordinates, categories, display settings, post location snapshots, posts, pages, media, comments, analytics, themes, API tokens, cron history, uploads, and settings are preserved.

## v0.5.36 - Local Places Check-In Pass

v0.5.36 adds the private Local Places directory and location check-ins in both Stream Post composers. Migration `0014_local_places.php` creates the places table. The migration does not rewrite existing posts, pages, media, comments, analytics, themes, API tokens, cron history, uploads, or settings.

After upgrading, open **Admin → Local Places** to add or review saved places. Browser location permission is requested only after pressing a location button, and public posts never expose latitude or longitude.

## v0.5.35 - Root RSS Discovery Hotfix

v0.5.35 restores `/feed.xml` as the canonical RSS feed advertised by public pages and keeps `/stream/feed.xml` as a compatibility alias for existing subscriptions and the stream archive route.

No migration runs. Existing posts, pages, users, comments, analytics, themes, API tokens, cron history, uploads, media files, and settings are preserved.

## v0.5.34 - Privacy-Safe Media Uploads Pass

v0.5.34 adds one idempotent media migration. It adds privacy status fields to the media table and a `media_privacy_mode` setting. Existing media files are not renamed, rewritten, or deleted. New uploads use randomized public filenames. Supported image uploads are re-encoded to remove metadata when possible, and best-effort mode warns instead of rejecting when metadata removal cannot be confirmed. Strict privacy mode can be enabled from **Admin → Settings → Writing**.

## v0.5.33 - Analytics Report Card Polish Pass

v0.5.33 improves the visual presentation of **Admin → Tools → Analytics** by replacing sparse table-style report cards with compact label/count summary rows and clearer empty states. No database migration runs, no analytics rows are changed, and no posts, pages, users, media, themes, settings, API tokens, cron history, uploads, or existing configuration are rewritten. Analytics collection, privacy boundaries, CSV export, and theme behavior are unchanged.

## v0.5.32 - Analytics Report Warning Hotfix

v0.5.32 fixes a PHP 8.1+ warning on **Admin → Tools → Analytics** that occurred when report tables used key-only column definitions. The fix changes only the analytics admin-table helper so it safely reads an optional formatter before calling it. No database migration runs, no analytics rows are changed, and no posts, pages, users, media, themes, settings, API tokens, cron history, uploads, or existing configuration are rewritten.

## v0.5.31 - Privacy-First Analytics Pass

v0.5.31 adds one idempotent analytics migration. It creates optional aggregate analytics storage and disabled-by-default settings without rewriting content, timestamps, themes, users, comments, media, API tokens, cron history, uploads, or existing configuration.

## v0.5.29 - PWA Direct Favicon Fallback Fix

v0.5.29 fixes the v0.5.28 PWA icon fallback for shared-hosting PHP builds without GD or Imagick. The app continues to generate square PNG icons when an image extension is available. Without one, the manifest and Apple app metadata now point directly to the selected Site Identity favicon rather than displaying the bundled Bonumark B. The service-worker cache name changes so PWA metadata can refresh. No database migration runs and no posts, users, themes, media records, settings, uploads, or content are rewritten.


## v0.5.27 - MariaDB Upgrade Compatibility Hotfix

v0.5.27 fixes a MariaDB limitation in the timestamp-cutover upgrade safety check. MariaDB does not accept a bound placeholder in `SHOW TABLES LIKE`, so v0.5.25 could fail after copying package files and before it recorded upgrade completion.

The corrected v0.5.27 package uses quoted `SHOW` statements. Because the currently running v0.5.25 upgrader loads its own database helper before it reads an uploaded package, an affected v0.5.25 MariaDB installation needs the one-time database bridge supplied with this release before uploading v0.5.27. The bridge changes only that one compatibility helper and does not modify stored data or public behavior. Once v0.5.27 completes, the package replaces the bridge with the normal corrected application file.

## v0.5.26 - Upgrade Recovery and UTC Consistency Pass

Future upgrades performed by the v0.5.26+ upgrader are forward-only once the database migration phase begins. Before that phase, a failed upgrade restores prior software files. Once migration begins, Bonumark keeps the newer compatible files, writes a private recovery marker, records recovery-required history, and allows the exact same release package to resume safely. If a server interruption leaves a `migration_in_progress` marker instead of a normal caught failure, Bonumark blocks another upgrade rather than guessing. Review the private upgrade backup and server error log before proceeding.

This behavior cannot retroactively change an older upgrader that is already executing. When upgrading an existing v0.5.25 installation, keep a normal database backup and use the v0.5.26 package only after confirming the backup is available.

v0.5.26 also stores remembered-device and invite expiration values in canonical UTC, retains configured site-time display for public Stream posts, blocks browser execution of shipped test scripts, adds server-side PWA share-target throttling, and removes legacy GET logout behavior. It does not rewrite posts, media, users, themes, API tokens, or settings.

## v0.5.25 - Release Audit Remediation Pass

v0.5.25 repairs the legacy timestamp-cutover fallback used by direct upgrades, changes PWA Web Share Target intake to POST, scopes session cookies per installation, makes migration recovery honest for MySQL/MariaDB DDL, and removes obsolete root PWA files during future upgrades. It does not rewrite posts, media, users, themes, API tokens, or settings.


## Supported upgrade path

The built-in upgrade tool supports upgrades from v0.4.0 and newer only.

Pre-v0.4 development builds are not supported by the current upgrader. Install the current v0.5.77 package fresh instead of trying to upgrade an older development build.

## What the upgrader preserves

The upgrader preserves current v0.4.0+ user-owned data and generated files:

- `_bonumark_stream/config.php`
- `_bonumark_stream/installed.lock`
- `_bonumark_stream/data/`
- `_bonumark_stream/backups/`
- `_bonumark_stream/tmp/`
- `media/`
- `uploads/`
- installed code-free theme packages and public theme assets that are not bundled with the release

The upgrader does not preserve old file-runtime content folders. Markdown remains available for import, export, backup, and portability only. Runtime publishing is database-first.

## Scheduled Tasks and Cron Foundation

v0.5.18 adds a reusable Scheduled Tasks runner, a protected web cron endpoint, a CLI-only server cron script, task health, and manual/cron run history. The upgrade adds a small `scheduled_task_runs` table plus task settings. Existing scheduled posts, their UTC schedule times, public hiding, and current traffic/heartbeat behavior are preserved. After upgrading, open **Admin → Settings → Scheduled Tasks** to choose a runner and copy setup instructions.

## Legacy timestamp display compatibility

v0.5.24 keeps the existing-post PDO save corrections and v0.5.23 runtime timezone handling, then restores correct display for legacy published posts with a compatibility boundary recorded from upgrade history. No post rows, bodies, titles, media, date fields, scheduled posts, drafts, pages, or imports are rewritten.

## Post actions menu update

v0.5.17 keeps the front-end three-dot **Post options** menu below its trigger without clipping and aligns published-post **Edit** plus **Pin to Stream** or **Unpin from Stream** as one consistent left-aligned action list. No database migration or API change is required.

## Pinned posts migration

v0.5.13 adds `is_pinned` and `pinned_at` to the posts table through a safe migration. Existing posts remain unpinned after upgrade. The migration preserves existing post content, author, publish date, schedule state, media, revisions, comments, likes, and exports.

## Static export

Static Site Export is optional tooling. It is not the normal publishing mode.

## Scheduled posts migration

Scheduled publishing was introduced in v0.5.5. Current fresh installs include the `scheduled_at` field, and upgrades from older v0.4.0+ builds receive it through the scheduled-post migration. Existing drafts and published posts are preserved.

As of v0.5.13, safe public dynamic traffic is the primary shared-hosting trigger for due scheduled posts. Admin and front-end composer heartbeats remain as backup helpers. Exact-to-the-minute scheduling still requires server cron or an external ping service hitting a public dynamic URL every minute.

## 0.5.76 - Public Release Hardening Pass

- Prevents admin ZIP upgrades from copying the entire live `media/` directory into every private upgrade backup merely because the release package contains `media/.gitkeep`.
- Keeps public media and upload directories preserved in place while continuing to back up package-managed software before replacement.
- Adds `CHANGELOG.md` and the root `analytics.php` controller to the upgrader's package-managed cleanup coverage so retired top-level software cannot remain as stale files in future releases.
- Updates the installation guide, upgrade guide, API documentation, Remote Posting guide, API response examples, and OpenAPI version metadata from the stale v0.5.59 baseline to the current release.
- Restores upgrade-guide coverage for v0.5.60 through v0.5.75 and documents this release's preservation boundary.
- Adds release smoke coverage for current documentation versions, upgrade media-backup exclusion, and package-managed cleanup coverage.
- Adds no database migration and preserves config, database records, posts, pages, drafts, revisions, comments, users, media, uploads, custom themes, settings, analytics, API tokens, scheduled tasks, cron history, Local Places, and all other owner data.

## 0.5.75 - Legacy Admin CSS Consolidation Pass 3

- Continues the controlled cleanup of the legacy Admin stylesheet without changing Admin behavior or adding another override layer.
- Removes 104 legacy declarations across 36 admin-only rule groups whose effective selectors and properties are already owned later by `admin-shell.css`, `admin-editor-workflow.css`, `admin-operations.css`, or `admin-places.css`.
- Removes 14 rule blocks that became empty after the declaration cleanup and reduces `assets/admin.css` from 7,258 lines to 7,117 lines.
- Keeps 8,719 final effective Admin selector-property winners unchanged before and after the cleanup.
- Explicitly retains generic legacy rules used by the standalone installer, login screen, or public preview, including panels, form controls, shared buttons, field help, the Admin footer, and the public Local Places dialog.
- Adds tighter regression coverage for the third consolidation baseline and for the component-owned shell, editor, dashboard, import, Upgrade, and Local Places definitions.
- Adds no database migration and changes no routes, publishing, pages, comments, accounts, registration, themes, settings, Local Places data, tools, upgrades, permissions, or stored data.

## 0.5.74 - Legacy Admin CSS Consolidation Pass 2

- Continues the controlled cleanup of the legacy Admin stylesheet without changing Admin behavior or adding another override layer.
- Removes 127 legacy declarations whose exact selectors and responsive contexts are already defined later by `admin-shell.css`, `admin-editor-workflow.css`, or `admin-operations.css`.
- Removes 12 rule blocks that became empty after the declaration cleanup and reduces `assets/admin.css` from 7,429 lines to 7,258 lines.
- Keeps the complete final Admin selector-property cascade unchanged across 9,090 results before and after the cleanup.
- Keeps login, installation, and public preview styling intact because every removed declaration is scoped to `body.bonumark-admin`, which is emitted by the shared Admin layout that loads the replacement component styles.
- Adds tighter regression coverage for the second consolidation baseline and for the component-owned shell, editor, and Upgrade action definitions.
- Adds no database migration and changes no routes, publishing, pages, comments, accounts, registration, themes, settings, Local Places, tools, upgrades, permissions, or stored data.

## 0.5.73 - Legacy Admin CSS Consolidation Pass 1

- Begins the controlled removal of legacy Admin CSS instead of adding another override section.
- Removes 38 legacy rule blocks containing 76 declarations only where a later component stylesheet uses the same selector and responsive context and fully covers every removed property, then removes four empty responsive wrappers left behind by that cleanup.
- Reduces `assets/admin.css` from 7,601 lines to 7,429 lines while keeping the dedicated shell and editor component layers authoritative.
- Keeps login and public preview styling intact because the removed rules were scoped to `body.bonumark-admin`, which is emitted through the shared Admin layout that also loads the component styles.
- Restores the omitted v0.5.71 release history and aligns the current version, package title, description, cache name, README, changelogs, and release manifest.
- Adds regression coverage for the smaller legacy stylesheet and the component-owned shell and editor definitions.
- Adds no database migration and changes no routes, publishing, pages, comments, accounts, registration, themes, settings, Local Places, tools, upgrades, permissions, or stored data.

## 0.5.72 - Operations Label Pill Hotfix

- Fixes the Administrator tools and Software replacement pills so their labels stay centered and fully contained on desktop.
- Keeps operation-risk pills allowed to wrap safely on smaller screens instead of forcing overflow.
- Preserves the accepted Tools and Upgrade workflow structure, routes, and operational behavior.
- Adds no database migration and changes no imports, exports, upgrades, recovery, diagnostics, analytics, scheduled tasks, API access, security, Help, permissions, or stored data.

## 0.5.71 - Tools and System Polish Hotfix

- Contains long hosting paths and diagnostic messages inside System Check cards and keeps PASS, warning, and failure labels within the phone viewport.
- Tightens Tools card spacing, height rhythm, action alignment, and operational label consistency without changing any operation or route.
- Moves recent Upgrade history directly below package staging on desktop and replaces separate From and To fields with one readable version transition.
- Compresses phone upgrade-history records into a two-column layout while preserving validation, backups, recovery, migrations, and package handling.
- Extends package smoke coverage for diagnostic wrapping, operational labels, the revised Upgrade grid, history placement, and compact version-change records.
- Adds no database migration and preserves import, export, upgrade, analytics, scheduled-task, API, security, diagnostic, permission, and stored-data behavior.

## 0.5.70 - Tools and System Operations Pass

- Rebuilds the Tools hub around four deliberate operation groups: data movement and ownership, software upgrades, diagnostics and automation, and access and support.
- Distinguishes preview-only, sensitive, diagnostic, high-risk, and irreversible actions instead of presenting every system tool like a normal settings form.
- Rebuilds Import around staged upload, private preview, review warnings, explicit import rules, and final confirmation before database writes.
- Rebuilds private Markdown folder import as an advanced migration workflow with clear database authority and force-refresh guidance.
- Rebuilds Export into portable outputs and private backups, with explicit warnings for database and full packages that may contain account, authentication, API, registration, and security data.
- Rebuilds Upgrade around package staging, backup readiness, migration state, recovery boundaries, typed risk language, responsive precheck status, and responsive upgrade-history records.
- Rebuilds System Check as a read-only diagnostic dashboard with pass, warning, and failure summaries.
- Extends the shared operations system across Analytics, Scheduled Tasks, Remote Posting, Security, and Help, and moves Scheduled Tasks and Remote Posting into the System navigation group.
- Adds `assets/admin-operations.css` without modifying the 7,601-line legacy `assets/admin.css`.
- Adds no database migration and preserves import, export, upgrade, analytics, scheduled-task, API, security, and diagnostic behavior.

## 0.5.69 - Local Places Preview Polish Hotfix

- Replaces the empty Add Place preview text with neutral guidance: `Enter a place name to preview the public label`.
- Hides the location marker until the selected display mode produces a real public label.
- Replaces the stray dot with a consistent inline location-pin icon when a live preview is available.
- Keeps secondary location text hidden until a valid primary label exists and preserves live updates across desktop and phone layouts.
- Extends package smoke coverage for the empty preview, marker visibility, placeholder styling, and location-pin treatment.
- Adds no database migration and preserves saved places, coordinates, privacy behavior, display modes, Stream Post location snapshots, public output, permissions, and stored data.

## 0.5.68 - Local Places Workflow Pass

- Rebuilds Local Places as a searchable responsive directory with current-state summaries, category filtering, nearby saved-place discovery, and deliberate empty, loading, and error states.
- Replaces the older directory cards and inline delete controls with structured desktop records, purpose-built phone cards, and one Actions menu per saved place.
- Rebuilds Add Place and Edit Place around identity labels, private coordinates, current-location capture, nearby duplicate checks, a live public-label preview, clear save hierarchy, and an explicit destructive section.
- Replaces direct list deletion with a dedicated typed-name confirmation screen while preserving the existing rule that published posts keep their saved public location snapshot.
- Adds `admin-places.css` and `admin-places.js` as dedicated component assets, integrates them into the Admin shell and service worker, and extends package smoke coverage for the complete workflow.
- Adds no database migration and preserves Local Places records, private coordinates, post location snapshots, Stream Posts, pages, media, themes, accounts, comments, permissions, public routes, and stored data.

## 0.5.67 - Settings URL Overflow Hotfix

- Prevents API endpoint URLs from extending outside the Remote Posting endpoint panel on desktop and phone layouts.
- Prevents sitemap, robots, manifest, share-target, web-cron, installation-path, and Settings record technical values from overflowing their containers.
- Adds reusable Settings shrink guards plus `overflow-wrap: anywhere`, `word-break: break-word`, and width constraints for long technical values while keeping the full values readable.
- Adds semantic `settings-technical-value` markup to the affected Settings screens and package smoke coverage for the responsive URL treatment.
- Adds no database migration and preserves Settings values, API routes, tokens, audit records, scheduled-task behavior, sitemap behavior, content, permissions, and stored data.

## 0.5.66 - Settings Workflow Pass

- Rebuilds General, Reading, and Writing as one coherent settings workflow with current-state summaries, grouped controls, clear save actions, and deliberate desktop and phone layouts.
- Replaces the Security redirect with a real settings hub for HTTPS awareness, system checks, admin account access, remembered devices, registration and recovery, Remote API tokens, and upgrade safety.
- Reorganizes Mail around transport readiness, sender identity, SMTP credentials, test delivery, and responsive test-history records instead of a legacy table.
- Rebuilds Remote Posting around global API safety rules, endpoints, scoped token creation, responsive token records, and responsive audit activity without changing API routes, scopes, or token storage.
- Treats expired API credentials as expired in the active-token summary and token records without rewriting their stored status.
- Rebuilds Scheduled Tasks around runner health, fallback controls, server cron, protected web cron, and responsive run-history records without changing runner behavior.
- Adds `admin-settings.css` as a dedicated component layer, adds Security to Settings navigation, renames Stream Settings to Reading Settings, and includes the new stylesheet in the Admin shell and service worker.
- Adds no database migration and preserves all setting keys, account data, API tokens and hashes, audit records, mail credentials, scheduled-task history, content, media, themes, permissions, public routes, and stored data.

## 0.5.65 - Appearance Metadata Polish Hotfix

- Corrects the desktop Theme Settings metadata layout that forced Slug, Version, Author, and Fields values to wrap one character per line inside the narrow active-theme card.
- Gives the Slug fact a full-width row and places Version, Author, and Fields in a stable three-column row at desktop widths.
- Keeps the accepted tablet and phone layout unchanged by scoping the correction to screens above the phone breakpoint.
- Extends package smoke coverage for the desktop metadata structure.
- Adds no database migration and preserves themes, theme settings, activation, Site Identity, Navigation, public rendering, content, permissions, and stored data.

## 0.5.64 - Appearance and Site Design Pass

- Rebuilds Themes as the Site Design hub with active-theme context, installed-theme health, package summaries, responsive theme cards, and clearer activation, settings, details, install, and deletion actions.
- Makes Theme Settings an active-theme configuration screen instead of combining theme activation and unrelated setting fields in one form.
- Reorganizes Theme Details, theme installation, and theme deletion around package health, declared assets, editable settings, code-free package boundaries, and explicit destructive confirmation.
- Splits Site Identity into public framing and browser/app icon management, adds a current-state summary, and keeps one clear save action without changing identity or favicon storage behavior.
- Rebuilds Navigation around menu state, automatic account links, editable ordered menu records, published-page additions, custom links, and deliberate desktop and phone layouts.
- Adds `admin-appearance.css` as a dedicated component layer and includes it in the Admin shell and service-worker asset list instead of extending the legacy 7,601-line `admin.css`.
- Adds no database migration and preserves themes, theme settings, content, pages, media, favicons, navigation records, accounts, comments, permissions, public rendering, and all existing routes.

## 0.5.63 - Registration State Polish Hotfix

- Hides the Mail verification warning while public registration is disabled so a configured future requirement does not make a closed registration system look broken.
- Keeps the strong Mail readiness warning for Open registration and Invite only when email verification is required but Mail is unavailable.
- Clarifies in the current-state summary and Public Account Page summary that verification and approval rules apply when registration is enabled.
- Extends package smoke coverage for disabled-state warning suppression and deferred registration-rule wording.
- Adds no database migration and preserves registration modes, verification and approval settings, invite codes, account records, Mail settings, permissions, public account routes, and stored content.

## 0.5.62 - Registration Workflow Pass

- Rebuilds Commenter Registration as a coherent account-access workflow instead of a long stack of unrelated settings panels.
- Adds a current-state summary for registration mode, email verification, admin approval, and active invite codes, plus focused notices for mail readiness and pending accounts.
- Groups registration rules into readable option cards while preserving Disabled, Open registration, Invite only, verification, approval, and honeypot behavior.
- Replaces the legacy invite table with responsive invite records showing the private label, code hint, usage, expiration, status, and revoke action.
- Adds a dedicated `admin-registration.css` component layer and phone layouts for settings, summaries, invite creation, and invite records instead of extending the legacy Admin stylesheet.
- Adds no database migration and preserves config, account records, invite hashes, registration rules, comments, Stream Posts, pages, drafts, media, uploads, themes, permissions, and public account routes.

## 0.5.61 - Comments and Accounts Polish Hotfix

- Separates Comments and Accounts status-filter labels from their numeric counts so Approved, Pending, Trash, All, Active, and Inactive no longer visually run into their totals.
- Adds one shared filter-count badge treatment in `admin-content-list.css` for consistent spacing, readable tabular numbers, and clearer active-state hierarchy.
- Makes the Total accounts summary card span both columns on phone widths so the three-card account summary no longer leaves an empty grid cell.
- Extends package smoke coverage for the filter markup, shared count styling, and full-width mobile Total accounts card.
- Adds no database migration and preserves config, account records, comments, Stream Posts, pages, drafts, scheduled posts, revisions, media, uploads, likes, themes, analytics, Local Places data, permissions, and all public routes.

## 0.5.60 - Comments and Accounts Correction Pass

- Corrects the misplaced bulk-selection validation that caused an undefined `$action` warning while formatting comment dates.
- Moves Comment Admin display helpers into a small reusable file and adds a warnings-as-exceptions runtime regression test for comment date, status, and return-URL behavior.
- Rebuilds the desktop Comments control bar as one compact row containing Select all, the bulk action, Apply, and the current result summary without the dead vertical space exposed in acceptance testing.
- Replaces the legacy Accounts table and duplicate quick-update controls with responsive account records showing identity, email verification, account type, status, pending reason, and one Actions menu.
- Standardizes the navigation and page title on Accounts because the screen manages both the installer-created admin and commenter accounts.
- Moves Add Commenter to a dedicated creation screen with account-specific field names, explicit autocomplete controls, password-manager ignore hints, and no prefilled credentials.
- Adds `admin-accounts.css` as a dedicated component layer instead of extending the legacy Admin stylesheet.
- Adds no database migration and preserves config, account records, comments, Stream Posts, pages, drafts, scheduled posts, revisions, media, uploads, likes, themes, analytics, Local Places data, permissions, and all public routes.

## 0.5.59 - Comments and Moderation Pass

- Replaces the legacy Comments table with a responsive moderation record system designed for desktop and phone use.
- Adds status counts, comment search, scoped Select all, and bulk approve, hold, trash, restore, and permanent-delete controls while preserving CSRF and capability checks.
- Keeps the full comment text, commenter avatar and account identity, related Stream Post title and slug, moderation status, and site-timezone date visible without forcing every detail into table columns.
- Replaces inline moderation buttons with one accessible Actions menu per comment and gives Trash records explicit restore-as-approved, restore-as-pending, and permanent-delete choices.
- Adds deliberate empty states for approved, pending, Trash, and search results, plus a three-column phone status filter and purpose-built mobile comment cards.
- Restricts permanent deletion at the data layer to comments already in Trash and adds reusable bulk moderation helpers without adding a database migration.
- Adds a dedicated `admin-comments.css` component layer instead of extending the legacy Admin stylesheet.
- Preserves config, database data, comments, commenter accounts, Stream Posts, pages, drafts, scheduled posts, revisions, media, uploads, likes, themes, analytics, Local Places data, permissions, public comment rendering, and all existing public routes.

## 0.5.58 - Pages Workflow Polish Hotfix

- Replaces the horizontally clipped phone Page status filters with a two-column grid so All, Drafts, Published, and Trash remain visible without hidden horizontal scrolling.
- Corrects Page editor preview guidance so it refers to unsaved page edits instead of inheriting Stream Post language.
- Revalidates the shared mobile editor action dock against its fixed visual-viewport position, safe-area reserve, publishing-card visibility, toolbar collision, and on-screen keyboard guards without changing the dock behavior.
- Extends package smoke coverage for the Page filter layout, page-aware preview copy, and existing viewport-safety behavior.
- Adds no database migration and preserves config, database data, page records, slugs, public URLs, navigation, dynamic rendering, posts, drafts, scheduled posts, revisions, media, uploads, comments, likes, users, API tokens, themes, analytics, cron history, Local Places, permissions, and all existing routes.

## 0.5.57 - Pages Workflow Pass

- Replaces the legacy Pages admin table with the responsive record-list system introduced for Stream Posts.
- Reduces the permanent desktop layout to Page, Status, Date, and Actions while keeping descriptions, public URLs, storage state, and original trash information close to each record.
- Converts phone Pages into concise cards with title, preview text, status, URL, date, and one accessible action menu.
- Preserves edit, preview, view, publish, move-to-drafts, move-to-trash, restore, permanent-delete, search, filtering, and empty-trash workflows.
- Makes Page URL and Page Settings collapsible secondary editor cards while keeping Publish permanently visible.
- Adds page-aware Screen Controls and the shared viewport-safe mobile Save, Publish Page, View Page, and Options action dock.
- Uses the existing `admin-content-list.css` and `admin-editor-workflow.css` component layers instead of adding another page-specific stylesheet or extending the legacy Admin stylesheet.
- Adds no database migration and preserves config, database data, page records, slugs, public URLs, navigation, dynamic rendering, posts, drafts, scheduled posts, revisions, media, uploads, comments, likes, users, API tokens, themes, analytics, Local Places data, permissions, and all existing routes.

## 0.5.56 - Media Library Polish Pass

- Integrates View details into a full-width card footer so the action no longer floats beside privacy status on narrow media cards.
- Enlarges media selection and details touch targets, clamps filenames to two lines, and normalizes card body height across desktop and phone grids.
- Corrects the file-type badge hidden state so an IMAGE label no longer appears beside real image previews.
- Rebuilds the shared details surface around a fixed header, internally scrolling content region, and fixed action footer containing Copy Markdown, Edit media, and Open file.
- Makes the phone details experience a true bottom sheet with a 90dvh limit, page scroll locking, safe-area-aware actions, and restored page position after closing.
- Compacts phone page actions into a two-column layout and tightens mobile bulk controls without changing media actions or routes.
- Adds no database migration and preserves config, database data, media records, uploaded files, optimized variants, posts, pages, drafts, users, themes, analytics, Local Places data, permissions, and all existing media workflows.

## 0.5.55 - Media Library Pass

- Rebuilds the Admin Media Library around a browsing-first responsive thumbnail grid instead of full administrative forms repeated under every file.
- Keeps only the filename, compact file information, privacy status, selection control, and one Details action visible on each card.
- Moves captions, privacy warnings, trash dates, full file details, Markdown, Edit, and Open controls into one reusable accessible details dialog.
- Adds a two-column phone thumbnail grid and a safe-area-aware bottom-sheet details view so mobile browsing no longer requires one full-width card per media item.
- Preserves Library and Trash views, search, bulk selection, bulk trash, restore, permanent deletion, media editing, public file URLs, Markdown generation, privacy status, and optimized-image workflows.
- Adds a dedicated `admin-media-library.css` component layer instead of extending the legacy Admin stylesheet.
- Adds no database migration and preserves config, database data, media records, uploaded files, optimized variants, posts, pages, drafts, users, themes, analytics, Local Places data, permissions, and all existing media routes.

## 0.5.54 - Mobile Editor Action Bar Hotfix

- Locks the mobile Save, Post Now or View, and Options dock to the true bottom of the visual viewport with safe-area-aware spacing and a higher admin-layer stacking context.
- Reserves bottom scroll space and adds scroll margins so editor controls, the Publish card, and collapsed metadata cards can move fully above the dock instead of ending underneath it.
- Hides the dock while the editor command bar or formatting toolbar occupies the dock zone, preventing the fixed controls from covering Refresh Preview, Focus Mode, Shortcuts, or formatting buttons.
- Hides the dock when the Publish card is already visible so mobile users do not see duplicate publishing controls, and dismisses it while the on-screen keyboard is open so it does not reduce the writing viewport.
- Adds no database migration and preserves config, database data, posts, pages, drafts, scheduled posts, revisions, media, uploads, comments, likes, users, API tokens, themes, analytics, Local Places data, permissions, and all publishing routes.

## 0.5.53 - Editor Scroll and Disclosure Correction Pass

- Removes the nested desktop scrollbar from the full editor metadata rail so Post URL, Stream Post, Location, Media, and Revisions follow the browser's normal page scroll.
- Keeps only the Publish card sticky on wide desktop layouts and respects the existing Screen Controls option for disabling sticky publishing.
- Reduces the mobile writing surface baseline from 300 pixels to 260 pixels on phone widths and lowers the tablet baseline from 340 pixels to 300 pixels while retaining content-driven growth for longer posts.
- Moves Location from a one-off native disclosure into the same reusable side-card component used by Post URL, Stream Post, Media, and Revisions, including the matching Open and Close control, spacing, remembered state, and Screen Controls behavior.
- Adds no database migration and preserves config, database data, posts, pages, drafts, scheduled posts, revisions, media, uploads, comments, likes, users, API tokens, themes, analytics, Local Places data, permissions, and all publishing routes.

## 0.5.52 - Editor Workflow Pass

- Replaces viewport and sidebar-balanced editor sizing with a content-driven writing surface that stays compact for short posts and grows only when the post needs more room.
- Keeps the desktop publishing rail sticky and independently scrollable while preventing secondary metadata cards from forcing the editor to become thousands of pixels tall.
- Makes Publish the only always-open rail card and collapses Post URL, Stream Post metadata, Media, Location, and Revisions by default while preserving browser-remembered card choices and Screen Controls.
- Separates preview, save, publish, scheduling, pinning, draft movement, and trash actions into a clearer hierarchy, with destructive and uncommon actions placed under More actions.
- Adds a fixed mobile editor action bar for Save, Post Now or View, and publishing options so core actions remain reachable without scrolling through the full editor.
- Makes mobile Post Now submit the current editor contents through the existing edit workflow instead of publishing a stale saved copy.
- Removes the duplicate Markdown Bullets control and adds a dedicated `admin-editor-workflow.css` component layer instead of appending another editor patch to the legacy stylesheet.
- Adds no database migration and preserves config, database data, posts, pages, drafts, scheduled posts, revisions, media, uploads, comments, likes, users, API tokens, themes, analytics, Local Places, permissions, and all publishing routes.

## 0.5.51 - Content List Responsive Pass

- Replaces the Stream Posts admin table with one responsive record-list component designed for both desktop and mobile use.
- Reduces the permanent desktop columns to Post, Status, Date, and Actions while keeping pinned, media, storage, and original-status information available as compact metadata chips.
- Replaces long inline action chains with an accessible per-post Actions menu that preserves edit, quick edit, preview, revisions, view, pin, publish, unpublish, restore, trash, and permanent-delete workflows.
- Converts mobile Stream Posts into concise cards with the title, short preview, status, date, useful indicators, selection control, and one action menu instead of exposing every table field as a stacked label.
- Improves status-filter scrolling, search layout, date sorting, item counts, Select all, bulk actions, and narrow-screen touch targets.
- Adds a dedicated `admin-content-list.css` component layer rather than adding another page-specific patch to the legacy admin stylesheet.
- Adds no database migration and preserves config, database data, posts, pages, drafts, scheduled posts, revisions, media, uploads, comments, likes, users, API tokens, themes, analytics, Local Places, permissions, and all publishing behavior.

## 0.5.50 - Admin Shell Foundation Pass

- Rebuilds the shared Admin application shell without changing publishing, settings, account, media, comment, import, export, upgrade, or database behavior.
- Replaces the mobile layout that stacked the entire sidebar above page content with a real off-canvas navigation drawer and backdrop.
- Adds Menu and Close controls, Escape-key closing, focus transfer, focus return, and responsive `aria-hidden` and `aria-expanded` state.
- Groups navigation around Publish, Manage, Design, Settings, and System jobs instead of giving every utility equal visual weight.
- Adds clear active-page and active-section states while retaining capability-based access controls.
- Simplifies the desktop top bar, profile controls, page heading, action placement, panel surfaces, spacing, form fields, and touch targets through a new shared `admin-shell.css` foundation.
- Declutters the Dashboard by removing repeated action groups and duplicate Admin Notes while keeping the same site counts, attention items, recent content, analytics, and system status data.
- Adds no database migration and preserves config, database data, posts, pages, drafts, scheduled posts, revisions, media, uploads, comments, likes, users, API tokens, themes, analytics, cron history, Local Places, and all other site data.

## 0.5.49 - Front-End Move to Trash Pass

- Adds a recoverable **Move to trash** action to the existing authorized front-end Stream Post options menu.
- Keeps the action inside the three-dot menu so public post cards gain no permanent visual clutter.
- Requires confirmation before moving a post and never performs permanent deletion from the front end.
- Enforces login, post-specific edit permission, CSRF protection, and stale-content conflict detection.
- Removes the matching post card from the stream immediately when JavaScript is available, including posts appended through Load More.
- Redirects safely back to the stream for no-JavaScript submissions and single-post deletions.
- Preserves the complete post in Admin Trash for restoration or later permanent deletion.
- Adds no database migration and preserves config, database data, users, media, uploads, themes, settings, revisions, likes, comments, and all other site data.

## 0.5.48 - Front-End Quick Edit Pass

- Adds a quiet Quick edit action to the existing authorized front-end Stream Post options menu.
- Makes front-end edit options part of the authorized workflow instead of allowing the old display toggle to hide them; the saved legacy setting is left untouched for compatibility.
- Replaces only the rendered post text with an inline Markdown textarea, Save, and Cancel controls.
- Keeps Open full editor available for media, metadata, URLs, scheduling, Local Places, link previews, revisions, and structural changes.
- Creates a normal revision before every changed published body is saved.
- Enforces login, post-specific edit permissions, CSRF protection, stale-content conflict detection, and the existing 2 MB editor limit.
- Updates only `content_body`, `content_hash`, and `updated_at`, preserving status, dates, slug, title, SEO fields, author, media, gallery order, place data, link previews, pins, likes, and comments.
- Supports Escape to cancel and initializes Quick edit controls on posts appended through Load More.
- Adds no database migration and preserves all existing config, database data, content, users, media, uploads, themes, settings, and site data.

## 0.5.47 - Compact Composer Controls Pass

- Restores the front-end composer’s quick-post visual hierarchy after the unified workflow added too many always-visible controls.
- Keeps media, scheduling, location, More options, and the primary Post action in the default toolbar.
- Moves Save draft, Continue in full editor, and Advanced options into one compact More options menu.
- Keeps Advanced metadata hidden until deliberately opened and adds close-button and Escape-key handling.
- Preserves publishing, scheduling, draft creation, full-editor continuation, Local Places, media galleries, link previews, and mobile share-target behavior.
- Adds no database migration and preserves all existing config, content, users, media, uploads, themes, settings, and site data.

## 0.5.46 - Unified Stream Composer Pass

- Makes the front-end stream composer the canonical place to create Stream Posts.
- Adds explicit Post, Schedule, Save draft, and Continue in full editor workflows.
- Adds an optional Advanced panel for internal title, slug, meta description, search title, and per-post indexing.
- Saves Continue in full editor submissions as database drafts before opening the existing full Admin editor.
- Retains the full editor for drafts, scheduled posts, published posts, revisions, previews, formatting, and media-library insertion.
- Retires the user-facing backend New Stream Post form and keeps `admin/new.php` as a compatibility redirect.
- Replaces Admin New Stream Post links with Open Stream Composer actions.
- Makes the composer permanently available to signed-in users who can edit content, preventing an old visibility setting from removing the only supported creation path.
- Preserves Pages, Remote Posting, mobile share-target intake, media, Local Places, link previews, scheduling, themes, settings, users, and database content.

## 0.5.45 - Gallery Preview and Viewer Fix Pass

### Fixed

- Fixes broken front-end photo previews by allowing local `blob:` image URLs in the Content Security Policy.
- Keeps composer object URLs alive while previews are displayed and revokes them when the preview is rebuilt or cleared.
- Replaces raw same-tab image navigation with a core full-size photo viewer for single photos and galleries.
- Adds a visible close button, click-outside closing, Escape-key support, focus restoration, and previous/next gallery controls.
- Uses delegated core viewer handling so photos appended through Load More work automatically.
- Keeps direct image links as the no-JavaScript fallback.
- Keeps viewer markup, behavior, and fallback CSS in core so existing code-free custom themes receive the fix automatically.

### Compatibility and upgrade notes

- No database migration runs in this release.
- Gallery storage, `featured_media`, responsive variants, API behavior, imports, exports, revisions, and scheduled publishing remain unchanged.
- Existing config, database data, posts, pages, drafts, revisions, users, comments, likes, media, uploads, themes, settings, analytics, API tokens, cron history, scheduled posts, Local Places, coordinates, consent records, and post location snapshots are preserved.

## 0.5.44 - Four-Photo Gallery Pass

### Added

- Adds ordered one-to-four-photo galleries to the front-end composer and full Admin editor.
- Adds preview, removal, and reordering controls before a post is saved.
- Adds structured `media_gallery` post metadata while keeping the first image in `featured_media` for old posts, themes, feeds, Open Graph metadata, and existing integrations.
- Adds responsive core gallery markup with stable classes, dimensions, `srcset`, count-aware `sizes`, asynchronous decoding, lazy loading, and first-visible-image priority.
- Adds core fallback gallery CSS so existing custom themes remain usable without updates.
- Adds a documented CSS-only theme contract and optional `supports.media_galleries` declaration.
- Adds explicit Remote API gallery creation with `media_display: "gallery"` while preserving inline media as the default.
- Preserves gallery order through database storage, revisions, trash restores, Markdown and JSON imports, and Bonumark export restores.

### Compatibility and upgrade notes

- No database migration runs in this release because the existing front-matter JSON storage already supports the ordered gallery field.
- Existing single-image posts continue to render through `featured_media` unchanged.
- Existing API clients continue to insert media into Markdown unless they explicitly request gallery mode.
- Existing config, database data, posts, pages, drafts, revisions, users, comments, likes, media, uploads, themes, settings, analytics, API tokens, cron history, scheduled posts, Local Places, coordinates, consent records, and post location snapshots are preserved.

## 0.5.43 - Token-Scoped Stream Read API

Bonumark Stream now exposes a general-purpose, read-only Stream catalog through the existing `/api/v1/stream/posts` resource. Authorized clients with the new `stream:read` scope can page through published Stream posts, retrieve a stable published post by ID, filter by modification time, and request rendered HTML alongside Markdown. The API remains product-neutral and contains no client-specific integration logic.

- Added `GET` and `HEAD` support to `/api/v1/stream/posts` while preserving the existing `POST` publishing behavior.
- Added the published-content-only `stream:read` token scope and Admin token control.
- Added stable IDs, permalinks, content hashes, categories, tags, timestamps, pin state, Markdown, optional HTML, and public metadata to read responses.
- Limited Local Places metadata to the same public labels selected for the published post, without exposing saved place IDs or hidden location components.
- Batched category and tag loading per catalog page to avoid one database query per post.
- Added pagination metadata and total-count response headers.
- Added single-record lookup and `modified_after` filtering for synchronization clients.
- Updated public API documentation, OpenAPI metadata, smoke checks, and release metadata.

## 0.5.42 - GitHub Release Hardening Pass

Bonumark Stream v0.5.42 hardens fresh-install timestamp consistency and completes release documentation for the work since v0.5.30.

- Sets the installer PDO session to UTC before running migrations and seed statements.
- Uses an explicit UTC timestamp for the initial Admin email-verification value.
- Restores v0.5.35 and v0.5.37 in the upgrade guide, corrects the Local Places admin label, and fixes the mislabeled v0.5.24 timestamp section.
- Extends package smoke coverage so installer UTC handling and the required post-v0.5.30 upgrade notes cannot silently regress.
- Adds no database migration and preserves all existing site data and user-owned files.

## 0.5.41 - Local Places Metadata Alignment Hotfix

Bonumark Stream v0.5.41 tightens the saved-place card metadata layout without changing Local Places data or behavior.

- Removes the floating category badge.
- Places Category beside Default public display as labeled supporting metadata.
- Keeps the place title and location visually primary and the right side focused on actions.
- Adds no database migration and preserves all existing places and post snapshots.

## 0.5.40 - Local Places Admin Polish Pass

Bonumark Stream v0.5.40 polishes the Local Places admin directory without changing its data or behavior.

- Replaces the wide table with compact responsive saved-place cards.
- Adds a saved-place count, stronger name and location hierarchy, category badges, and readable display-mode details.
- Moves the coordinate privacy explanation into a subdued information notice.
- Separates Edit place and Delete into clear standard and destructive actions.
- Adds no database migration and preserves all existing places and post snapshots.

## 0.5.39 - Local Places Visual Consistency Hotfix

Bonumark Stream v0.5.39 makes the Local Places composer interface visually consistent with the existing Schedule panel.

- Uses the same default-theme panel background, border, radius, field colors, and muted text system as scheduling.
- Aligns the selected-place row and add-place dialog with the Midnight Ledger theme tokens.
- Keeps orange limited to active controls and actions instead of tinting the entire Places panel.
- Adds no database migration and changes no Local Places behavior or stored data.

## 0.5.38 - Local Places Geolocation Hotfix

Bonumark Stream v0.5.38 restores the browser location controls introduced with Local Places.

- Allows geolocation for the Bonumark Stream origin instead of blocking it through Permissions Policy.
- Moves the Admin **Use current location** handler into the external admin script so the Content Security Policy permits it to run.
- Reports clear HTTPS, permission-denied, unavailable-location, and timeout messages.
- Adds no database migration and preserves all existing Local Places and post snapshots.

## 0.5.37 - Local Places Composer Simplification Pass

Bonumark Stream v0.5.37 simplifies the Local Places posting workflow without changing the underlying place directory or stored post location data.

- Reduces both Stream Post composers to saved-place selection, nearby lookup, and a compact add-place dialog.
- Removes category, area, locality, region, country, coordinates, and per-post display-mode controls from the composer interface.
- Keeps full place-detail editing under Admin → Local Places.
- Adds only place name and an optional public location label when creating a place from a composer.
- Automatically uses the saved place's default public display mode.
- Collapses selected places into a compact Change/Remove row.
- Adds no database migration and preserves all existing Local Places and post snapshots.

## 0.5.36 - Local Places Check-In Pass

Bonumark Stream v0.5.36 adds a private local check-in system that works without paid APIs or outside places services.

### Added

- `_bonumark_stream/app/places.php` for Local Places validation, CRUD, nearby distance matching, post snapshots, picker rendering, and public display data.
- Migration `0014_local_places.php` for the local places table.
- Protected admin Local Places list, add/edit, delete, nearby lookup, and composer-save endpoints.
- Local Places controls in both Stream Post composers.
- Public location rendering with exact-place, approximate-area, and city-only labels.
- Database and Markdown front-matter persistence for location snapshots.

### Privacy and resilience

- Device location is requested only after an explicit button press.
- Latitude and longitude are never emitted in public post HTML.
- Saved places remain selectable if location permission is denied.
- Existing posts keep their saved display text if a place is later deleted.
- No external places service or shared directory is required.

## 0.5.35 - Root RSS Discovery Hotfix

Bonumark Stream v0.5.35 restores root-level RSS discovery after noticing public pages advertised the `/stream/feed.xml` feed instead of the root feed.

- Changes public page RSS discovery metadata to advertise `/feed.xml` as the canonical feed URL.
- Keeps `/stream/feed.xml` available as a compatibility alias.
- Makes the root RSS feed channel link point to the site root while the stream alias feed can still point to `/stream/`.
- Does not add a database migration and does not rewrite posts, pages, users, comments, analytics, themes, API tokens, cron history, uploads, media files, or existing settings.

## 0.5.34 - Privacy-Safe Media Uploads Pass

Bonumark Stream v0.5.34 adds privacy-safe media upload handling for normal shared hosting.

- Randomizes the public filename for uploaded media instead of using the original filename in public URLs.
- Attempts metadata stripping for supported image uploads by re-encoding images through PHP image handling, with Imagick used only when available.
- Keeps best-effort mode as the default so uploads remain usable when metadata removal cannot be confirmed.
- Adds optional strict privacy mode under **Admin → Settings → Writing** for sites that prefer rejecting image uploads over storing unconfirmed image metadata.
- Shows media privacy status and warnings in the admin media library and media edit screen.
- Adds migration `0013_privacy_safe_media_uploads.php` for media privacy status fields and the `media_privacy_mode` setting.

## 0.5.33 - Analytics Report Card Polish Pass

Bonumark Stream v0.5.33 polishes the Privacy-First Analytics report-card presentation without changing analytics collection or storage.

- Replaces compact report tables with label/count summary rows on **Admin → Tools → Analytics**.
- Removes table headers from small aggregate cards and replaces empty tables with cleaner empty-state copy.
- Humanizes device, browser, and direct-referrer labels while preserving CSV export output.
- Does not change analytics schema, migrations, privacy boundaries, collector behavior, settings, public output, themes, posts, pages, users, media, API tokens, cron history, or existing data.

## 0.5.32 - Analytics Report Warning Hotfix

Bonumark Stream v0.5.32 fixes a confirmed PHP 8.1+ warning in the Privacy-First Analytics report renderer.

- Makes the analytics admin-table helper read its optional formatter key safely before determining whether the column uses a callback or a direct row key.
- Removes `Undefined array key "value"` warnings from key-only report columns, including daily views, entry pages, referrer domains, device categories, and browser families.
- Does not change analytics storage, database schema, migrations, collection behavior, privacy boundaries, settings, public output, themes, posts, pages, users, media, API tokens, cron history, or existing data.

## 0.5.31 - Privacy-First Analytics Pass

Bonumark Stream v0.5.31 adds an optional, self-hosted, cookieless aggregate analytics layer without adding a visitor identity or consent system.

- Adds `analytics_daily`, an aggregate-only daily counter table with no raw page-view event log.
- Keeps analytics disabled by default on fresh installs and upgrades.
- Adds same-origin core collector behavior for eligible public reading routes only, with bot filtering and no analytics cookies, browser storage, IDs, sessions, IP values, IP hashes, raw user agents, or user-agent hashes.
- Stores clean public paths, referrer hostnames only, broad device and browser groups, and sanitized UTM source, medium, and campaign values.
- Adds Admin → Tools → Analytics settings, reporting, CSV export, retention cleanup, confirmed aggregate-data deletion, and a restrained dashboard Traffic card.
- Keeps themes presentation-only. No theme JavaScript, templates, settings, hooks, or tracking code are required.
- Adds migration `0012_privacy_first_analytics.php`.

## 0.5.30 - GitHub Release Hardening Pass

Bonumark Stream v0.5.30 prepares the current v0.5.x line for public GitHub distribution without adding product features or changing runtime behavior.

- Aligns release version markers, package metadata, public documentation, OpenAPI metadata, API examples, and installer copy.
- Makes the installer render its version dynamically from the packaged version marker so future releases cannot leave the welcome screen stale.
- Adds a public root changelog, expanded contribution guidance, and explicit GitHub private vulnerability-reporting guidance.
- Uses a clean single-root release ZIP for predictable GitHub downloads and cPanel extraction.
- Does not modify the database schema, migrations, posts, pages, dates, times, users, comments, media, themes, settings, uploads, backups, cron history, API tokens, or existing installations.

## 0.5.29 - PWA Direct Favicon Fallback Fix

Bonumark Stream v0.5.29 fixes the v0.5.28 PWA icon fallback on shared-hosting PHP builds without GD or Imagick.

- Keeps generated square PNG PWA icons when GD or Imagick is available.
- Uses the selected Site Identity favicon directly, with its native MIME type and dimensions, when the host cannot generate a resized PNG.
- Ensures the PWA manifest and Apple app-icon metadata use the selected favicon instead of falling back to the bundled Bonumark B solely because an image extension is unavailable.
- Keeps the bundled Bonumark B only when no valid Site Identity favicon is selected or its public file cannot be read.
- Rotates the service-worker cache name so installed apps can refresh their PWA metadata after upgrading.
- Does not change posts, users, media records, themes, settings, database schema, or content.

## 0.5.28 - PWA Site Identity Icon Pass

Bonumark Stream v0.5.28 makes installed PWA icons follow the favicon selected in Site Identity.

- Uses the existing Site Identity favicon as the source for versioned 192 × 192 and 512 × 512 PNG PWA icon responses.
- Adds the managed `pwa-icon.php` endpoint, which center-crops the selected image into a square icon and falls back to the bundled Bonumark B when no usable favicon or image processor is available.
- Updates the dynamic web app manifest and Apple mobile app metadata to use the selected favicon-derived icon instead of hardcoded bundled icons.
- Removes hardcoded app icons from the service-worker precache so a changed favicon gets a new versioned icon URL instead of a stale cached install icon.
- Keeps existing Site Identity settings, uploads, media records, themes, posts, users, and PWA settings unchanged. No database migration is required.

## 0.5.27 - MariaDB Upgrade Compatibility Hotfix

Bonumark Stream v0.5.27 fixes a confirmed MariaDB upgrade failure without adding product features.

- Replaces the parameterized `SHOW TABLES LIKE :table_name` migration safety check with MariaDB-compatible quoted SQL. MariaDB rejects placeholders in this `SHOW` statement, which caused the v0.5.25 upgrader to fail after safely copying files and before recording upgrade completion.
- Makes the optional database smoke test use the same MariaDB-compatible quoted `SHOW TABLES`, `SHOW COLUMNS`, and `SHOW INDEX` checks.
- Adds package smoke-test coverage so a parameterized `SHOW` statement cannot quietly return in a future release.
- Does not change posts, dates, times, settings, users, themes, media, uploads, API tokens, cron history, or public behavior.

## 0.5.26 - Upgrade Recovery and UTC Consistency Pass

Bonumark Stream v0.5.26 resolves confirmed release-audit findings without adding product features.

- Makes future admin ZIP upgrades forward-only once the database migration phase begins. Failures before that phase restore the previous software files. Failures after it retain the newer compatible files, write a private recovery marker, record recovery-required history, and allow the exact package to resume safely.
- Stores remember-device expiry and rotation timestamps in canonical UTC. Admin-entered invite expirations are interpreted in the configured site timezone, converted to UTC for storage, validated as UTC, and rendered back in the site timezone.
- Aligns nearby database-bound account verification, comment approval, initial admin verification, and mail-test timestamps with UTC storage.
- Makes package smoke and migration-recovery scripts CLI-only in addition to the existing Apache/LiteSpeed directory denial.
- Adds a server-side, locked, salted-IP-hash throttle for PWA Web Share Target submissions. The payload remains session-bound and never publishes automatically.
- Removes legacy GET logout behavior. Sign-out now remains a CSRF-protected POST action only.
- Keeps public Stream post dates and times displayed in the configured site timezone. Visitor-local conversion is intentionally not added.

## 0.5.25 - Release Audit Remediation Pass

Bonumark Stream v0.5.25 resolves confirmed release-audit findings without adding product features.

- Repairs the legacy `1970-01-01 00:00:00` published-timestamp cutover fallback through a safe upgrade preflight plus corrective migration. Valid cutovers remain untouched, direct legacy upgrades receive the actual upgrade boundary, and fresh UTC-era installs use their install boundary.
- Changes the PWA Web Share Target from GET to POST so shared text and URLs do not enter browser-visible URLs, access logs, or redirect query strings. Shared content remains session-bound and lands only in the front-end composer after login and publish-capability checks.
- Treats MySQL/MariaDB DDL migrations as resumable rather than transactional. Failed DDL migrations are not marked complete and retry through existing idempotent safeguards.
- Scopes a Bonumark session cookie name and path to each installation, including subdirectory installs.
- Adds same-origin protection for anonymous browser like requests while retaining public likes and rate limiting.
- Classifies root `manifest.php` and `sw.js` as managed upgrade files so future removed PWA root files are cleaned safely.
- Replaces raw admin exception messages with sanitized server-side logging and generic UI notices.
- Updates stale release wording and release validation coverage.

## 0.5.24 - Legacy Published Timestamp Compatibility Hotfix

Bonumark Stream v0.5.24 corrects the v0.5.23 regression that shifted pre-existing published Stream posts by the site timezone offset.

- Records the exact v0.5.23 upgrade time from existing upgrade history, without modifying posts.
- Treats published timestamps before that boundary as legacy site-local values, preserving the display they had before today’s timezone work.
- Keeps published timestamps created after the v0.5.23 boundary as canonical UTC values, so new posts remain correct.
- Corrects public Stream cards, single posts, admin content date labels, and chronological Stream sorting through one shared compatibility rule.
- Does not rewrite post records, titles, bodies, media, date fields, scheduled posts, drafts, pages, or imports.

## 0.5.23 - Timezone Runtime and UTC Canonicalization Hotfix

Bonumark Stream v0.5.23 fixes Stream post timestamps displaying four hours ahead when the saved **General Settings** timezone differed from the original `config.php` timezone.

- Makes the persisted site timezone the active PHP runtime timezone after installation, instead of leaving `config.php` as the long-term display authority.
- Locks every PDO MySQL/MariaDB connection to UTC so `NOW()` writes remain canonical regardless of the database server timezone.
- Renders public Stream post dates, account activity dates, dashboard timestamps, and ISO timestamps explicitly in the saved site timezone, without depending on PHP's incidental default timezone.
- Uses UTC when creating direct published-at database values, keeping new posts aligned with scheduled-post and cron behavior.
- Existing post data corrects on display immediately. No migration, content rewrite, or manual timestamp repair is required.

## 0.5.22 - Post Update PDO Binding Compatibility Hotfix

Bonumark Stream v0.5.22 fixes the remaining **Save failed. SQLSTATE[HY093]: Invalid parameter number** error when updating an existing Stream post on MySQL/MariaDB hosting environments that still reject the long named-parameter update statement.

- Replaces the complete existing-post database update bind set with ordered positional PDO placeholders.
- Covers existing draft, published, scheduled, renamed, and pinned Stream posts through the shared database-first save path.
- Leaves the post fields, pin state rules, schedule handling, author preservation, revisions, themes, API behavior, PWA behavior, cron, and public output unchanged.
- Requires no database migration, content rewrite, or configuration change.

## 0.5.21 - Post Update Save Hotfix

Bonumark Stream v0.5.21 fixes the **Save failed. SQLSTATE[HY093]: Invalid parameter number** error when updating an existing post on MySQL/MariaDB installations using native PDO prepared statements.

- Replaces repeated named placeholders in the existing-post update query with distinct bound parameter names.
- Restores normal saves for existing draft, published, scheduled, and pinned stream posts.
- Fixes the same native-prepared-statement compatibility issue in comment-status updates.
- Requires no database migration, content rewrite, or configuration change.
- Leaves cron behavior, scheduled-task history, post timestamps, theme structure, API behavior, PWA behavior, and public output unchanged.

## 0.5.20 - Scheduled Tasks UTC Timestamp Hotfix

Bonumark Stream v0.5.20 fixes the **Admin → Settings → Scheduled Tasks → Run history** time display for server-cron, web-cron, and manual runs.

- Parses stored scheduled-task history timestamps explicitly as UTC before converting them to the configured site timezone.
- Corrects incorrect local times caused by PHP interpreting UTC database values in the server or application default timezone.
- Applies to existing history rows immediately, with no database migration or data rewrite required.
- Leaves cron execution, CLI behavior, web cron keys, task history storage, fallback checks, scheduled-post timing, API behavior, and theme structure unchanged.

## 0.5.19 - Scheduled Tasks Run History Alignment Hotfix

Bonumark Stream v0.5.19 fixes the **Admin → Settings → Scheduled Tasks → Run history** table so each header lines up with the correct value.

- Replaces the reused six-column Stream Posts table pattern with a dedicated five-column task-history table.
- Defines stable column widths for When, Source, Result, Published, and Details.
- Keeps manual, server-cron, and web-cron history data unchanged.
- Improves mobile task-history readability by labeling each stacked value.
- Leaves the reusable task runner, cron paths, keys, fallbacks, scheduled-post timing, permissions, API behavior, and theme structure unchanged.

## 0.5.18 - Scheduled Tasks and Cron Foundation Pass

Bonumark Stream v0.5.18 turns the existing scheduled-post checks into a reusable Scheduled Tasks foundation without changing theme structure or scheduled-post permissions.

- Adds one shared due-task runner for public traffic, browser heartbeat, admin, manual, server cron, and protected web cron execution.
- Preserves the existing scheduled-post publisher, lock, public hiding rules, front-end composer heartbeat, admin heartbeat, and manual behavior.
- Adds a CLI-only server cron script at `scripts/run-scheduled-tasks.php`.
- Adds an optional protected web cron endpoint at `/api/v1/cron`, authenticated with a generated key stored only as a hash.
- Adds Admin → Settings → Scheduled Tasks with fallback controls, server and web cron setup details, runner health, manual execution, and retained manual/cron run history.
- Adds a migration for task-run history and scheduled-task settings.
- Keeps public traffic and active-browser checks as configurable fallbacks instead of treating them as real cron.
- Establishes the reusable task foundation for future features that need dependable scheduled execution.

## 0.5.17 - Post Options Menu Alignment Hotfix

Bonumark Stream v0.5.17 fixes the visual alignment inside the front-end three-dot **Post options** menu.

- Makes button-based actions such as **Pin to Stream** or **Unpin from Stream** use the same left-aligned layout as the **Edit** link.
- Overrides the bundled theme's broad public button centering only inside the post-options menu.
- Keeps the compact menu, its current below-trigger placement, pinning behavior, permissions, feeds, search, exports, API, PWA, and mobile behavior unchanged.
- Updates the bundled Midnight Ledger reference theme CSS to 1.2.6, fallback styling, PWA cache version, package metadata, release manifest, and current-version documentation.

## 0.5.16 - Post Options Menu Visibility Hotfix

Bonumark Stream v0.5.16 fixes the front-end Post options menu so it remains visible and usable when it opens below its three-dot trigger.

- Stops the bundled Midnight Ledger stream card from clipping the open menu.
- Keeps the menu layered above the following stream card while it is open.
- Keeps the compact three-dot control, Edit action, Pin to Stream or Unpin from Stream action, permissions, and mobile behavior unchanged.
- Updates the bundled Midnight Ledger reference theme CSS to 1.2.5, fallback styling, PWA cache version, package metadata, release manifest, and current-version documentation.

## 0.5.15 - Post Options Menu Position Hotfix

Bonumark Stream v0.5.15 fixes the front-end Post options menu placement without changing its actions, authorization, or pinning behavior.

- Positions the three-dot post menu below its trigger instead of opening upward across the current post card.
- Keeps the compact menu, front-end Edit action, secure Pin to Stream or Unpin from Stream action, reader controls, and mobile behavior unchanged.
- Updates the bundled Midnight Ledger reference theme CSS to 1.2.4, fallback styling, PWA cache version, package metadata, release manifest, and current-version documentation.

## 0.5.14 - Post Actions Menu Pass

Bonumark Stream v0.5.14 makes the front-end post action row quieter without changing post permissions or pin behavior.

- Replaces the visible front-end **Edit** and **Pin to Stream** or **Unpin from Stream** pills with one compact three-dot **Post options** menu.
- Keeps reader-facing likes and comments visible in the normal action row.
- Uses semantic `<details>` markup so the menu works without JavaScript, while preventing card click-through navigation when the menu is used.
- Keeps authorization, CSRF-protected pinning, post state rules, RSS, sitemap, search, static export, Remote Posting API, PWA, and share-to-post behavior unchanged.
- Adds core fallback styling and Midnight Ledger styling for the post options menu, including mobile use.
- Updates README, install, architecture, theming, upgrade, API, package metadata, service-worker cache version, and release manifest for v0.5.14.

## 0.5.13 - Pinned Posts Pass

Bonumark Stream v0.5.13 adds core-owned pinned stream posts without changing normal publishing behavior.

- Adds `is_pinned` and `pinned_at` post metadata through a safe database migration.
- Adds secure Pin to Stream and Unpin from Stream actions in the front-end post controls, back-end editor, and Admin → Stream Posts list.
- Supports multiple pinned posts, ordered by most recently pinned first.
- Adds a quiet Pinned area above the homepage timeline and removes those same records from the page-one timeline so posts are not duplicated.
- Prevents drafts, scheduled posts, pages, unpublished content, and trash from being pinned publicly. Moving a pinned post out of published stream status clears pin state.
- Keeps RSS/feed order, sitemap behavior, search, normal archive behavior, static export output, Remote Posting API behavior, PWA install behavior, and share-to-post flow unchanged.
- Adds core fallback styling and Midnight Ledger presentation styling without moving pin logic into themes.
- Updates README, architecture, theming, install, upgrade, package metadata, service-worker cache version, and release manifest for v0.5.13.

## 0.5.12 - Scheduled Admin Sort Source Fix Pass

Bonumark Stream v0.5.12 fixes the admin stream-post list sort source for scheduled posts.

- Uses UTC-aware scheduled and published timestamps when sorting stream posts in admin and public helpers.
- Makes scheduled posts sort by the same effective time shown in the admin Date column.
- Prevents newly scheduled posts from drifting lower in All Stream Posts behind older published posts.
- Keeps the clean scheduled date display added in v0.5.11.
- Leaves scheduling logic, public runner behavior, timestamp publishing behavior, PWA/share flow, front-end composer behavior, and back-end composer behavior unchanged.
- Updates package metadata, service worker cache version, docs, and release manifest for v0.5.12.

## 0.5.11 - Scheduled Admin List Date Polish Pass

Bonumark Stream v0.5.11 fixes scheduled-post ordering and date display in the admin stream-post list.

- Sorts scheduled posts by their scheduled publish time in the All Stream Posts list.
- Keeps scheduled posts in the same date order as published posts instead of drifting lower based on their original creation time.
- Shows the scheduled post date column as a clean site-local date and time without appending the timezone name.
- Keeps the scheduled/published timestamp behavior introduced in v0.5.8.
- Leaves scheduling logic, public runner behavior, PWA/share flow, front-end composer behavior, and back-end composer behavior unchanged.
- Updates package metadata, service worker cache version, docs, and release manifest for v0.5.11.

## 0.5.10 - Backend Composer Publish Box Polish Pass

Bonumark Stream v0.5.10 polishes the back-end composer Publish box without changing scheduled-post logic.

- Hides the scheduled publish time field by default on new draft posts.
- Keeps Save Draft and Post Now as the primary visible back-end editor actions.
- Adds quiet Schedule for later and Reschedule disclosures that reveal the schedule field only when needed.
- Shows already scheduled posts with clear scheduled status, scheduled time, Reschedule, Post Now, and Cancel Schedule actions.
- Leaves scheduling logic, due runner behavior, timestamp handling, PWA/share flow, and the front-end composer unchanged.
- Updates package metadata, service worker cache version, docs, and release manifest for v0.5.10.

## 0.5.9 - Public Traffic Scheduled Runner Pass

Bonumark Stream v0.5.9 makes public traffic the primary shared-hosting trigger for scheduled posts.

- Added a public-request scheduled-post runner helper that only runs on safe GET/HEAD requests.
- Runs due scheduled-post checks before public stream, feed, sitemap, search, profile, account, page, comments, and robots handlers load public output.
- Keeps the existing throttle and lock so normal traffic does not run heavy scheduled-post work on every request.
- Keeps the authenticated admin and front-end composer heartbeats as backup helpers instead of the primary trigger.
- Keeps scheduled posts hidden from public queries until they are published.
- Keeps the v0.5.8 scheduled/published timestamp behavior intact.
- Documents that exact-to-the-minute scheduled publishing requires server cron or an external ping hitting a public URL.

## 0.5.8 - Scheduled Publish Time Fix Pass

Bonumark Stream v0.5.8 tightens scheduled publishing behavior after the front-end scheduling fixes.

- Reduced the conservative scheduled-post traffic runner throttle from five minutes to thirty seconds.
- Added an authenticated scheduled-post runner endpoint for active admin/front-end composer sessions.
- Added lightweight admin and front-end composer heartbeats so due posts are checked while the site owner is actively using Bonumark Stream.
- When a scheduled post becomes public, Bonumark now uses the scheduled/published timestamp for public display, feeds, single-post metadata, and exported Markdown front matter instead of the original creation time.
- Converted scheduled publish dates through the site timezone for date storage.
- Kept scheduled-post storage, permissions, public hiding, PWA/share-to-post, and existing API behavior intact.

## 0.5.7 - Front-End Scheduler Submit Fix Pass

Bonumark Stream v0.5.7 fixes the front-end composer scheduling submit path introduced during the v0.5.6 UI polish.

- Added hidden front-end composer fields for submit intent and active schedule state.
- Updated the composer JavaScript so activating scheduling updates those hidden fields immediately and again at submit time.
- Removed reliance on mutating the visible submit button value for scheduling intent.
- Hardened the quick-post endpoint so it treats either explicit schedule action or active schedule state as a scheduled post request.
- Kept the compact v0.5.6 composer UI, backend scheduling controls, scheduled-post storage, PWA/share-to-post flow, and public hiding behavior intact.
- Updated package metadata, service worker cache version, docs, and release manifest for v0.5.7.

## 0.5.6 - Front-End Composer Scheduling UI Polish Pass

Bonumark Stream v0.5.6 polishes the front-end composer scheduling UI without changing scheduled-post core behavior.

- Reworked the front-end composer into a cleaner posting-box toolbar flow.
- Replaced the large Attach media pill and full-width Schedule instead block with compact composer action buttons.
- Added an inline schedule panel that only appears when scheduling is active.
- Changed the main composer submit button from Post to Schedule when the scheduler is active.
- Kept the back-end composer/editor scheduling controls unchanged.
- Preserved scheduled-post storage, public hiding, due-post publishing, Remote Posting API behavior, and PWA/share-to-post routing.
- Updated package metadata, service worker cache version, docs, and release manifest for v0.5.6.

## 0.5.5 - Scheduled Posts Pass

Bonumark Stream v0.5.5 adds scheduled stream posts while keeping the front-end composer as the primary posting flow.

- Added a scheduled stream-post status and `scheduled_at` database field with migration support for fresh and upgraded installs.
- Added scheduling controls to the front-end composer and back-end editor.
- Added scheduled-post editing, rescheduling, cancel-to-draft, trash/restore, quick edit, admin list filtering, and publish-now support.
- Added a conservative traffic-triggered due-post runner with throttling and a lock, plus a manual Tools action to run due scheduled posts.
- Kept scheduled posts out of public timeline, single routes, feeds, sitemap, search, and static export until published.
- Added site-timezone display for schedule fields while storing canonical scheduled times in UTC.
- Added optional `scheduled_at` support for trusted Remote Posting API clients without changing existing draft/publish behavior.
- Updated README, docs, package metadata, service worker cache version, and release manifest for v0.5.5.

## 0.5.4 - Stream Settings Label Cleanup Hotfix

Bonumark Stream v0.5.4 cleans up stale Reading Settings wording on the admin Stream settings screen.

- Changed the admin settings page title from Reading Settings to Stream Settings.
- Changed the visible page heading from Reading to Stream.
- Changed save/error flash copy and the submit button to use Stream settings wording.
- Updated the intro copy so the screen reflects what it now controls: stream display, composer behavior, sitemap, PWA/mobile share, and app login persistence.
- Added a smoke check to prevent the stale Reading Settings labels from returning.
- Updated package metadata, version references, service worker cache version, and release manifest for v0.5.4.

## 0.5.3 - Remember Me App Login Pass

Bonumark Stream v0.5.3 adds secure app-friendly login persistence so installed/mobile use does not constantly force the owner back through login.

- Added a Remember this device checkbox to the admin login form and public account login form.
- Added persistent login tokens stored in a new remember_tokens database table.
- Uses selector plus validator cookies with hashed validators in the database, HttpOnly cookies, SameSite=Lax, secure cookies on HTTPS, and token rotation when a remembered login is restored.
- Added Stream settings to enable or disable remember-me login and set the remembered-device window from 1 to 90 days, defaulting to 30 days.
- Revokes remembered device tokens on logout, current-user password change, password reset, and admin password reset.
- Kept normal sessions unchanged for users who do not check Remember this device.
- Updated README, install docs, package metadata, migrations, smoke checks, and release manifest for v0.5.3.

## 0.5.2 - Frontend Share Composer Routing Hotfix

Bonumark Stream v0.5.2 corrects the mobile share-to-post flow so shared text and URLs land in the primary front-end composer instead of the backend draft editor.

- Changed the secure share-target route into an intake handoff that stores the shared payload briefly, requires login, requires publishing permission, and redirects back to the public stream.
- Prefills the front-end composer with shared title, text, and URL content so the user can review, edit, and press Post.
- Removed the backend shared draft review form from the share-target path.
- Preserved admin-only publishing controls, because shared content still never auto-publishes and the composer only renders for authenticated users who can publish.
- Kept image/file share-target intake deferred.
- Updated README, install docs, package metadata, smoke checks, service worker versioning, and release manifest for v0.5.2.

## 0.5.1 - PWA and Mobile Share-to-Post Flow Pass

Bonumark Stream v0.5.1 adds the first clean installable-app and mobile share-to-draft layer while preserving admin-only publishing and the existing theme/API structure.

- Added a dynamic web app manifest with app name, short name, description, start URL, scope, display mode, colors, icons, and Web Share Target metadata.
- Added bundled PNG app icons generated from Bonumark Stream identity, with no external or copyrighted assets.
- Added a conservative versioned service worker that caches only safe static assets and avoids admin pages, account pages, draft pages, API responses, private files, and user-specific content.
- Added PWA registration and recovery behavior through a shared core PWA script.
- Added an authenticated admin share-target route for shared text, titles, and URLs.
- Preserved shared text/URL payloads through login when practical, then routed them to a draft review screen.
- Kept shared content draft-only until the Admin reviews and saves it, with normal publishing still handled by the existing admin editor.
- Added Stream settings for enabling installable app metadata/service worker support and the mobile share target.
- Deferred Web Share Target image/file intake to avoid browser-specific upload handoff risk in the first PWA pass.
- Updated README, package metadata, config defaults, installer seed settings, migrations, smoke checks, and release manifest for v0.5.1.

## 0.5.0 - GitHub Release Preparation Pass

Bonumark Stream v0.5.0 prepares the next public GitHub release after v0.4.5 by promoting the local/test work through v0.4.26 into a public release package.

- Bumped package, application, documentation, OpenAPI, and release manifest version references to v0.5.0.
- Polished the public README so it clearly explains what Bonumark Stream is, who it is for, the Admin/Commenter account model, the code-free theme system, install requirements, upgrade expectations, security expectations, and the Remote Posting API.
- Updated public repository-facing files, including `SECURITY.md` and `CONTRIBUTING.md`, so they no longer describe old v0.4.8 development state.
- Added a consolidated release summary for the major work completed since v0.4.5, including Remote Posting API expansion, scoped tokens, API audit/rate limiting, remote media upload/import, ChatGPT Actions support, client documentation, draft preview cleanup, admin polish, and footer cleanup.
- Confirmed public examples stay placeholder-safe with `example.com`, placeholder tokens, and no private site assumptions.
- Kept old changelog history intact.
- Kept features, API behavior, public behavior, theme structure, and database schema unchanged.

## 0.4.26 - General Audit Cleanup Pass

Bonumark Stream v0.4.26 performs a small post-audit cleanup without adding features or changing public/API behavior.

- Removed duplicate wording from the Remote API media upload documentation.
- Added smoke-test coverage to catch duplicate uploaded-media second-request wording in `docs/API.md`.
- Clarified in `scripts/smoke-test.php` that database smoke tests require real `BMS_DB_*` environment variables and `BMS_DB_DANGER_RESET=1` so the package smoke test never touches a live database accidentally.
- Added an explicit smoke-test comment around wrapped media helper fallbacks to avoid app helper redeclaration during isolated Markdown rendering checks.

## 0.4.25 - Remote API Final Validation Pass

Bonumark Stream v0.4.25 locks down Remote Posting API validation coverage before moving on to the next feature area.

- Strengthened package smoke coverage for Remote API route files, shared API handlers, `.htaccess` clean URL routing, Authorization passthrough, and `index.php` API dispatch.
- Strengthened OpenAPI, API docs, Remote Posting docs, client docs, scope, idempotency, and media upload/import documentation checks.
- Added optional `scripts/api-database-smoke-test.php` coverage for disabled API behavior, missing tokens, invalid tokens, draft creation, publish scope enforcement, publish confirmation enforcement, media upload scope enforcement, idempotency replay, and idempotency conflict behavior against a temporary real database install.
- Kept API endpoints, API behavior, admin behavior, public rendering, theme structure, and database schema unchanged.

## 0.4.24 - Remote Posting Client Docs Expansion Pass

Bonumark Stream v0.4.24 expands Remote Posting API documentation for common external clients without changing API behavior.

- Added `docs/REMOTE-POSTING-CLIENTS.md` with setup examples for PowerShell, curl, Python, GitHub Actions, Apple Shortcuts, Zapier Webhooks, Make HTTP module, IFTTT Webhooks, and generic no-code automation tools.
- Added client examples for status checks, draft creation, direct publishing, existing media embedding, media URL import, and local image upload where supported by the client.
- Updated README documentation references so users can find Remote Posting setup, endpoint details, ChatGPT Actions setup, and client examples from the package overview.
- Updated `docs/API.md`, `docs/REMOTE-POSTING.md`, and `docs/CHATGPT-ACTIONS.md` to cross-link the new client examples.
- Kept API endpoints, authentication behavior, token scopes, media behavior, admin settings, public rendering, theme structure, and database behavior unchanged.
- Added smoke coverage requiring the Remote Posting client examples document and its major client sections.

## 0.4.23 - Footer Slash Separator Removal Hotfix

Bonumark Stream v0.4.23 removes the automatic public footer slash separator that still appeared when custom footer text and the Bonumark credit were both shown.

- Removed the default `/` footer separator from shared core footer render data.
- Updated the default footer template so separators are rendered only when an explicit non-empty separator is supplied.
- Preserved custom footer text and the Bonumark credit output without inserting an unwanted slash between them.
- Kept theme structure, admin settings behavior, public rendering outside the footer, API behavior, media behavior, and database behavior unchanged.
- Added smoke coverage proving the shared public footer does not auto-render a slash separator.

## 0.4.22 - Footer Custom Text Separator Hotfix

Bonumark Stream v0.4.22 fixes shared public footer rendering so custom footer text does not create stray separator characters.

- Added core footer item render data so footer output is built from actual non-empty footer items.
- Updated the default footer template to render separators only between real footer items.
- Preserved custom footer text output, optional Bonumark credit output, and the intentional separator when both are shown.
- Kept the fix in shared core footer rendering so Midnight Ledger and code-free custom themes inherit it.
- Added smoke coverage for footer item rendering and separator placement.

## 0.4.21 - Admin Content List Width Utilization Pass

Bonumark Stream v0.4.21 improves admin list layout so content tables use the available panel width instead of collapsing tightly around their cells.

- Made stream post, page, comment, user, token, and compact admin list tables use full available width.
- Added a dedicated stream post table layout so the Post column expands while checkbox, Status, Media, Storage, and Date columns stay stable.
- Improved metadata column spacing and kept row actions stable from v0.4.20.
- Added responsive horizontal scrolling for admin table wrappers without changing mobile stacked table behavior.
- Added CSS smoke coverage for full-width admin list tables and stable stream post metadata columns.

## 0.4.20 - Admin Row Action Hover Stability Hotfix

Bonumark Stream v0.4.20 normalizes admin list row-action styling so links and form buttons behave consistently without hover layout shifts.

- Normalized row actions for stream post lists across draft, published, and trash rows.
- Stabilized Edit, Quick Edit, Preview, Revisions, View, Publish, Move to Drafts, Trash, Restore, and Delete Permanently actions.
- Prevented hover states from changing row height, width, padding, borders, line height, or font weight.
- Kept state-changing actions visually distinct and destructive actions visibly destructive without affecting layout.
- Added similar stability rules for page table row actions that share the same pattern.
- Added smoke coverage for admin row-action styling and content-list action classes.

## 0.4.19 - Draft Preview Public Menu Removal Hotfix

Bonumark Stream v0.4.19 removes public navigation controls from draft preview headers so previews no longer feel like live public pages.

- Removed the public Menu button from draft preview headers.
- Kept one clear Preview indicator only.
- Prevented public navigation HTML from being passed into draft preview header render data.
- Kept the top admin preview bar unchanged.
- Kept the bottom Back to editor button unchanged.
- Kept published public post headers unchanged.
- Applied the fix through core render data so Midnight Ledger and code-free custom themes inherit it.
- Kept comments, likes, API behavior, media behavior, and theme structure unchanged.
- Added smoke coverage proving draft preview does not render the public menu control.

## 0.4.18 - Draft Preview Header Controls Hotfix

Bonumark Stream v0.4.18 fixes draft preview header controls so preview state is clear and not duplicated.

- Hid the public post-count pill in draft preview mode.
- Kept one clear Preview indicator in the public header during draft previews.
- Added explicit preview header state and count-chip render data for core templates and code-free themes.
- Kept the public menu available while preventing draft preview routes from being treated as live/current navigation.
- Left the top admin preview bar and bottom Back to editor action unchanged.
- Kept public published header behavior, comments, likes, API behavior, media behavior, and theme structure unchanged.
- Added smoke coverage for draft preview header controls.

## 0.4.17 - Draft Preview Interaction State Hotfix

Bonumark Stream v0.4.17 makes admin draft previews behave like previews instead of live public posts while preserving the public published post experience.

- Added a core public preview-mode flag available to renderers and theme views.
- Added preview body/header state so themes can detect preview rendering.
- Made likes inactive on draft previews.
- Made comment links and comment loading preview-safe on draft previews.
- Replaced draft-preview single-post back behavior with a preview-safe Back to editor target.
- Prevented draft-preview card clicks from navigating to a live public route.
- Made the header count/status pills show preview state instead of a misleading live post count.
- Kept the menu usable in preview mode without marking the previewed draft as the active public route.
- Added smoke coverage for preview-mode controls.

## 0.4.16 - Remote Imported Media Rendering Hotfix

Bonumark Stream v0.4.16 fixes a rendering issue found during remote URL media import testing.

- Fixed Markdown image rendering so generated responsive image `srcset` and `sizes` metadata stays inside the image tag instead of appearing as visible post text.
- Protected generated image HTML during inline Markdown formatting so underscores in `_generated` paths are not treated as emphasis markers.
- Kept captions visible as normal post content after embedded images.
- Preserved responsive image variant output for performance.
- Added smoke coverage for Markdown image rendering with generated responsive variants.
- Kept remote upload/import API behavior, media validation, token logic, and GPT Action schema structure unchanged.

## 0.4.15 - GPT Media Guardrails and URL Import Pass

Bonumark Stream v0.4.15 improves GPT Actions media behavior by rejecting fake placeholder uploads and adding a safer URL-based image import workflow.

- Added `POST /api/v1/media/import` for importing public HTTP/HTTPS image URLs into the Media library.
- Added clean URL routing and a direct PHP endpoint for remote media imports.
- Reused existing safe remote media download checks, public IP validation, redirect limits, cURL enforcement, image validation, and media upload rules.
- Added API guardrails that reject the known 1x1 placeholder PNG commonly generated by GPT Action tests instead of real image data.
- Added inline stream post support for `media_import`, `media_imports`, `image_url`, `media_import_url`, and `remote_image_url` so clients can import and embed media in one remote post request.
- Added media import audit log entries.
- Fixed the existing remote media import temporary-directory helper typo so URL imports can create temporary files correctly.
- Updated OpenAPI, API docs, ChatGPT Actions docs, Remote Posting docs, README, package metadata, smoke tests, and release manifest.

## 0.4.14 - Remote Media Embed Persistence Hotfix

Bonumark Stream v0.4.14 fixes a remote media embedding bug found during live GPT Actions testing.

- Fixed `POST /api/v1/stream/posts` so `media_id`, `media_ids`, `media_url`, `media_urls`, `public_path`, `public_paths`, `media_items`, `media_upload`, and `media_uploads` are resolved before the post body is saved.
- Fixed remote post responses so `embedded_media` reflects the media actually inserted into the saved post.
- Fixed media-only remote posts so embedded image Markdown can create the post body when no text content is supplied.
- Kept `POST /api/v1/media`, token auth, publish controls, idempotency, and upload validation behavior unchanged.
- Added smoke-test coverage to catch server code that documents media embedding but fails to call the embed/persist helpers.

## 0.4.13 - OpenAPI GPT Actions Schema Hotfix

Bonumark Stream v0.4.13 tightens the OpenAPI schema after live GPT Actions setup showed importer compatibility warnings.

- Shortened the `createStreamPost` operation description to stay under GPT Actions importer limits.
- Removed the `HEAD` operation from `/api/v1/status` in the OpenAPI schema because the GPT Actions importer only needs the documented `GET` status check.
- Kept runtime API behavior unchanged. The status endpoint can still handle normal status checks, and all remote posting/media behavior from v0.4.12 remains intact.
- Added smoke-test checks so operation descriptions stay short and unsupported `HEAD` operations do not reappear in the Action schema.

## 0.4.12 - Remote Media Embed Workflow Pass

Bonumark Stream v0.4.12 extends remote stream post creation so trusted clients can create posts with embedded image media in a cleaner workflow.

- Added embedded media support to `POST /api/v1/stream/posts`.
- Added support for referencing existing library images by `media_id`, `media_ids`, `media_url`, `media_urls`, `public_path`, or `media_items`.
- Added support for one-step post creation with `media_upload` or `media_uploads` JSON payloads when the token also has `media:upload` and remote media uploads are enabled.
- Added `embedded_media` and `media_position` fields to the remote post response.
- Allowed media-only remote posts when embedded media is supplied.
- Updated OpenAPI, API docs, Remote Posting docs, ChatGPT Actions docs, README, package metadata, and smoke tests.

# Changelog

## 0.4.11 - Remote Media Upload API Pass

- Added optional remote image uploads through `POST /api/v1/media`.
- Added the `media:upload` token scope and admin setting for remote media uploads.
- Reused the existing media upload validation, size limits, storage, metadata, and image derivative behavior.
- Added API audit log entries for remote media upload successes and validation failures.
- Returned media ID, URL, filename, alt text, caption, dimensions, and Markdown embed text.
- Updated OpenAPI, API docs, ChatGPT Actions docs, remote posting docs, smoke tests, and package metadata.

## 0.4.10 - Remote Posting Admin Scope Polish Hotfix

Bonumark Stream v0.4.10 polishes the Remote Posting admin screen after live API testing confirmed the endpoint chain works.

- Restyled the API token scope cards so they use the dark admin surface and border variables instead of light fallback colors.
- Changed the scope selector to a balanced two-column layout on desktop and a single-column layout on mobile.
- Improved spacing, checkbox alignment, hover states, disabled/reserved scope styling, and text contrast.
- Kept API behavior, token scopes, publishing controls, idempotency behavior, routing, migrations, and database logic unchanged.
- Updated package metadata, docs, version markers, smoke tests, and release manifest.

## 0.4.9 - API Upgrade Route Hotfix

Bonumark Stream v0.4.9 fixes Remote Posting API routing for sites upgraded from older v0.4 packages whose installed upgrader did not yet know to copy newly introduced top-level directories.

- Routes `/api/v1/status` and `/api/v1/stream/posts` through `index.php` so clean API URLs work even when the physical `/api/` folder was not copied by an older upgrader.
- Keeps the physical `/api/` endpoint files for fresh installs and direct-file compatibility.
- Moves shared API endpoint execution into `_bonumark_stream/app/api.php` to avoid duplicated endpoint logic.
- Future-proofs the admin upgrader by deriving software copy roots from the release manifest instead of relying only on a hardcoded top-level directory list.
- Updates package metadata, docs, smoke tests, and release manifest.

## 0.4.8 - Remote Publish Controls Pass

Bonumark Stream v0.4.8 adds optional direct publishing controls to the Remote Posting API while keeping draft-first behavior as the default.

- Enabled the `stream:publish` API token scope.
- Added direct remote publishing as an Admin-controlled setting, disabled by default.
- Added a default remote post status setting.
- Added publish confirmation behavior with `confirm_publish: true` or `confirmation: "publish"`.
- Added idempotency key support using the `Idempotency-Key` header or request payload keys.
- Added an API idempotency database table and migration.
- Updated `POST /api/v1/stream/posts` to create drafts or published posts based on settings, scopes, and confirmation.
- Returned public URLs for remotely published posts.
- Added a full OpenAPI schema at `docs/openapi/bonumark-stream-api.json`.
- Added ChatGPT Actions setup documentation at `docs/CHATGPT-ACTIONS.md`.
- Updated Remote Posting admin controls, API docs, package metadata, smoke tests, and release manifest.

## 0.4.7 - Remote Draft API Pass

Bonumark Stream v0.4.7 adds the first real remote posting endpoint while keeping remote creation draft-only.

- Added `POST /api/v1/stream/posts` for draft-only remote stream post creation.
- Enabled the `stream:draft` API token scope.
- Added JSON request parsing and remote draft creation helpers.
- Added authenticated draft creation with bearer token scope checks and rate limiting.
- Added API audit logging for remote draft creation and validation failures.
- Returned the new draft ID, slug, title, filename, and admin edit URL after successful creation.
- Added shared-hosting rewrite support for `/api/v1/stream/posts`.
- Added `docs/API.md` with status and remote draft endpoint documentation.
- Updated Remote Posting docs, package metadata, smoke tests, and release manifest.


## 0.4.6 - Remote Posting Foundation Pass

Bonumark Stream v0.4.6 adds the disabled-by-default foundation for remote posting integrations without enabling remote post creation yet.

- Added Remote Posting settings in the admin area.
- Added scoped API token creation and revocation with hashed token storage.
- Added API token, API audit log, and API rate-limit database tables.
- Added reusable API authentication, JSON response, audit logging, and rate-limiting helpers.
- Added `GET /api/v1/status` for API health and authenticated token checks.
- Added shared-hosting routing support for `/api/v1/status`.
- Updated exports, docs, package metadata, smoke tests, and release manifest.

## 0.4.5 - Public Release Legacy Cleanup Pass

Bonumark Stream v0.4.5 cleans the public release package after the Admin and Commenter account reset.

- Aligned upgrade support around v0.4.0 and newer only.
- Removed legacy theme-layout compatibility keys from the code-free theme manifest system.
- Removed old file-runtime content-folder preservation from the upgrader.
- Cleaned active docs so older development history stays in `docs/HISTORY.md`.
- Removed old media-limit wording from admin upload/help text.
- Tightened routing comments and package permissions for public release readiness.
- Updated package metadata, version markers, smoke tests, and release manifest.

## 0.4.4 - Admin and Commenter Account Reset Pass

Bonumark Stream v0.4.4 removes obsolete multi-publisher behavior and resets the account model around one Admin plus Commenter accounts.

- Collapsed public/account roles to Admin and Commenter.
- Made Admin the sole publisher and admin-area user.
- Kept Commenter accounts for comments, profiles, password recovery, verification, approval, and account participation.
- Removed obsolete multi-publisher workflow surfaces, publishing-user settings, and old media-limit rules.
- Reworked registration so public registration creates Commenter accounts only when enabled.
- Updated admin account screens, writing settings, registration settings, route handling, baseline schema, package metadata, and release manifest.

## 0.4.3 - Theme System Clarity Pass

Bonumark Stream v0.4.3 clarifies the code-free presentation theme model without adding features or redesigning the theme system.

- Removed leftover theme-layout wording from the admin theme screens.
- Updated theme manager, theme details, theme settings, and theme install copy to describe themes as presentation packages.
- Removed obsolete layout-control fields from Midnight Ledger's example `theme.json`.
- Kept the current code-free theme model: core renders the public site, themes supply metadata, settings, CSS, images, fonts, screenshots, and docs.
- Updated package metadata, focused theme wording, and release manifest.

## 0.4.2 - Midnight Ledger Reference Theme Pass

Bonumark Stream v0.4.2 makes Midnight Ledger the working example for code-free Bonumark Stream themes.

- Made the bundled theme folder self-contained with `theme.json`, README files, screenshot, and `assets/css/theme.css`.
- Mirrored Midnight Ledger assets to the public theme asset directory used by core rendering.
- Updated `theme.json` so asset paths match the copyable theme folder structure.
- Added clear `--bms-*` design tokens at the top of the CSS while keeping Midnight Ledger aliases contained.
- Cleaned development-era comments from the reference theme CSS.
- Kept themes code-free, with no PHP, JavaScript, HTML files, or executable code in the theme package.
- Updated package metadata, theme docs, and release manifest.

## 0.4.1 - Code-Free Theme Installer Pass

Bonumark Stream v0.4.1 restores installable themes without allowing theme packages to run code.

- Re-enabled theme ZIP installation for code-free presentation packages.
- Theme packages may include `theme.json`, documentation, CSS, images, fonts, screenshots, supports, and editable settings.
- Theme packages may not include PHP, JavaScript, HTML files, route handlers, server config files, symlinks, or executable code.
- Core-owned public views remain responsible for rendering and behavior.
- Active theme metadata, settings, and assets now apply while core renders the view layer.
- Theme validation now rejects executable files in private theme metadata and public theme assets.
- Updated admin theme screens, theme install flow, theme docs, package metadata, and release manifest.

## 0.4.0 - Foundation Reset Pass

Bonumark Stream v0.4.0 is the clean public foundation reset.

- Fresh-install only baseline.
- Standardized the internal function prefix on `bms_`.
- Collapsed old development migrations into one clean v0.4.0 baseline schema.
- Confirmed the database as the source of truth.
- Kept Markdown for import, export, backup, and portability only.
- Reset the upgrader to support v0.4.0 and newer only.
- Kept Midnight Ledger as the only bundled public release theme.
- Moved Midnight Ledger rendering code into core-owned views so theme packages remain code-free.
- Disabled third-party theme ZIP installation until the declarative code-free theme format is finalized.
- Kept normal operation dynamic and database-first.
- Kept Static Site Export as optional portability tooling.
- Kept accounts, profiles, comments, public likes, and all importers.
- Kept `/stream/` as an alias while `/` remains the real stream home.
- Removed development-only route cleanup rules.
- Removed sample public content from the install baseline.
- Updated public documentation for the v0.4.0 foundation.

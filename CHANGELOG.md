## 0.5.77 - GitHub README Cleanup

- Restores `README.md` as a product-first GitHub landing page instead of using it as a release archive.
- Removes the 26 embedded v0.5.49 through v0.5.76 release sections that appeared before the product overview.
- Keeps the current version visible and links directly to `CHANGELOG.md` for complete release history.
- Adds smoke-test coverage preventing release history from being dumped back into the README.
- Changes no application code, database schema, routes, publishing behavior, permissions, content, media, uploads, themes, settings, or user data.

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

# Changelog

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

### Fixed

- Forces the fresh installer database session to UTC before migrations and initial seed writes, matching normal runtime database behavior.
- Stores the initial Admin email-verification timestamp explicitly in UTC.
- Restores missing v0.5.35 and v0.5.37 upgrade notes and corrects the mislabeled v0.5.24 timestamp section.
- Adds package smoke-test coverage for installer UTC handling and post-v0.5.30 upgrade-guide completeness.

### Upgrade notes

- No database migration runs in this release.
- Existing config, database data, posts, pages, drafts, revisions, users, comments, likes, media, uploads, themes, settings, analytics, API tokens, cron history, scheduled posts, Local Places, coordinates, consent records, and post location snapshots are preserved.

## 0.5.41 - Local Places Metadata Alignment Hotfix

### Fixed

- Removes the floating category badge from each Local Places directory card.
- Places Category beside Default public display in the labeled metadata section.
- Keeps the place name and location primary while reserving the right side for edit and delete actions.
- Changes presentation only. Local Places storage, geolocation, composer behavior, post snapshots, and public output remain unchanged.

### Upgrade notes

- No database migration runs in this release.
- Existing places, coordinates, posts, settings, media, themes, uploads, and other site data are preserved.

## 0.5.40 - Local Places Admin Polish Pass

### Changed

- Replaces the wide Local Places admin table with compact responsive saved-place cards.
- Adds a saved-place count, clearer place-name and location hierarchy, category badges, and easier-to-scan default display details.
- Separates the private-coordinate explanation into a subdued information notice.
- Gives Edit place and Delete distinct, better-spaced actions and improves the one-place and mobile layouts.
- Changes presentation only. Local Places storage, geolocation, composer behavior, post snapshots, and public output remain unchanged.

### Upgrade notes

- No database migration runs in this release.
- Existing places, coordinates, posts, settings, media, themes, uploads, and other site data are preserved.

## 0.5.39 - Local Places Visual Consistency Hotfix

### Fixed

- Matches the Local Places composer panel background, border, radius, fields, selected-place row, and add-place dialog to the existing Schedule panel color system in the default theme.
- Removes the unrelated brown-tinted panel treatment while keeping orange limited to active controls and actions.
- Changes presentation only. Local Places behavior, geolocation, saved places, post snapshots, and database data remain unchanged.

### Upgrade notes

- No database migration runs in this release.
- Existing places, coordinates, posts, settings, media, themes, uploads, and other site data are preserved.

## 0.5.38 - Local Places Geolocation Hotfix

### Fixed

- Allows same-origin browser geolocation instead of disabling geolocation through the global Permissions Policy.
- Moves the Admin Local Places current-location handler from blocked inline JavaScript into the CSP-approved external admin script.
- Adds clear feedback for HTTPS requirements, denied permission, unavailable location, and request timeout failures.
- Restores **Use current location** in Admin and the geolocation foundation used by **Find nearby** in both composers.

### Upgrade notes

- No database migration runs in this release.
- Existing places, coordinates, posts, post location snapshots, settings, media, themes, uploads, and other site data are preserved.

## 0.5.37 - Local Places Composer Simplification Pass

### Changed

- Reduced Local Places in both Stream Post composers to a compact saved-place picker, nearby search, and one **Add a new place** action.
- Moved category, approximate-area, city, region, country, coordinates, and default-display management out of the posting flow and kept those controls under **Admin → Local Places**.
- Replaced the expanded composer form with a small two-field dialog for place name and an optional public location label.
- Collapsed a selected place into a single compact row with Change and Remove actions.
- Uses each saved place's default public display automatically instead of asking for a display mode on every post.

### Upgrade notes

- No database migration runs in this release.
- Existing places, coordinates, categories, display settings, post location snapshots, posts, pages, media, comments, analytics, themes, API tokens, cron history, uploads, and settings are preserved.

## 0.5.36 - Local Places Check-In Pass

### Added

- Private, self-hosted Local Places directory with no paid or external places API.
- Browser-permission nearby matching against places saved on the current Bonumark Stream instance.
- Location controls in both the front-end composer and back-end Stream Post editor.
- Public display choices for place name and city, approximate area, or city only.
- Protected Local Places admin screens for adding, editing, and deleting saved places.
- Markdown export and database front-matter support for portable location snapshots.

### Privacy boundaries

- Location access begins only after the signed-in owner presses a location button.
- Raw device coordinates are used for nearby matching and are stored only when the owner intentionally saves a place.
- Public post markup never includes latitude or longitude.
- No background tracking, external places lookup, or shared-directory dependency is included.

### Upgrade notes

- Adds migration `0014_local_places.php` to create the local places table.
- Existing posts, pages, media, comments, analytics, themes, API tokens, cron history, uploads, and settings are preserved.

This file is the public release summary for Bonumark Stream. Detailed package-by-package upgrade history is kept in [`_bonumark_stream/CHANGELOG.md`](_bonumark_stream/CHANGELOG.md).

## 0.5.35 - Root RSS Discovery Hotfix

### Fixed

- Restored `/feed.xml` as the canonical RSS feed advertised in public page `<link rel="alternate">` metadata.
- Adjusted the root RSS channel link so the root feed identifies the site root instead of `/stream/`.
- Preserved `/stream/feed.xml` as a working compatibility alias for existing subscriptions and the stream archive route.

### Upgrade notes

- No database migration runs in this release.
- No posts, pages, users, comments, analytics, themes, API tokens, cron history, uploads, media files, or existing settings are rewritten.

## 0.5.34 - Privacy-Safe Media Uploads Pass

### Added

- Randomized public filenames for uploaded media so original device or local filenames are not used in public media URLs.
- Best-effort image metadata removal for supported JPG, PNG, and WebP uploads using shared-hosting PHP image handling, with optional Imagick support when available.
- Optional strict media privacy mode that rejects image uploads when metadata removal cannot be confirmed.
- Media privacy status indicators in the admin media library and media edit screen.
- Clear warnings when Bonumark Stream can randomize the filename but cannot confirm metadata removal.

### Changed

- Empty upload alt text now defaults to a generic safe label instead of deriving public alt text from the original filename.

### Upgrade notes

- Adds migration `0013_privacy_safe_media_uploads.php` to add media privacy status fields and the `media_privacy_mode` setting.
- Existing media records are preserved and marked as legacy unchecked until replaced or reuploaded.
- No posts, pages, users, comments, analytics, themes, API tokens, cron history, uploads, or existing media files are rewritten.

## 0.5.33 - Analytics Report Card Polish Pass

### Improved

- Replaced the sparse table-style presentation in the compact Privacy-First Analytics report cards with cleaner label/count summary rows.
- Humanized small aggregate labels such as device categories and browser families, so values like `desktop` and `chrome` display as `Desktop` and `Chrome`.
- Improved empty states for Stream posts, pages, referrers, devices, browsers, entry pages, and daily views so empty cards no longer show table headers.

### Upgrade notes

- No database migration runs in this release.
- No analytics aggregate rows, settings, posts, pages, timestamps, users, comments, media, themes, API tokens, cron history, uploads, or existing configuration are rewritten.
- Analytics collection, storage fields, privacy boundaries, CSV export, and theme behavior are unchanged.

## 0.5.32 - Analytics Report Warning Hotfix

### Fixed

- Corrected the Privacy-First Analytics report-table helper so key-only column definitions no longer trigger PHP 8.1+ `Undefined array key "value"` warnings on **Admin → Tools → Analytics**.
- Restored clean reporting for Views by Day, Top Entry Pages, Referrer Domains, Device Categories, Browser Families, and any future analytics table that uses a direct row key instead of a formatter callback.

### Upgrade notes

- No database migration runs in this release.
- No analytics aggregate rows, settings, posts, pages, timestamps, users, comments, media, themes, API tokens, cron history, uploads, or existing configuration are rewritten.

## 0.5.31 - Privacy-First Analytics Pass

### Added

- Optional self-hosted, cookieless Privacy-First Analytics, disabled by default on fresh installs and upgrades.
- Aggregate public page-view reporting by day, Stream post, page, entry path, referrer domain, broad device category, broad browser family, and sanitized UTM campaign fields.
- Protected **Admin → Tools → Analytics** controls for enablement, retention, aggregate CSV export, and confirmed data clearing.
- A restrained dashboard Traffic card for administrators.

### Privacy boundaries

- No analytics cookies, browser storage, visitor IDs, sessions, fingerprints, unique-visitor estimates, raw or hashed IP addresses, raw user agents, full referrers, query strings, or visitor-level event logs.
- Admin, account, login, API, feed, sitemap, search, cron, install, upgrade, private, and authenticated activity is excluded.

### Upgrade notes

- Adds one idempotent migration for aggregate analytics storage and disabled-by-default analytics settings.
- Existing sites do not collect analytics until an administrator explicitly enables the feature.
- No posts, pages, timestamps, users, comments, media, themes, API tokens, cron history, uploads, or existing configuration are rewritten.

## 0.5.30 - GitHub Release Hardening Pass

### Improved

- Prepared the release package for public GitHub distribution with aligned version markers, a single package root, and refreshed repository documentation.
- Added a public release summary and expanded contributor and security-reporting guidance.
- Made the installer display the packaged version dynamically so its welcome screen cannot drift behind the release version again.

### Fixed

- Corrected stale version references in public documentation, OpenAPI metadata, API response examples, and installer copy.

### Upgrade notes

- No database migration runs in this release.
- No posts, pages, users, comments, media, themes, settings, API tokens, scheduled-task history, uploads, or existing configuration are rewritten.
- The built-in upgrader continues to support Bonumark Stream v0.4.0 and newer only.

## 0.5.x since v0.5.0

### Added

- Installable PWA support and a secure text-and-URL mobile share target that hands content to the front-end composer for review.
- Scheduled posts, a shared Scheduled Tasks runner, server cron guidance, protected web cron, task health, and run history.
- Pinned Stream posts with core-owned post actions in both front-end and admin workflows.
- Optional Remote Posting API features for scoped tokens, idempotent post creation, scheduled publishing, media upload, and safe remote image import.

### Improved

- Site-timezone rendering with canonical UTC database timestamps for current content and scheduled work.
- Upgrade recovery and MariaDB compatibility safeguards.
- PWA install icons that follow the Site Identity favicon, including safe fallback behavior on shared hosts without GD or Imagick.

### Fixed

- Existing-post save compatibility on MySQL and MariaDB native prepared statements.
- Legacy post-time display after the timezone runtime update.
- Scheduled-post display and task-history timestamp alignment.

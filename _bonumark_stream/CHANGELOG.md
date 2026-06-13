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

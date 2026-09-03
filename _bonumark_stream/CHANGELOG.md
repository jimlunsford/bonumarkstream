## 0.8.0 - ActivityPub Federation

- Integrates the completed ActivityPub Stages 1 through 7 and Stage 6.5 subsystem as the v0.8.0 release candidate.
- Keeps ActivityPub optional, default-off, single-owner, and subordinate to Bonumark's existing personal publishing model.
- Adds WebFinger, stable actor/outbox/object discovery, encrypted signing keys, key rotation, legacy RSA and RFC 9421 signatures, digest validation, replay protection, SSRF-safe remote access, and actor/key/activity consistency checks.
- Adds authenticated follower handling, moderation, owner Follow and Unfollow, inbound replies, Likes, Announces and Undo, owner Reply, Like, Unlike, Boost and Unboost, actor/domain blocking, and remote identity lifecycle handling.
- Adds generation-aware Create, Update, and Delete delivery with media, alt text, shared-inbox deduplication, asynchronous retries, dead letters, immutable payload reuse, queue repair, pause, delivery suspension, and irreversible Actor Delete.
- Adds the private frontend Following timeline and conversation views while keeping ordinary participation controls out of Admin and preserving the core/theme responsibility boundary.
- Adds migrations `0018_activitypub_foundation.php` through `0028_activitypub_remote_actor_lifecycle.php` without renumbering or rewriting historical migrations.
- Preserves existing posts, Pages, profiles, comments, local Likes, media, themes, imports, exports, runtime data, configuration, accounts, settings, API state, and other owner data.
- Updates all current version surfaces, user/operator documentation, package metadata, OpenAPI metadata, service-worker cache identity, and release notes for v0.8.0.
- Expands release-candidate verification so release-branch compatibility jobs enforce the final manifest and validate a freshly built and extracted distributable package.

## 0.7.2 - Database Smoke Test Correctness Pass

- Supersedes the unreleased v0.7.1 candidate while keeping the public milestone name **Hosting Portability & Upgrade Workflow** and the same runtime feature set.
- Corrects `scripts/database-smoke-test.php` so it no longer treats the current cumulative `0001_initial_schema.php` as if it were a historical install and then blindly replays every later migration on top of it.
- Adds a verified historical v0.4.x initial-schema fixture matching Git blob `3e7e70385dcaa2fb621809430e1e660bdce9459b`, captured from the clean v0.4.5 public baseline that retained the supported v0.4.0+ upgrade line.
- Separates real-database verification into two paths: the current fresh-install path through `bms_install_schema()` and a supported-upgrade path that starts from the historical v0.4.x baseline before applying migrations `0002` through `0017`.
- Extends the package smoke contract so the historical fixture identity and the two-path database test model cannot regress silently.
- Keeps the GitHub compatibility matrix on the clean tracked source snapshot introduced in v0.7.1.
- Changes no Bonumark runtime feature behavior and adds no database migration.

## 0.7.1 - Compatibility Workflow Correction Pass

- Supersedes the unreleased v0.7.0 release candidate without changing the product milestone or runtime feature set.
- Updates `.github/workflows/compatibility.yml` to use the Node 24 based `actions/checkout@v5`, create a clean tracked source snapshot with `git archive HEAD`, and run PHP lint, the package smoke test, migration/schema smoke test, and Remote Posting API database smoke test from that snapshot.
- Prevents Git checkout metadata under `.git/` from being misclassified as unexpected package files by the release-manifest smoke checks.
- Ensures `api/v1/stream/posts.php` is present in repository source, matching the v0.7.0 release package, release manifest, Remote Posting documentation, and smoke-test contract.
- Updates current-version documentation and package metadata to v0.7.1.
- Keeps the compatibility floors, application runtime behavior, upgrade behavior, owner-data preservation contract, and migration set unchanged.
- Adds no database migration.

## 0.7.0 - Hosting Portability & Upgrade Workflow

- Creates the next intended public GitHub milestone after v0.6.0 by consolidating the completed v0.6.1 through v0.6.8 development work under one release identity.
- Formalizes locked-down application trees as a supported operating model where the web/PHP process may write required runtime storage without owning or replacing package-managed application software.
- Keeps Admin ZIP upgrades for writable application trees and adds the owner-run `scripts/deploy-update.php` workflow for locked-down shell-access deployments, with both paths using the shared `_bonumark_stream/app/upgrader.php` engine.
- Preserves release-manifest validation, owner-data protection, private software backups, selective pre-migration rollback, obsolete package-file cleanup, migration locking/recovery, external database-backup confirmation when migrations are pending, and upgrade-history recording.
- Adds read-only installed-site deployment verification, pending-migration and obsolete-file checks, real clean-route probing, read-only private-path verification, CLI-only script protection, and safer installer handling when private-path protection cannot be verified automatically.
- Adds maintained Nginx deployment guidance, explicit MySQL 8.0+/MariaDB 10.6+ floors, capability-based reporting for optional extensions/features, mbstring fallbacks, and a GitHub Actions compatibility matrix covering PHP/database floor and newer reference targets.
- Keeps the supported upgrade floor at v0.4.0 and does not change the database runtime source of truth, code-free theme boundary, or owner-data preservation contract.
- Adds no new database migration compared with v0.6.0.

## 0.6.8 - Owner-Run Upgrade Confirmation Polish Pass

- Makes the owner-run CLI `UPGRADE` confirmation case-insensitive while still requiring the explicit confirmation word before package-managed software replacement begins.
- Accepts `upgrade`, `Upgrade`, `UPGRADE`, and other letter-case variants after trimming surrounding whitespace; unrelated input still cancels the upgrade before files are changed.
- Adds package smoke-test coverage so the case-insensitive confirmation behavior cannot regress silently.
- Preserves the v0.6.7 shared upgrade engine, application-owner permission model, release validation, software backup/rollback, migration recovery, post-upgrade deployment verification, and no-privilege-escalation boundary unchanged.
- Adds no database migration.

## 0.6.7 - Owner-Run Upgrade Workflow Pass

- Extracts the package-validation, software-copy, backup, rollback, obsolete-file cleanup, migration-recovery, and upgrade-history logic from `admin/upgrade.php` into the shared `_bonumark_stream/app/upgrader.php` engine.
- Keeps Admin ZIP upgrades on the same shared engine for hosts where the web/PHP process can safely replace package-managed application files.
- Adds `scripts/deploy-update.php` as a first-class owner-run CLI software upgrade workflow for locked-down application trees with shell access.
- Supports bootstrapping the owner-run workflow from an extracted newer release with `--site-root=/path/to/live/site`, so an older locked-down installation does not need a one-time full manual overlay just to gain the helper.
- Makes the CLI workflow validate the ZIP and release manifest, verify the current CLI identity can replace the target application files, show the version/migration/write-access plan, and refuse accidental root execution unless explicitly acknowledged.
- Keeps privilege boundaries intact: the CLI helper never invokes `sudo`, installs a privileged daemon, uses setuid behavior, or grants the web/PHP process additional filesystem rights.
- Reuses the existing private software backup, selective pre-migration rollback, obsolete package-file cleanup, forward-only migration recovery, and upgrade-history recording behavior.
- Requires explicit external database-backup confirmation before a CLI upgrade applies a release with pending database migrations.
- Protects the confirmation window against a swapped/replaced ZIP by hashing the validated release package and rechecking it immediately before installation.
- Automatically runs the installed-site deployment verification after a successful owner-run upgrade so package integrity, obsolete files, runtime directories, database compatibility, and pending migrations are checked before the command returns success.
- Updates System Check and Admin upgrade guidance so locked-down installations point to the owner-run CLI workflow first when shell access is available, while retaining the manual/hosting-layer process as the no-shell fallback.
- Keeps manual `rsync`, SFTP, and hosting-control-panel deployment documentation as the fallback rather than the primary locked-down shell workflow.
- Adds no database migration.

## 0.6.6 - Portability Audit Remediation Pass

- Adds `scripts/run-migrations.php`, an owner-run CLI migration workflow for locked-down/manual deployments with explicit database-backup confirmation, migration locking/ledger reuse, forward-only recovery state, and manual upgrade-history recording.
- Adds pending migration and migration-recovery reporting to `scripts/deployment-check.php` and Admin → System Check so a manual file overlay cannot appear complete while database work remains.
- Extends the installed-site deployment check to detect obsolete package-managed files left behind by non-destructive overlays while preserving runtime data and custom themes.
- Replaces the hardcoded Public URL mode PASS with a read-only `/api/v1/status` clean-route probe that verifies a Bonumark-specific response marker.
- Makes fresh installation require explicit independent verification when the private-folder HTTP probe is inconclusive instead of silently treating unknown protection as acceptable. Confirmed exposure still blocks installation.
- Documents the fresh locked-down install bootstrap, generic manual migration workflow, obsolete-file verification, and repository-only `.github/` deployment boundary.
- Makes Apache/LiteSpeed private and hidden-file deny rules safe across modern and legacy authorization directive generations.
- Removes fatal mbstring assumptions from import, profile, place, analytics, scheduler, PWA, and theme paths by routing multibyte truncation/length handling through guarded core helpers.
- Adds `docs/COMPATIBILITY.md` and a GitHub Actions compatibility matrix covering PHP 8.1/8.3 with MySQL 8.0/8.4 and MariaDB 10.6/11.4 plus package, migration/schema, and Remote API smoke tests.
- Cleans the primary README upgrade guidance so it is version-neutral for the maintained v0.4.0+ upgrade line.
- Adds no database migration.

## 0.6.5 - Deployment Workflow & Compatibility Pass

- Formalizes locked-down manual software deployment in `docs/server/MANUAL-DEPLOYMENT.md`, including backup, dry-run, preservation, SFTP/control-panel, HTTP-boundary, and post-deployment verification steps.
- Adds `docs/server/MANUAL-THEME-DEPLOYMENT.md` with the private/public theme storage boundary and a canonical distributable layout for installations where PHP cannot write theme directories.
- Adds `scripts/deployment-check.php`, a read-only CLI installed-site check for version markers, package-managed file integrity, runtime-directory presence, and database connection/compatibility, while leaving PHP/web capability checks to Admin → System Check.
- Defines Bonumark database compatibility floors of MySQL 8.0+ and MariaDB 10.6+ and recommends a vendor-supported database release for production.
- Adds reusable database-family/version parsing and compatibility helpers, including handling for MariaDB compatibility-prefixed version strings.
- Makes fresh installation reject database servers below the documented compatibility floor and shows the detected supported database server before site creation.
- Adds database server compatibility to Admin → System Check so existing installations can see the detected family/version and compatibility status without changing state.
- Updates the disposable database smoke test and contribution guidance so database-sensitive changes record and verify the actual supported server version used.
- Intentionally does not add a privileged deployment daemon, sudo bridge, or self-elevating updater; locked-down software replacement remains an explicit application-owner/hosting-layer action.
- Adds no database migration.

## 0.6.4 - Hosting Capability & Nginx Support Pass

- Updates package identity from a shared-hosting-specific label to `self-hosted` while preserving shared-hosting compatibility as a supported deployment target.
- Adds `bms_web_server_capability()` for Apache, LiteSpeed, Nginx, and unknown/other server reporting.
- Adds `bms_php_ini_bytes()`, `bms_format_bytes_compact()`, and `bms_upload_limit_capability()` for PHP/Bonumark upload ceiling diagnostics.
- Adds `bms_theme_zip_install_capability()` and integrates it with System Check and Admin theme installation so locked-down theme directories use an explicit manual-deployment path.
- Adds cURL, ZipArchive, image-processing, theme-install, upload-ceiling, and web-server capability reporting without turning optional feature gaps into core failures.
- Adds maintained Nginx server documentation/configuration under `docs/server/`, including private-path denial, clean-route rewrites, Authorization forwarding, media PHP execution denial, and upload request sizing.
- Adds a documented manual locked-tree software deployment workflow and clarifies the runtime/owner paths that must be preserved.
- Updates installer server information to show detected web server plus optional cURL and ZipArchive feature availability.
- Adds no database migration.

## 0.6.3 - Routing & Diagnostic Safety Pass

- Replaces the write-based `_bonumark_stream/security-probe-*` exposure check with a read-only probe of `_bonumark_stream/VERSION`.
- Adds `bms_readonly_http_probe()` with cURL-first and stream fallback behavior and no redirect following.
- Adds `bms_private_folder_probe_response()` so protected, exposed, redirected, successful-but-ambiguous, and failed probe responses are classified consistently.
- Preserves an empty Stream slug before `bms_slugify()` so `/stream` remains an archive request.
- Adds the missing CLI-only guard to `scripts/admin-comments-runtime-test.php`.
- Extends package smoke coverage to every top-level PHP script under `/scripts` instead of checking only selected test scripts.
- Makes the package smoke test exit early on installed trees containing `config.php` or `installed.lock`, with an explicit Admin > System Check handoff.
- Adds no database migration.

## 0.6.2 - Upgrade Capability & Recovery Pass

- Adds `bms_package_managed_software_path()`, `bms_installed_release_manifest_paths()`, and `bms_automatic_upgrade_capability()` so automatic-upgrade support is a detected capability instead of an assumed hosting property.
- Requires writable managed files and containing directories for automatic software replacement, cleanup, and rollback while keeping runtime-only writability sufficient for normal Bonumark operation.
- Adds automatic-upgrade capability reporting to System Check and to the Upgrade screen before a package is run.
- Uses uploaded plus installed release-manifest paths during package precheck so the actual target release and possible obsolete managed files are considered.
- Refuses an automatic upgrade before backup/copy when package-managed software cannot be replaced safely.
- Tracks successfully copied package files and actually removed obsolete files during the install phase.
- Replaces broad pre-migration rollback with exact restoration/removal of only changed software and reports when no rollback is necessary because no software changed.
- Adds no database migration.

## 0.6.1 - Filesystem Capability Foundation Pass

- Adds `bms_runtime_directory_definitions()` as the single core definition for runtime storage required by installation and hosting diagnostics.
- Adds `bms_ensure_runtime_directories()` to create missing runtime directories and return consistent existence/write-state results.
- Moves the installer off its private hardcoded directory list and makes failed runtime preparation a clear install-time error with portable, relative path names.
- Adds `bms_runtime_directory_status()` so System Check can report the shared runtime paths without mutating the filesystem.
- Moves `bms_security_status()` off its separate hardcoded writable list and reports the shared runtime paths and their purposes instead.
- Runs shared runtime-directory provisioning on the first post-upgrade request so upgraded installs gain newly required runtime paths when host permissions allow it.
- Adds `_bonumark_stream/tmp`, `_bonumark_stream/content/versions`, `_bonumark_stream/import-staging`, `_bonumark_stream/import-staging/previews`, and `media` to the unified contract alongside the existing export, upgrade, data, and Markdown-import paths.
- Adds no database migration.

## 0.6.0 - Profiles & Theme Architecture 2.0

- Creates the next public GitHub milestone after v0.5.77 by consolidating the completed v0.5.78 through v0.5.120 development work into one release identity.
- Promotes Profiles into a first-class identity surface with structured identity metadata, Featured Work, Profile portability, cover media, and Profile photo galleries.
- Delivers Theme Architecture 2.0 Layout Schema 1 across Profile, Stream Card, Site Header, and Home while keeping core responsible for application behavior, data, security, accessibility semantics, and component rendering.
- Preserves legacy CSS-only themes through fixed composition fallback and keeps custom installed themes outside package-managed bundled-theme cleanup.
- Consolidates the first-party theme collection to Midnight Ledger and makes it the complete bundled/default reference implementation for all four declarative surfaces.
- Includes the Midnight Ledger visual, responsive, Home, Stream, Profile, canvas-alignment, content-resilience, and image-delivery work completed after the architecture foundation.
- Locks external link-preview title fidelity and the fragment/document SEO boundary so remote metadata cannot be rewritten as local Bonumark document SEO.
- Retains upgrade-time PHP cache invalidation while removing temporary runtime and pipeline diagnostics from the user-facing product.
- Adds Profile-only responsive image delivery with bounded fallbacks, optional WebP picture sources, high-priority cover loading, and lazy gallery delivery without changing normal Stream/Home media behavior.
- Adds migrations `0015_profile_identity_foundation.php`, `0016_profile_featured_work.php`, and `0017_profile_photos.php` for upgrades from the v0.5.77 public baseline.
- Rewrites the root README as a GitHub project landing page and separates public release summaries from detailed package development history.
- Fixes Midnight Ledger's empty-Profile state so the identity card stays below the Site Header when no Profile cover is present, while preserving the intended cover overlap when a cover is rendered on desktop and mobile.
- Bumps Midnight Ledger to theme version 1.9.1 so the corrected Profile CSS receives a fresh theme-owned asset cache revision.
- Updates current-version documentation, API examples, OpenAPI metadata, package metadata, service-worker cache revision, security support policy, and release manifest to v0.6.0.

## 0.5.120 - Profile Modern Image Delivery Pass

- Adds Profile-only WebP responsive derivatives for cover and gallery images when the host provides GD WebP or Imagick WebP encoding support.
- Wraps Profile cover and gallery images in `<picture>` with WebP `source` candidates while preserving the existing responsive JPEG/PNG/WebP `<img>` fallback path.
- Adds an explicit responsive Profile-cover preload in the document head and keeps the cover eager with `fetchpriority="high"` to improve LCP discovery.
- Keeps below-fold Profile gallery photos lazy and marks them `fetchpriority="low"` so they do not compete with the cover during initial rendering.
- Uses bounded fallback derivatives as the source for legacy-image WebP backfill when available, avoiding repeated decoding of multi-megapixel originals on the first upgraded Profile request.
- Uses bounded fallback `src` values for the Profile cover and gallery instead of pointing the base `src` at a full-size original when a suitable derivative exists.
- Generates modern Profile derivatives at upload time and can create missing Profile-only WebP candidates for existing Profile media when supported; originals remain untouched.
- Falls back cleanly to the v0.5.119 responsive image path when WebP encoding is unavailable.
- Does not change Stream/Home media delivery, Midnight Ledger layout or CSS, Profile composition, publishing, comments, likes, routing, APIs, Theme Architecture 2.0, or the database schema.
- Adds no database migration.

## 0.5.119 - Profile Image Delivery Optimization Pass

- Fixes the Profile cover delivery path so existing generated cover derivatives are actually exposed through `srcset` instead of always sending the original upload.
- Keeps the above-fold Profile cover eager and `fetchpriority="high"` while preserving verified width/height attributes for layout stability.
- Adds 240px and 360px Profile-photo derivative candidates alongside 480px, 800px, and 1200px sizes so mobile and four-column desktop gallery slots do not over-download 480px images.
- Uses `sizes="auto"` for lazy Profile gallery images with safe fallback lengths so browser selection follows the real rendered slot even when a code-free theme changes gallery columns.
- Generates the full Profile-photo derivative set at upload time and can create missing bounded Profile cover/photo candidates once for upgraded installs when resize support is available.
- Leaves original uploads untouched, preserves lazy loading for below-fold Profile photos, and makes no changes to Profile composition, Midnight Ledger layout JSON, Header, Home, Stream Card, publishing, comments, likes, routing, APIs, or Theme Architecture 2.0.
- Adds no database migration.

## 0.5.118 - Midnight Ledger Profile Content Resilience Pass

- Reorganizes Midnight Ledger's declarative Profile composition so About and the supporting Now/Interests/Links/Details rail share the upper desktop row.
- Moves Featured and Photos out of the primary text column so both begin below the taller upper column and span the full 1040px Profile identity canvas.
- Keeps mobile order predictable as About, Featured, Photos, then the supporting rail instead of using masonry or content-dependent reordering.
- Allows longer Now text, additional links, more interests, and optional Details content to grow naturally without colliding with the showcase sections.
- Keeps public Profile links as wrapping pills so additional links increase card height instead of overflowing horizontally.
- Makes the full-width desktop photo showcase count-aware: one photo remains constrained, two use two columns, three use three equal columns, and four use four equal columns; smaller viewports keep the core two-column gallery behavior.
- Bumps Midnight Ledger to theme version 1.9.0 for a fresh theme-owned asset cache revision.
- Adds no database migration and makes no changes to Header, Home, Stream Card, publishing, comments, likes, permissions, routing, media behavior, APIs, metadata, or Theme Architecture 2.0.

## 0.5.117 - Midnight Ledger Profile Banner Alignment Pass

- Explicitly centers the Midnight Ledger Profile cover within the 1040px identity canvas used by the Profile masthead and composition.
- Makes the declarative `profile.cover` wrapper and cover panel stretch to the full centered Profile canvas instead of relying on implicit grid stretching.
- Explicitly centers the cover image crop with `object-position: 50% 50%`.
- Normalizes Midnight Ledger's older 980px base Profile shell limit to the current 1040px identity canvas.
- Preserves all four Midnight Ledger layout JSON files byte-for-byte and makes no changes to the layout engine, renderer, public templates, or application behavior.
- Bumps Midnight Ledger to theme version 1.8.3 for a fresh theme-owned asset cache revision.
- Adds no database migration and makes no changes to publishing, comments, likes, permissions, routing, media processing, APIs, metadata, or Theme Architecture 2.0.

## 0.5.116 - Midnight Ledger Canvas Alignment Pass

- Aligns the shared Midnight Ledger Site Header to the intentional content canvas of each major public surface instead of keeping the wider shell width everywhere.
- Uses the focused 860px reading canvas for Home, single Stream posts, Stream archives, and search.
- Keeps Profile on the wider 1040px identity canvas so the masthead continues to align with the cover and profile composition.
- Preserves the exact declarative Site Header layout and core Header components; only theme-level surface alignment changes.
- Keeps mobile behavior naturally fluid because both canvas widths collapse to 100% of the available viewport.
- Preserves all four Midnight Ledger layout JSON files byte-for-byte and makes no changes to the layout engine, renderer, public templates, or application behavior.
- Bumps Midnight Ledger to theme version 1.8.2 for a fresh theme-owned asset cache revision.
- Adds no database migration and makes no changes to publishing, comments, likes, permissions, routing, media processing, APIs, metadata, or Theme Architecture 2.0.

## 0.5.115 - Midnight Ledger Responsive Polish Pass

- Polishes Midnight Ledger responsive behavior without changing its four declarative Schema 1 layouts or any application behavior.
- Extends the compact one-line masthead treatment into common medium phone/tablet widths while preserving natural title wrapping on truly narrow screens.
- Restrains single-post body typography and paragraph rhythm at medium responsive widths so moderate posts do not become oversized editorial blocks.
- Gives the mobile comment textarea an explicit compact default height while retaining vertical resize behavior.
- Tightens the single-post-to-comments spacing and keeps the flattened discussion treatment introduced in v0.5.113.
- Preserves the existing Home, Profile, Stream Card, and Site Header layout JSON byte-for-byte.
- Bumps Midnight Ledger to theme version 1.8.1 for a fresh theme-owned asset cache revision.
- Adds no database migration and makes no changes to publishing, comments logic, likes, permissions, routing, media processing, APIs, metadata, or Theme Architecture 2.0.

## 0.5.114 - Midnight Ledger Home Composition Pass

- Moves Midnight Ledger's `home` surface onto Layout Schema 1 using the existing core-owned Home component contract.
- Adds a private `layouts/home.json` composition with notices first, a dedicated publish region containing the atomic composer, and a timeline region containing pinned posts, feed, and pagination.
- Completes Midnight Ledger declarative adoption across all four current Schema 1 public surfaces: Profile, Stream Card, Site Header, and Home.
- Preserves Bonumark's single-column publish-first Home workflow instead of introducing sidebar, dashboard, or reading-first experiments.
- Refines the Home composer proportions and composed-region spacing without changing composer fields, validation, CSRF, scheduling, media, or publishing behavior.
- Keeps the masthead title on one line at intermediate phone/small-tablet widths when space permits while allowing natural wrapping on truly narrow screens.
- Tightens the single-post-to-comments transition while preserving the flatter v0.5.113 comments treatment.
- Bumps Midnight Ledger to theme version 1.8.0 for a fresh theme-owned asset cache revision.
- Adds no database migration and makes no changes to Profile composition, Stream Card composition, routing, permissions, comments logic, likes, APIs, media processing, metadata, or core application behavior.

## 0.5.113 - Midnight Ledger Stream & Mobile Refinement Pass

- Moves Midnight Ledger's `site-header` surface onto Layout Schema 1 using core-owned site identity, Menu toggle, and primary navigation components.
- Removes Midnight Ledger's theme-specific Live microblog status chip, published-post-count chip, and their Theme Settings controls.
- Reworks mobile link previews into compact horizontal cards so linked content supports the post instead of dominating the viewport.
- Reduces the visual weight of likes, comments, and Post Options controls while preserving all existing behavior and accessibility hooks.
- Flattens the public comments area into the single-post reading flow and reduces form/container chrome.
- Tightens mobile masthead, post, preview, action, and comment spacing without changing Profile composition.
- Bumps Midnight Ledger to theme version 1.7.0 for a fresh theme-owned asset cache revision.
- Adds no database migration and does not change publishing, comments logic, likes, permissions, routing, media processing, APIs, or metadata behavior.

## 0.5.112 - Midnight Ledger Visual Foundation Pass

- Begins the intentional Midnight Ledger redesign after Theme Architecture 2.0 consolidation, using the real desktop/mobile acceptance screenshots as the visual baseline.
- Widens the public shell while constraining Home, archive, search, and single-post reading surfaces to a calmer focused column instead of stretching every screen to the same width.
- Reworks Midnight Ledger design tokens, spacing rhythm, borders, shadows, radii, masthead proportions, composer treatment, timeline card density, link previews, media framing, comments, and small-screen behavior without changing application behavior.
- Re-composes the declarative Profile into a cover/identity story followed by a desktop main-content and supporting-information grid that collapses to a single mobile flow.
- Visually connects the Profile identity panel to the cover with a controlled overlap, reduces repetitive full-width section stacking on desktop, and preserves every core Profile component and owner action.
- Keeps Midnight Ledger Header and Home on their existing legacy composition paths in this pass while improving their presentation through theme CSS; no new declarative surface or application component is added.
- Keeps the existing Midnight Ledger Stream Card component order and all core-owned likes, comments, Quick edit, Post Options, media, link-preview, CSRF, accessibility, and routing behavior unchanged.
- Bumps Midnight Ledger to theme version 1.6.0 so the visual-foundation CSS receives a new theme-owned cache revision.
- Adds no database migration and makes no changes to publishing, permissions, APIs, metadata, navigation logic, media processing, or application routes.

## 0.5.111 - Midnight Ledger Declarative Baseline Pass

- Moves the bundled/default Midnight Ledger theme onto Layout Schema 1 for the `profile` and `stream-card` surfaces.
- Adds private `layouts/profile.json` and `layouts/stream-card.json` files that preserve Midnight Ledger's established Profile and Stream Card component order while using the validated Theme Architecture 2.0 renderer.
- Keeps Midnight Ledger's Site Header and Home on the legacy core renderer so the existing theme-specific status chip and Home workflow are not changed accidentally during structural conversion.
- Adds declarative-wrapper CSS bridges so the new Profile and Stream Card composition retains the established Midnight Ledger presentation and responsive behavior.
- Bumps Midnight Ledger to theme version 1.5.0 so browsers receive the declarative-baseline CSS revision independently from the Bonumark Stream application version.
- Preserves the four-surface Theme Architecture 2.0 API, third-party CSS-only compatibility, internal dual-composition regression fixtures, and all core application behavior.
- Adds no database migration and makes no changes to routing, publishing, permissions, comments, likes, media, APIs, metadata, Header composition, or Home composition.

## 0.5.110 - Theme Consolidation Pass

- Consolidates Bonumark Stream to a single bundled/default public theme: Midnight Ledger.
- Removes Editorial Profile and Split Profile from the installable Theme Manager collection and removes their public asset mirrors.
- Preserves both materially different four-surface Schema 1 compositions as internal regression fixtures under `scripts/fixtures/declarative-themes/`, so Theme Architecture 2.0 continues to prove independent `profile`, `stream-card`, `site-header`, and `home` composition without creating a theme-maintenance burden.
- Changes the protected bundled-theme registry to `default` only, allowing third-party themes to use former proof-theme slugs normally.
- Extends upgrade cleanup to retire only old Editorial/Split copies whose installed manifests explicitly identify them as Bonumark bundled proof themes; unrelated or user-replaced themes with the same slugs remain preserved.
- Keeps Midnight Ledger on the existing Legacy Core Renderer and makes no visual redesign in this pass.
- Preserves all four declarative surfaces, Layout Schema 1, Theme Health, installer validation, component registries, legacy CSS-only compatibility, and third-party declarative theme support.
- Adds no database migration and makes no changes to publishing, routing, permissions, comments, likes, media, APIs, metadata, or accessibility behavior.

## 0.5.109 - Site Composition Proof & Hardening Pass

- Completed the first combined Theme Architecture 2.0 site-composition acceptance pass across `profile`, `stream-card`, `site-header`, and `home` without adding a fifth declarative surface.
- Added integrated regression coverage that renders Site Header and Home together with nested declarative Stream Cards through each bundled proof theme, preserving composer CSRF, Quick edit, likes, Post Options, pagination, navigation identity, and the single Home `<h1>` contract.
- Added an explicit regression proving Midnight Ledger remains on the legacy fallback path for every currently supported declarative surface.
- Hardened Editorial Profile and Split Profile Header/Home CSS containment for narrow widths, long site/navigation text, composer controls, notices, and composed feed/pagination regions.
- Bumped both bundled declarative proof themes to version 1.3.1 so the hardening CSS receives a fresh theme-owned cache revision.
- Added `docs/DECLARATIVE-LAYOUTS.md` as the stable third-party Layout Schema 1 reference covering manifest fields, supported surfaces, component identifiers, nesting, fallback, Theme Health, responsive expectations, and compatibility rules.
- Preserved the existing Profile, Stream Card, Site Header, and Home component contracts, layout JSON, application behavior, permissions, forms, routing, metadata, accessibility, and static/dynamic shared rendering boundaries.
- Added no database migration and made no unrelated product or admin changes.

## 0.5.108 - Declarative Home Composition Pass

- Enabled `home` as the fourth Layout Schema 1 declarative surface.
- Kept the document shell, `<main>`, notices semantics, atomic Stream composer, pinned-post selection, feed/empty-state logic, pagination behavior, permissions, validation, and accessibility in Bonumark Stream core.
- Wired the existing `home.php` template to use validated declarative Home composition when the active theme declares `layouts.home`, while preserving the complete fixed legacy Home arrangement for themes that do not opt in.
- Preserved `home.feed` as the semantic Stream region and empty-state boundary, so Home layouts need no condition or expression language.
- Kept pinned and normal posts on the existing `stream-card` renderer instead of creating a second post-composition API inside Home.
- Extended Editorial Profile with a reading-first Home composition and Split Profile with a workspace Home composition using a desktop publish rail beside the timeline; both collapse safely on smaller screens.
- Bumped both bundled declarative proof themes to version 1.3.0 so Home layout/CSS changes receive fresh theme-owned cache revisions.
- Added regression coverage for Home surface validation, core component parity, unsupported-component rejection, private layout-file handling, materially different proof-theme composition, atomic composer preservation, Stream Card reuse, and legacy Home fallback.
- Added no database migration and made no changes to Profile, Stream Card, Site Header, publishing, comments, likes, media, routes, or application behavior.

## 0.5.107 - Home Composition Foundation Pass

- Added five core-owned Home components for public notices, the atomic Stream composer, pinned posts, the normal feed/empty state, and pagination.
- Registered the Home component family in the existing Theme Architecture 2.0 component registry without enabling `home` as a declarative surface yet.
- Separated `bms_render_stream_index()` into prepared `notices_html`, `composer_html`, `pinned_posts_html`, `feed_html`, and `pagination_html` boundaries while preserving the complete legacy `items_html` concatenation used by the current Home template.
- Kept the existing `home.php` composition unchanged so Midnight Ledger, bundled declarative themes, and third-party themes continue through the fixed Home arrangement in this release.
- Kept the composer atomic and core-owned, kept pinned/normal posts on the existing `stream-card` renderer, and kept empty state as a core state of `home.feed` rather than exposing a conditional theme component.
- Added regression coverage for Home component contracts, prepared-data-only rendering, feed semantics, legacy Home fallback, and the still-disabled `home` surface.
- Added no database migration and made no changes to Site Header, Profile, Stream Card, publishing, comments, likes, media, routing, or theme layout JSON.

## 0.5.106 - Declarative Site Header Composition Pass

- Enabled `site-header` as the third Layout Schema 1 declarative surface.
- Kept the outer semantic public `<header>` shell in Bonumark Stream core while allowing validated themes to compose the Header interior from registered core-owned components.
- Preserved the Site Header component contract: required `site-header.site-identity`, required `site-header.primary-navigation`, optional `site-header.menu-toggle`, and optional `site-header.stream-count`.
- Kept navigation records, URLs, active-state logic, authenticated account decisions, menu JavaScript behavior, title heading selection, accessibility semantics, and Static Site Export session-neutral navigation in core.
- Preserved the complete fixed legacy Header composition for Midnight Ledger and any theme that does not declare `layouts.site-header`.
- Extended Editorial Profile with an integrated always-visible navigation masthead that intentionally omits the menu toggle.
- Extended Split Profile with a utility-first masthead that uses the core-owned menu toggle and a separately composed primary navigation region.
- Bumped both bundled declarative proof themes to version 1.2.0 so their Header layout/CSS changes receive new theme-owned cache revisions.
- Added regression coverage proving valid/invalid Site Header layout contracts, optional menu-toggle behavior, stable identity/navigation/count output, materially different proof-theme composition, private layout-file handling, and legacy Header equivalence.
- Added no database migration and made no changes to Home, Profile, Stream Card, publishing, comments, likes, media, or routing behavior.

## 0.5.105 - Site Header Component Foundation Pass

- Added four core-owned Site Header components for site identity, primary navigation, menu toggle, and published Stream count.
- Registered the Site Header component family in the existing Theme Architecture 2.0 component registry without enabling `site-header` as a declarative surface yet.
- Kept the existing public Header template and legacy theme output unchanged so CSS-only themes continue through the fixed Header composition.
- Extended the prepared Header view data with a normalized menu label and an explicit account-navigation state for the upcoming declarative composition milestone.
- Kept navigation URLs, active-state logic, authentication decisions, menu behavior, headings, accessibility semantics, and all application behavior in core.
- Hardened Static Site Export so exported Header navigation omits session-specific account destinations from the authenticated admin session that launched the export.
- Added regression coverage for the four Site Header component contracts, prepared-data-only rendering, behavior hooks, legacy Header fallback, and the still-disabled `site-header` surface.
- Added no database migration and made no changes to Profile or Stream Card declarative composition.

## 0.5.104 - Runtime Diagnostic Cleanup Pass

- Removed the manual `admin/runtime-cache.php` diagnostic page.
- Removed the PHP Runtime Cache card from Admin > Tools.
- Removed the link-preview runtime revision marker that existed only to support the manual runtime diagnostic.
- Removed diagnostic-only smoke-test requirements and active upgrade guidance tied to the deleted runtime UI.
- Preserved automatic per-file OPcache invalidation when the admin upgrader replaces PHP files.
- Preserved the automatic full OPcache reset request after package-managed software replacement completes.
- Preserved the v0.5.102 Fragment SEO Boundary Fix that prevents document SEO from rewriting fragment data such as external link-preview titles.
- Preserved external link-preview metadata protections and fragment-title regression tests.
- Added cleanup regression coverage that fails if the removed runtime diagnostic page, Tools link, or runtime revision marker is reintroduced.
- Kept PHP runtime cache handling as an internal upgrade responsibility instead of exposing it as a user-facing maintenance tool.

## 0.5.103 - Cleanup & Regression Lock Pass

- Removed the temporary `admin/link-preview-pipeline.php` investigation page added in v0.5.101.
- Removed link-preview AJAX session capture from the normal preview-fetch endpoint.
- Removed raw request, sanitized payload, front-matter, stored database, hydrated post, and render-payload session tracing from the normal quick-post publishing path.
- Removed temporary link-preview pipeline trace helper functions from the core link-preview module.
- Removed the Link Preview Pipeline card from Admin > Tools.
- Preserved the v0.5.102 **Fragment SEO Boundary Fix** that prevents document SEO from rewriting fragment data such as external link-preview titles.
- Preserved regression tests proving link-preview and card fragment titles remain untouched while complete public documents still receive normal SEO metadata.
- Added cleanup regression checks that fail the package smoke test if temporary pipeline tracing or its admin page is accidentally reintroduced.
- Preserved external preview title fidelity/isolation protections from v0.5.98-v0.5.99.
- Preserved v0.5.100 upgrade-time PHP OPcache invalidation/reset behavior and the general Admin > Tools > PHP Runtime Cache diagnostic.
- Bumped the runtime marker to `0.5.103-link-preview` so the PHP Runtime Cache screen can verify the cleanup build is actually loaded.

## 0.5.102 - Fragment SEO Boundary Fix

- Fixed the root cause of external link previews incorrectly ending with the local Bonumark site name.
- `bms_public_seo_view_data()` now applies document-title SEO only to complete public document templates: `layout`, `home`, `archive`, `single`, `page`, `profile`, `account`, and `search`.
- Fragment templates such as `link-preview`, `card`, `media`, `location`, `composer`, `comments`, `pagination`, and empty states now retain their domain data exactly as supplied by their owning renderer.
- This prevents a correct external title such as `Builder Receipt: Reworking Bonumark Stream | Jim Lunsford` from being rewritten as a Bonumark document title ending in `| Bonumark Stream`.
- The v0.5.101 pipeline diagnostic remains available and now provides proof that fetch, submission, storage, hydration, and render payloads all remain unchanged through the fixed fragment-render boundary.
- Added regression coverage proving link-preview and card fragment titles are not rewritten while actual page templates continue receiving document SEO metadata.
- Bumped the link-preview runtime marker to `0.5.102-link-preview` for verification.

## 0.5.101 - Link Preview Pipeline Diagnostic Pass

- Added **Admin > Tools > Link Preview Pipeline**, an admin-only diagnostic that exposes the exact link-preview title at every stage without changing preview behavior.
- Added a live URL probe showing the raw remote HTML `<title>`, `og:title`, `twitter:title`, `og:site_name`, `application-name`, the selected pre-sanitizer title/site name, the local site name, suffix-strip probe result, and final fetch payload.
- Captures the exact JSON preview returned by the real AJAX composer endpoint in the current admin session.
- Captures the next front-composer link-preview submission from raw hidden fields through request sanitization, generated front matter, parsed page values, stored database front matter, hydrated page values, and final render payload.
- Added a recent stored-preview inspector comparing raw stored front matter with hydrated and rendered preview metadata for published posts.
- Added explicit local-site-name JSON output so invisible whitespace or punctuation differences cannot hide during diagnosis.
- Added a clear-session-traces action that does not modify posts.
- Kept v0.5.100 runtime-cache diagnostics and v0.5.99 link-preview title behavior unchanged. This release is diagnostic only.
- Updated smoke-test coverage to require the pipeline diagnostic, trace helpers, AJAX capture, quick-post capture, and v0.5.101 runtime revision marker.

## 0.5.100 - PHP Runtime Cache Reliability Pass

- Added per-file OPcache invalidation whenever the admin upgrader replaces a PHP file.
- Added a full OPcache reset request after package-managed software replacement and obsolete-file cleanup complete.
- Added `admin/runtime-cache.php`, a new admin-only diagnostic page that reports the installed Bonumark version, the link-preview runtime revision PHP actually loaded, the link-preview file SHA-256 on disk, OPcache availability, timestamp-validation state, revalidation frequency, and cached-script status when the host exposes it.
- Added a manual **Refresh PHP Runtime Cache** action that invalidates the link-preview PHP file and resets OPcache when the hosting environment permits it.
- Added an explicit `0.5.100-link-preview` runtime revision marker so an installation can distinguish the VERSION file on disk from the link-preview code PHP is actually executing.
- Added a Runtime Cache diagnostic card under Admin > Tools.
- Kept the v0.5.99 external-preview title isolation logic unchanged; this release targets stale PHP runtime execution rather than changing metadata rules again.
- Updated smoke-test coverage to require the runtime diagnostic, runtime revision marker, and upgrade-time cache invalidation paths.

## 0.5.99 - External Preview Title Isolation Pass

- Enforced a shared link-preview invariant: an external title cannot retain a trailing local Bonumark installation site name when the remote page identifies itself as a different site.
- The fix does not reconstruct or substitute remote titles. It only removes the local contamination suffix.
- Applied through the shared preview sanitizer, covering newly fetched previews, submitted composer metadata, stored front matter, and rendered existing previews.
- Existing stored previews such as `Remote Article | Remote Site | Bonumark Stream` now render as `Remote Article | Remote Site` without requiring the post to be recreated.
- Preserves titles unchanged when a linked remote site legitimately uses the same site name as the local installation.
- Retains v0.5.98 remote HTML `<title>` precedence with Open Graph / Twitter title fallback.
- Added regression coverage for both contaminated external titles and legitimate same-name remote sites.

## 0.5.98 - Remote Link Title Fidelity Pass

- Changed external link previews to prefer the linked page's own HTML `<title>` as the preview title.
- Keeps `og:title` and `twitter:title` as fallbacks only when the remote document does not provide a usable `<title>`.
- Keeps remote `og:site_name` / application-name metadata as a separate site label instead of merging it into the preview title.
- Removed the v0.5.97 local-site title normalization and reconstruction path; the local Bonumark Stream site name no longer participates in external preview titles.
- Added a pure HTML-to-preview extraction path so title selection can be regression-tested without making network requests.
- Existing stored preview metadata is left untouched rather than guessed or rewritten; new or refreshed previews use the corrected remote-title behavior.
- Updated package smoke-test coverage to enforce document-title precedence and prevent reintroduction of local-site title reconstruction.

## 0.5.97 - Link Preview Metadata Integrity Pass

- Added remote-title normalization for link previews when fetched metadata incorrectly contains the local Bonumark Stream site name as its suffix while the remote page identifies a different site name.
- Uses the remote `og:site_name` / application name as the correction target instead of hiding the bad suffix in a theme.
- Applies normalization through the shared link-preview payload sanitizer, so future composer previews are corrected before storage and already-saved previews are corrected when rendered.
- Leaves legitimate matching site names alone and does not rewrite unrelated external titles.
- Added package smoke-test coverage for the normalization path.

## 0.5.96 - Dual Stream Card Layout Proof Pass

- Extends the bundled Editorial Profile and Split Profile proof themes to the Schema 1 `stream-card` surface with real private `layouts/stream-card.json` files.
- Gives Editorial Profile a content-first Stream Card composition that renders body, media, and link preview as the story, then places avatar, author/date, location, and core actions in a restrained byline footer.
- Gives Split Profile a materially different desktop Stream Card composition with avatar, author/date, location, likes/comments, and Post Options in a narrow metadata rail beside the main body/media/link-preview column.
- Adds responsive rules so both declarative card compositions collapse to safe single-column mobile flows without changing core component markup or behavior.
- Bumps both bundled declarative proof themes to version 1.1.0 so their new Stream Card layouts and CSS receive fresh theme-owned cache revisions.
- Keeps all seven Stream Card components core-owned and verifies the two bundled themes render identical component HTML from identical prepared card data while producing opposite body/header ordering.
- Keeps Quick edit, likes, comments, Post Options, full editor, pin/unpin, trash, CSRF fields, accessibility hooks, and the outer `data-stream-card` article shell in Bonumark Stream core.
- Keeps both Stream Card layout JSON files private and mirrors only declared CSS/screenshots to public theme assets.
- Keeps Midnight Ledger on the fixed Legacy Core Renderer Stream Card composition.
- Adds no database migration and changes no Stream Card data preparation, component PHP, Profile layouts/components, routes, publishing, media processing, permissions, or static export pipeline.

## 0.5.95 - Declarative Stream Card Composition Pass

- Enables `stream-card` as the second supported Schema 1 Declarative Layout surface.
- Allows a layout-aware theme to declare private `layouts/stream-card.json` and arrange the seven registered core-owned Stream Card components with validated `group` and `component` nodes.
- Keeps the outer Stream Card `<article>`, `data-stream-card`, public URL, preview state, card classes, and click/interaction boundary in Bonumark Stream core.
- Uses declarative composition only for the card interior; legacy themes and layout-aware themes that do not declare `stream-card` continue through the exact existing `stream-card-inner` / `stream-card-main` fixed composition.
- Keeps Quick edit atomic inside `stream-card.body` and keeps likes, comments, single-post back navigation, full editor access, pin/unpin, trash, CSRF fields, and the Post Options menu atomic inside `stream-card.actions`.
- Revalidates the private Stream Card layout at render time and supplies the same stable `data-bms-layout`, `data-bms-layout-group`, and `data-bms-component` hooks used by the Profile surface.
- Adds regression coverage proving two materially different Stream Card layouts render identical prepared data through identical core components while preserving Quick edit, like, and Post Options behavior hooks.
- Confirms the declarative branch replaces only presentation-only inner/main wrappers; JavaScript continues to bind to core-owned `data-stream-card` and component behavior hooks.
- Keeps the bundled Midnight Ledger, Editorial Profile, and Split Profile themes on their existing Stream Card composition because none declares `layouts/stream-card.json` in this pass.
- Adds no database migration and changes no Stream Card data preparation, Profile composition, routes, publishing, comments, likes, media processing, permissions, or static export pipeline.

## 0.5.94 - Stream Card Component Extraction Pass

- Extracts the existing public Stream card interior into seven core-owned presentation components without enabling declarative Stream Card composition yet.
- Registers `stream-card.avatar`, `stream-card.header`, `stream-card.body`, `stream-card.location`, `stream-card.link-preview`, `stream-card.media`, and `stream-card.actions` to private Bonumark-owned PHP component files.
- Keeps the existing `<article>`, `stream-card-inner`, and `stream-card-main` shell in the core card template so the current DOM structure and CSS contract remain intact.
- Keeps Quick edit markup and hooks atomic inside `stream-card.body`, while likes, comments, single-post back navigation, full-editor access, pin/unpin, trash, CSRF fields, and the post-options menu remain atomic inside `stream-card.actions`.
- Preserves the existing prepared renderer data and the existing location, link-preview, and media HTML render paths; the extracted components do not query the database, read request input, or take over application behavior.
- Intentionally leaves `stream-card` out of the supported declarative layout surfaces, so themes cannot declare `layouts/stream-card.json` in this pass and all Stream cards continue through the fixed legacy composition.
- Adds regression coverage for all seven component files, registry mappings, prepared-data-only boundaries, preserved behavior hooks, and the continued absence of declarative Stream Card rendering.
- Adds no database migration and changes no Profile layout/schema/components, bundled theme composition, Stream card data preparation, routes, publishing, comments, likes, media processing, static export, or application permissions.

## 0.5.93 - Declarative Profile Responsive Hardening Pass

- Hardens the two bundled Theme Architecture 2.0 Profile proof themes after real desktop/mobile testing exposed horizontal clipping in Editorial Profile on narrow screens.
- Adds explicit declarative Profile containment so layout roots, groups, component wrappers, Profile cards, and nested grid/flex content may shrink with `min-width: 0` and stay within the available width.
- Makes long Profile headings, paragraphs, links, Featured text, interests, and metadata wrap safely instead of establishing a wider min-content size.
- Replaces Editorial Profile's margin-dependent identity/content sizing with explicit viewport-safe calculated widths and centered margins, plus an additional small-phone breakpoint for the identity panel and narrative text.
- Applies the common declarative containment contract to both Editorial Profile and Split Profile while preserving their materially different compositions.
- Bumps both bundled proof themes from 1.0.0 to 1.0.1 so the corrected CSS receives a new theme-owned cache revision.
- Adds regression coverage for proof-theme cache revisions and declarative Profile containment CSS.
- Adds no database migration and changes no Profile data, Schema 1 layout document, core Profile components, Profile routes/editing, Midnight Ledger, legacy rendering, Stream cards, publishing, comments, likes, APIs, or media processing.

## 0.5.92 - Dual Profile Layout Proof Pass

- Adds two bundled, code-free Theme Architecture 2.0 proof themes: Editorial Profile and Split Profile.
- Gives Editorial Profile a wide magazine-style composition with a pulled-up identity band, centered narrative flow, and separate secondary/meta grids using the same ten core-owned Profile components.
- Gives Split Profile a materially different desktop sidebar/main-content composition that places identity, Details, and Links in the sidebar while About and other narrative components remain in the main column.
- Adds explicit responsive rules so both proof compositions collapse to safe single-column mobile flows without changing core component markup, accessibility semantics, Profile data, or behavior.
- Keeps both layout documents private under `layouts/profile.json`; only CSS and screenshots are mirrored to public theme assets.
- Protects all three bundled themes, Midnight Ledger, Editorial Profile, and Split Profile, from deletion or replacement through the theme uploader.
- Adds regression coverage proving both proof themes pass Theme Health, render all ten registered components from identical prepared data, retain exactly one Profile heading and media-viewer behavior, preserve identical core component HTML, and produce different component ordering/composition.
- Keeps Midnight Ledger on the Legacy Core Renderer, adds no database migration, and changes no Profile storage/editing, Stream cards, publishing, comments, likes, APIs, media processing, routes, or static export behavior.

## 0.5.91 - Declarative Profile Composition Pass

- Enables Theme Architecture 2.0 composition for the public Profile interior when the active theme explicitly declares a valid Schema 1 `profile` layout.
- Loads private `layouts/profile.json` server-side, revalidates it at render time, and renders only the existing ten core-owned Profile components through safe `group` and `component` nodes.
- Adds core-owned stable composition hooks: `data-bms-layout`, `data-bms-layout-schema`, `data-bms-layout-group`, and `data-bms-component`, plus predictable Bonumark-owned CSS classes for layout groups and components.
- Keeps the document shell, Profile not-found state, Profile data preparation, component markup, headings, accessibility behavior, media viewer hooks, owner controls, permissions, routes, and application actions in Bonumark Stream core.
- Preserves the complete v0.5.90 fixed Profile composition as the fallback for Midnight Ledger and every existing CSS-only theme that does not opt into declarative layouts.
- Treats a declared but missing, malformed, or invalid runtime layout as an integrity failure instead of accepting unvalidated theme input.
- Adds regression coverage for active Profile composition, stable layout/component hooks, ten-component rendering, and explicit legacy-theme fallback behavior.
- Adds no database migration and changes no Stream cards, Profile storage/editing, publishing, comments, likes, APIs, media processing, or static export behavior.

## 0.5.90 - Profile Component Extraction Pass

- Extracts the existing public Profile interior into ten core-owned presentation components without enabling declarative Profile composition yet.
- Maps the existing Theme Architecture 2.0 Profile registry to private core component files for cover, avatar, identity, About, Featured, Photos, Now, Interests, Links, and Details.
- Adds a core-owned component renderer that accepts already-prepared public view data and resolves component PHP files exclusively from Bonumark Stream core. Themes still cannot supply PHP, callbacks, templates, or renderer code.
- Keeps the existing Profile not-found state and legacy Profile composition wrapper in the core Profile template, including the current Profile hero section around the separately extracted avatar and identity components.
- Preserves current Profile markup behavior and section order while separating component implementation from component arrangement in preparation for the next declarative composition milestone.
- Adds regression coverage for all ten component files, registry-to-template mappings, prepared-data-only component boundaries, and continued absence of `data-bms-layout` declarative rendering hooks.
- Adds no database migration and changes no Profile storage, routes, editing, metadata, media behavior, permissions, theme settings, Stream rendering, or static export behavior.

## 0.5.89 - Declarative Theme Integration Pass

- Integrates the v0.5.88 Declarative Layout Themes foundation with the real theme package lifecycle while leaving public Profile composition, Stream cards, routes, database schema, permissions, forms, and application behavior unchanged.
- Extends the theme installer so layout-aware themes may ship only explicitly declared private `layouts/*.json` files. Declared layout documents are validated before installation, copied only into the private theme directory, and never published under `assets/themes/`.
- Extends Theme Health and activation checks so missing, malformed, unsupported, or structurally invalid declarative layout files prevent activation while legacy CSS-only themes continue to require no layout files.
- Extends Theme Manager and Theme Details reporting with renderer mode, layout schema, declared layout count, and layout surface/file information so legacy and layout-aware themes are clearly distinguishable.
- Corrects public theme asset cache revisions so CSS, images, fonts, and screenshots use the active theme manifest version instead of the Bonumark Stream application version.
- Updates theme installation guidance and theming/architecture documentation for the integrated declarative package contract.
- Adds regression coverage for valid and missing layout-file health checks, private installer copying, Theme Manager reporting, and theme-version asset cache keys.
- Keeps Midnight Ledger on the Legacy Core Renderer, adds no database migration, and does not yet route Profile rendering through declarative composition.

## 0.5.88 - Declarative Layout Foundation Pass

- Begins Theme Architecture 2.0 without changing public rendering, Profile markup, Stream cards, database schema, routes, permissions, forms, or application behavior.
- Adds optional versioned `layout_schema` and `layouts` theme manifest fields while keeping themes with neither field fully backward compatible.
- Adds a core-owned declarative layout registry with the Profile as the first supported surface and ten approved Profile components: cover, avatar, identity, About, Featured, Photos, Now, Interests, Links, and Details.
- Adds strict code-free layout validation for `group` and `component` nodes only, including private `layouts/*.json` path confinement, supported-surface checks, maximum depth/node limits, required component cardinality, and rejection of unknown properties or unregistered components.
- Adds no expression language, arbitrary HTML, PHP, JavaScript, SQL, callbacks, routes, database access, or behavior hooks to themes.
- Adds regression coverage proving legacy CSS-only themes require no declarative fields, valid Profile layouts pass the new validator, unsafe/unknown properties and components fail, and the existing Profile renderer remains untouched in this foundation pass.
- Defers theme-installer layout copying, Theme Health/Manager layout reporting, Profile component extraction, declarative Profile rendering, and Stream-card composition to later controlled milestones.

## 0.5.87 - Profile Gallery Pass

- Adds an optional Profile Photos section with up to four owner-selected images. Profile photos are identity media only and never create Stream posts or Media Library records.
- Stores ordered semantic photo data in `user_profiles.profile_photos_json`: Profile-owned media path, alt text, and optional caption.
- Adds a compact Profile editor with current-image previews, Add Photo, Remove, Move up, and Move down controls while keeping a one-photo no-JavaScript fallback.
- Reuses Bonumark image validation, configured media upload limits, privacy cleaning, responsive derivative generation, and the existing full-image viewer.
- Adds a theme-neutral public Photos section after Featured Work. Core supplies stable `.profile-photo-*` markup and fallback layout while themes remain responsible for presentation.
- Extends Profile portability so original Profile photos export under `profile-media/photo-1.*` through `photo-4.*`, with order, alt text, captions, and portable media references preserved in `profile.json` and `profile.md`.
- Deletes Profile photo originals and generated variants when photos are replaced, removed, or the owning account is deleted.
- Adds migration `0017_profile_photos.php` and regression coverage for the four-photo limit, Profile-only storage boundary, public gallery contract, viewer integration, and portability.
- Changes no Stream routes, publishing workflow, post media, account roles, Profile metadata, Featured Work behavior, or theme presentation settings.

## 0.5.86 - Profile Portability Pass

- Adds owner-controlled Profile export from the Profile editor for every signed-in account, without granting Commenters access to Admin system/database exports.
- Generates a structured `profile.json` identity export and a human-readable `profile.md` companion file.
- Includes original local Profile picture and cover-image files under `profile-media/` when available, while omitting generated responsive variants.
- Exports the accepted Profile identity model: handle, display name, headline, location, short bio, About Markdown, Now text, website, visibility, flexible links, interests, Featured Work references, and optional-public-detail preferences.
- Preserves internal Featured Stream posts and Pages as semantic type + slug references rather than copying post/page content into the Profile package.
- Explicitly excludes email, password hashes, roles, activity counts, login/security records, API credentials, post/comment contents, and theme presentation settings from the Profile export contract.
- Adds a CSRF-protected, no-store Profile export endpoint and package-integrity regression coverage.
- Adds no database migration and changes no public Profile rendering, Profile metadata, Stream routes, publishing workflow, account roles, or theme presentation contract.

## 0.5.85 - Profile Identity Metadata Pass

- Keeps the accepted public Profile and editor presentation unchanged while making the Profile a stronger identity endpoint for search engines, link previews, and other web consumers.
- Generates Profile-specific document titles and descriptions from the existing display name, headline, short bio, and About content without adding new Profile fields.
- Normalizes canonical Profile metadata around the clean `/profile/{username}` route, including base-path-safe absolute canonical URLs.
- Adds theme-independent Open Graph and Twitter Profile metadata, including Profile type, site name, username, description, sharing card type, and cover-image sharing with profile-picture fallback.
- Adds JSON-LD `ProfilePage` metadata with a nested `Person` entity using the existing public name, handle, avatar, website/Profile links (`sameAs`), and interests (`knowsAbout`).
- Keeps private Profiles out of structured identity metadata and emits `noindex,nofollow` when a private Profile is viewed by its owner or an administrator. Missing Profiles also receive noindex metadata.
- Improves sitemap Profile handling by removing account email from the sitemap query, using `user_profiles.updated_at` when available for Profile last-modified dates, and recognizing clean `/profile/` URLs in the human-readable sitemap view.
- Adds `article:author` metadata to published Stream post pages when the post already has a public author Profile.
- Adds regression coverage for Profile metadata, structured identity data, social-sharing tags, sitemap privacy/data minimization, and public author Profile references.
- Adds no database migration and changes no visible Profile sections, Featured Work behavior, Stream routes, publishing workflow, account roles, permissions, or theme presentation contract.

## 0.5.84 - Profile Featured Polish and Empty Stream Layout Fix

- Keeps the accepted Profile foundation and v0.5.83 Featured Work behavior unchanged while polishing the bundled Midnight Ledger presentation found during desktop and phone testing.
- Fixes the large vertical gap on an empty or otherwise short public Stream by correcting the public shell itself: the viewport-height flex wrapper now aligns the inner site grid to the top instead of stretching it across the available height.
- Improves one-to-four Featured item layouts in the bundled theme. A lone item, or the final item in an odd three-item set, spans the full desktop row instead of leaving an unintended empty half-column.
- Adds defensive long-text wrapping for Featured titles and descriptions so unusually long words or URLs cannot force card overflow.
- Bumps the bundled Midnight Ledger reference theme to 1.3.1 for the presentation fixes.
- Adds regression coverage for the corrected short-page shell behavior and Featured card layout rules.
- Adds no database migration and changes no Stream routes, publishing workflow, Profile storage, account roles, permissions, or theme contract.

## 0.5.83 - Profile Featured Work Pass

- Adds deliberate Profile curation without creating a second Stream, recent-activity block, or automatic content feed.
- Lets a Profile owner feature up to four published Stream posts, published Pages, or external links in an explicit saved order.
- Stores semantic featured-item data in `user_profiles.featured_items_json`: type, target, optional custom title, and optional short description.
- Resolves internal items only while their referenced content is published. Unpublished or missing internal content is skipped publicly instead of becoming a broken Profile link.
- Adds a compact Featured work editor with Add/Remove controls, published-content slug suggestions, and a no-JavaScript starter row.
- Adds a public Featured section after About. The bundled Midnight Ledger theme styles the semantic featured-item markup while core remains presentation-neutral.
- Adds migration `0016_profile_featured_work.php`, theme-contract documentation, editor JavaScript, responsive default-theme styling, and regression coverage for the new curation boundary.
- Changes no Stream routes, publishing workflow, Profile routing, account roles, post/page storage, or automatic activity behavior.

## 0.5.82 - Profile Foundation Cleanup Pass

- Keeps the accepted v0.5.80-v0.5.81 Profile Identity Foundation architecture unchanged and limits this pass to editor cleanup found during populated desktop and phone testing.
- Stops rendering an automatic blank Profile link row when one or more saved links already exist. Profiles with no links still receive one starter row so the first link can be added without JavaScript.
- Updates Add Link behavior so an untouched empty starter row is focused instead of creating a second blank row.
- Shortens and visually contains the cover-image and profile-picture removal controls so they read as deliberate secondary actions instead of raw form plumbing.
- Tightens the About editor's default height while keeping the full Markdown field and normal vertical resizing available.
- Updates regression coverage for saved-link-only rendering, empty-starter behavior, compact image-removal controls, and Profile editor JavaScript.
- Adds no database migration and changes no Stream routes, publishing behavior, public Profile rendering, Profile data model, account permissions, theme contract, or stored identity data.

## 0.5.81 - Profile Foundation Editor Polish Pass

- Keeps the v0.5.80 Profile Identity Foundation architecture unchanged while tightening the editor based on desktop and phone testing.
- Replaces the eight always-visible Profile link rows with a compact editor that shows existing links plus one blank fallback row, with Add Link and Remove controls up to the existing eight-link limit.
- Moves Profile visibility and optional public activity details out of Identity into a dedicated Profile settings section.
- Polishes cover-image and profile-picture controls with current-image presentation, clearer Change/Remove actions, and theme-consistent file inputs.
- Fixes optional-detail checkbox alignment so each control stays attached to its label on desktop and mobile.
- Adds regression coverage for the compact link editor, Profile settings grouping, and the Profile editor JavaScript behavior.
- Adds no database migration and changes no Stream routes, publishing behavior, public Profile architecture, account permissions, themes contract, or stored identity data.

## 0.5.80 - Profile Identity Foundation Pass

- Rebuilds the public Profile as a first-class identity surface without creating a second Stream, personal feed, or per-profile publishing route.
- Adds a dedicated `user_profiles` data layer for headline, About Markdown, location, Now text, cover image, flexible ordered links, interests, and optional public activity details.
- Preserves existing profile identity by carrying current social-link JSON into the new profile identity record while leaving usernames, display names, short bios, websites, visibility, avatars, account credentials, posts, and comments in their established owners.
- Adds a focused Profile editor at `account.php?section=profile` and keeps login username, email, password, and account activity on the Account surface.
- Adds profile cover images with JPG, PNG, and WebP validation, a 6 MB upload ceiling, responsive derivatives when supported, and explicit 1600 × 600 guidance in the editor.
- Changes the canonical public Profile URL to `/profile/{username}` while retaining numeric-id lookup as a compatibility fallback and removing display-name routing from the public route.
- Removes recent Stream activity from the public Profile. Published post count, approved comment count, and member-since date are now optional secondary details.
- Adds the publishing owner's public Profile as a normal primary-navigation destination when public navigation is enabled, without altering the existing Stream.
- Removes the unused `profile_layouts` declaration from the bundled theme. Core does not define Profile layout or accent selectors; themes remain responsible for presentation.
- Removes email from purpose-built public profile and author queries.
- Adds migration, smoke-test, theme, account, profile, routing, and release metadata coverage for the new identity foundation.

## 0.5.79 - Upgrade Protected Data Layout Hotfix

- Corrects the desktop Upgrade page's What is protected card, where a narrow two-column label and value layout forced values to wrap almost one word per line.
- Stacks each fact label above its value only while the Upgrade workflow uses the narrow desktop operations rail.
- Keeps the accepted tablet and phone layout unchanged and leaves the shared fact-list component available for wider operational panels.
- Adds regression coverage for the Upgrade-specific desktop layout rule.
- Adds no database migration and changes no upgrade behavior, routes, permissions, runtime data, media, uploads, themes, settings, or stored owner data.

## 0.5.78 - Admin UI Contract

- Adds `docs/ADMIN-UI-GUIDELINES.md` as the repository-level contract for future Admin screens, controls, states, and workflow changes.
- Requires new Admin work to start from the newest package, inspect the closest existing workflow, reuse established components, and identify any genuinely new UI component before implementation.
- Documents shared shell use, information hierarchy, responsive records, forms, actions, notices, destructive behavior, accessibility, JavaScript boundaries, CSS ownership, naming, and the desktop, tablet, phone, state, regression, and package acceptance checklist.
- Protects `assets/admin.css` as a legacy and compatibility layer instead of a default destination for new authenticated Admin workflow styling.
- Links the Admin UI contract from `README.md` and `CONTRIBUTING.md` and adds contributor requirements for workflow reference, component ownership, responsive states, and accessibility verification.
- Adds smoke-test coverage requiring the Admin UI contract and its repository references.
- Adds no database migration and changes no application behavior, routes, publishing, permissions, content, media, uploads, themes, settings, or user data.

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

Public GitHub release summaries are kept in the root [`CHANGELOG.md`](../CHANGELOG.md). This file retains the detailed package-by-package development history.

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

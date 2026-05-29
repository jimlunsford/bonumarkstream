# Changelog

## 0.3.12 - SEO Title Output Repair Pass

- Added core SEO title helpers that build browser title output in one place instead of allowing themes and stored SEO fields to double-append the site title.
- Repaired home page title output so the browser tab uses `Site Title | Tagline` when a tagline exists.
- Repaired stream post, page, search, profile, account, and archive title output so the site title is appended exactly once.
- Added core public head normalization that corrects `<title>`, `og:title`, and `twitter:title` output even when an external theme was built against an older template contract.
- Updated generated stream and page SEO title helpers so new generated titles store the primary title only while public rendering appends the site title.
- Added migration `0095_seo_title_output_repair.php` and updated package metadata, documentation, and release manifest.

## 0.3.11 - Migration Release Integrity Cleanup Pass

- Corrected migration `0086_load_more_route_fallback_repair.php` so its historical version setting records `0.3.3`, matching the pass it represents.
- Cleaned up `_bonumark_stream/migrations/README.md` so migrations that only record pass markers no longer claim to update version settings.
- Added migration `0094_migration_release_integrity_cleanup.php` to record this cleanup pass and advance installed sites to version `0.3.11`.
- Updated package metadata, documentation, and release manifest.

## 0.3.10 - Theme-Independent Favicon Output Hotfix

- Repaired Site Identity favicon output so public favicon tags are injected at the core rendering layer instead of depending only on individual theme templates.
- Preserved existing theme-level favicon output while preventing duplicate favicon tags when a theme already prints `favicon_tags`.
- Kept admin favicon output unchanged and made external themes receive favicon tags even when their templates were built before the favicon setting existed.
- Added migration `0093_theme_independent_favicon_output_hotfix.php` and updated package metadata, documentation, and release manifest.

## 0.3.9 - External Theme Upgrade Preservation Hotfix

- Repaired ZIP upgrade cleanup so custom installed themes are preserved when they are not part of the incoming core package.
- Stopped deleting external `microblog-stream` installs just because the slug matches a retired bundled theme.
- Limited retired bundled theme cleanup to themes whose installed `theme.json` clearly identifies them as bundled leftovers, such as `package: bundled-theme`.
- Updated upgrade backup/log language to state that custom installed themes are preserved, including external themes that reuse retired bundled slugs.
- Added migration `0092_external_theme_upgrade_preservation_hotfix.php` and updated package metadata, documentation, and release manifest.

## 0.3.8 - Site Identity Favicon Pass

- Added a favicon control to Admin → Site Identity with Media Library selection, direct image upload, current preview, and remove/reset support.
- Stored favicon media ID and public path settings so selected favicon images survive upgrades and still resolve if the media record is available.
- Output favicon tags in public document heads and admin document heads, including `icon`, `shortcut icon`, and `apple-touch-icon` when the selected image is a suitable square image.
- Validated favicon selections and uploads as active JPG, PNG, GIF, or WebP image media, while allowing non-square images with a guidance notice instead of hard-blocking them.
- Added migration `0091_site_identity_favicon.php` and updated package metadata, documentation, and release manifest.

## 0.3.7 - WordPress Featured Media Import Repair Pass

- Repaired WordPress WXR imports so `_thumbnail_id` featured image references are preserved as `featured_media` instead of being inserted as fake body Markdown images.
- Updated import preview media counting so remote featured images are detected and make **Import media into Media** the default media handling option.
- Allowed WordPress posts with a featured image but no body text to remain importable instead of being skipped as empty.
- Added clearer preview and confirmation warnings for WordPress featured media and remote image download failures, including cURL availability.
- Added migration `0090_wordpress_featured_media_import_repair.php` and updated package metadata, documentation, and release manifest.

## 0.3.6 - Load More Archive Routing Repair Pass

- Repaired Load More routing so stream archive pagination wins over single-post slug handling.
- Added explicit `stream_page` pagination handling for `index.php?__bonumark_route=stream&stream_page={n}` so the button does not depend on a standalone endpoint or a fragile `page` query key.
- Hardened `/stream/page/{n}/`, `/stream/page/`, and stale slug=page rewrite cases so they render the archive instead of the raw stream-post-not-found view.
- Removed the JavaScript redirect-on-AJAX-failure behavior so a failed Load More request no longer dumps the visitor onto an error page.
- Added migration `0089_load_more_archive_routing_repair.php` and updated package metadata, documentation, and release manifest.

## 0.3.5 - Load More Index Route Repair Pass

- Repaired Midnight Ledger Load More pagination by using the existing `index.php?__bonumark_route=stream&page={n}` public route for both AJAX and click fallback behavior.
- Removed the fragile `stream-page.php` endpoint from the release package so Load More no longer depends on a new root PHP file being present or directly servable.
- Kept clean `/stream/page/{n}/` archive routes as canonical public URLs while making the button itself use the safer installed entry point.
- Kept obsolete `stream-page.php` marked as package-managed so upgrades can remove the v0.3.4 endpoint from existing installs.
- Added migration `0088_load_more_index_route_repair.php` and updated package metadata, documentation, and release manifest.

## 0.3.4 - Load More Archive Endpoint Repair Pass

- Repaired Load More fallback behavior by adding a direct `stream-page.php?page={n}` archive endpoint for paginated stream output.
- Updated Load More pagination so both the clickable fallback and AJAX request use the direct archive endpoint instead of a clean route that some installs can misread as a stream post slug.
- Added defensive stream route handling so `/stream/page/{n}/` cannot fall through to the single-post not-found view if a server misroutes the archive path.
- Added migration `0087_load_more_archive_endpoint_repair.php` and updated package metadata, documentation, and release manifest.

## 0.3.3 - Load More Route Fallback Repair Pass

- Repaired Midnight Ledger Load More pagination by giving the button a rewrite-independent AJAX URL while preserving the clean public `/stream/page/{n}/` link as the normal fallback.
- Updated the Load More script so it follows the clean pagination URL if AJAX loading fails instead of leaving the visitor stuck on a dead button.
- Added migration `0086_load_more_route_fallback_repair.php` and updated package metadata, documentation, and release manifest.

## 0.3.2 - Mobile Public Page Containment Repair Pass

- Repaired mobile public page viewport containment so long page headings, masthead text, and rich page content cannot force horizontal scrolling on phones.
- Added stronger width containment for the public shell, header, page cards, page headers, and page content in the bundled Midnight Ledger theme.
- Kept tables and code blocks locally scrollable without making the whole page swipe sideways.
- Added migration `0085_mobile_public_page_containment_repair.php` and updated package metadata, documentation, and release manifest.

## 0.3.1 - Public Rendering Regression Repair Pass

- Repaired public page Markdown presentation so tables, blockquotes, links, lists, and code blocks render with bundled theme styling that matches the editor preview more closely.
- Wrapped rendered Markdown tables in a responsive table container so structured page content does not break narrow screens.
- Repaired desktop link previews without images by adding the expected `no-image` class and preventing an empty reserved media column.
- Added migration `0084_public_rendering_regression_repair.php` and updated package metadata, documentation, and release manifest.

## 0.3.0 - Public GitHub Release Baseline

- Promoted the stable v0.2.58 release candidate to the v0.3.0 public GitHub release baseline.
- Updated root and private version files, default config, config sample, package metadata, README, install docs, upgrade docs, architecture docs, migration notes, and release manifest to the new version.
- Added migration `0083_public_github_release_baseline.php` so upgraded installs record the v0.3.0 baseline in stored settings.
- Preserved install behavior, upgrade behavior, themes, navigation, account flows, comments, media handling, sitemap routes, and user-owned data paths.

## 0.2.58 - Public Navigation Account Links Toggle Pass

- Added a separate **Show automatic account links** setting to Admin → Navigation.
- Kept automatic account links enabled by default so v0.2.57 installs keep their access path unless the admin turns it off.
- Updated public navigation building so sign-in, registration, dashboard/account, profile, and sign-out links are appended only when the new setting is enabled.
- Preserved the new setting across add-page and add-custom-link navigation actions.
- Added migration `0082_public_navigation_account_links_toggle.php` and updated package metadata, documentation, and release manifest.

## 0.2.57 - Public Navigation Account Links Pass

- Added account-aware default links to the public navigation menu while keeping the header design unchanged.
- Logged-out visitors now get **Sign in** and, when registration is enabled, **Create account** inside the public menu.
- Signed-in users now get **Dashboard** when they can view admin, otherwise **Account**, plus **Profile** and **Sign out**.
- Added duplicate URL protection so manually added account routes are not repeated in the rendered public menu.
- Added a CSRF-protected sign-out link path for the public navigation menu.
- Added migration `0081_public_navigation_account_links.php` and updated package metadata, documentation, and release manifest.

## 0.2.56 - Account Registration Kicker Cleanup Pass

- Removed the visible **Public registration** kicker above the public **Create an account** form.
- Kept the account registration form, registration status messaging, mail warning, and sign-in flow unchanged.
- Added migration `0080_account_registration_kicker_cleanup.php` and updated package metadata, documentation, and release manifest.

## 0.2.55 - Comment Account Link Cleanup Pass

- Changed the public comment prompt from an action pill to normal inline link text.
- Made the comment prompt say **Sign in to comment** when public comment-account creation is not available.
- Linked the create-account prompt directly to the registration section when public comment-account registration is available.
- Removed the visible **Account** kicker above the public Sign in heading.
- Added migration `0079_comment_account_link_cleanup.php` and updated package metadata, documentation, and release manifest.

## 0.2.54 - User Edit Action Alignment Pass

- Aligned the **Save user** and **Cancel** controls on the Edit User account-details form so they sit on the same baseline.
- Added a dedicated `user-edit-actions` admin form-action row style and removed the inherited top-margin offset from the primary button in that row.
- Added migration `0078_user_edit_action_alignment.php` and updated package metadata, documentation, and release manifest.

## 0.2.53 - User Management Actions Pass

- Added a dedicated admin user management screen for editing account details, role, status, profile visibility, and email verification state.
- Added admin password reset for any managed user while invalidating outstanding password reset tokens where available.
- Added guarded account deletion that blocks self-deletion, protects the last active administrator, and reassigns owned posts, comments, media, trash ownership, and related records to another active account.
- Added a Manage action from the Users screen so admin tasks are no longer limited to role/status quick updates.
- Kept shared-hosting behavior, install behavior, upgrade behavior, public output, and existing user data paths intact.
- Added migration `0077_user_management_actions.php` and updated package metadata, documentation, and release manifest.

## 0.2.52 - Admin Date-Time Input Style Repair Pass

- Repaired browser-native date and date-time fields in the admin so invite expiration and similar controls stay inside the Bonumark dark form style.
- Added `input[type="datetime-local"]`, `input[type="time"]`, and `input[type="month"]` coverage where appropriate, including focus and calendar picker styling.
- Kept shared-hosting behavior, install behavior, upgrade behavior, public output, and data handling unchanged.
- Added migration `0076_admin_datetime_input_style_repair.php` and updated package metadata, documentation, and release manifest.

## 0.2.51 - Upgrade Screen Simplification Pass

- Simplified the admin Upgrade screen so the default view focuses on installed version, package upload, package status, and the run/cancel action.
- Moved protected paths, updated software details, package validation details, pending migration filenames, and the longer upgrade rule into collapsible details sections.
- Shortened the ready-to-upgrade language so admins can quickly see the current version, uploaded version, backup status, migration count, and public output status.
- Kept upgrade validation, backups, rollback behavior, migrations, install behavior, public output, and data handling unchanged.
- Added migration `0075_upgrade_screen_simplification.php` and updated package metadata, documentation, and release manifest.

## 0.2.50 - Admin Autofill Input Color Repair Pass

- Repaired browser autofill styling in the admin so saved usernames, emails, passwords, and other autofilled fields stay in Bonumark's dark form style instead of turning white.
- Added WebKit autofill overrides for Chrome, Edge, and Android browser behavior while preserving caret color, text color, borders, and focus treatment.
- Kept shared-hosting behavior, install behavior, upgrade behavior, public output, and data handling unchanged.
- Added migration `0074_admin_autofill_input_color_repair.php` and updated package metadata, documentation, and release manifest.

## 0.2.49 - Upgrade Action Button Alignment Pass

- Aligned the Run Upgrade and Cancel buttons in the admin upgrader when a release package is ready to run.
- Added a narrow upgrade confirmation action style so primary and secondary buttons share the same baseline, height, and vertical centering.
- Kept install behavior, upgrade behavior, migrations, package validation, public output, and data handling unchanged.
- Added migration `0073_upgrade_action_button_alignment.php` and updated package metadata, documentation, and release manifest.

## 0.2.48 - Admin Form Input Style Repair Pass

- Repaired admin form fields that could fall back to browser-default styling when an input omitted an explicit `type` attribute.
- Added `input:not([type])` and `input[type="tel"]` to the Bonumark admin field style rules so future plain text fields inherit the dark admin form treatment.
- Normalized the Users screen Add Account form by giving username and display name fields explicit `type="text"` attributes.
- Kept shared-hosting behavior, install behavior, upgrade behavior, public output, and data handling unchanged.
- Added migration `0072_admin_form_input_style_repair.php` and updated package metadata, documentation, and release manifest.

## 0.2.47 - Mobile Text Overflow Repair Pass

- Repaired mobile text containment in the bundled Midnight Ledger public theme.
- Added hard width containment to stream cards, card interiors, card body content, page cards, and page body content so text cannot paint past the right edge on small screens.
- Normalized non-breaking spaces during Markdown rendering so rich-editor or pasted content can wrap naturally on mobile.
- Added explicit mobile wrapping rules for paragraphs, headings, lists, blockquotes, tables, and link preview text.
- Kept preformatted blocks and code blocks horizontally scrollable without forcing the entire page to overflow.
- Reduced narrow-mobile stream card padding and avatar sizing slightly to protect readable text width without changing the desktop layout.
- Added migration `0071_mobile_text_overflow_repair.php` and updated package metadata, documentation, and release manifest.

## 0.2.46 - Public Release Audit Repair Pass

- Hardened failed-upgrade rollback so newly copied package files that did not exist before the attempt are removed after backup restore.
- Removed stale `.github` handling from package-managed upgrade paths and de-duplicated the software item list before copying.
- Tightened safe remote fetch behavior for link previews and imported media by requiring cURL for those optional remote-fetch features, pinning resolved public addresses when supported, and rejecting unsafe connected IPs.
- Normalized public route exception output so internal database, path, config, and stack details are not shown to visitors.
- Removed the stream single-post legacy Markdown route fallback from public routing.
- Changed the bundled header status chip default from a theme-branded label to `Live microblog`.
- Added `scripts/database-smoke-test.php` for disposable MySQL/MariaDB migration validation before public release tags.
- Clarified private directory protection, trusted PHP theme boundaries, remote fetch boundaries, and release validation requirements in documentation.
- Added migration `0070_public_release_audit_repair.php` to normalize the bundled theme status label on upgraded installs.
- Updated version markers, package metadata, documentation, and release manifest.

## 0.2.45 - Admin Dashboard Column Flow Polish Pass

- Corrected the admin dashboard body so cards stack in independent left and right columns instead of being locked into row heights.
- Removed the large dead spaces that appeared between dashboard cards when one card in a row was taller than the card beside it.
- Kept Quick Actions and Needs Attention in the left workflow column.
- Kept Recent Stream Posts, System Status, and Admin Notes in the right status/activity column.
- Preserved dashboard counts, links, permissions, data logic, public output, install behavior, and upgrade behavior.
- Added migration `0069_admin_dashboard_column_flow_polish.php` and updated package metadata, documentation, and release manifest.

## 0.2.44 - Admin Dashboard Layout Polish Pass

- Reworked the admin dashboard overview and activity rows onto a consistent 12-column grid.
- Removed the narrow/wide bottom-row split so Recent Pages and Admin Notes align with the dashboard cards above.
- Stopped dashboard cards from stretching to match taller neighbors, reducing awkward empty vertical space in Quick Actions, System Status, and Admin Notes.
- Gave Admin Notes the same header rhythm and helper text treatment as the other dashboard panels.
- Preserved dashboard data logic, permissions, public output, install behavior, and upgrade behavior.
- Added migration `0068_admin_dashboard_layout_polish.php` and updated package metadata, documentation, and release manifest.

## 0.2.43 - Admin Dashboard Order Polish Pass

- Reordered the admin dashboard body so Quick Actions appears as the first main card after the site snapshot.
- Moved Recent Stream Posts into the second main card so publishing activity stays immediately visible.
- Kept Needs Attention, System Status, Recent Pages, and Admin Notes below the primary action/activity row.
- Preserved dashboard counts, permissions, existing queries, shared-hosting behavior, and public output.
- Added migration `0067_admin_dashboard_order_polish.php` and updated package metadata, documentation, and release manifest.

## 0.2.42 - Admin Dashboard Overview Pass

- Rebuilt the admin dashboard into a stronger overview surface instead of a sparse starter screen.
- Added site snapshot metrics for published posts, drafts, pages, media, comments, and users when the current user has access.
- Added a Needs Attention panel for pending review, pending comments, pending users, trash, media trash, and sitemap status.
- Added a System Status panel for version, active theme, sitemap, robots.txt, mail transport, registration, publishing mode, and noindex count.
- Added recent Stream Post and Page activity cards plus a fuller Quick Actions panel.
- Added responsive dashboard styling for desktop and mobile admin use.
- Added migration `0066_admin_dashboard_overview.php` and updated package metadata, documentation, and release manifest.

## 0.2.41 - Migration Placeholder Hotfix
- Fixed the v0.2.40 sitemap presentation migration so it uses the configured settings table prefix instead of an unsupported `{{settings}}` placeholder.
- Removed the invalid `created_at` column reference from the settings migration statements.
- Added a migration execution guard that stops unreplaced placeholders before they reach MariaDB.
- Preserved the styled sitemap presentation work from v0.2.40.

## 0.2.40 - Sitemap Presentation Polish Pass
- Reworked the `/sitemap.xml` browser presentation so it uses a dedicated external Bonumark stylesheet instead of inline XSL styling.
- Added `assets/sitemap.css` for a cleaner dark presentation, readable table layout, responsive mobile cards, URL type badges, and improved spacing.
- Simplified the human sitemap table to focus on Type, URL, and Last Modified while keeping valid sitemap XML fields intact for crawlers.
- Kept `/sitemap.xsl`, `/sitemap.xml`, `/robots.txt`, static export support, and crawler-facing sitemap behavior intact.
- Added migration `0064_sitemap_presentation_polish.php` to record the pass and update version settings.

## 0.2.39 - Styled XML Sitemap Pass
- Added a human-readable XSL stylesheet for `/sitemap.xml` so browser views show a clean URL table instead of raw XML.
- Added dynamic `/sitemap.xsl` routing for the sitemap stylesheet.
- Added the sitemap stylesheet processing instruction to XML sitemap output.
- Improved `/robots.txt` formatting by separating crawl directives from the `Sitemap:` reference with a blank line.
- Added `sitemap.xsl` to Static Site Export output.
- Added migration `0063_styled_xml_sitemap.php` to record the pass and update version settings.

## 0.2.38 - XML Sitemap Pass
- Added dynamic `/sitemap.xml` routing for XML sitemap output.
- Added dynamic `/robots.txt` routing with a sitemap reference when sitemap support is enabled.
- Added Reading Settings controls for sitemap enablement, published stream posts, published pages, and public profile URLs.
- Excluded search, account, admin, draft, trash, pending, and noindex content from sitemap output.
- Added sitemap and robots.txt generation to optional static site exports.
- Added migration `0062_xml_sitemap.php` and install defaults for sitemap settings.

## 0.2.37 - Public Release Polish and Upgrade Cleanup Pass
- Cleaned stale public-release documentation so README, architecture, install, upgrade, and migration notes point to the current v0.2.37 baseline.
- Fixed the README Current release section so the latest release appears first.
- Removed a duplicate stream-like screen-reader selector from `assets/stream.js`.
- Added targeted upgrade cleanup for the retired bundled `microblog-stream` theme directories while preserving custom installed themes.
- Added migration `0061_public_release_polish_upgrade_cleanup.php` to record the pass.

## 0.2.36 - Public Release Cleanup and Theme Separation Pass
- Removed the optional Microblog Stream theme from the shared-hosting release package now that it has been exported as a separate installable theme ZIP.
- Kept Midnight Ledger as the bundled first-party public theme and updated documentation to describe optional themes as installable packages.
- Hardened remote media import URL validation to check A and AAAA records, block local/private/reserved hosts, and restrict remote import fetches to HTTP and HTTPS ports 80 and 443.
- Fixed public stream edit-link visibility so links only render for users who can edit the specific stream post.
- Fixed public Page edit-link visibility so links only render for users with Page management permission.
- Updated the Theme Install example to include all required templates, including page and link-preview.
- Removed stale Microblog-era public class/id names from the default theme and stream JavaScript instead of keeping compatibility aliases.
- Removed repository-only `.github` files from the shared-hosting release package so upgrades clean them from hosting installs.
- Added migration `0060_public_release_cleanup.php` to move installs off the removed bundled theme and record the version.

## 0.2.35 - Media Edit Button Alignment Hotfix
- Restored Media Edit action buttons to a consistent visual style after the previous polish pass made the copy buttons look different.
- Removed the inherited top margin that pushed Save Media lower than Copy Markdown and Copy URL.
- Kept optimized image variant pill centering intact.
- Kept this pass limited to admin CSS and version/release metadata.

## 0.2.34 - Media Edit Alignment Polish Pass
- Centered status pill text for optimized image variant summaries.
- Tightened the optimized image variant summary pill so wrapped text sits correctly.
- Aligned the Save Media button with Copy Markdown and Copy URL on the Media Edit screen.
- Kept this pass limited to admin CSS and Media Edit markup.

## 0.2.33 - Image Variants Admin Polish Pass

- Renames the Media Edit image diagnostics card to Optimized image variants.
- Rewords the card so variants feel like a normal media-management feature instead of a temporary debug panel.
- Moves GD, Imagick, generated-folder, and memory-limit checks behind a troubleshooting details section.
- Renames the one-item regenerate action to Refresh variants and clarifies that the original file is preserved.
- Polishes the existing Optimize Images screen wording while keeping its batched behavior unchanged.
- Does not change public image rendering, image URLs, responsive `srcset`, image generation, upload handling, compression, or format conversion.
- Adds migration `0057_image_variants_admin_polish.php` to record the pass.

## 0.2.32 - LCP Image Priority Correction Pass

- Corrects stream image priority so the first actual image in the rendered stream card DOM receives `loading="eager"` and `fetchpriority="high"`.
- Prevents later card images from stealing high priority when an earlier visible image exists.
- Moves priority assignment to the rendered card list and single-card output instead of guessing from card index and attachment/body-image state.
- Keeps all later images lazy.
- Does not change image URLs, `srcset`, `sizes`, image derivatives, compression, or format handling.
- Adds migration `0056_lcp_image_priority_correction.php` to record the pass.
## 0.2.31 - Stream Card Avatar Variant Correction Pass

- Forces compact stream-card avatar output to request the 96w avatar variant without falling forward to 192w when the 96w file can be generated.
- Adds a safe exact-avatar variant helper so missing 96w avatar files can be generated from the original avatar when GD or Imagick support is available.
- Keeps 192w avatar output for larger profile and account header displays.
- Applies the same compact 96w behavior to comments and the admin header avatar.
- Does not change stream post media, responsive image output, generated post image variants, or image compression behavior.
- Adds migration `0055_stream_card_avatar_variant_correction.php` to record the pass.
## 0.2.30 - Avatar Size Selection Cleanup Pass

- Uses the 96w generated avatar variant for compact public avatar contexts such as stream cards, comments, and small admin/header avatar displays.
- Keeps larger profile/account header avatar displays on the 192w generated avatar variant.
- Preserves original avatar files and existing avatar fallback behavior.
- Does not change stream post media, responsive image output, or generated post image variants.
- Adds migration `0054_avatar_size_selection_cleanup.php` to record the pass.

## 0.2.29 - Existing Media Regeneration Tool Pass

- Adds a manual Existing Media Regeneration screen for safely regenerating optimized image variants on old media.
- Processes existing images in small admin-triggered batches instead of attempting a full-library rebuild in one request.
- Shows progress, processed/skipped/failed counts, and the next batch action so the workflow stays shared-hosting safe.
- Keeps public image rendering rules unchanged: responsive output only uses variants that already exist and are verified.
- Adds migration `0053_existing_media_regeneration_tool.php` to record the regeneration tool pass.

## 0.2.28 - Responsive Image Output Pass

- Adds responsive image `srcset` and `sizes` output only when Bonumark has recorded derivative metadata and confirms the generated files exist on disk.
- Keeps the original image URL as the fallback `src` for public media rendering.
- Does not generate image variants during public rendering, invent derivative URLs, convert image formats, or batch-regenerate old media.
- Leaves images without verified variants on the existing single-src output path.
- Adds migration `0052_responsive_image_output.php` to record the responsive image output pass.

## 0.2.27 - Image Derivative Diagnostics Pass

- Adds image optimization diagnostics to Media Edit so administrators can see GD/Imagick availability, generated-folder writability, memory limits, and per-variant status.
- Adds a per-media "Regenerate variants" action for one image at a time.
- Shows clear reasons when variants are missing, including unsupported image type, missing resize engine, unwritable generated folder, small source image, or missing original file.
- Keeps public image rendering on original URLs only, with no srcset, no derivative public output, and no format conversion.
- Adds migration `0051_image_derivative_diagnostics.php` to record the diagnostics pass.

## 0.2.26 - Upload Image Derivatives Pass

- Generates large, medium, and small image derivatives for new direct media uploads when GD or Imagick support is available.
- Stores verified derivative metadata in the media record without changing public stream image output or adding srcset.
- Leaves imported media derivative generation off by default so imports remain display-first unless a later tool explicitly regenerates variants.
- Keeps original image files intact and falls back to originals when variants are missing.
- Adds migration `0050_upload_image_derivatives.php` to add `image_variants_json` and record the pass.

## 0.2.25 - Avatar Optimization Pass

- Generates small avatar variants on new avatar upload using shared-hosting-safe GD or Imagick support when available.
- Public avatar markup now prefers an optimized avatar variant when one exists and falls back to the original avatar when it does not.
- Keeps original avatar files intact and does not change normal stream media output, add general srcset, or convert image formats.
- Adds migration `0049_avatar_optimization.php` to record the pass.

## 0.2.24 - Media Metadata and LCP Priority Pass

- Added safe local-image dimension output to public image attributes when Bonumark can verify the file and dimensions.
- Kept display-first media rendering intact by avoiding srcset, generated variants, format conversion, or derivative URLs.
- Boosted the first visible Markdown body image on a stream card or single post with eager loading and high fetch priority.
- Preserved first visible featured-media priority while avoiding duplicate high-priority image hints when the post body already has the first visible image.
- Refreshed upload metadata from the stored file after upload so file size, hash, image width, and image height stay accurate.
- Added migration marker for the media metadata and LCP priority pass.


## 0.2.23 - Admin Menu Export Cleanup Pass

- Removed the redundant Static Site Export item from the admin sidebar Tools menu.
- Removed the standalone Static Site Export admin route from the package so static export is managed from the Export screen.
- Kept Static Site Export available as an export type under Tools > Export.
- Updated Tools, Help, README, architecture, install, and upgrading language so export ownership is clear.
- Added migration `0047_admin_menu_export_cleanup.php` to align stored version metadata.

## 0.2.22 - Footer Link and Navigation Cleanup Pass

- Removed public navigation item output from bundled public footers.
- Added safe link support for custom Site Identity footer text.
- Kept footer text sanitized so unsafe protocols and unsupported markup are stripped before public rendering.
- Preserved the linked `Published with Bonumark Stream.` footer credit.
- Added migration `0046_footer_link_navigation_cleanup.php` to align stored version metadata.

## 0.2.21 - Site Identity Link and Footer Credit Pass

- Added safe public link support for the Site Identity tagline field.
- Allowed trusted anchor attributes for tagline links while stripping unsafe markup.
- Rendered tagline links in both bundled public theme headers.
- Kept plain-text meta descriptions and feed descriptions by stripping tagline markup outside public display.
- Turned the "Published with Bonumark Stream." footer credit on by default.
- Linked the Bonumark Stream footer credit to https://bonumark.org.

## 0.2.20 - Public Profile Header Cleanup Pass

- Removed the visible `Public Profile` kicker from bundled public profile headers.
- Tightened profile header spacing so the public username sits naturally below the display name.
- Updated both bundled profile templates and theme CSS without changing profile data, social links, stats, activity, or routing behavior.
- Added migration `0044_public_profile_header_cleanup.php` to align stored version metadata.

## 0.2.19 - Profile Links Form Polish Pass

- Polished Profile links fields in the admin account editor so URL fields match the dark Bonumark Stream admin form controls.
- Reworked profile link fields into a cleaner grid with grouped custom link cards.
- Updated public account profile editors in both bundled themes to use the same cleaner profile link field structure.
- Added admin styling coverage for `url`, `email`, `number`, and `search` inputs so future typed fields do not fall back to browser defaults.
- Added migration `0043_profile_links_form_polish.php` to align stored version metadata.

## 0.2.18 - Public Profile Social Links Pass

- Added optional social/profile links to the user profile editor and public account profile editor.
- Added X, Bluesky, GitHub, Instagram, YouTube, LinkedIn, Facebook, TikTok, Mastodon, and two custom profile links.
- Stores profile social links as JSON on the user record so future platforms do not require a new column.
- Displays filled profile links as public profile pills beside the Website pill in both bundled themes.
- Validates profile link URLs and only permits `http://` and `https://` links.
- Added migration `0042_public_profile_social_links.php` to add user social link storage and align stored version metadata.

## 0.2.17 - Profile Layout Upgrade Hotfix

- Fixed the `0040_public_profile_layout_cleanup.php` migration so it uses the standard `{{prefix}}` table placeholder instead of the invalid literal `{prefix}` table name.
- Preserves the v0.2.16 public profile layout cleanup while repairing the upgrade path from v0.2.15 and earlier v0.2.x installs.
- Added migration `0041_profile_layout_upgrade_hotfix.php` to align stored version and fresh-install baseline with 0.2.17.

## 0.2.16 - Public Profile Layout Cleanup Pass

- Removed public account role output from public profile stat cards.
- Removed the duplicate About panel from public profile pages because the bio already appears in the profile header.
- Made the Activity / Stream posts panel use the full profile width in both bundled themes.
- Updated profile CSS so the stat grid is sized for Published posts, Comments, and Joined.
- Stopped passing internal `role_label` data to public profile templates.
- Added migration `0040_public_profile_layout_cleanup.php` to align stored version and fresh-install baseline with 0.2.16.
- Updated version, README, docs, package metadata, config sample, bundled theme templates, bundled theme CSS, and release manifest.

## 0.2.15 - Admin Clarity Polish Pass

- Added a clear Pending Review intro block to the Review Queue view under Stream Posts.
- Clarified that submitted stream posts appear there when users can write but cannot publish directly.
- Kept review queue workflow unchanged and limited this pass to admin-facing clarity.
- Added migration `0039_admin_clarity_polish.php` to align stored version and fresh-install baseline with 0.2.15.
- Updated version, README, docs, package metadata, config sample, and release manifest.

## 0.2.14 - SEO Slug Generation Cleanup Pass

- Changed generated stream slugs to prefer a manual title when supplied.
- Added Markdown H1 detection so posts beginning with `# Heading` generate clean heading-based slugs.
- Changed fallback slug generation to use the first meaningful sentence only when no title or H1 exists.
- Removed forced date suffixes from generated slugs. Duplicate slugs now use numeric suffixes such as `-2` and `-3`.
- Tightened Markdown cleanup so formatting syntax, images, fenced code blocks, links, and inline code do not pollute generated URLs.
- Capped generated slug length while preserving readable word boundaries.
- Clarified backend editor slug help text so users understand generated slugs become public URLs while Markdown filenames remain internal.
- Added migration `0038_seo_slug_generation_cleanup.php` to align stored version and fresh-install baseline with 0.2.14.
- Updated version, README, docs, package metadata, config sample, and release manifest.

## 0.2.13 - Navigation Manager Interface Polish Pass

- Reworked Admin → Navigation menu items into cleaner stacked cards.
- Put each item title and URL preview together at the top of the card.
- Moved item controls into a consistent action row.
- Made Move up and Move down lighter secondary controls.
- Made Remove less visually dominant while keeping it clear.
- Stacked Label, URL, and Open fields vertically to remove the scattered two-column layout.
- Improved spacing between menu item cards.
- Improved mobile layout for menu item controls and fields.
- Kept the v0.2.12 navigation architecture intact.
- Added migration `0037_navigation_manager_interface_polish.php` to align stored version and fresh-install baseline with 0.2.13.
- Updated version, README, docs, package metadata, admin navigation markup, admin CSS, and release manifest.

## 0.2.12 - Navigation Manager Rebuild Pass

- Made public navigation optional through Admin → Navigation.
- Changed fresh installs so Home is the only default menu item and public navigation is off by default.
- Removed page-level menu controls from the Page editor.
- Stopped page create, publish, edit, unpublish, delete, and restore actions from automatically changing navigation.
- Rebuilt Admin → Navigation as the single menu manager with a display toggle, current item editor, published-page add flow, custom-link add flow, remove controls, and Move up / Move down ordering.
- Removed the visible 8-row menu UI and the old 12-item save cap, using a 100-item request safety cap instead.
- Added one-time legacy page-navigation migration support for older page metadata when the Navigation screen is opened.
- Removed forced RSS/default footer-navigation injection so links appear only when public navigation is enabled and configured.
- Updated bundled theme footers so disabled navigation does not leave a stray footer separator.
- Added migration `0036_navigation_manager_rebuild.php` to align stored version and fresh-install baseline with 0.2.12.
- Updated version, README, docs, package metadata, installer seed data, navigation defaults, admin CSS, and release manifest.

## 0.2.11 - Page Title Spacing and Updated Date Default Pass

- Tightened public Page title spacing in the default Midnight Ledger theme.
- Tightened public Page title spacing in the Microblog Stream theme.
- Removed the leftover top-title gap created by the old visible Page kicker layout.
- Changed bundled Page updated-date theme setting defaults from on to off.
- Updated Page templates so missing theme settings fall back to hiding the visible updated date.
- Added migration `0035_page_title_spacing_updated_date_default.php` to align stored version and fresh-install baseline with 0.2.11.
- Updated version, README, docs, package metadata, bundled theme manifests, and release manifest.

## 0.2.10 - Midnight Ledger Avatar Alignment Pass

- Fixed Midnight Ledger fallback avatar initials so generated user initials are centered inside avatar circles.
- Added explicit fallback avatar image and initials styling for stream cards, profile/account views, account avatar previews, and comments.
- Added migration `0034_midnight_ledger_avatar_alignment.php` to align stored version and fresh-install baseline with 0.2.10.
- Updated version, README, docs, package metadata, issue template placeholder, and release manifest.

## 0.2.9 - Public Release Audit Repair

- Fixed installable theme handling so every declared template in `theme.json` is copied, not only the required template subset.
- Made `page.php` and `link-preview.php` official required public theme templates to prevent mixed-theme output.
- Added upgrade cleanup for obsolete package-managed files while preserving config, database data, uploads, backups, runtime data, and custom installed themes.
- Added upgrade history recording for successful upgrades even when no migrations run.
- Added `.gitignore` to the admin upgrade software copy list.
- Replaced public like endpoint exception details with a generic public error and private server logging.
- Updated stale editor wording from old static-generation language to database-first URL update language.
- Documented the internal `mp_` PHP function prefix as a private Bonumark Stream namespace.
- Updated version, docs, bundled theme manifests, package metadata, and release manifest.

## 0.2.8 - Public Release Audit Cleanup

- Fixed public-release audit findings without preserving old static/Markdown compatibility paths.
- Aligned package, README, changelog, docs, and issue-template release metadata.
- Added complete app-table database export coverage and private-backup warnings for database/full export ZIPs.
- Added stream-like attempt rate limiting to reduce public endpoint abuse.
- Repaired content-type-aware uniqueness by migrating posts from `slug_status` to `post_type_slug_status`.
- Converted clean author routes into real author/profile rendering instead of a redirect-only alias.
- Fixed theme activation error helper behavior when no errors are present.
- Removed stale regeneration variables and old v0.1.x pass comments from public-release CSS.
- Added migration `0032_public_release_audit_cleanup.php`.

## 0.2.7 - Clean Author Route Repair

- Re-scanned v0.2.6 before installation and found the documented clean `/author/{username}/` route was missing from `.htaccess`.
- Added Apache rewrite rules for `author.php`, `/author/`, and `/author/{username}/` so author/profile redirects resolve through the dynamic route dispatcher.
- Updated fresh-install baseline settings, package metadata, docs, and release manifest.


## 0.2.6 - Preinstall Deep Audit Repair

- Re-scanned v0.2.5 before installation with stricter metadata, security, old-helper, route, and architecture-residue checks.
- Corrected stale package metadata fields that still described the older Static Export Isolation Repair.
- Updated Security Policy supported-version table for the v0.2.x fresh-install baseline.
- Reworded security-sensitive area language so static site export is described as an export artifact, not a live public-output layer.
- Removed unused legacy helpers that pointed to live public `index.html` paths or archived Markdown revision files.
- Added migration `0030_preinstall_deep_audit_repair.php` to align stored package baseline settings.

## 0.2.5 - Preinstall Version Alignment Repair

- Re-scanned v0.2.4 before installation and found stale v0.2.3 defaults in the default config, fresh-install migrations, migration README, README, and issue template.
- Updated default config and fresh-install baseline settings to 0.2.5.
- Added migration `0029_preinstall_version_alignment_repair.php` so the stored version and fresh-install baseline match this package.
- Rebuilt the release manifest after the deeper pre-install audit.

## 0.2.4 - Dynamic Feed Route Repair

- Re-scanned the v0.2.3 fresh-install package before installation and found that RSS feed links still pointed at generated `feed.xml` files after live static output was removed.
- Added dynamic RSS feed route handling for `/feed.xml` and `/stream/feed.xml`.
- Updated `.htaccess` rewrite rules so feed URLs route to dynamic database-rendered RSS output.
- Added `0028_dynamic_feed_route_repair.php`.
- Updated README, docs, package metadata, version files, and release manifest.

## 0.2.3 - Static Export Isolation Repair

- Re-scanned the v0.2.2 fresh-install package before installation and found that Static Site Export still wrote generated HTML into the live public root.
- Changed Static Site Export generation to write into temporary private export folders instead of the live public root.
- Updated Tools > Static Site Export to download a ZIP artifact directly.
- Updated full/static exports so they package generated HTML from temporary export folders instead of active public output paths.
- Added static export path helpers for isolated export generation.
- Updated fresh-install baseline settings to v0.2.3.
- Added `0027_static_export_isolation_repair.php`.
- Re-ran syntax, manifest, migration, route, architecture-language, and database-first flow scans.

## 0.2.2 - Fresh Install Preflight Repair

- Re-scanned every package file before live installation and found stale fresh-install seed settings.
- Fixed the browser installer seed so fresh installs record the current package version and baseline.
- Aligned installer defaults with the database seed helper, including admin email, homepage, navigation, mail, and database storage settings.
- Updated the database seed helper to use `mp_version()` instead of hard-coded v0.2.0 settings.
- Added `0026_fresh_install_preflight_repair.php` to correct version, baseline, and database storage settings during schema setup.
- Fixed Static Site Export ZIP creation so export downloads generate current static output and include pages, assets, and media.
- Removed database metadata sync calls from static export generation so export actions do not mutate live content records.
- Removed normal admin workflow cleanup calls that still touched Static Site Export artifacts.
- Removed retired static variant deletion helpers and made Static Site Export clean its own page output before export.
- Removed a duplicate page SEO helper left over from the architecture transition.
- Re-ran the full package audit, manifest verification, syntax checks, migration scan, and database-first flow scan.

## 0.2.1 - Preinstall Audit Repair

- Re-scanned the v0.2.0 fresh-install baseline before live installation.
- Fixed Bonumark Stream export ZIP restore support so the importer recognizes the v0.2.x database-first Markdown export layout under `markdown/posts` and `markdown/pages`.
- Added import item content-type support so Bonumark export restores can preserve stream posts and pages instead of treating everything as a stream post.
- Updated stale importer and theme documentation language that still described old Markdown-front-matter storage paths.
- Re-ran the full package audit, manifest verification, syntax checks, and database-first flow scan.

## 0.2.0 - Fresh Install Foundation Reset

- Declared v0.2.0 as the clean fresh-install foundation baseline after the database-first architecture pivot.
- Documented that v0.2.0 should be installed fresh instead of used as an upgrade bridge from the old v0.1.x development line.
- Removed old upgrade cleanup hooks for retired static-output compatibility route files.
- Kept the live site database-rendered by default.
- Kept Markdown as export output and legacy import/fallback only.
- Kept Static Site Export as a Tools-only generated HTML artifact.
- Trimmed the migration directory by removing empty and obsolete v0.1.x marker migrations not needed for clean installs.
- Added `0025_fresh_install_foundation_reset.php` to record the fresh-install baseline settings.
- Updated README, architecture docs, upgrade docs, package metadata, version files, and release manifest.

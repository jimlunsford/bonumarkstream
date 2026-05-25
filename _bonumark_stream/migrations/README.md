# Migrations

Bonumark Stream v0.3.2 uses a database-first schema with dynamic public rendering, Markdown export/fallback, and Static Site Export as an optional artifact.

The migration set includes the clean install schema plus v0.2.x hardening migrations so installs from the current v0.2.x development baseline can continue forward. Do not use this package as an upgrade bridge from the old v0.1.x development line.

Migrations must return a numeric array of SQL statement strings. Do not insert migration markers inside migration files. The migration runner records completed migrations and treats duplicate-column, duplicate-key, already-exists, and missing-index cleanup errors as idempotent when replaying or recovering from partial migration attempts.

## Recent v0.2.x release markers

- `0042_public_profile_social_links.php` adds optional user profile social link storage and aligns v0.2.18 metadata.
- `0043_profile_links_form_polish.php` aligns v0.2.19 metadata after the profile links form polish pass.
- `0044_public_profile_header_cleanup.php` aligns v0.2.20 metadata after the public profile header cleanup pass.
- `0045_site_identity_link_footer_credit.php` turns the public footer credit on by default and aligns v0.2.23 metadata after the site identity link pass.
- `0046_footer_link_navigation_cleanup.php` removes footer navigation output, enables safe links in custom footer text, and aligns v0.2.23 metadata.
- `0047_admin_menu_export_cleanup.php` records the admin menu and export cleanup pass.
- `0048_media_metadata_lcp_priority.php` records the v0.2.24 media metadata and LCP priority pass.
- `0049_avatar_optimization.php` records the v0.2.25 avatar optimization pass.
- `0050_upload_image_derivatives.php` adds stored derivative metadata for new direct image uploads and records the v0.2.26 upload image derivatives pass.
- `0051_image_derivative_diagnostics.php` records the image derivative diagnostics pass and keeps image optimization troubleshooting visible without changing public image output.
- `0052_responsive_image_output.php` records the v0.2.28 responsive image output pass.
- `0053_existing_media_regeneration_tool.php` records the v0.2.29 existing media regeneration tool pass.
- `0054_avatar_size_selection_cleanup.php` records the v0.2.30 avatar size selection cleanup pass.
- `0055_stream_card_avatar_variant_correction.php` records the v0.2.31 stream card avatar variant correction pass.
- `0056_lcp_image_priority_correction.php` records the v0.2.32 LCP image priority correction pass.
- `0057_image_variants_admin_polish.php` records the v0.2.33 image variants admin polish pass.
- `0058_media_edit_alignment_polish.php` records the v0.2.34 media edit alignment polish pass.
- `0059_media_edit_button_alignment_hotfix.php` records the v0.2.35 media edit button alignment hotfix.
- `0060_public_release_cleanup.php` records the v0.2.36 public release cleanup and theme separation pass.
- `0061_public_release_polish_upgrade_cleanup.php` records the v0.2.37 public release polish and upgrade cleanup pass.
- `0062_xml_sitemap.php` adds sitemap setting defaults and records the v0.2.38 XML sitemap pass.
- `0063_styled_xml_sitemap.php` records the v0.2.39 styled XML sitemap pass and updates version settings.
- `0064_sitemap_presentation_polish.php` records the v0.2.40 sitemap presentation polish pass and updates version settings.
- `0065_migration_placeholder_hotfix.php` records the v0.2.41 migration placeholder hotfix and updates version settings.
- `0066_admin_dashboard_overview.php` records the v0.2.42 admin dashboard overview pass and updates version settings.
- `0067_admin_dashboard_order_polish.php` records the v0.2.43 admin dashboard order polish pass and updates version settings.
- `0068_admin_dashboard_layout_polish.php` records the v0.2.44 admin dashboard layout polish pass and updates version settings.
- `0069_admin_dashboard_column_flow_polish.php` records the v0.2.45 admin dashboard column flow polish pass and updates version settings.
- `0070_public_release_audit_repair.php` records the v0.2.46 public release audit repair pass and updates version settings.
- `0071_mobile_text_overflow_repair.php` records the v0.2.47 mobile text overflow repair pass and updates version settings.
- `0072_admin_form_input_style_repair.php` records the v0.2.48 admin form input style repair pass and updates version settings.
- `0073_upgrade_action_button_alignment.php` records the v0.2.49 upgrade action button alignment pass and updates version settings.
- `0074_admin_autofill_input_color_repair.php` records the v0.2.50 admin autofill input color repair pass and updates version settings.
- `0075_upgrade_screen_simplification.php` records the v0.2.51 upgrade screen simplification pass and updates version settings.
- `0076_admin_datetime_input_style_repair.php` records the v0.2.52 admin date-time input style repair pass and updates version settings.
- `0077_user_management_actions.php` records the v0.2.53 user management actions pass and updates version settings.
- `0078_user_edit_action_alignment.php` records the v0.2.54 Edit User action-alignment pass and updates version settings.
- `0079_comment_account_link_cleanup.php` records the v0.2.55 comment account link cleanup pass and updates version settings.
- `0080_account_registration_kicker_cleanup.php` records the v0.2.56 account registration kicker cleanup pass and updates version settings.
- `0081_public_navigation_account_links.php` records the v0.2.57 public navigation account links pass and updates version settings.
- `0082_public_navigation_account_links_toggle.php` records the v0.2.58 public navigation account links toggle pass and seeds the default enabled account-link setting.
- `0083_public_github_release_baseline.php` records the v0.3.0 public GitHub release baseline and updates version settings.
- `0084_public_rendering_regression_repair.php` records the v0.3.1 public rendering regression repair pass and updates version settings.
- `0085_mobile_public_page_containment_repair.php` records the v0.3.2 mobile public page containment repair pass and updates version settings.

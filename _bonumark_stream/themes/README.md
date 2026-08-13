# Bonumark Stream Themes

Bonumark Stream themes are code-free presentation packages.

Midnight Ledger in `_bonumark_stream/themes/default/` is the single bundled/default theme and the working legacy CSS-only example. Site owners may install additional third-party themes from Appearance without changing Bonumark core.

To create a traditional CSS-only theme, copy Midnight Ledger, rename the folder and slug, update `theme.json`, replace the screenshot, edit the CSS, zip the folder, and upload it from Appearance.

To create a Declarative Layout theme, use the same code-free theme package rules and follow `docs/DECLARATIVE-LAYOUTS.md` for Layout Schema 1, private `layouts/*.json`, supported surfaces, core component identifiers, validation, and fallback behavior.

Themes may include metadata, settings, CSS, images, fonts, screenshots, documentation, and explicitly declared private layout JSON. Themes may not include PHP, JavaScript, arbitrary HTML, route handlers, database writes, permission logic, server config files, symlinks, expressions, callbacks, or application behavior.

Theme asset URLs use the theme manifest `version` as their cache revision. Bump the theme version whenever presentation assets or layout files change.

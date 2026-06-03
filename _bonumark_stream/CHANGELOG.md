# Changelog

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

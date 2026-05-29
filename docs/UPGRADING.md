# Upgrading Bonumark Stream

Bonumark Stream v0.3.12 is the current public GitHub release baseline. It keeps the public package focused on the bundled Midnight Ledger theme, preserves the database-first upgrade path from the stable v0.2.x line, keeps dynamic XML sitemap, styled sitemap output, robots.txt discovery routes, the improved admin dashboard overview, admin form input style repairs, upgrade action button alignment, admin autofill input color repair, upgrade screen simplification, admin user management actions, Edit User action-row alignment, comment account link cleanup, account registration kicker cleanup, public navigation account links and account-link toggle, public page Markdown presentation repair, desktop no-image link-preview repair, mobile public page containment repair, Load More archive routing repair, WordPress featured media import repair, Site Identity favicon support, theme-independent favicon output, external theme upgrade preservation, and migration release integrity cleanup, SEO title output repair.

Do **not** treat v0.3.12 as an upgrade bridge from the old v0.1.x development line. The v0.1.x line carried Markdown-first and static-generation transition code that has now been removed or simplified. For a clean foundation, install v0.3.12 fresh.

## Recommended path from v0.1.x test installs

1. Export anything you want to keep.
2. Download any media you want to preserve.
3. Delete or archive the old test install.
4. Upload v0.3.12 fresh.
5. Run `install.php`.
6. Re-import only the content you actually want.

## v0.2.x upgrade path

The v0.2.x migration set is retained so current v0.2.x test installs can move forward without relying on removed v0.1.x compatibility code. The supported upgrade line is v0.2.x and later.

## Future upgrades

The admin ZIP upgrader remains in the package for v0.2.x and later releases. Upgrades from the v0.2.x baseline remove obsolete package-managed files while preserving user-owned data. Existing navigation settings are preserved. The v0.2.12 navigation rebuild also has a one-time compatibility pass for older page-level menu metadata when the Navigation screen is opened. Upgrades should preserve:

- `_bonumark_stream/config.php`
- `_bonumark_stream/installed.lock`
- `_bonumark_stream/content/`
- `_bonumark_stream/data/`
- `_bonumark_stream/backups/`
- `_bonumark_stream/tmp/`
- uploaded media
- database records
- custom installed themes, including external themes that reuse retired bundled slugs unless their installed theme manifest clearly identifies them as bundled leftovers

## Static Site Export after upgrades

Static Site Export is optional. Dynamic public routes use database records directly, so generated HTML is not required for the live site. Static exports include sitemap and robots discovery files when sitemap support is enabled.

## XML sitemap after upgrades

After upgrading, check Reading Settings to confirm sitemap inclusion choices. The live sitemap is available at `/sitemap.xml`, and `/robots.txt` includes a sitemap reference when sitemap support is enabled.


## v0.2.26 avatar optimization note

v0.2.26 keeps the v0.2.24 media metadata and LCP priority behavior and adds a controlled avatar-only optimization layer. New avatar uploads keep the original file, generate small avatar variants when GD or Imagick is available, and use the optimized avatar publicly when the file exists. Normal stream media output is unchanged, and no general srcset is emitted.


## v0.2.26 upload image derivatives note

v0.2.26 adds an `image_variants_json` column for media derivative metadata. New direct image uploads may create large, medium, and small derivative files when GD or Imagick is available. Public rendering still falls back to original files and does not emit general `srcset` yet. Imported media derivative generation remains off by default.


## v0.2.27 image derivative diagnostics note

v0.2.27 adds diagnostics to Media Edit so administrators can see whether optimized image variants exist, whether GD or Imagick is available, whether the generated media folder is writable, and why individual variants were skipped or failed. The regenerate action retries one media item at a time and does not change public image output.


## v0.2.30 responsive image output note

v0.2.30 adds srcset and sizes only for local media with recorded derivative metadata and confirmed generated files. The original image remains the fallback src. Missing variants, imported media without variants, and older media without metadata continue to render on the original image path.

## Existing media optimization

After upgrading from an older release, use **Media > Optimize Images** to regenerate optimized variants for existing images in small batches. Public pages only reference variants that exist and are verified.



## v0.2.32 LCP image priority correction note

v0.2.32 changes stream image priority assignment so the first actual image in the rendered card list, or the first image on a single post page, receives eager/high-priority loading. Later images remain lazy. This pass does not change public image URLs, responsive `srcset`, generated derivatives, image compression, or format handling.

## v0.2.31 avatar stream-card correction note

v0.2.31 forces compact stream-card, comment, and admin-header avatar contexts to use the 96w avatar variant when available or generate it safely from the original avatar. Larger profile and account header displays continue to use 192w avatars. Normal post image handling is unchanged.

## v0.2.30 avatar size selection note

v0.2.30 uses 96w avatar variants for compact stream-card/comment contexts and keeps 192w avatar variants for larger profile/account header displays. It does not change normal post image handling.


## Rollback behavior

The admin ZIP upgrader backs up package-managed software files before copying the new package. If an upgrade fails after copying begins, Bonumark Stream restores backed-up files and removes newly copied package files that did not exist before the attempt. User-owned data is still preserved: `_bonumark_stream/config.php`, `_bonumark_stream/installed.lock`, `_bonumark_stream/content/`, `_bonumark_stream/data/`, `_bonumark_stream/backups/`, `_bonumark_stream/tmp/`, uploaded media, database records, and custom installed themes are not deleted by rollback cleanup, including external themes that reuse retired bundled slugs.

## Release validation

Before publishing a release, run `php scripts/smoke-test.php` and, when a disposable MySQL/MariaDB database is available, run `php scripts/database-smoke-test.php` with the required `BMS_DB_*` environment variables. Static linting is not enough to prove install and migration safety.

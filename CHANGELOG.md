# Bonumark Stream Changelog

This file tracks public GitHub release milestones. Detailed package-by-package development history is retained in [`_bonumark_stream/CHANGELOG.md`](_bonumark_stream/CHANGELOG.md).

## 0.7.1 - Compatibility Workflow Correction Pass

- Supersedes the unreleased v0.7.0 candidate as the intended public release after v0.6.0 while retaining the same hosting-portability, deployment, upgrade, and compatibility milestone.
- Corrects the GitHub Actions compatibility matrix to use the Node 24 based `actions/checkout@v5` and run package, migration/schema, and Remote Posting API checks from a clean tracked source snapshot rather than a checkout containing `.git/` metadata.
- Restores `api/v1/stream/posts.php` to the GitHub release branch so repository source, the release manifest, Remote Posting documentation, and the package smoke contract agree.
- Keeps the documented PHP 8.1+, MySQL 8.0+, and MariaDB 10.6+ floors unchanged.
- Changes no Bonumark runtime behavior and adds no database migration compared with the v0.7.0 candidate.
- Retains the v0.4.0+ supported upgrade line and the existing owner-data preservation boundary.

The unreleased v0.7.0 candidate is retained in the detailed package history under [`_bonumark_stream/CHANGELOG.md`](_bonumark_stream/CHANGELOG.md).

## 0.6.0 - Profiles & Theme Architecture 2.0

Bonumark Stream v0.6.0 brings together the work completed since the last public release, v0.5.77.

### Highlights

- Promotes Profiles into a first-class public identity surface with headline, About, Now, location, interests, flexible links, Featured Work, cover media, Profile photos, structured identity metadata, and owner-controlled Profile export.
- Introduces Theme Architecture 2.0 with validated Layout Schema 1 composition for Profile, Stream Card, Site Header, and Home while keeping application behavior and component rendering in core.
- Preserves CSS-only theme compatibility through fixed legacy composition fallback.
- Consolidates the bundled theme set to a single default/reference theme, Midnight Ledger, and rebuilds it across the complete four-surface declarative stack.
- Hardens external link-preview metadata so remote document titles remain isolated from local document SEO and site-name suffixing.
- Strengthens package/upgrade protection around owner data and custom themes.
- Improves Profile image delivery with responsive candidates, bounded fallbacks, cover preload/high fetch priority, lazy gallery delivery, and optional WebP picture sources when supported by the host.
- Fixes Midnight Ledger's fresh/empty Profile layout so the identity card does not overlap the Site Header when no Profile cover has been added.
- Adds and expands regression coverage for declarative theme validation, component contracts, Profile metadata, link previews, Midnight Ledger composition, upgrade cleanup, and media delivery.

### Database changes

Upgrading from v0.5.77 adds three migrations:

- `0015_profile_identity_foundation.php` creates the Profile identity table and seeds existing accounts with empty Profile records.
- `0016_profile_featured_work.php` adds Featured Work storage.
- `0017_profile_photos.php` adds Profile photo-gallery storage.

The upgrade does not rewrite existing Stream Post or Page content.

### Upgrade notes

The built-in upgrader continues to support v0.4.0 and newer. It preserves configuration, database records, posts, pages, drafts, scheduled posts, revisions, comments, accounts, media, uploads, settings, analytics, API tokens, scheduled-task history, Local Places, custom themes, and other owner data.

Back up site files and the database before upgrading a production installation.

## 0.5.77 - Admin, Composer & Gallery Release

v0.5.77 was the previous public GitHub release. It added one-to-four-photo galleries, consolidated the front-end Stream composer, added front-end Quick edit and recoverable trash actions, rebuilt the Admin experience across desktop and mobile, improved media/page/comment/account/Local Places workflows, consolidated legacy Admin CSS, and hardened upgrade/package handling.

Earlier public release notes remain available in the repository's GitHub Releases history.

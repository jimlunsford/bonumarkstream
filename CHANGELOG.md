# Bonumark Stream Changelog

This file tracks public GitHub release milestones. Detailed package-by-package development history is retained in [`_bonumark_stream/CHANGELOG.md`](_bonumark_stream/CHANGELOG.md).

## 0.7.2 - Hosting Portability & Upgrade Workflow

Bonumark Stream v0.7.2 is the next intended public GitHub release after v0.6.0. It consolidates the completed v0.6.1 through v0.6.8 development line and supersedes the unreleased v0.7.0 and v0.7.1 release candidates.

### Highlights

- Formalizes locked-down application trees as a supported operating model where PHP can write required runtime storage without owning package-managed application code.
- Adds the owner-run `scripts/deploy-update.php` workflow for shell-access deployments while keeping Admin ZIP upgrades for hosts where PHP can safely replace application files.
- Routes both upgrade paths through the same core engine for package validation, owner-data preservation, private software backups, selective pre-migration rollback, obsolete-file cleanup, migration recovery, and upgrade-history recording.
- Adds read-only deployment verification, pending-migration and obsolete-file checks, safer private-path probing, real clean-route testing, and CLI-only protection for shipped scripts.
- Adds maintained Nginx guidance and explicit PHP 8.1+, MySQL 8.0+, and MariaDB 10.6+ compatibility targets.
- Adds a GitHub Actions matrix covering PHP 8.1/8.3 with MySQL 8.0/8.4 and MariaDB 10.6/11.4.
- Corrects release-candidate verification so CI tests a clean tracked source tree, includes the Remote Stream Posts API route in repository source, and validates current fresh installs separately from historical v0.4.x migration replay.
- Keeps the supported upgrade floor at v0.4.0 and the existing owner-data preservation boundary.
- Adds no new database migration compared with v0.6.0.

Detailed v0.6.1 through v0.6.8 development history and the unreleased v0.7.0/v0.7.1 candidate notes remain in [`_bonumark_stream/CHANGELOG.md`](_bonumark_stream/CHANGELOG.md).

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

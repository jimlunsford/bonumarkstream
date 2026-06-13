# Upgrading Bonumark Stream

Bonumark Stream v0.5.0 continues the v0.4.0+ clean-break foundation.

## Supported upgrade path

The built-in upgrade tool supports upgrades from v0.4.0 and newer only.

Pre-v0.4 development builds are not supported by the current upgrader. Install the current v0.5.0 package fresh instead of trying to upgrade an older development build.

## What the upgrader preserves

The upgrader preserves current v0.4.0+ user-owned data and generated files:

- `_bonumark_stream/config.php`
- `_bonumark_stream/installed.lock`
- `_bonumark_stream/data/`
- `_bonumark_stream/backups/`
- `_bonumark_stream/tmp/`
- `media/`
- `uploads/`
- installed code-free theme packages and public theme assets that are not bundled with the release

The upgrader does not preserve old file-runtime content folders. Markdown remains available for import, export, backup, and portability only. Runtime publishing is database-first.

## Static export

Static Site Export is optional tooling. It is not the normal publishing mode.

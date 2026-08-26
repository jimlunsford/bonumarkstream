# Manual software deployment fallback

Use this workflow when the first-class owner-run CLI upgrade is unavailable, such as a host without shell access or an environment that deliberately uses an external file-deployment system.

A locked-down application tree is a supported Bonumark Stream deployment model. Keep the PHP-FPM/web process limited to the runtime paths reported by System Check. When shell access is available, prefer `php scripts/deploy-update.php /path/to/release.zip` as the application owner. The CLI workflow uses Bonumark's shared upgrade engine and is safer and less error-prone than a hand-managed overlay.

Use the manual steps below for SFTP, hosting control panels, or external deployment systems. Do not make the full site writable to PHP just to enable the Admin ZIP upgrader.

## Before you start

1. Read the target release notes and `docs/UPGRADING.md`.
2. Record the currently installed version before replacing files. On shell-access hosts: `CURRENT_VERSION=$(cat VERSION)`.
3. Confirm whether the target release contains database migrations.
4. Back up the database.
5. Back up the complete live site.
6. Extract the new Bonumark release outside the live web root.
7. Keep a terminal/control-panel session available until verification is complete.

A file overlay is not a complete upgrade while database migrations are pending. v0.6.6 and newer include a generic owner-run migration helper for manual deployments; v0.6.7 and newer normally handle that migration phase automatically when `scripts/deploy-update.php` is used.

## Protected owner/runtime paths

A manual deployment must preserve the live installation's real data. Do not replace or delete:

- `_bonumark_stream/config.php`
- `_bonumark_stream/installed.lock`
- `_bonumark_stream/data/`
- `_bonumark_stream/tmp/`
- `_bonumark_stream/backups/`
- `_bonumark_stream/content/`
- `_bonumark_stream/import-staging/`
- `media/`
- custom owner themes or other owner-managed files that are not package-managed

## Unix-like hosts with rsync

Replace the example source and destination paths with the real paths for the release and site.

Dry run first:

```sh
rsync -avnc \
  --exclude='_bonumark_stream/config.php' \
  --exclude='_bonumark_stream/installed.lock' \
  --exclude='_bonumark_stream/data/' \
  --exclude='_bonumark_stream/tmp/' \
  --exclude='_bonumark_stream/backups/' \
  --exclude='_bonumark_stream/content/' \
  --exclude='_bonumark_stream/import-staging/' \
  --exclude='media/' \
  --exclude='.github/' \
  /path/to/extracted/bonumark-stream-vX.Y.Z/ \
  /path/to/live/site/
```

Review the complete file list. If it is correct, remove only the dry-run `n`:

```sh
rsync -avc \
  --exclude='_bonumark_stream/config.php' \
  --exclude='_bonumark_stream/installed.lock' \
  --exclude='_bonumark_stream/data/' \
  --exclude='_bonumark_stream/tmp/' \
  --exclude='_bonumark_stream/backups/' \
  --exclude='_bonumark_stream/content/' \
  --exclude='_bonumark_stream/import-staging/' \
  --exclude='media/' \
  --exclude='.github/' \
  /path/to/extracted/bonumark-stream-vX.Y.Z/ \
  /path/to/live/site/
```

Do **not** add `--delete` at the site root. A blind delete can remove custom themes or other owner data. After the overlay, `scripts/deployment-check.php` detects obsolete package-managed files that remain from older releases while preserving runtime data and custom themes. Remove only the exact obsolete paths it reports, after confirming your backup.

The repository-only `.github/` directory is not required on a deployed web root and is excluded in the shell examples above.

When the host uses a deliberate owner/group model, preserve that model with the host's normal ownership tools or an appropriate `rsync --chown` option. Bonumark does not require a specific Unix username or group.

## Database migrations after the file overlay

After replacing application files, check the installed migration state **before** considering the deployment complete:

```sh
php scripts/run-migrations.php --check
```

If the result reports zero pending migrations, continue to the deployment check. If migrations are pending, confirm the external database backup exists, then run them as the application owner using the version you recorded before the overlay:

```sh
php scripts/run-migrations.php --run --confirm-backup --from-version="$CURRENT_VERSION"
```

The helper uses Bonumark's migration ledger and migration lock, writes forward-only recovery state before the migration phase, records successful manual upgrade history, and never changes application files or elevates operating-system privileges. MySQL/MariaDB DDL can auto-commit, so if a migration run stops after DDL begins, **do not restore older application files over the possibly migrated database**. Fix the cause and rerun the same installed target release.

## SFTP or hosting control panel

The same boundary applies without shell access:

1. Back up the site and database.
2. Extract the release locally.
3. Upload/replace package application files and directories.
4. Preserve every owner/runtime path listed above.
5. Do not use a control-panel "replace everything" option that deletes files not present in the release package.
6. Apply any release-specific cleanup or migration instructions.

If the target release has pending database migrations and the hosting account has no CLI/terminal access, do not treat a file-only manual overlay as complete. Use the host's supported shell/console migration path or a staging/deployment method that can execute the owner-run migration helper. The normal in-app upgrader remains the appropriate path on hosts where PHP is intentionally allowed to replace package-managed software.

## Verification

From the live site root, SSH users can run the read-only deployment check. It verifies package/version/database state, reports pending migrations, and detects obsolete package-managed files as the current CLI identity; Admin → System Check remains authoritative for web/PHP permissions and HTTP protections:

```sh
php scripts/deployment-check.php
```

Then verify the live HTTP boundaries:

```sh
curl -sS -o /dev/null -w "Home: %{http_code}\n" https://example.com/
curl -sS -o /dev/null -w "Stream: %{http_code}\n" https://example.com/stream
curl -sS -o /dev/null -w "Private storage: %{http_code}\n" https://example.com/_bonumark_stream/VERSION
curl -sS -o /dev/null -w "Scripts: %{http_code}\n" https://example.com/scripts/deployment-check.php
```

The home page and `/stream` should normally return HTTP 200. Private storage and `/scripts` must not return HTTP 200; a deliberate 403 or protected 404 is acceptable.

Finally open **Admin → System Check**. Core failures should be resolved before the deployment is considered complete. Warnings for **Web-based software upgrades** or **Theme ZIP installation** can be intentional on a locked-down installation.

## Why there is no privileged deployment helper

Bonumark Stream v0.6.7 includes an owner-run deployment helper, but there is still **no privileged deployment helper**. `scripts/deploy-update.php` runs only with the permissions of the user who invoked it. Bonumark does not install a privileged daemon, sudo bridge, setuid executable, or self-elevating updater. The web/PHP process should not gain operating-system privileges merely to replace application code.

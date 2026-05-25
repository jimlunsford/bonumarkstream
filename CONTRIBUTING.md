# Contributing to Bonumark Stream

Bonumark Stream is in active pre-1.0 development and is licensed under the GNU Affero General Public License v3.0 or later (`AGPL-3.0-or-later`). The repository is shared for review, testing, transparency, and future community participation.

## Current contribution status

Public pull requests may be reviewed, but acceptance is not guaranteed. Small issue reports, reproducible bugs, documentation corrections, security reports, and focused patches are welcome.

By submitting code, documentation, theme files, test fixtures, or other project changes, you agree that your contribution may be included in Bonumark Stream under the same project license: `AGPL-3.0-or-later`.

## Before opening an issue

Please include:

- Bonumark Stream version
- PHP version
- MySQL or MariaDB version
- browser and device if the issue is visual
- active public theme
- whether this is a fresh install or upgrade
- exact steps to reproduce
- screenshots or logs when useful

Do not include passwords, database dumps, private exports, API keys, recovery tokens, or private media files.

## Development expectations

Changes should preserve these project rules:

- shared-hosting compatibility comes first
- content ownership and portability come first
- public presentation belongs in themes
- core owns data, security, publishing, imports, upgrades, and admin behavior
- runtime content, uploads, backups, and local config must stay out of the repository
- version bumps use `MAJOR.MINOR.PATCH`
- release packages must update version markers, changelog, and release manifest
- contributed code and documentation must remain compatible with `AGPL-3.0-or-later`

## Validation before proposing changes

Run the same checks used by CI:

```bash
find . -name '*.php' -print0 | xargs -0 -n1 php -l
find assets -name '*.js' -print0 | xargs -0 -n1 node --check
php scripts/smoke-test.php
```

A change is not ready if it leaves stale version numbers, malformed migrations, manifest mismatches, debug output, or runtime files in the package.

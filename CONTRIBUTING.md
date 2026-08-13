# Contributing

Bonumark Stream is a database-first, shared-hosting-friendly microblog CMS. Contributions should protect the product boundaries that keep installs portable, upgrades safe, and themes presentation-only.

## Project rules

- Database is the source of truth.
- Markdown is import, export, backup, and portability only.
- Core owns application logic.
- Themes are presentation-only and code-free.
- Shared-hosting compatibility matters.
- The Admin account is the sole publisher.
- Commenter accounts are for participation, not publishing.
- Do not add upgrade support for pre-v0.4 development builds.
- Use the `bms_` internal function prefix.
- Keep API, admin, public, and theme behavior separated.
- Treat `docs/ADMIN-UI-GUIDELINES.md` as the contract for new or changed Admin interfaces.

## Before opening a pull request

- Work from the current package baseline.
- For Admin work, identify the closest existing workflow and component stylesheet before implementation.
- For Admin work, verify desktop, tablet, phone, empty, warning, error, and destructive states as applicable.
- Keep changes focused on one confirmed issue or feature.
- Do not commit `_bonumark_stream/config.php`, `installed.lock`, uploads, exports, backups, logs, database dumps, API tokens, or local test data.
- Update public documentation only when the source actually supports the documented behavior.
- Run PHP lint across changed PHP files, JavaScript syntax checks across changed JavaScript files, JSON validation for changed JSON files, and `php scripts/smoke-test.php` from the project root.
- For database, installer, or upgrade changes, test against a disposable MySQL or MariaDB database before proposing the change.

## Pull request notes

Explain the user-visible impact, upgrade impact, migration impact, and verification performed. Call out anything that cannot be verified without a live server or database.

For Admin changes, also name the existing workflow being followed, the stylesheet that owns the change, any new UI component introduced, and the responsive and accessibility checks performed.

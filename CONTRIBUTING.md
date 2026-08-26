# Contributing

Bonumark Stream is a database-first, self-hosted microblog CMS designed for portable PHP hosting. Contributions should protect the product boundaries that keep installs portable, upgrades safe, and themes presentation-only.

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
- Run PHP lint across changed PHP files, JavaScript syntax checks across changed JavaScript files, JSON validation for changed JSON files, and `php scripts/smoke-test.php` from a clean source/release tree from the project root.
- When changing Nginx support, run `nginx -t` against the shipped example in a disposable test configuration and keep its route/security contract aligned with the bundled Apache/LiteSpeed rules.
- For database, installer, or upgrade changes, test against disposable supported targets (MySQL 8.0+ and/or MariaDB 10.6+) before proposing the change, and record the exact server version used.
- Keep `.github/workflows/compatibility.yml` and `docs/COMPATIBILITY.md` aligned with the documented PHP/database floors and reference targets.
- Keep database compatibility-floor parsing/reporting covered by the package smoke test. Production guidance should still prefer vendor-supported database releases.

## Pull request notes

Explain the user-visible impact, upgrade impact, migration impact, and verification performed. Call out anything that cannot be verified without a live server or database.

For Admin changes, also name the existing workflow being followed, the stylesheet that owns the change, any new UI component introduced, and the responsive and accessibility checks performed.

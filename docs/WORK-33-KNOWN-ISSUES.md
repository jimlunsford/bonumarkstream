# Work 33: existing issues outside the interface scope

## Remote cache cleanup fails on the starting source

Reproduced against untouched commit `3d950a10e0231649de25e7d97a92c1feede9409f` in a disposable installation, with MariaDB 11.8.6 and PHP 8.5.4. No production or development installation was accessed.

`bms_activitypub_cleanup_remote_cache()` in `_bonumark_stream/app/activitypub-operations.php` joins `activitypub_reply_targets` as `r`, then tests `r.id IS NULL`. Migration `0026_activitypub_owner_participation.php` creates that table with `post_id` as its primary key and no `id` column. The candidate selection throws:

`SQLSTATE[42S22]: Column not found: 1054 Unknown column 'r.id' in 'WHERE'`

Reproduction: install the current schema in a disposable database, load the application scheduler, then invoke `bms_activitypub_cleanup_remote_cache()`. The empty database reproduces the invalid column reference. The Admin `cleanup_remote_cache` POST action catches the exception and reports that the change could not be completed.

The blocked-content UPDATE precedes the failing SELECT and is not wrapped in a transaction. Therefore a failure must not be interpreted as proof that no cleanup work occurred on populated data. Work 33 tests this only with an empty disposable cache and never invokes live cleanup.

Work 33 preserves the action and its honest failure presentation. It does not correct the query, change the schema or claim that cache cleanup passes. The focused HTTP suite explicitly records the known failure and checks that it does not display false success. A separate bounded functional correction should verify cache retention, references, tombstones, blocked objects and partial-write behavior on MySQL and MariaDB.

## Existing revoke confirmation handlers do not execute

The existing Remote Posting form has `data-confirm="Revoke this API token? Connected clients using it will stop working."`. Its listener is `attachConfirmations()` in `assets/editor.js`. The shared Admin layout loads `assets/admin.js` and `assets/admin-places.js`; Remote Posting does not load `editor.js`. Therefore clicking Revoke submits immediately, without the intended browser confirmation. This also occurs on untouched starting source, with JavaScript enabled.

Work 33 retains the exact confirmation attribute and consequence text, token hashing, action and permissions. It does not add a new global confirmation subsystem or silently repair this pre-existing functional defect. A separate correction should restore the intended handler, test cancellation and acceptance, and review other `data-confirm` forms. Registration has a separate inline `onsubmit` confirmation, but the existing `script-src 'self'` Content Security Policy in `_bonumark_stream/app/functions.php` blocks inline event handlers. Its invite revoke also submits without a prompt on the untouched base. Preserve the restrictive CSP; a separate correction should use an allowed external handler. The Work 33 browser report does not claim that either confirmation works.

## Apollo PHP 8.5 source-smoke limitation

On both untouched base and candidate clean source trees, the source smoke check's `php -n` multibyte fallback probe receives a PHP 8.5 deprecation message for `$http_response_header` before the expected `mb-off|3|abc` output. This causes that assertion to fail under Apollo's PHP 8.5.4. Work 33 does not change the core function or suppress the warning. Supported PHP 8.1 and 8.3 results are reported separately in the GitHub compatibility evidence.

## Other retained findings

- Page restoration's previously documented delete-before-sync failure risk remains unchanged.
- The previously recorded legacy MySQL commenter-approval banner issue remains outside this scope.
- Existing Comments timestamp interpretation differences remain outside this scope.

No Work 32 lifecycle, federation core, mail transport, token hashing, authentication, migration or public-theme behavior is modified by Work 33.

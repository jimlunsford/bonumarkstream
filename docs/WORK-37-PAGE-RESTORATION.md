# Failure-atomic Page restoration

`bms_restore_page_trash_item()` owns a database transaction, or a uniquely named savepoint when its caller already owns one. It locks and rereads the selected Trash row, reconstructs the Page from the stored database body and front matter, inserts a new active Page, synchronizes its terms, and removes exactly one Trash row. Success is returned only after COMMIT or savepoint release succeeds. A nested success is still subject to the caller's final commit or rollback.

The previous implementation deleted Trash before calling a synchronization helper. A controlled INSERT failure on untouched main `dc7beaf227ca449417bcf815c3ea8f0c82c3df7f` left neither the active Page nor the original recovery row. The HTTP error handler could report failure but could not undo that autocommitted deletion.

Restoration and the New Page write call deliberately use `bms_insert_new_page()`, a checked INSERT instead of the shared upsert. A concurrent creator must not become an update target between a friendly collision check and the restoration write. The existing unique `(post_type, slug, status)` key arbitrates that race. The locked Trash row serializes two restorers, including when a caller has an older REPEATABLE READ snapshot. A losing restoration rolls back and retains its recovery record. A second request for a successfully consumed item cannot create another Page.

An additional controlled interleaving proved that an ordinary creator could pass its slug checks before restoration committed, then resume its legacy upsert and overwrite the restored Page. The one-line New Page call-site correction is necessary to preserve new-record intent in that race. The shared upsert and Work 32A active-Page update implementation remain unchanged.

The existing Page record mapper and term synchronization remain in use. The stored body is not trimmed or rebuilt. All front-matter keys, original date, tags, supported media references, SEO fields and original author attribution are retained. Stored original status determines Draft versus Published. The established current-owner fallback remains when original author information is absent. No schema, migration, active-Page update helper, Stream lifecycle, restoration HTTP handler, authorization, CSRF or version changes are required.

## Reproducible isolated verification

Run only with a disposable database, the same database variables used by the other runtime tests, and `BMS_DB_DANGER_RESET=1`:

```sh
php scripts/page-restore-runtime-test.php
```

The test creates randomly prefixed tables, a private temporary application, synthetic users/content, and a loopback PHP server. It disables scheduling, mail and federation. Its temporary copy of the PDO factory loads `scripts/fixtures/page-restore-test-support.php`; production source has no fault-injection hook. The helper must never be installed into a populated application. Child workers use separate processes and database connections. Test-owned tables, applications and workers are cleaned in `finally`.

The regression verifies successful Draft/Published HTTP restoration and public rendering, complete metadata fidelity, actual database snapshots after injected failures, retries, caller-owned savepoints, affected-row and false-return checks, authorization/CSRF/GET rejection, existing error logging, unchanged unrelated records, and concurrent restoration/creation, including a creator resuming after a restoration commit, under REPEATABLE READ and READ COMMITTED. Native SQL failure is induced by temporarily renaming a column in the disposable schema, then restoring it. COMMIT faults are injected before the real COMMIT is sent, so their rollback result is unambiguous.

A lost connection after the server has committed can make the response outcome uncertain. InnoDB still commits or rolls back the whole operation; this test does not claim to resolve an ambiguous network acknowledgement. An engine deadlock that aborts an entire caller transaction cannot preserve that caller's earlier uncommitted writes. Ordinary nested failures preserve the caller transaction using the savepoint; engine-wide aborts remain the caller's responsibility.

## Pre-existing CI exception

Work 36 post-merge run `45474264499` had three passing jobs and one failing PHP 8.3/MySQL current-LTS job. The Remote API `publishing_safety` scenario reported `The route smoke request failed: Connection refused`. That historical failure remains recorded, not reclassified as a successful baseline. Work 37 does not modify Remote API behavior, loopback server startup or compatibility infrastructure. Adding the new regression and work-branch trigger is the only workflow change.

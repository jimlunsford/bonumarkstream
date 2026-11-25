# Atomic Page creation

`bms_insert_new_page()` inserts one new Page and synchronizes its category and tags as one operation. It starts a transaction before writing if none exists. Otherwise it establishes a unique `bms_page_create_*` savepoint. It returns only after commit or savepoint release succeeds. A nested success remains uncommitted and belongs to the caller.

On a recoverable failure, rollback removes the Page, its associations, and any terms created by that operation. A nested rollback preserves earlier caller writes. Transaction/savepoint setup, completion and cleanup false returns are checked. Cleanup failure is reported with the original exception retained as the cause. Callers must resolve a cleanup failure before continuing a transaction.

The helper retains strict INSERT and the existing `(post_type, slug, status)` uniqueness contract. It does not upsert or adopt a competing identity. New Page HTTP validation and automatic slug generation remain unchanged. On the old source, a term failure could leave a partial Page; retrying the HTTP submission generated a suffixed slug. After a known successful rollback, retry can use the original slug.

Required term deletion, lookup, creation and association failures now propagate, including false returns in non-exception modes. A false lookup fetch is accepted as absence only with a successful statement SQLSTATE. A valid `INSERT IGNORE` returning zero affected rows is still success. Shared callers are `bms_upsert_database_content()`, `bms_sync_stream_metadata()` and `bms_sync_page_metadata()`, plus creation and their existing lifecycle callers. The term helpers do not independently introduce transactions for those other callers.

Restoration retains its outer transaction/savepoint, locking read, strict identity checks and Trash deletion. Its call to creation now uses an inner savepoint. Test injections explicitly distinguish `bms_page_create_*` from `bms_page_restore_*`; the restoration-owned commit test still reaches COMMIT after both creation and Trash deletion.

## Failure limits

Known recoverable statement failures and controlled failures before COMMIT or RELEASE are tested. Whole-transaction invalidation, such as an InnoDB deadlock, can discard caller-owned work. Loss of a connection around COMMIT can make the durable outcome uncertain. No preservation or safe automatic retry is promised for those events. The error retains its original cause and reports unexpected transaction termination or cleanup failure. A simulated pre-COMMIT failure is not proof about a lost response after a real COMMIT.

Concurrency tests keep the current database behavior. Independent Pages sharing terms may serialize or encounter a recoverable lock timeout, especially with Repeatable Read gap locks around association cleanup. Competing term creation can fail on the existing unique term key. The losing operation must roll back completely and can then be retried after the conflict resolves. This correction adds neither a retry loop nor a general term-concurrency redesign.

## Verification

Use a disposable database with `BMS_DB_HOST`, `BMS_DB_NAME`, `BMS_DB_USER`, `BMS_DB_PASS`, and `BMS_DB_DANGER_RESET=1`:

```sh
php scripts/page-creation-runtime-test.php
php scripts/page-restore-runtime-test.php
php scripts/page-lifecycle-runtime-test.php
php scripts/admin-pages-runtime-test.php
php scripts/admin-content-runtime-test.php
php scripts/api-database-smoke-test.php
```

The creation suite uses random table prefixes and isolated application copies. It covers Draft/Published fidelity, HTTP failure/retry, authorization/CSRF/validation, false returns and exceptions, ownership and independent visibility, cleanup failures, transaction-wide rollback, concurrent creators in Repeatable Read and Read Committed, shared terms, and unchanged cross-status uniqueness. Restoration covers competing restoration and ordinary creation in both orders. Existing lifecycle/content/API suites exercise affected shared term writers.

For the focused browser check, install `playwright-core`, make it available through `NODE_PATH`, and set `BMS_CREATION_BROWSER_REPORT` to an evidence directory. `BMS_BROWSER_EXECUTABLE` optionally selects an installed Chromium executable. Otherwise `/usr/bin/google-chrome` is used. The browser submits Draft and Published requests, observes injected failure feedback, retries the same slug, and observes success and the editing screen. The four compatibility jobs run both creation and browser checks and retain synthetic browser evidence.

To reproduce the original defect, copy the creation suite and its fixture into an unmodified pre-fix source tree, then run `php scripts/page-creation-runtime-test.php --baseline`. This mode requires partial-write evidence and suffixed HTTP retries; it must fail on corrected source. Fault switches exist only in disposable test copies, never in application request code. The suite removes its synthetic tables and files on exit. No auto-increment rollback is required.

No migration, version change, editor redesign, deployment, or release is included.

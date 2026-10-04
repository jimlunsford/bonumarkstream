# Structured upgrade receipts

## Design and evidence contract

Gate 4 extends the shared `bms_upgrade_install()` engine. It does not introduce a
second deployment engine or change the migration ledger into an audit log.

Migration `0029_structured_upgrade_receipts` creates `upgrade_operations` and
`upgrade_operation_events`. The operation has an opaque random 128-bit identifier,
UTC start/completion timestamps, method, status, error code, and schema-versioned
evidence. Events are append-only observations. Projection and event inserts commit
together. Existing `upgrade_history` rows and their timestamp-cutover consumers
remain unchanged; legacy summaries are not reconstructed as full receipts.

An attempt begins when the owner authorizes execution, including an execution
blocked before software replacement. Upload/precheck and CLI `--check` create no
receipt. Receipt initialization must succeed before mutation on an installation
with the receipt migration applied. Subsequent evidence-write failures are logged
and reported, but cannot enter the upgrader's rollback decision. Database/software
consistency takes priority over audit completeness. An interrupted operation can
remain `running`; that is not evidence of success.

The existing migration recovery marker and ledger remain authoritative. The marker
may carry an operation ID and package hash for linkage and exact-package checking.
Resume appends evidence to the same operation, preserving its original start,
earlier recovery failure, completed migrations, and backups. The operation's latest
status is a projection, not a replacement for historical events.

Schema version 1 separates package, preflight, backup, execution, migrations,
preservation, verification, and outcome. Unknown or unobserved values are explicit;
zero is used only for a measured count. External database backup confirmation is
an operator assertion, never a restore test. Software backup identifiers are
basenames. Preservation evidence describes the applied replacement/cleanup policy
and observed config/lock equality; it does not certify database or media equality.

Automatic verification uses shared read-only installed-deployment checks. System
Check snapshots, if captured, identify the executing PHP context and record the
actual status counts and check labels without diagnostic messages or credentials.
The System Check page itself remains read-only. Human acceptance is `not_run`.

Admin Upgrade owns recent receipts, detail, and JSON download under its existing
authorization. JSON is generated on demand from database state, has schema version
1, and is not a second datastore. No automatic pruning is implemented. Runtime
configuration, raw exceptions, credentials, absolute paths, and backup contents
are excluded from structured evidence. Legacy history retains its old semantics.

## Bootstrap

An upgrade started by a pre-receipt Admin runtime cannot retroactively capture
package hash, preflight, or phase evidence. It continues to leave legacy history;
the UI identifies that history as incomplete legacy evidence. No backfill fabricates
missing fields. Migration 0029 still runs through the normal migration runner.

A new owner CLI operating on a pre-receipt installation can observe evidence in
memory, but cannot durably initialize a receipt before migration 0029. It may persist
those actual observations once the migration creates the tables, explicitly marked
`deferred_until_schema`. A crash or failure before schema availability leaves only
the existing legacy/recovery evidence. No pre-migration DDL is performed. After
0029 is recorded, missing/unwritable receipt tables block new execution.

The pinned pre-receipt engine fixture comes from accepted commit
`49a16586128a2cb547e2d6b4e0b2ee6d515ca672`. The test runs that engine against
a pre-0029 schema and proves it leaves legacy history without manufactured receipts.
The deferred-bootstrap test separately exercises receipt-capable code against a
pre-0029 database. Older loaded database helpers can omit new recovery linkage
fields during that one-time transition; such legacy recovery cannot guarantee the
same operation association. Its preexisting marker/ledger rules remain authoritative.

## JSON schema version 1

The exported object contains these fields. Database timestamps are UTC; a null
completion time means no terminal completion was recorded.

| Field | Meaning |
| --- | --- |
| `operation_id` | 32 lowercase hex characters, random opaque identity |
| `schema_version` | Integer `1` |
| `started_at`, `completed_at` | Original start and latest terminal time |
| `method` | `admin_zip` or `owner_cli`; resume method is also retained in its event |
| `status`, `error_code` | Latest outcome and nullable stable code |
| `evidence` | Historical evidence projection described below |
| `events` | Ordered observations with numeric event ID, UTC observation time, type and evidence |

`evidence` contains `from_version`, `target_version`, `bootstrap`, `outcome`,
`package`, `preflight`, `backup`, `execution`, `migrations`, `recovery`,
`preservation`, and `verification`. `outcome` provides a human summary and next
action derived from status. Package identity is logical rather than an absolute
uploaded pathname. Manifest count includes the manifest itself, matching existing
precheck terminology. `migrations.completed` lists the expected migrations observed
in the canonical ledger; event timestamps are observation times, not reconstructed
migration execution timestamps. `verification.system_check` captures labels,
normalized statuses and exact `pass`/`warning`/`fail` counts when observed. Raw
diagnostic messages are excluded. The execution context distinguishes CLI from web
capabilities; a CLI snapshot does not certify PHP-FPM permissions or HTTP behavior.

Stable codes include `execution_locked`, `package_invalid`, `package_changed`,
`package_not_newer`, `recovery_package_mismatch`,
`receipt_bootstrap_migration_missing`, `migration_preflight_unavailable`,
`database_backup_confirmation_required`, `software_not_writable`,
`software_backup_failed`, `preflight_failed`, `software_unchanged`,
`software_rolled_back`, `rollback_failed`, `migration_phase_failed`, and
`verification_failed`. The status plus phase and rollback evidence controls the
next action; raw exception text is never used as the structured error code.

`complete` means software/migration execution and the automatic deployment checks
completed. It does not mean every System Check item passed. Warnings remain
warnings, and the actual System Check snapshot remains inspectable. `failed` with
`verification_failed` means the newer software stays installed. `blocked` records
zero package replacements. `recovery_required` is nonterminal and retains its
original start through retry. `running` may describe interrupted evidence and must
never be interpreted as a completed attempt. There is no automatic retry or pruning.

The receipt service refuses to join or commit an application transaction. Each
projection/event batch uses its own transaction. JSON reads hold the operation row
while reading its events, avoiding an export that mixes two committed snapshots.

## Verification

`php scripts/upgrade-receipts-test.php` uses disposable site copies, synthetic ZIPs
and random `bms_receipt_test_*` table prefixes. It requires the same database test
environment as `database-smoke-test.php`, including `BMS_DB_DANGER_RESET=1`.
It tests the shared engine, actual owner CLI, actual Admin authorization/detail/JSON
route, migration failure and exact-byte recovery, rollback failure, evidence writer
failure before and after replacement, secrets, schema replay, and both bootstrap
paths. Fault injection edits only disposable fixture code or creates test-database
triggers; production code has no fault-injection switch. The Compatibility matrix
runs this coverage on both supported MySQL and MariaDB floor/reference targets.

## Admin UI reuse

The closest workflow is Admin Upgrade and its operations history. Reuse
`assets/admin-operations.css`, the shared header/footer, responsive record cards,
fact lists, status text, and native details disclosures. Detail and JSON are actions
within the existing Upgrade route. No new visual component or JavaScript is needed.
Empty, blocked, failed, recovery-required, unavailable, and complete states use
explicit wording. Long IDs wrap with the existing technical-value class. Keyboard
navigation uses normal links and native disclosures on desktop, tablet, and phone.

## Release scope

This contained upgrade/deployment improvement is PATCH-level under Bonumark's
pre-1.0 policy. Recommended next version: 0.8.3, subject to later release approval.
Implementation does not change version markers or regenerate the release manifest.

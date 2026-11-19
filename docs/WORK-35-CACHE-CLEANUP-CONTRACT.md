# Work 35: remote-cache cleanup contract

Recorded before changing the cleanup function. Starting source:
`e6fd5525c45adc3629cd9ea28066d0ce5b3e350f`.

## Existing behavior and intended retention

| Record or condition | Contract in the starting source |
| --- | --- |
| Blocked object | Clear HTML, text and metadata; retain its identity and other fields. |
| Active object with an actor block | Mark blocked and clear the same fields. The cleanup query matches actor blocks, not domain strings. Domain blocking is handled by the existing blocking operation. |
| Expired active object | Eligible only when `expires_at < UTC_TIMESTAMP() - INTERVAL 30 DAY`, not merely after its short fetch-cache expiry. |
| Accepted Following | Protect every cached object associated with that remote actor ID. Other Following states do not confer this protection. |
| Owner interaction | Any matching `target_object_uri` protects the object, including undone interactions. No state filter exists. |
| Reply target | Any matching `remote_object_id` protects the object. The non-null key is `post_id`, not `id`. |
| Deleted-object tombstone | Neither cleared nor deleted, including when its actor is blocked. |
| Remote actor and relationship records | Cleanup never deletes or rewrites these tables. It operates only on remote objects. |
| Limits | Default 200, clamped to 1 through 1000. Each phase has its own limit, not one shared mutation budget. Deletion candidates are ordered by object ID. |
| Repeated execution | Deleted eligible objects disappear from subsequent candidates. Existing blocked rows can be revisited and their `updated_at` refreshed. This existing update policy does not promise that a blocked backlog drains between calls. |
| Counts | PDO affected rows: blocked updates include state/content/timestamp changes. Deletions count actual deleted rows, not selected IDs. |
| Failures | Existing exceptions reach the Admin handler, which logs and flashes an error, then redirects. The starting function permits partial writes before the invalid SELECT fails. |

Sources examined: `activitypub-operations.php`, `activitypub-owner.php`,
`activitypub-interactions.php`, `activitypub.php`, Admin controller/view,
migrations 0025/0026/0028 and the relevant foundation schema, database connection,
ActivityPub suites and Settings HTTP suite. PDO uses native prepared statements
and exception mode. The relevant tables are created with InnoDB.

## Bounded correction and transaction contract

Replace the invalid anti-join check with `r.post_id IS NULL`. Preserve the
existing predicates, ordering, limits, update fields and PDO counts.

Follow the existing Page update convention: start a transaction if none is
active; otherwise create a unique savepoint. Commit or release only after both
phases succeed. On any exception roll back the owned transaction, or roll back
to and release the cleanup savepoint. Rethrow; never return success counts after
a failure. A caller owns the final commit when cleanup runs inside its transaction.

Tests must verify a failure after blocked content changes, a failure during
deletion, transaction ownership, caller changes surviving savepoint rollback,
and retry after fault removal. No migration, federation request, delivery worker,
retention change or Admin presentation change is needed for this correction.

## Starting failure

The original query reads `r.id IS NULL`, but migration 0026 defines
`activitypub_reply_targets(post_id PRIMARY KEY, ...)` without an `id` column.
The blocked UPDATE runs before this SELECT and outside a transaction. The
dedicated negative control retains the exact original function as a test-only
fixture so all compatibility engines can prove both the SQL error and the
persisted earlier write. Full before/after synthetic records are emitted.

## Approved safety extension, November 19, 2026

The following supersedes the starting behavior where specified:

- Select only blocked records with remaining HTML/text/metadata, or active
  records belonging to explicitly blocked actors. Exact byte checks include
  whitespace and noncanonical empty metadata. Already-cleared rows retain their
  timestamps and consume no work. Clearing is ordered by object ID.
- Select at most the clamped limit of clearing IDs, then update only those IDs
  with a current-state recheck. This bounds write locks as well as mutations.
- Independently exclude explicit actor blocks from deletion candidates. This
  applies even if an object's lifecycle remains active because clearing did
  not reach it.
- Reuse all eligibility joins and predicates in the final DELETE, including
  actor blocks, accepted Following, any owner interaction, reply targets,
  active lifecycle and the unchanged expiry grace. The bounded ID list limits
  deletions; it is not permission to ignore newly committed protection.
- The final InnoDB DELETE rechecks current state. Tests commit changes through
  a second native connection between selection and deletion, at both REPEATABLE
  READ and READ COMMITTED. A concurrent protective change either prevents the
  deletion or causes a database concurrency exception; an exception rolls back
  cleanup and reaches the caller without success counts. A later retry uses
  current state. No automatic retry loop or isolation-level change is added.
- Database deadlocks can abort an entire caller transaction under normal InnoDB
  semantics. Cleanup never conceals such an exception or commits caller work.
- Counts are actual changed rows and actual deletions. Exhausted clearing work
  remains idempotent even as the clock advances.

All retention periods, limit values, domain-block policy, migrations, federation
behavior and Admin presentation remain unchanged. This is not a guarantee that
an actor blocked after a completed deletion can recover an already-expired
object. It prevents clearing-limit exhaustion and stale candidate selection
from authorizing deletion of a currently protected object.

### Reproducible negative controls

Run `php scripts/activitypub-cache-cleanup-test.php --negative-control` with the
same disposable database environment used by the other runtime suites. This
loads the exact unchanged baseline cleanup function and proves error 1054 plus
the earlier persisted blocked-content update.

The stock baseline cannot reach DELETE because of that error. Run
`--retention-negative-control` to isolate the second defect with **only**
`r.id IS NULL` changed to `r.post_id IS NULL` in the test-only baseline function.
No retention or transaction correction is applied to this control. It proves
that an already-cleared early row consumes limit 1 and that the next blocked
actor's expired active object is wrongly deleted ahead of an ordinary object.
The positive suite uses the identical fixture and requires the opposite result.
Do not describe this column-only control as an unmodified stock-schema deletion.

The full suite preserves the prior 83 independent checks, updating the
now-authorized idempotent timestamp expectation, and adds the retention fixture,
mixed batches, exact-byte clearing cases, second-connection interleavings and
retry verification. The historical negative-control fixture is never required
by application code. Test-only HTTP failure injection uses a disposable FK guard,
not a production migration or a privileged server setting.

### Database references

- [MySQL 8.4 transaction isolation and current locking reads](https://dev.mysql.com/doc/refman/8.4/en/innodb-transaction-isolation-levels.html)
- [MariaDB transaction isolation and locking](https://mariadb.com/docs/server/reference/sql-statements/administrative-sql-statements/set-commands/set-transaction)

Engine-level behavior is also tested on each supported compatibility target;
the implementation does not rely on SQL mocks or source-string assertions alone.

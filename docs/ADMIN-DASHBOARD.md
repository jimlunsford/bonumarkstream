# Dashboard state contract

Dashboard is a read-only presentation of owner review work and the next saved schedule. It uses the existing Admin shell and route guards. It is not a health monitor, publishing workspace or management directory.

## Included sources

| Source | Successful nonempty result | Successful empty result | Unavailable result | Destination |
| --- | --- | --- | --- | --- |
| Comments | Count of local comments with `status = pending` | Explicit count `0` | `state = unavailable`, `count = null` | `comments.php?status=pending` |
| Commenter approvals | Count with `role = commenter`, `status = pending`, and verified email | Explicit count `0`; pending email verification does not count | `state = unavailable`, `count = null` | `users.php?status=pending` |
| Scheduled content | All scheduled Stream records; earliest saved UTC due time, ordered by ID for ties | Empty scheduled set, not an empty future-only subset | `state = unavailable`, `count = null`, no preview | `content.php?status=scheduled` |

`_bonumark_stream/app/dashboard.php` queries each source independently. Database exceptions and failed query returns produce unavailable results. Compatibility helpers that return zero or an empty array on error are not used. Technical exception details are never included in the page.

## Presentation

- **Needs attention:** independently conditional pending-comment and owner-approval rows. A saved timestamp at or before the current instant adds **Scheduled content needs review**, its saved title and time, and a management link. This does not diagnose scheduler failure.
- **Scheduled next:** shown only when the earliest saved due time across the whole scheduled set lies in the future. The saved title (or body excerpt for an untitled record), date, time, configured IANA timezone and date-appropriate abbreviation are displayed. Other records remain on the Scheduled list.
- **Quiet:** **Nothing awaiting your review** requires all three sources to be included, successfully queried and empty. Its three absence statements are scoped to these sources.
- **Unavailable:** names each failed source with a management link. Independently verified work and empty results remain visible. No full quiet statement appears.
- **Missing or invalid due time:** the existing record prevents quiet and requires review. No date is invented. Such a record takes priority over a future preview until its saved schedule can be interpreted.

Count results have `state` (`populated`, `empty`, `unavailable`) and `count` (integer or null). Scheduled results also have `next` (record or null) and `needs_review` (boolean). A valid preview carries an immutable `due` value converted from UTC to the site's configured zone. This is request-time presentation, not a stored status or telemetry record.

## Preservation boundaries

The Dashboard calls the existing shared header before reading its sources, so ordinary header-triggered scheduled publication completes before the displayed snapshot is taken. The scheduler, heartbeat, configuration, permissions, schema, public frontend and existing editing routes are unchanged. The shared approval banner is omitted on Dashboard only because its failure-aware approval row owns that task; other Admin screens retain the banner. A visit to Admin can still run scheduled work under the established rules.

CSS uses the existing tokens in `assets/admin-shell.css`. Contextual links have 44px targets and native keyboard behavior. The dormant secondary light palette uses the same structure; no appearance selector, stored preference or public-theme coupling is introduced. Legacy metric styles remain because Analytics still uses them.

## Verification

Run `php scripts/admin-dashboard-runtime-test.php` with the existing disposable database environment (`BMS_DB_HOST`, `BMS_DB_NAME`, `BMS_DB_USER`, `BMS_DB_PASS`, `BMS_DB_DANGER_RESET=1`). It creates random `bms_dashboard_ci_*` tables and a temporary application/server, and removes only that fixture.

The test uses actual HTTP authentication, all required populated/empty combinations, real SQL failures caused by temporarily renamed fixture columns, whole-database row snapshots, denied guest/commenter access, and date-specific timezone assertions. Existing scheduler lock contention keeps saved-record snapshots stable without replacing scheduler code or execution rules. A separate unlocked case proves automatic publication still works and the Dashboard does not render a stale pre-header snapshot. The four-job compatibility matrix includes this regression alongside the existing application tests.

Real Chrome review must use a disposable installation too. Cover desktop, mobile, narrow and intermediate widths, both palettes, 200% text, keyboard/focus, drawer behavior, long titles and no-JavaScript navigation. Chrome-only testing does not establish full accessibility conformance.

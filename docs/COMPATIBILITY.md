# Hosting and database compatibility

Bonumark Stream is designed for standard self-hosted PHP environments rather than one control panel, Linux distribution, or web server.

## Documented floors

Core compatibility targets:

- PHP 8.1+
- MySQL 8.0+
- MariaDB 10.6+
- PDO MySQL

For production, use PHP and database releases that still receive vendor security updates. Optional capabilities such as cURL, ZipArchive, GD/Imagick, and Fileinfo are reported separately by **Admin → System Check**. mbstring is optional: Bonumark uses it when available and falls back to core string operations when it is absent.

## Continuous compatibility matrix

The repository workflow under `.github/workflows/compatibility.yml` exercises the documented floors and newer reference targets:

| PHP | Database | Purpose |
| --- | --- | --- |
| 8.1 | MySQL 8.0 | Documented PHP/database floor |
| 8.1 | MariaDB 10.6 | Documented PHP/database floor |
| 8.3 | MySQL 8.4 | Newer MySQL LTS reference |
| 8.3 | MariaDB 11.4 | Newer MariaDB LTS reference |

Each matrix job first creates a clean tracked source snapshot with `git archive HEAD`. The test tree therefore contains repository-managed package files but not `.git/` checkout metadata, matching the release-manifest package boundary.

Each matrix job then runs:

- PHP syntax checks
- JavaScript syntax checks
- JSON validation
- Markdown relative-link validation
- public interaction, Admin UI, public semantics, timestamp (RSS/sitemap), and Unicode metadata regression tests, including the no-mbstring fallback
- the clean-package smoke test
- all ActivityPub Stages 1 through 7 and Stage 6.5 regression suites
- the Admin comments runtime regression test
- the disposable database smoke test covering both current fresh-install schema creation and historical supported-upgrade migration replay
- the migration-recovery smoke test
- the disposable Remote Posting API database smoke test

Release-branch pushes are held to the strict package boundary. The workflow does not allow the source-branch manifest exemption on `release/**`. It verifies the final release manifest, builds the canonical versioned ZIP from the commit, extracts it into a clean directory, and repeats PHP, JavaScript, JSON, package, ActivityPub, migration, and Remote API validation against the extracted archive on all four matrix combinations. The PHP 8.3/MySQL 8.4 job also retains the validated ZIP as a short-lived workflow artifact for release-candidate inspection.

The database smoke tests create only randomly prefixed temporary tables inside the CI database and remove them afterward. The schema test deliberately keeps two paths separate: it runs the current fresh-install path through `bms_install_schema()`, then independently starts from a verified historical v0.4.x baseline and applies only the migrations that follow that baseline. This prevents the cumulative current `0001_initial_schema.php` from being misused as an old installation state. The upgrade fixture retains representative owner, migrated Profile, post, media, comment, local Like, and setting records. It also checks the normal default-off ActivityPub state and preservation of a deliberately enabled prerelease ActivityPub state through the later federation migrations.

The Remote Posting API database suite builds a disposable installed site for each scenario. Its release path also runs the read-only installed-site deployment check and requires matching version markers, package hashes, zero obsolete managed files, zero pending migrations, supported database status, and clear migration-recovery state.

## Web servers

Apache and LiteSpeed use the shipped `.htaccess` routing and deny rules. Nginx has a maintained configuration example under `docs/server/`. Other servers are supported when they provide equivalent clean-route handling, Authorization forwarding where needed, PHP execution, and direct HTTP denial for `_bonumark_stream/` and `scripts/`.

System Check verifies the live deployment separately. In particular, **Public URL mode** performs a read-only request to Bonumark's clean `/api/v1/status` route, and **Private folder exposure** performs a read-only request against the private VERSION marker.

## Locked-down application trees

Bonumark supports deployments where PHP can write runtime storage but cannot replace application code or themes. On hosts with shell access, software upgrades should normally run as the application owner through the first-class owner-run workflow:

```sh
php scripts/deploy-update.php --check /path/to/bonumark-stream-vX.Y.Z.zip
php scripts/deploy-update.php /path/to/bonumark-stream-vX.Y.Z.zip
```

The helper uses the same core upgrade engine as Admin → Upgrade, does not elevate privileges, and automatically runs the installed-site deployment check after success. The lower-level `scripts/run-migrations.php` and `scripts/deployment-check.php` helpers remain available for manual/hosting-layer deployments and recovery work.

When a manual deployment introduces database migrations, back up the database and use the explicit owner-run migration command documented in `docs/server/MANUAL-DEPLOYMENT.md` before considering the deployment complete.

### Remote API HTTP fixture startup

The two Remote API HTTP launchers use `scripts/api-test-server.php`. Readiness
requires HTTP 200 with the exact per-launch random nonce and child PID, plus a
live child before and after the probe. A temporary router handles only the
random readiness path; existing application requests and assertions are unchanged.
The publishing fixture still serves ordinary PHP files, and the ActivityPub
fixture still routes application requests through its original index.php.

There are at most four launch attempts, a two-second readiness deadline per
attempt, and a twelve-second overall startup deadline. Reads have absolute
150 ms deadlines. Only an exited child whose own log confirms an address-in-use
bind failure permits another launch. Incorrect identities, other child exits,
configuration errors, router errors and timeouts fail. Application assertions
are outside the startup loop and are never retried. The helper disables inherited
PHP CLI workers, closes stdin, terminates and reaps only its direct child, and
removes its private router/log directory. Failed startup logs and application
failure logs are emitted before removal. It does not terminate port owners.

Every compatibility job runs:

- `php scripts/api-test-server-test.php`, deterministic collision, ownership,
  exhaustion, invalid launch, router failure, silent responder, assertion-failure
  and repeated cleanup coverage.
- `php scripts/api-test-server-negative-test.php`, an instrumented baseline
  launcher reproduction and an HTTP-only-readiness mutation rejected by the same
  regression suite.
- `php scripts/api-database-smoke-test.php`, the unchanged application scenarios.
- `php scripts/api-test-server-integration-test.php`, the complete suite in a
  disposable source copy with the first port forced to a controlled HTTP listener
  for both launchers. It requires exactly two confirmed collisions and verifies
  that no application requests reached the foreign listener.

The baseline fixture is extracted from commit
`358871f48a5940cf804f6039e900716482607599`. Its disclosed instrumentation replaces
the random port with a supplied port, replaces application assertions with a
callback, and captures the log before deletion. It preserves the old readiness
loop and process handling. The integration test edits only port selection and
diagnostic capture in its temporary copy. Neither test adds a production endpoint,
application fault switch or database bypass. The earlier connection-refused
reports are not assigned this cause without separate evidence.

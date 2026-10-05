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
- the clean-package smoke test
- all ActivityPub Stages 1 through 7 and Stage 6.5 regression suites
- the Admin comments runtime regression test
- the disposable database smoke test covering both current fresh-install schema creation and historical supported-upgrade migration replay
- the migration-recovery smoke test
- the disposable Remote Posting API database smoke test
- Connect site authorization, migration-0030 retry, one-use code races, owner binding, rollback, revocation and HTTP-flow tests

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

## Isolated Connect service verification

`AGENTS.md` and the entire `services/` directory are excluded from normal Stream
archives. Compatibility compares both tar and canonical ZIP file lists against
that exact exclusion, including every other tracked package file. The source-tree
smoke exemption never regenerates a public manifest.

The independent `.github/workflows/connect-service.yml` checks the Node 24 relay
with both MySQL 8.0 and MariaDB 10.6. It verifies pinned dependency integrity,
production dependency audit, syntax, encryption, SSRF/DNS pinning, real TLS limits,
account/session binding, real PHP authorization, browser layout at desktop/tablet/
phone sizes, revocation, clean service packaging and committed-secret patterns.
Its dependencies and test fixtures never enter the ordinary Stream distribution.

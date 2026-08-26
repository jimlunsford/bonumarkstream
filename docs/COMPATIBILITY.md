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
- the clean-package smoke test
- the disposable migration/schema database smoke test
- the disposable Remote Posting API database smoke test

The database smoke tests create only randomly prefixed temporary tables inside the CI database and remove them afterward.

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

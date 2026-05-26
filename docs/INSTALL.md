# Installing Bonumark Stream

Bonumark Stream v0.3.11 is the public GitHub fresh-install foundation baseline. Use a clean install instead of upgrading an old v0.1.x test site.

Bonumark Stream is built for ordinary PHP and MySQL shared hosting.

## Requirements

- PHP 8.2 or newer
- MySQL 5.7 or newer, or MariaDB 10.3 or newer
- PDO MySQL extension
- ZipArchive extension for upgrades, exports, and theme ZIP uploads
- mbstring recommended
- Apache rewrite support recommended
- outbound HTTPS optional, used by remote media import and link previews

## Fresh install

1. Upload the package contents to the target web directory.
2. Visit `install.php` in the browser.
3. Enter database credentials and site settings.
4. Create the first administrator account.
5. Complete the installer.
6. Remove or lock the installer when prompted.
7. Sign in at `/admin/`.
8. Review Settings, Site Identity, Reading, Writing, Mail, Registration, Themes, and Navigation. Site Identity tagline supports plain text and safe links.

## Files that should not be public in source control

The repository `.gitignore` excludes local configuration, runtime content, backups, generated pages, uploaded media, logs, archives, and editor files. Do not commit live secrets or user content.

## After install

Recommended first checks:

- run the System Check screen
- verify the public homepage loads
- create a test post
- upload a test media item
- use Tools > Export > Static Site Export only if you want a portable downloadable HTML copy
- configure mail only if registration, password reset, or notification features require it
- open Appearance > Navigation if you want to display a public menu
- verify `/sitemap.xml` and `/robots.txt` load after you publish content


## Private exports

Database and full export ZIPs are private backup files. Store them outside the public web root and do not share them publicly because they may contain password hashes, account records, email addresses, invite/reset records, and security logs.


## Image metadata

Bonumark reads local image dimensions when available so public pages can output stable image dimensions without requiring responsive derivatives or server-level image tools.


## Private directory protection

Bonumark Stream stores configuration, migrations, backups, temporary files, and private data under `_bonumark_stream/`. The package includes Apache `.htaccess` denial files, but shared hosting must actually honor them. If the host uses Nginx, IIS, LiteSpeed with custom rules, or a control-panel proxy layer, configure equivalent denial rules before public launch. The System Check screen should be used after install to confirm the private folder is not publicly exposed.

## Remote fetch requirements

Link previews and remote media imports use safety checks for scheme, host, port, DNS resolution, redirects, and connected IPs. cURL is the supported transport for those optional remote-fetch features because it allows address pinning and post-connect validation. The rest of Bonumark Stream can run without remote-fetch features if cURL is unavailable.

## Release validation database smoke test

Before tagging a public release, run the database smoke test against a disposable MySQL or MariaDB database:

```bash
BMS_DB_HOST=localhost \
BMS_DB_NAME=bonumark_test \
BMS_DB_USER=bonumark_test \
BMS_DB_PASS='password' \
BMS_DB_DANGER_RESET=1 \
php scripts/database-smoke-test.php
```

The script creates temporary `bms_ci_*` tables, runs every bundled migration, verifies the migration ledger, then drops only those temporary tables. Do not point it at a database unless the supplied user has permission to create and drop temporary test tables.

# Install Bonumark Stream

Bonumark Stream v0.5.0 is a fresh-install public development release.

## Requirements

- PHP 8.1 minimum.
- PHP 8.2 or newer recommended.
- MySQL or MariaDB.
- PDO MySQL extension.
- Apache or LiteSpeed with `.htaccess`, or equivalent routing rules on another server.

## Steps

1. Upload the package contents to the target web root or subdirectory.
2. Visit `install.php`.
3. Confirm the server checks.
4. Enter the database connection details.
5. Create the sole Admin account.
6. Finish installation.
7. Log in at `/admin/`.

The installer creates an empty site. It does not publish sample posts or pages.

## Private files

The `_bonumark_stream/` directory is protected by `.htaccess` on Apache and LiteSpeed. Nginx users must add equivalent deny rules for `_bonumark_stream/`, config files, backups, data, and temp folders.

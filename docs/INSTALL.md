# Install Bonumark Stream

Bonumark Stream v0.5.30 is a fresh-install public development release.

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

The `_bonumark_stream/` directory and `scripts/` directory are protected by `.htaccess` on Apache and LiteSpeed. Nginx and other non-Apache servers must add equivalent deny rules for `_bonumark_stream/`, `scripts/`, config files, backups, data, and temp folders. Do not expose shipped CLI test scripts through the web server.


## Install as app after setup

After installation, open **Admin → Settings → Stream** to confirm the installable app and mobile share settings.

When PWA support is enabled, Bonumark Stream exposes `manifest.php`, a conservative service worker, and install icons. A favicon selected in Admin → Site Identity becomes the installed-app icon source. Servers with GD or Imagick receive generated versioned 192 × 192 and 512 × 512 PNG icons. Servers without those extensions use the selected favicon directly with its real image type and dimensions. Use a square 512 × 512 PNG for best results. The bundled Bonumark B remains the fallback only when no usable favicon is selected. Supported browsers may show an install option from the browser menu.

When mobile share target support is enabled, supported browsers can share text and URLs into Bonumark Stream. Shared content enters through the secure share-target route, requires login, then redirects to the public stream with the front-end composer prefilled so the user can review and press Post.

Image/file sharing through Web Share Target is not enabled in this release. Once shared text or URLs reach the front-end composer, the Admin can either post now or schedule the post for later.

## Remember this device

Bonumark Stream supports app-friendly login persistence through a Remember this device checkbox on login forms. The feature stores a rotating device token in the database, keeps the cookie HttpOnly, uses SameSite=Lax, uses Secure on HTTPS, and revokes remembered devices on logout or password changes. The default remembered-device window is 30 days and can be adjusted in Settings > Stream.

## Pinned posts

After publishing a stream post, open the front-end three-dot **Post options** menu and choose **Pin to Stream**. The same compact, left-aligned menu holds the front-end Edit action. Pinning is also available from the back-end editor Publish card and **Admin → Stream Posts**. Use the same action to unpin it. Multiple pinned posts are ordered by their most recent pin time and appear above the normal homepage timeline.

Pins do not change a post’s original publish date or its place in RSS, sitemap, search, archives, or static exports. Scheduled posts cannot be pinned until they publish. Moving a pinned post to draft, scheduled, or trash removes it from the public pinned area.

## Scheduled posts

The front-end composer and back-end editor both support scheduled stream posts. In the back-end editor, Save Draft and Post Now stay primary, while Schedule for later reveals the date/time field only when needed. Schedule fields display the configured site timezone, while Bonumark stores the canonical scheduled time in UTC.

Open **Admin → Settings → Scheduled Tasks** after install. Server cron is the recommended runner. Shared-hosting and external services can use the protected web cron endpoint. Public traffic and signed-in browser heartbeat checks remain optional fallback paths. The same shared task runner handles every path and records health plus manual/cron history.

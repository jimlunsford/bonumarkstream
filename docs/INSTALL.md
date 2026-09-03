# Install Bonumark Stream

Bonumark Stream v0.8.0 is the current release package.

## Runtime storage contract

During installation, Bonumark Stream prepares the runtime directories it needs and verifies that the PHP process can write them. The same directory definition is used by Admin → System Check, so installation and diagnostics report the same hosting contract. System Check is read-only and does not create missing directories.

This includes private data and temporary storage, exports, upgrade staging/backups, Markdown import and content-version storage, private import staging/previews, and the public `media/` directory. A hosting provider does not need to use any specific Unix user or ownership model; the requirement is simply that the PHP process can write the reported runtime paths while private paths remain blocked from public HTTP access.

## Requirements

Core requirements:

- PHP 8.1 minimum.
- PHP 8.2 or newer recommended.
- MySQL 8.0+ or MariaDB 10.6+.
- PDO MySQL extension.
- For production, use a database release that is still receiving vendor security updates.
- Writable runtime storage as reported by the installer/System Check.
- Web-server routing for Bonumark clean URLs.
- Web-server protection that blocks `_bonumark_stream/` and `scripts/` from public HTTP access.

Optional feature capabilities:

- PHP cURL: safe link previews, remote media import, and the preferred HTTP diagnostic transport.
- ZipArchive: Admin ZIP upgrades, Admin theme ZIP installation, and ZIP-based export features.
- GD or Imagick: stronger image privacy processing, responsive derivatives, and generated PWA icons.
- Fileinfo: preferred MIME validation for uploads.
- mbstring: improves Unicode-aware text operations when available; Bonumark includes core fallbacks when it is absent.

Apache and LiteSpeed use the included `.htaccess` files. Nginx users should start with [`server/NGINX.md`](server/NGINX.md) and the shipped `server/bonumark-stream-nginx.conf` example. Other servers need equivalent routing and deny rules. The database step identifies the connected MySQL/MariaDB family and version and refuses fresh installation below the documented compatibility floor.
See [`COMPATIBILITY.md`](COMPATIBILITY.md) for the maintained PHP/database matrix and CI coverage.

## Steps

1. Upload the package contents to the target web root or subdirectory.
2. Visit `install.php`.
3. Confirm the server checks.
4. Enter the database connection details.
5. Create the sole Admin account.
6. Finish installation.
7. Log in at `/admin/`.

The installer creates an empty site. It does not publish sample posts or pages.

## Private-storage verification during install

Bonumark performs a read-only HTTP request against the private `_bonumark_stream/VERSION` marker before creating the site. A deliberate `403`, protected `404`, `401`, or `410` is treated as protected. Exact retrieval of the private VERSION marker blocks installation.

If the HTTP probe is inconclusive because the host blocks loopback requests, redirects unexpectedly, or does not provide an available HTTP probe transport, Bonumark **does not silently assume the private directory is safe**. The Site Setup step requires an explicit confirmation that the administrator independently verified `/_bonumark_stream/` cannot be read over HTTP. Apache/LiteSpeed should process the shipped `.htaccess`; Nginx should use the maintained configuration under `docs/server/`; other servers need equivalent deny rules.

## Fresh install on a locked-down application tree

A locked-down application tree is supported, but installation itself must be able to create the site config and installed-state marker once. Use this capability-based sequence rather than copying a specific Unix username or group:

1. Deploy the Bonumark application files as the application owner.
2. Configure the web server so `_bonumark_stream/` and `scripts/` are blocked before visiting `install.php`.
3. Give the PHP/web identity the minimum temporary write access needed to create `_bonumark_stream/config.php`, `_bonumark_stream/installed.lock`, and the runtime directories reported by the installer. Do not make the whole application tree permanently writable.
4. Run `install.php` and complete database/site setup.
5. Ensure the runtime paths remain writable to PHP, including `_bonumark_stream/data/`, `_bonumark_stream/tmp/`, import/version/upgrade storage, and public `media/`.
6. Remove the temporary PHP write access to application/config creation paths so package-managed code returns to the locked-down owner-controlled model.
7. From the site root, run `php scripts/deployment-check.php`.
8. Open **Admin → System Check** and resolve core failures. A warning for web-based software upgrades can be intentional on a locked-down installation because future software updates can run as the application owner through `php scripts/deploy-update.php /path/to/release.zip`. Theme ZIP installation may still require the manual theme workflow.

The web/PHP identity never needs sudo, a privileged helper, or permanent ownership of the application code.

## Media privacy

Bonumark Stream randomizes public filenames for newly uploaded media. Supported image uploads are re-encoded to remove metadata when the server has the needed PHP image support. Best-effort mode is the default and warns when metadata removal cannot be confirmed. Strict privacy mode is available after install under **Admin → Settings → Writing**. No shell tools, SSH, Composer, npm, or server packages are required.

## Private files

The `_bonumark_stream/` directory and `scripts/` directory are protected by `.htaccess` on Apache and LiteSpeed. Nginx deployments should use the maintained example under `docs/server/`; other non-Apache servers must add equivalent deny rules. Do not expose shipped CLI test scripts through the web server. After installation, **Admin → System Check** should report both **Private folder exposure** and **Public URL mode** as passing.


## Install as app after setup

After installation, open **Admin → Settings → Stream** to confirm the installable app and mobile share settings.

When PWA support is enabled, Bonumark Stream exposes `manifest.php`, a conservative service worker, and install icons. A favicon selected in Admin → Site Identity becomes the installed-app icon source. Servers with GD or Imagick receive generated versioned 192 × 192 and 512 × 512 PNG icons. Servers without those extensions use the selected favicon directly with its real image type and dimensions. Use a square 512 × 512 PNG for best results. The bundled Bonumark B remains the fallback only when no usable favicon is selected. Supported browsers may show an install option from the browser menu.

When mobile share target support is enabled, supported browsers can share text and URLs into Bonumark Stream. Shared content enters through the secure share-target route, requires login, then redirects to the public stream with the composer prefilled so the user can review it and choose Post, Schedule, Save draft, or Continue in full editor.

Image/file sharing through Web Share Target is not enabled in this release. Once shared text or URLs reach the stream composer, the Admin can post, schedule, save a draft, or continue in the full editor.

## Remember this device

Bonumark Stream supports app-friendly login persistence through a Remember this device checkbox on login forms. The feature stores a rotating device token in the database, keeps the cookie HttpOnly, uses SameSite=Lax, uses Secure on HTTPS, and revokes remembered devices on logout or password changes. The default remembered-device window is 30 days and can be adjusted in Settings > Stream.

## Pinned posts

After publishing a stream post, open the front-end three-dot **Post options** menu and choose **Pin to Stream**. The same compact, left-aligned menu holds the front-end Edit action. Pinning is also available from the back-end editor Publish card and **Admin → Stream Posts**. Use the same action to unpin it. Multiple pinned posts are ordered by their most recent pin time and appear above the normal homepage timeline.

Pins do not change a post’s original publish date or its place in RSS, sitemap, search, archives, or static exports. Scheduled posts cannot be pinned until they publish. Moving a pinned post to draft, scheduled, or trash removes it from the public pinned area.

## Scheduled posts

The stream composer schedules new Stream Posts. The full editor reschedules saved posts, publishes drafts immediately, or cancels scheduled posts back to drafts. Schedule fields display the configured site timezone, while Bonumark stores the canonical scheduled time in UTC.

Open **Admin → Settings → Scheduled Tasks** after install. Server cron is the recommended runner. Shared-hosting and external services can use the protected web cron endpoint. Public traffic and signed-in browser heartbeat checks remain optional fallback paths. The same shared task runner handles every path and records health plus manual/cron history.

## Optional ActivityPub hosting

ActivityPub is disabled by default. Before enabling it, confirm **Admin → System Check** reports a canonical root-level HTTPS URL, domain-root WebFinger routing, OpenSSL, cURL, a protected signing-key secret, and dependable server cron or protected web cron. Reverse proxies must forward the signed federation and digest headers listed in [ACTIVITYPUB.md](ACTIVITYPUB.md). Subdirectory installs require hosting-level domain-root WebFinger mapping and are not automatically accepted. Keep package-managed application code read-only to PHP and grant write access only to the normal Bonumark runtime paths.

After every requirement passes, open **Admin → ActivityPub**, provision the signing key, verify key health, and then enable federation. WebFinger advertises the single owner actor. Normal publishing can then send Create, Update, and Delete activities after local commits, and accepted remote relationships can appear in the private chronological Following experience.

Pause and delivery suspension are reversible maintenance controls. Permanent federation deactivation is not reversible: it sends Actor Delete, permanently retires the current actor URI, removes it from WebFinger, and prevents it from being enabled again. Bonumark v0.8.0 does not create a replacement actor identity.

## Locked-down deployments

If System Check later reports that PHP cannot replace application files, keep the code tree locked and use the owner-run `php scripts/deploy-update.php /path/to/release.zip` workflow when shell access is available. Use the supported [manual software deployment](server/MANUAL-DEPLOYMENT.md) fallback when shell access is unavailable. If PHP cannot install theme ZIPs, use the [manual theme deployment](server/MANUAL-THEME-DEPLOYMENT.md) workflow. The read-only `php scripts/deployment-check.php` helper remains available for independent installed-site verification.

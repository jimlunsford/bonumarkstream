# Scheduled Tasks

Bonumark Stream uses one server-side Scheduled Tasks runner for work that must happen after a chosen time. Scheduled posts use it today. Future core features can use the same runner without creating separate cron systems.

## Choose a runner

Open **Admin → Settings → Scheduled Tasks**.

Bonumark Stream supports these execution paths:

- **Server cron, recommended:** Your host runs the local PHP script on a schedule. This is the most dependable option because it works even when nobody visits the site.
- **Web cron:** A hosting panel or external scheduler calls the protected `/api/v1/cron` endpoint using a generated cron key.
- **Public traffic fallback:** Safe public GET and HEAD requests may check for due work before rendering. Useful for active sites, but not dependable for quiet sites.
- **Browser heartbeat fallback:** A signed-in admin or front-end composer checks for due work every 30 seconds while the page is open. Useful while you are working, but not dependable on its own.
- **Manual run:** Admins can run tasks immediately for testing or troubleshooting.

All paths call the same task runner and share one lock, so the same due task cannot be processed twice just because two execution paths run close together.

## Run-history layout

The Run history table uses its own five-column layout for **When**, **Source**, **Result**, **Published**, and **Details**. It does not share the Stream Posts list layout, so task history remains aligned as more task types are added.

## Server cron

Use the schedule and command shown in **Admin → Settings → Scheduled Tasks**. A five-minute example looks like this:

```cron
*/5 * * * * php /absolute/path/to/bonumark-stream/scripts/run-scheduled-tasks.php >/dev/null 2>&1
```

Use a one-minute interval when scheduled posts need to publish as close as possible to their selected time. Five minutes is a practical default. Fifteen minutes is often enough for future continuity or reminder-style features.

The script is CLI-only. A browser request to `scripts/run-scheduled-tasks.php` is refused, and the scripts folder is protected by its own server rules.

Some hosts require a full PHP binary path, such as `/usr/local/bin/php` or `/opt/cpanel/ea-php82/root/usr/bin/php`. Use the PHP path shown by your host if `php` alone does not work.

## Web cron

Web cron is intended for shared hosting or external services that cannot run a local PHP command.

1. Open **Admin → Settings → Scheduled Tasks**.
2. Choose **Enable Web Cron and Generate Key**.
3. Copy the key immediately. Bonumark Stream stores only a hash and cannot display the key again.
4. Configure your host or external service to call the endpoint at the interval you selected.

Preferred authentication uses a request header:

```bash
curl -fsS -H 'X-Bonumark-Cron-Key: YOUR_KEY' 'https://example.com/api/v1/cron'
```

Bearer authentication also works:

```bash
curl -fsS -H 'Authorization: Bearer YOUR_KEY' 'https://example.com/api/v1/cron'
```

A `?key=YOUR_KEY` query parameter is accepted only for limited third-party services that cannot send headers. Header authentication is safer because query parameters can be retained in logs.

Regenerating the web cron key immediately invalidates the old one. Disabling web cron removes the stored key hash.

## Health and history

The Scheduled Tasks screen records:

- last task run time
- last execution source
- last result
- expected interval
- whether public traffic and browser heartbeat fallbacks are active
- retained manual, server-cron, and web-cron run history

Traffic and heartbeat checks update health without filling the history table with constant background entries.

A runner is marked as needing attention when no successful task activity has been recorded within three expected intervals, with a minimum 15-minute threshold. This is a health signal, not proof that host cron is configured. Confirm the execution source shown on the page.

## Security

- Server cron runs locally and does not use a public key.
- Web cron is disabled by default.
- Web cron accepts only GET or POST requests.
- The web cron key is generated randomly, stored only as a password hash, and shown only once after creation.
- The cron endpoint returns only task-run status. It does not expose site settings, task configuration, or private content.
- The task runner uses a lock to prevent overlapping runs.

## What Scheduled Tasks does today

Today, the runner publishes due scheduled stream posts. It is intentionally a reusable core system, so future features can add their own due-task logic without relying on theme code, site traffic, or a separate scheduler.

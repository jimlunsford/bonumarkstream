# Privacy-First Analytics

Bonumark Stream includes an optional, self-hosted Privacy-First Analytics mode for basic publishing insight without identifying or following visitors.

Analytics is disabled by default on new installs and upgrades. An administrator must enable it from **Admin → Tools → Analytics**.

## What it reports

- Aggregate page views
- Page views by day
- Top Stream posts and pages
- Top entry-page paths
- Referrer domains only, such as `google.com`, `x.com`, `facebook.com`, or `Direct`
- Broad device categories: desktop, mobile, tablet, or other
- Broad browser families: Chrome, Safari, Firefox, Edge, or Other
- Sanitized `utm_source`, `utm_medium`, and `utm_campaign` totals

A page reload counts as a page view. Bonumark Stream intentionally does not estimate unique visitors, returning visitors, sessions, or time spent on a page.

## What it does not collect

Privacy-First Analytics does not use or store:

- Cookies, local storage, or session storage
- Visitor IDs, persistent identifiers, or browser fingerprints
- Unique-visitor or returning-visitor estimates
- Sessions, individual behavior trails, click tracking, scroll tracking, or time-on-page tracking
- Raw IP addresses, hashed IP addresses, raw user-agent strings, or user-agent hashes
- Full referrer URLs, referrer paths, query strings, fragments, usernames, or ports
- Arbitrary request parameters or search queries
- Private content, drafts, scheduled content, admin activity, account activity, login activity, APIs, feeds, sitemaps, cron, installer, or upgrader activity

Only eligible public reading routes load the collector. The collector is same-origin, accepts no read requests, and writes aggregate counters only.

## Data design

Analytics does not keep a per-view event log. It stores a daily aggregate counter keyed by the reporting date, clean public path, content type and slug, referrer domain, broad device category, broad browser family, and approved UTM fields. This supports useful aggregate reporting while avoiding visitor-level browsing records.

Query strings are stripped before a page path is stored. Referrers are reduced to the hostname only. UTM values are lowercased, length-limited, and restricted to safe campaign characters before storage.

## Retention, export, and deletion

The default retention period is 90 days. Administrators can select 30, 90, 180, 365, or 730 days. Cleanup removes expired rows from the analytics aggregate table only.

The Analytics screen can export the selected range as CSV. The CSV contains aggregate rows only and does not include IP addresses, raw user agents, cookies, session data, identifiers, or visitor-level records.

The clear-data action requires an explicit confirmation phrase and deletes only the analytics aggregate table.

## Themes

Analytics is core functionality. Bonumark Stream injects the optional collector from core only on eligible public reading routes. Themes do not need analytics JavaScript, templates, settings, custom hooks, or tracking code.

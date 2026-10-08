# Bonumark Connect service source

Gate 6A implements authorization and connection-health routing. This service has
not been deployed. The actual hostname, hosting identity and production account
provider are deliberately unset. Gate 6B owns deployment and restore acceptance;
Gate 6C owns the hosted development-site proof. Gate 7 has not begun.

## Stack and package decision

Node.js 24.19.0 or later in the 24.x LTS line, JavaScript ES modules, built-in
HTTP/TLS/crypto and node:test, pinned mysql2 and ipaddr.js, and dedicated MySQL 8.0+
or MariaDB 10.6+ storage. Transactional SQL fits the existing database/backup
architecture and proves one-use transitions across processes. Built-in HTTP avoids
an unnecessary application framework; JavaScript avoids a compilation/deployment
layer. Playwright is a test-only development dependency. No content database,
OAuth provider, MCP implementation or normal-site runtime dependency is introduced.

`services/` is repository-only and excluded by `.gitattributes` from Stream's
normal tar and ZIP archives. The Compatibility workflow compares both archives
against the complete expected file list. The independent service package contains
only `src`, schema, README, example configuration, package manifest and lockfile.
It excludes tests, node_modules, local configuration, keys, logs and database files.

## Development commands

```sh
npm ci --ignore-scripts
npm run check
npm test
npm audit --omit=dev --audit-level=high
npm run package
```

`package` verifies a clean production-only install from the lockfile, imports the
runtime modules, removes dependencies, then produces a deterministic source tarball
in the temporary directory (override with absolute `BMC_PACKAGE_PATH`). The separate
Connect Service CI exercises Node 24 with MySQL 8.0 and MariaDB 10.6, real PHP 8.1
site authorization, browser layout/accessibility contracts, TLS/SSRF tests, crypto,
account isolation, database races, a clean package and committed-secret checks.

Database integration is destructive and restricted to disposable databases named
`bonumark_ci` and `bmc_test`. It requires PHP with PDO MySQL, cURL and the normal
site prerequisites. Set `BMS_DB_HOST`, `BMS_DB_NAME=bonumark_ci`, `BMS_DB_USER`,
`BMS_DB_PASS`, `BMS_DB_DANGER_RESET=1`, `BMC_TEST_DB_USER`,
`BMC_TEST_DB_PASSWORD`, and optionally `BMC_TEST_DB_PORT`, then run
`npm run test:database`. Set `BMC_BROWSER_TEST=1` after
`npx playwright install chromium` for mandatory CI browser checks. A local test-only
`BMC_BROWSER_EXECUTABLE` override can select an installed browser. Screenshots are
optional through `BMC_UI_ARTIFACT_DIR`; traces and secret-bearing network archives
are not collected. TLS tests use an ephemeral local test CA only in a child test
process, never a runtime TLS bypass. Integration injects an explicit fixture
transport; production configuration cannot enable it.

## Configuration and operator boundary

`BMC_CONFIG_FILE` is an absolute path to private JSON, outside the checkout, with
mode 0600 or stricter and a maximum size of 16 KiB. Copy the *shape* of
`config.example.json`, then supply operator-selected values. The hostname shown is
only an example. `baseUrl` is a canonical HTTPS origin, `callbackUrl` is exactly
that origin plus `/callback`, and `clientId` must match the site's provisioned
registration. Runtime binds only `127.0.0.1`, default port 8088. Database host must
be local, database name starts `bmc_`, and the service requires a separate database
identity. No runtime schema migration or elevated privileges are used.

The separate private `keyFile` has shape `{"active":"version_name","keys":{...}}`.
Each key value is a securely generated 32-byte key encoded as 64 lowercase hex
characters. Do not store key material with the database or in git. Run operator
commands with the same explicit configuration:

```sh
node src/admin.mjs migrate
node src/admin.mjs create-account
node src/main.mjs
```

`create-account` returns an opaque UUIDv4 account ID and a random `bmca_` development
credential once to the operator terminal. Do not capture that command in service
logs. Share it only through an approved development credential channel. Account
credentials and browser sessions are stored as domain-separated SHA-256 hashes.
There is no anonymous account/site enrollment, public signup, billing or assumption
about undocumented ChatGPT account identifiers. Gate 11 owns the normal plugin
identity integration. `/login` accepts the development account credential only,
never the Bonumark owner password. Secure, HttpOnly, SameSite=Lax host-only cookies
expire after eight hours. Accounts may have at most ten live browser sessions.
Successful GET `/login` and authenticated GET `/` HTML form pages alone use
`Referrer-Policy: same-origin`. Native form POSTs then carry the configured Origin
instead of the literal `null` produced under `no-referrer`. Referrers are sent only
within the relay origin. Callback handling, redirects, JSON/error responses and
other pages retain `no-referrer`; no-store, frame denial and the script-free CSP
are unchanged. Exact Host validation prevents arbitrary forwarded-host trust.

GET `/login` issues a fresh random, HMAC-SHA256-signed `__Host-bmc_login` cookie
with Secure, HttpOnly, SameSite=Strict, Path=/ and a ten-minute lifetime, plus a
domain-separated hidden form proof bound to the complete cookie. The signature
binds the nonce, expiry and configured origin; validation checks server-side
expiry and rejects ambiguous duplicate cookies before credential authentication.
Signing keys are process-local: restarting the single relay invalidates open login
forms, which must be refreshed. No anonymous SQL session or persistent key is
created. Successful login clears the temporary cookie and issues the existing
eight-hour authenticated session cookie. This is a short-lived CSRF proof, not
an account credential or a durable, one-use authorization code.

POST `/session` always requires the independent login cookie/form proof. Only
this route may accept a genuinely absent Origin with valid proof. Explicit
`Origin: null`, foreign, empty and malformed Origins are rejected even with valid
proof. Authenticated Connect, confirm, health and disconnect POSTs still require
the exact configured Origin, a valid session and session-bound CSRF. Missing or
null Origin is never accepted there. Fetch Metadata is not an authority substitute.

## Protocol and routing

The site protocol is documented in `docs/CONNECT-AUTHORIZATION.md` at repository
root. The authenticated account starts discovery at `/connections/start`.
`/callback` requires both the account session and random state, exact issuer and
site ID. State alone is never authentication. The service claims an exchange once
before dispatch and does not retry a lost code exchange. A failed or uncertain
exchange can leave a site grant needing local owner revocation; never assume a
network error means no authority was issued.

The returned credential is encrypted and remains unusable until POST confirmation
from the originating account and exact browser session. Health, confirmation and
disconnect routes accept only an opaque `connection_id` and CSRF, never a replacement
URL. All outbound endpoints are revalidated against the stored binding. Site health
is checked live; cached scopes cannot restore authority. Disconnect disables routing
before trying site revocation. An outage leaves `disconnect_pending` with explicit
owner-review status, not an assertion that site authority was revoked. Maintenance
makes one bounded revocation attempt for expired unconfirmed approvals. No blind
retry loop exists.

Durable tables contain accounts, hashed sessions, authenticated site bindings,
connection/grant references, encrypted verifier/credential, scopes, state, minimal
health/backoff timestamps, and hashed rate buckets. There is no content, media,
Profile, ActivityPub, settings or conversation mirror. Multiple sites per account
are supported, capped at 100 retained connections. Authenticated global site-ID
bindings survive history cleanup to reject duplicate identity at another origin.
Unauthenticated duplicates cannot disable the valid connection. A legitimate origin
move needs explicit operator review, site-local revocation and fresh approval; no
automatic trust transfer is available in Gate 6A.

## Encryption, rotation and recovery

Usable site credentials and pending PKCE verifiers use AES-256-GCM with a random
96-bit nonce and 128-bit authentication tag. Version, purpose, account, connection,
site ID, origin, base path and client form authenticated associated data. Moving
ciphertext across accounts or bindings, wrong keys and tampering fail closed.

For rotation, stop the development service, retain the old keys, add a new random
version, make it active in the separate key file, and run
`node src/admin.mjs rotate-keys`. This reencrypts in locked, bounded batches and is
restartable; all read versions remain available until every live envelope has been
rewritten. Restart only after verification. Retiring a key also requires accounting
for retained encrypted backups. Losing the key requires local site revocation and
fresh approvals. Gate 6B must implement separate protected key backup, database
backup, practical restore, and rollback recovery before production acceptance.
Neither encryption nor a database restore automatically prevents resurrected trust.

## Transport and resource limits

Every outbound connection resolves both A and AAAA, rejects any unsafe answer,
then pins a validated address while retaining normal TLS hostname verification.
The socket cannot perform a second DNS lookup. IP literals, private/reserved IPv4,
IPv6 loopback/local/mapped/reserved ranges, HTTP, ambiguous paths and non-443 ports
are rejected. Only unauthenticated discovery may follow up to three validated HTTPS
redirects. Code and credential-bearing requests never follow redirects.

| Resource | Source default / hard ceiling |
| --- | --- |
| DNS | 2-second resolver, one try; 2.5-second outer deadline, at most 32 answers |
| Outbound HTTPS | 5-second total deadline, 64 KiB body, 8 KiB headers, eight concurrent, no queue |
| HTTP service | 16 concurrent by default, configurable to 64; eight additional accepted sockets |
| Incoming body/headers | 8 KiB each; 10-second request, 5-second header timeout |
| Account requests | 60/minute, configurable up to 120 |
| Discovery attempts | 5/account/minute, configurable up to 20 |
| Outbound operations | 20/account/minute, configurable up to 60 |
| Service/login | 300/minute total; 10/minute per direct peer address for login |
| Database | Eight connections, no waiting queue, 3-second connect/lock and 5-second query timeout |
| Failure backoff | 2 to 60 seconds between explicit health attempts, no automatic retry |

`/healthz` returns only readiness, database readiness, safe build identity and a
random request ID. It reveals no accounts, sites, configuration, secrets or stack
traces. It is still subject to connection/concurrency limits. SIGTERM/SIGINT stop
new acceptance and drain connections with a 10-second shutdown deadline.

Logs are JSON projections of UTC time, fixed event, random request ID and HTTP
status. Raw URLs, headers, bodies, exceptions, codes, verifiers and credentials are
never serialized. Login credentials, cookies and form proofs are likewise excluded.
The HTTPS relay browser regression uses an ephemeral TLS proxy, real Chromium,
the production request handler and disposable SQL accounts. It verifies native
Origin headers, login proof failures, cookie clearing, authenticated forms and
response policies without collecting secret-bearing browser artifacts. It runs
with the existing `BMC_BROWSER_TEST=1 npm run test:database` checks in CI.
Proposed operational log retention is seven days, enforced later
by Gate 6B. Source maintenance runs once per minute without overlap: removes up to
100 expired sessions, retires up to 100 expired pending exchanges, handles up to ten
unconfirmed approvals and removes up to 100 terminal history rows older than 90
days. Active/suspended bindings and unresolved disconnects remain for owner action.
Rate counters prune bounded expired rows. Site cleanup is separately documented.

## Remaining acceptance

Gate 6B must select the real deployment identity, provision a least-privilege Unix
and database account, local service, systemd, Nginx, authorized DNS/TLS, access-log
redaction, operational limits, Restic coverage and a practical restore test. It
must preserve existing SSH, UFW and Fail2ban policies. None is performed by this
source candidate. Gate 6C must prove a development installation through that hosted
service, including confirmation, health, revocation, reconnect, conflicts and relay
failure isolation. No content tool, media listing scope or public release is added.

## Browser connection actions

Confirm, Check connection and Disconnect use the same Relay methods for every
caller. Authenticated, CSRF-valid form POSTs accepting `text/html` return HTTP 303
to `/?notice=<fixed-name>` and render the Connected sites page. Only server-defined
notice names and fixed non-secret messages are supported. No service result,
credential, upstream error, or connection identifier enters the redirect URL.
Notices describe the preceding action and confer no authority; the listed state
is always freshly loaded. Reloading the result page never repeats the operation.
Explicit `Accept: application/json` (including alongside HTML), absent Accept, and
wildcard-only Accept retain JSON results and error status/certainty. Media ranges
with zero or invalid quality do not opt into either representation.

Operation failures return fixed, conservative browser notices, including uncertain
disconnect outcomes. Authentication, Host, Origin, CSRF, malformed input and
pre-operation admission failures retain their existing JSON HTTP errors. There
are no automatic retries. Confirmed site revocation and routing disabled with
owner review required have distinct notices; an unconfirmed revocation is never
reported as successful.

| Listed state | Browser actions |
| --- | --- |
| awaiting_confirmation | Confirm connection, Disconnect |
| active | Check connection, Disconnect |
| pending | Disconnect (cancel pending routing) |
| suspended, revoked_or_expired | Disconnect |
| exchanging | None while exchange is in flight |
| disconnect_pending | None; owner review, no browser retry |
| disconnected, denied, failed, abandoned, unknown | None |

This is presentation only. Existing ownership, originating-session confirmation,
expiry, live-health authority, and routing-before-revocation rules are unchanged.
The HTTPS approval regression now follows confirmation, health, and both disconnect
outcomes through the final HTML page, checking single operation execution and
reload safety. All fixtures are disposable; hosted connections are untouched.

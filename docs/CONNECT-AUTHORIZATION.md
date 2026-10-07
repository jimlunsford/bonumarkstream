# Connect authorization protocol, Gate 6A

This source candidate implements connection authority and health routing only.
The separate relay is not deployed. Gate 7 content adapters and MCP tools do not
exist. The accepted authority model remains [BONUMARK-CONNECT.md](BONUMARK-CONNECT.md).

## Site setup and identity

Migration `0030_connect_authorization_foundation` adds eight Connect-only tables:
control lock, clients, sessions, grants, codes, credentials, audit, and rate limits.
It uses resumable `CREATE TABLE IF NOT EXISTS` statements and an insert-only control
row. Fresh installation uses the normal migration path; no owner record is rewritten.

An active Admin explicitly initializes Connect in **Settings > Connected
Applications** or with `php scripts/connect-admin.php initialize OWNER_ID`.
Initialization creates a cryptographically random lowercase UUIDv4 in the reserved
`connect_site_id` setting if absent. Reinitialization preserves it. Discovery never
creates identity. Connect starts disabled and has its own enable state, independent
of the existing Remote Posting switch. A valid installation security salt of at
least 32 bytes is required; Connect has no fixed hash-key fallback.

Site identity is independent of owner ID, Profile, canonical address, ActivityPub
actor and any future revision incarnation. Addresses require HTTPS on port 443,
lowercase IDNA ASCII hostnames, no IP literal, credentials, query, fragment,
backslash, percent encoding or dot segment. One terminal DNS dot and default port
are normalized. Base paths preserve case; root is an empty string. Unicode host
initialization requires PHP intl; ASCII hostnames do not. DNS/public-address safety
is enforced again by the relay before every outbound connection.

Only the site operator provisions trusted clients:

```sh
php scripts/connect-admin.php provision OWNER_ID /private/path/client.json
```

The JSON contains `client_id` (16 to 80 ASCII letters, digits, underscores or
hyphens), a trusted `display_name`, and `callbacks` (one to five exact canonical
HTTPS URLs). Registration is insert-only. There is no public registration route,
wildcard callback, user-supplied display-name trust or arbitrary return URL.
Deployment registrations remain configuration data; no production hostname is chosen.

## Implemented site surfaces

All paths are relative to the approved installation base path. The literal `.php`
entry points work without adding a rewrite dependency.

| Method | Path | Authority and result |
| --- | --- | --- |
| GET | `/api/connect/v1/discovery.php` | Anonymous, enabled Connect only; identity, protocol, endpoints, scope representation and implemented connection-status capability. |
| GET | `/admin/connect-authorize.php` | Normal site Admin login; validates and stores an immutable pending request, then displays owner approval. Never approves. |
| POST | `/admin/connect-authorize.php` | Exact active owner, existing session, CSRF; explicit approval or denial. |
| POST | `/api/connect/v1/token.php` | JSON authorization code and S256 verifier with exact tuple/client/callback. |
| GET | `/api/connect/v1/status.php` | Dedicated credential, `X-Bonumark-Client`, live `status:read`; reports current grant binding only. |
| POST | `/api/connect/v1/revoke.php` | Dedicated credential, exact client, JSON object `{}`; revokes that grant, including an empty-scope grant. |
| GET/POST | `/admin/connected-applications.php` | Normal active Admin with settings capability; owner-visible grants and CSRF-protected management. |

Discovery contains no owner ID, grants, credentials or private content. It advertises
only connection status as implemented. Representable initial scopes are
`status:read`, `stream:read`, `stream:draft`, and `media:upload`. Neither
`stream:publish` nor future `media:read` can enter a Connect grant. Unknown requested
scopes fail rather than being removed. Content scopes create no callable content
surface in this gate. An empty approval grants no operational authority.

## Browser and exchange protocol

1. The relay authenticates an independently provisioned account and browser session.
   It discovers the proposed site with its bounded public HTTPS transport.
2. It stores an opaque connection ID, exact originating account/session, site ID,
   origin/base path, client, requested scopes, hashed state and encrypted verifier.
   Its approval link carries `client_id`, `redirect_uri`, `site_id`,
   `canonical_origin`, `base_path`, `state`, `code_challenge`,
   `code_challenge_method=S256`, and space-separated `scope`.
3. The owner signs into the actual site through normal Admin login. The relay never
   receives the normal site password. The site stores its own request, bound to
   that exact active Admin. Pending requests last at most 600 seconds. `state` is
   64 lowercase hex characters from 32 random bytes; the challenge is SHA-256 in
   unpadded base64url. Plain PKCE is rejected.
4. The owner reviews the provisioned trusted client, actual site, requested scopes
   and expiry, then submits a separate CSRF-protected POST. The chosen scope set
   can only narrow the immutable request. UI lifetimes are 1, 30 or 90 days;
   the core accepts 1 hour through 90 days. Denial creates no code or credential.
5. Approval creates a UUIDv4 grant and a 32-byte random hex authorization code.
   Only its domain-separated HMAC-SHA256 is stored. The code expires within 60
   seconds and no later than the pending request deadline. The callback uses only
   the provisioned exact URL and returns `code`, `state`, `iss` (origin plus base
   path) and `site_id`; denial returns `error=access_denied` instead of code.
6. In the same authenticated relay account/session, callback handling claims the
   pending record once before exchange. It verifies state, issuer and site ID.
   The token JSON contains `code`, `code_verifier`, `client_id`, `redirect_uri`,
   `site_id`, `canonical_origin`, and `base_path`. Every code/binding/verifier
   rejection is the generic `invalid_grant`. JSON and method errors remain distinct.
7. Atomic site exchange consumes the code, activates the grant and inserts one
   credential. Issuance audit must commit with authority. A lost response cannot
   create a second credential. There is no automatic exchange retry or refresh.
8. A successful response contains `ok`, random `request_id`, binding fields,
   `client_id`, `grant_id`, `scopes`, absolute UTC Unix `expires_at`,
   `token_type=Bearer`, and `access_token`. The credential is `bmsc_` followed by
   32 random bytes in lowercase hex, returned once. The site stores a distinct
   domain-separated HMAC, never plaintext. Existing `bmsrt_` behavior is unchanged.
9. The relay validates the returned grant, encrypts the credential, and leaves the
   connection awaiting confirmation. Only the originating account and exact
   browser session can confirm before the original 600-second deadline. A callback
   alone never enables health routing. Abandoned approvals receive one bounded
   site-revocation attempt; uncertain outcomes require local owner review.

Both site and relay use no-store, frame denial and restrictive resource policies.
The relay's successful `/login` and authenticated `/` HTML form pages alone use
`Referrer-Policy: same-origin` so native POSTs supply the exact configured Origin;
callbacks, errors and other relay responses retain `no-referrer`. Login additionally
requires a short-lived signed host-only cookie and bound hidden form proof before
credential authentication. Only login may tolerate an absent Origin with valid
proof; explicit null/foreign Origins fail. Authenticated mutations always require
exact Origin plus the session-bound CSRF proof. The site retains no-referrer;
approval uses the shared Admin shell without third-party resources.
Credentials and verifiers never appear in browser URLs. Callback query strings do
contain short-lived codes: web server access logging must omit query strings on
these surfaces. Application logs never serialize request URLs or raw exceptions.
Gate 6B must verify proxy/PHP logging behavior before hosted use.

## Live authorization and owner controls

Each credential check locks the current credential, grant, exact active Admin,
registered client and current installation binding, then reads current scopes.
There is no fallback to another Admin. Revocation and scope reduction take effect
on the next check. A shared Connect authority lock serializes issuance, reduction,
revocation, recovery and future authorized adapter mutations. The authorized
operation runs inside that transaction. Nested calls retain the caller's outer
transaction and lock; failures roll back to a savepoint. Future adapters must use
this primitive and preserve its transaction boundary.

Origin/base-path mismatch durably suspends the grant. Restoring the old address
does not reactivate it. Reconnect uses a new approval/grant; revoked grants are
terminal. The relay's global authenticated site binding rejects a duplicate site
ID at another address without disabling the legitimate existing connection.
Resolving a real address move requires site-local revocation/reapproval and an
explicit operator review of the retained relay binding. There is deliberately no
automatic trust-transfer or caller-provided replacement-URL route.

Connected Applications shows up to 100 recent owner grants, trusted name, grant
ID, scopes, state, creation/approval/expiry and last-use times. It never displays
hashes, bearer values, codes, PKCE or encryption internals. It supports individual
revocation and scope reduction. Typed `REVOKE` plus CSRF is required for revoke all,
compromise/restore recovery, or independent-clone reset. Local actions require no
relay call. Revoke all invalidates all site Connect authority, including outstanding
codes and requests; Remote Posting controls remain independent.

Normal password reset preserves owner identity and healthy grants and directs the
owner to Connected Applications. After restoring a backup, keep Connect routes
inaccessible until `php scripts/connect-admin.php recovery OWNER_ID --confirm-revoke`
(or the corresponding Admin action) succeeds. This preserves logical site and
owner IDs, revokes restored grants/codes/credentials/requests and disables Connect.
Reenable explicitly and obtain fresh approvals. An independent clone uses `clone`
instead to also generate a new Connect site ID. Database rollback or same-address
cloning cannot always be detected automatically; a backup containing hashes can
still restore usable old authority. No automatic rollback guarantee is claimed.

## Audit, cleanup and limits

Site audit stores random server-generated UUIDv4 request IDs, optional grant ID,
fixed operation/outcome/result codes, UTC timestamps and domain-separated network
hashes. Approval, denial, request creation, exchange, scope reduction, revocation,
reapproval through a new grant and recovery produce evidence. Authority issuance
fails closed on audit failure. Ordinary successful-operation evidence is best
effort and cannot trigger operation reexecution. Caller request IDs are not used
as trusted identities. No bodies, prompts, conversations, credentials, codes or
verifiers enter audit.

Connect HTTP routes use separate durable quotas: 300 requests per site/minute,
30 per remote-address/route/minute, and at most 1,000 unexpired pending requests.
Existing Remote Posting quotas are unchanged. `connect-admin.php maintenance
OWNER_ID` prunes at most 1,000 audit rows older than 90 days and at most 1,000 expired
code/request rows older than one day per invocation, plus 500 stale rate buckets.
Grant history remains for owner review. Cleanup never determines validity or
reactivates authority. Gate 6B owns any operational scheduling and backup retention.

See [Gate 6A implementation evidence](development/connect-gate6a.md) for validation
and the separate relay README in `services/bonumark-connect/` for its source and
configuration. The service directory is excluded from normal Stream archives.

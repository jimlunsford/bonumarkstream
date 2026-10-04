# Bonumark Connect architecture and prerequisite contract

## Status and authority

Gate 5A defines future architecture, not implemented Connect behavior. Acceptance
of this document does not authorize Gates 5B, 6, 7, 8, or 9, a deployment, or a
release. Gate 5 closes only after both 5A and 5B are accepted. Current runtime
behavior remains governed by source, tests, and [API.md](API.md).

The audited baseline is develop `2a32ac7bc368c4b5372cc5707cc7ee1d7c448f4b`,
tree `d540288f73a765b6403a306567f50823c27abec5`. Public v0.8.2 and its
release artifact are separate from this development checkpoint. The earlier
PATCH / 0.8.3 recommendation remains unenacted.

The current roadmap and Connect product plan supplied the planning direction.
The roadmap's Gate 4 closeout SHA is historical; this audit uses the newer
explicit checkpoint above. No roadmap status is advanced here.

## Authority and failure boundary

Bonumark Stream remains fully functional without Connect. The self-hosted site's
database and core own content, media, permissions, publishing, scheduling,
federation, and lifecycle semantics. Connect is an optional relay for authenticated
account-to-site routing, authorization coordination, and tool transport. It is
not another CMS, content mirror, or identity provider required for local login.

The relay may retain account identity, approved site bindings, encrypted outbound
credentials, grant/scope metadata, minimal operation state, and bounded security
and health metadata. Returned content is transient response data, not an
authoritative central archive. Prompts and conversations are not site audit data.

Connect receives no normal Admin password, SSH/VPS/database credential, security
salt, ActivityPub private key, raw filesystem access, or server authority.
MCP implements no independent publishing rules. Every content operation reaches
site-owned core through a scoped site adapter.

Public requests, Admin, direct local publishing, media, and ActivityPub have no
synchronous dependency on Connect. Relay failure affects integration calls only.
Local Connected Applications controls must support revocation while the relay is
unreachable. Site management remains possible without the service.

## Verified current runtime

Primary source: [api.php](../_bonumark_stream/app/api.php), its thin
[REST entry points](../api/v1/), [database.php](../_bonumark_stream/app/database.php),
[media.php](../_bonumark_stream/app/media.php), and
[scheduler.php](../_bonumark_stream/app/scheduler.php).

### Authentication, permissions, accounting

`bms_api_authenticate()` checks the Remote API switch, bearer token, route-specific
rate controls, active token and expiry, then every required scope. Tokens are
random `bmsrt_` credentials containing 32 random bytes encoded as hex. Only an
HMAC-SHA256 token hash, prefix/hint, and metadata are stored. The hash uses the
installation security salt (the implementation contains a fixed fallback).
The full credential is returned once on creation. Expiry is optional for existing
Remote Posting tokens; revocation updates live token status, checked on subsequent
authentication. Last-use timestamp/IP hash updates are best effort.

`created_by` associates tokens with their creator. `bms_api_token_author_id()`
uses an active Admin creator when possible, then falls back to the first active
Admin. This is current attribution behavior, not a durable Connect grant or a
strict owner-bound authentication check. Connect must require its exact active
owner ID; it must never inherit that fallback as grant-transfer behavior.

| Current scope | Actual meaning |
| --- | --- |
| `status:read` | Authenticated status and token metadata. Anonymous status is separately available. |
| `stream:read` | Published Stream Posts only, including single ID selection. No draft, scheduled, Trash, Page, or private Following reads. |
| `stream:draft` | Create a new draft; also required with publish scope for other creation statuses. |
| `stream:publish` | Additional authority for new published or scheduled posts, subject to site controls. |
| `media:upload` | Image upload/import when enabled, including uploads/imports embedded in creation. |

Unknown scopes are discarded by current normalization; an empty result defaults
to `status:read`. Future Connect approval must instead reject unsupported requested
scopes and preserve an explicitly empty grant as no authority.

Site rate controls use a configurable 5 to 600 attempts per minute (default 60),
per token and route, plus an IP/route check for authentication failures. Successful
authenticated requests count against the token; missing bearer requests are
recorded and rejected before the IP-limit check. Anonymous status bypasses token
authentication. Counting and insertion are separate, not an atomic quota; recording
is best effort and a count-query failure denies access. These provide baseline
target-site protection, not aggregate relay abuse prevention or a hard concurrency
bound. Do not claim every incoming request is IP-throttled.

`bms_api_record_audit()` records token ID, event, method/route, hashed IP and user
agent, HTTP status, success, bounded message/request ID, and timestamp. Writes
are best effort. Not every unexpected 500 receives an API audit row. This is a
reusable foundation, not a complete tamper-proof or guaranteed Connect audit.

### Operations and accepted corrections

| Operation | Current contract |
| --- | --- |
| Status | GET/HEAD `/api/v1/status`; anonymous capability/configuration summary; bearer supplied means authentication with `status:read`. |
| Published list/get | GET/HEAD `/api/v1/stream/posts`; ID selection or bounded page query, public metadata projection, optional HTML. |
| Draft creation | POST to Stream collection with explicit draft status and `stream:draft`. |
| Scheduled creation | Adds `stream:publish`, enabled remote publishing, future site-local schedule converted to UTC. Omitted status plus schedule means scheduled. |
| Direct publishing | Adds `stream:publish`, enabled remote publishing, and configured explicit confirmation. Omitted status otherwise uses the site's default. |
| Image upload/import | POST `/api/v1/media` or `/api/v1/media/import`; shared validation/privacy processing, feature switch and upload scope. Import enforces public-address/redirect safety. |
| Creation retry | Optional Stream creation idempotency key; standalone media upload/import has no idempotency reservation. A media `client_request_id` is audit metadata only. |

The following corrections are present in baseline ancestry and source. Their
accepted Compatibility runs each contain four successful PHP/database jobs:

| PR | Accepted head | Merge | Run |
| --- | --- | --- | --- |
| #11 | `feeaa6fcceeef9e356880d139700243e3df9633d` | `10c1a071fc6fb91439dc7201ce3139344296f4fd` | [37215037854](https://github.com/jimlunsford/bonumarkstream/actions/runs/37215037854) |
| #12 | `7b459efd38cd9b582e414f1affc7fed4b79feed3` | `06f63a0ac9affcf34bfa7840ab186243173f9af6` | [37218010201](https://github.com/jimlunsford/bonumarkstream/actions/runs/37218010201) |
| #13 | `b044ad9f42b2c24ff7b9bbdc3186dc2df5b802ec` | `2a32ac7bc368c4b5372cc5707cc7ee1d7c448f4b` | [37224406596](https://github.com/jimlunsford/bonumarkstream/actions/runs/37224406596) |

- PR #11: successful reservation INSERT alone returns the internal row ID.
  Observers/collision losers get no handle. Store/release require that exact ID,
  token/key/hash, and unfinished state. Completed replay returns stored outcome;
  changed intent conflicts; unfinished exact duplicates remain processing.
  Completed records expire 24 hours after acquisition; unfinished records are
  not expired automatically. Stale handles cannot affect replacements. Storage
  after creation is best effort, not permanent exactly-once execution.
- PR #12: only explicit public-safe media exception classes supply validation
  messages. Standalone, embedded, and imported media share the boundary. Unexpected
  PDO/filesystem/processing/transport/PHP failures become sanitized `server_error`.
  Private diagnostics contain bounded context/class/source location, not raw
  exception messages. Alt text retains `422 alt_text_invalid`.
- PR #13: `bms_insert_database_content()` rejects existing identity and returns only
  its own inserted row. Remote creation does not recover identity by slug.
  `bms_with_stream_slug_lock()` coordinates through the existing InnoDB `site_name`
  settings row, with current locking reads across all Stream statuses and aliases.
  The lock lasts through the outermost transaction. Remote insertion retries at
  most five times, rebuilding slug-dependent fields from original intent without
  repeating media preparation. Existing-object transitions reject foreign
  ownership. Scheduler selection is rechecked under this boundary per post, with
  independent recorded outcomes and continuation after failure. Independent
  published creation observes Create for its own ID, never Update of a collision.
  No migration was added; 0030 remains unused. Direct SQL and simultaneously
  running old application code are outside this cooperative guarantee.

Regression authority includes `idempotency_ownership` and `idempotency_lifecycle`
in [API database tests](../scripts/api-database-smoke-test.php), the real HTTP
[media failure scenario](../scripts/media-error-sanitization-scenario.php), and
[slug safety scenarios](../scripts/stream-slug-safety-scenario.php), including
cross-status creation, aliases, outer rollback/lock lifetime, stale database
snapshots, scheduler isolation, HTTP replay, and independent federation identity.

### Missing concepts are planned work

No durable installation ID, connected-client registry, site grant, code exchange,
PKCE/state flow, Connect Admin approval/revocation UI, hosted relay, account/site
routing, MCP implementation, or `media:read` scope exists in the audited runtime.
Current site-name/tagline helpers are presentation identity, not installation
identity. ActivityPub actor identity is not a substitute. No new endpoint, scope,
or discovery field is advertised by this document or current OpenAPI.

## Site identity and canonical address

Future `site_id` is an opaque lowercase UUIDv4 generated from secure randomness,
stored once in the site's database settings under a reserved core-owned key.
Gate 6 initializes it atomically during install/upgrade or explicit Connect setup,
not from arbitrary public requests. Normal upgrades preserve it. It is public
identity, not a secret, authorization proof, domain name, or owner user ID.

Identity and network location are separate. The trust binding is the tuple
`(site_id, canonical_origin, base_path, owner_user_id, client_id, grant_id)`.
Gate 2 preserves the existing owner row; neither site ID nor grant creates an
additional publisher. Gate 8 revision incarnation is also separate: a preserved
site ID does not permit reuse of revisions after divergent restoration.

| Event | Required disposition |
| --- | --- |
| Normal upgrade or reconnect at the same address | Preserve site ID. Reconnect requires current owner approval and rotates the dedicated credential; no automatic revival of revoked grants. |
| Legitimate disaster recovery | Preserve site ID and owner ID. Before enabling Connect, revoke restored integration credentials/codes and require fresh approval so a backup cannot resurrect revoked authority. Preserve audit history as historical evidence. |
| Domain, port, or base-path change | Preserve logical site ID, suspend the old network binding, and require explicit owner approval at the verified new address. Never forward the old bearer credential to the new address. |
| Independent clone | Generate a new site ID before Connect activation, clear inherited connection credentials/authorization sessions, and connect independently. A clone is not a trusted move. |
| Same site ID discovered at another address | Reject automatic enrollment/rerouting; quarantine the candidate as an identity conflict. Do not let an unauthenticated duplicate disable or take over an existing connection. Owner must resolve clone versus move. |

Canonical network address rules for protocol version 1:

- HTTPS only with normal certificate and hostname verification. Normalize DNS
  names to lowercase IDNA ASCII, remove one terminal DNS dot, omit explicit 443.
  Reject malformed/ambiguous hosts, userinfo, fragments, query strings, backslashes,
  encoded separators, and dot-segment ambiguity. Require public DNS hostnames;
  IP literals and private/local/reserved destinations are not supported in v1.
- `canonical_origin` is scheme plus hostname and optional port, with no path or
  trailing slash. Version 1 allows only HTTPS port 443; nondefault ports require
  a future deliberate transport policy, never silent fallback to HTTP.
- Bonumark supports `base_path` via `bms_base_path()` and `bms_site_url()`.
  Store it separately as empty for root or a leading-slash path without a trailing
  slash, preserving case. Reject noncanonical ambiguous encoding instead of
  guessing equivalent paths. `canonical_base_url` is origin plus base path;
  append exactly one slash when resolving relative API paths.
- The entered discovery URL is a hint. Before approval, anonymous discovery may
  follow at most three HTTPS redirects with full destination validation at every
  hop. Display the final actual installation address to the owner. Site-reported
  identity/origin/path must match the verified discovery destination; disagreement
  fails closed, not automatic trust in the site's supplied URL.
- Code exchange and credential-bearing requests do not follow redirects. Endpoint
  addresses must stay inside the approved origin and exact base-path boundary.
  A redirect or changed canonical address suspends routing pending reapproval.
- Relay transport resolves and rejects unsafe A/AAAA results before every outbound
  connection, pins the validated address for that connection while verifying TLS
  for the hostname, and enforces time/size/redirect bounds. This prevents DNS
  rebinding between validation and connect. The site separately owns remote-media
  import SSRF protection. Neither trusts a user-supplied URL merely because the
  other layer checked it earlier.

## Connected applications and credentials

A connected application is a registered client, not an Admin login. Version 1
uses an explicitly provisioned Bonumark Connect client entry with stable opaque
`client_id`, trusted display name, and exact HTTPS callback allowlist. There is no
arbitrary dynamic client registration or caller-selected trusted display name.
Gate 6 chooses and provisions the actual service hostname/callbacks; none is
authorized by this document.

Each site grant has an opaque UUIDv4 `grant_id`, client ID/display-name snapshot,
existing owning Admin user ID, site/address binding, requested and granted scope
sets, pending/active/suspended/revoked/expired state, creation/approval/update times,
last-use timestamp and hashed network metadata, expiry, revocation time/reason,
credential linkage, and immutable audit identity. Grant ID persists across an
approved rotation of that active connection. Revoked grants are terminal; a new
approval creates a new grant. Preserve their historical audit identity.

Distinguish these objects:

| Object | Lifetime and protection |
| --- | --- |
| Authorization session | Pending request, account/browser binding, site tuple, requested scopes, callback, state, PKCE challenge; 10-minute maximum. Not authority to call tools. |
| Authorization code | At least 256 random bits, hashed site-side, one-use, 60 seconds after approval, bound to session/client/callback/site/owner/scopes/PKCE. Not an access credential. |
| Grant | Durable live permission state and owner/client association. Independent of any one bearer string. |
| Access credential | Dedicated opaque `bmsc_` plus 32 random bytes encoded as hex. Hash/HMAC only at site; 90-day maximum lifetime, optionally shorter by owner policy. No refresh credential in v1; expiry requires reapproval. |
| Revocation | Authoritative site-side state invalidating linked credentials and outstanding codes. Relay caches are advisory only. |

The relay needs a usable outbound credential and must encrypt it at rest with
authenticated encryption, with keys separated from its credential database and
rotation/recovery procedures in Gate 6. Never log it, put it in a tool result, or
send it to ChatGPT. Site credential hashes use a dedicated domain-separated
purpose; credentials are not interchangeable with existing `bmsrt_` tokens.
Existing Remote Posting token semantics stay compatible. Code/credential issuance
and consumption are atomic database operations; response loss requires a fresh
authorization, never reusing a consumed code to mint another credential.

Every Connect request resolves a credential to the exact current active grant,
active owning Admin, client, site/address binding, and effective granted scopes.
No fallback owner, embedded stale scope claim, or cached relay decision can grant
authority. Scope expansion requires owner approval; reduction applies on the next
site authorization check without waiting for expiry. A zero-scope grant grants
nothing. Revocation/reduction and write authorization must serialize at the site
mutation admission boundary so work admitted after a committed revocation cannot
execute. Revocation cannot undo an already committed operation or recall bytes
already returned. These are Gate 6 requirements, not current token guarantees.

## Authorization protocol, version 1

Use a restricted authorization-code protocol with `state` and PKCE S256. It draws
on [RFC 7636](https://www.rfc-editor.org/rfc/rfc7636) and the redirect/CSRF/code
security guidance in [RFC 9700](https://www.rfc-editor.org/rfc/rfc9700). This is
not a claim that Bonumark is a general OAuth provider. ChatGPT-to-relay account
authentication is a separate Gate 6 integration decision.

1. The authenticated relay account selects a site. Relay validates discovery and
   pins the tuple and supported protocol. It creates a 10-minute pending session,
   a random state value of at least 256 bits, and a fresh PKCE verifier (32 random
   bytes, base64url without padding). Only its S256 challenge goes to the browser.
2. The browser opens the actual site's authorization screen. The request names the
   provisioned client, exact callback, site/address tuple, requested scopes, state,
   and challenge. Reject unsupported scopes, non-S256 challenges, wrong site tuple,
   and unknown clients/callbacks before offering approval. No wildcard callbacks,
   arbitrary return URLs, or redirects to an unvalidated callback on error.
3. The owner signs in directly at Bonumark. The site uses its normal session and
   a separate CSRF-protected POST to approve or deny the immutable request. Show
   client identity, actual site, requested permissions, and credential expiry.
   Require active Admin authority; a GET never approves anything.
4. Approval creates a pending grant/code bound to all approved values. Denial
   creates no credential. Return only the short-lived code, state, and issuer
   identity to the allowlisted callback. Authorization surfaces use no-store,
   no-referrer, no third-party resources, and redacted access logs. No bearer
   credential ever appears in a browser URL.
5. Relay checks state against the originating authenticated account/browser
   session, expiry, and expected site issuer before exchange. It must not accept
   a callback into another account or choose the token destination from callback
   input. An interrupted browser session must be reauthenticated to the same
   account; state alone is not account authentication.
6. Relay exchanges code plus verifier and exact client/callback/site binding over
   HTTPS directly with the pinned site. Site atomically validates unexpired,
   unused code, PKCE, current owner/grant, scopes, and binding, consumes the code,
   activates the grant, and returns the dedicated credential once with grant ID,
   site ID, canonical address, effective scopes, and expiry. Replay fails.
7. Relay verifies response binding, encrypts the credential, and requires a final
   confirmation in the originating authenticated relay account before exposing
   the new connection to tools. This guards account/site linking confusion.
   Abandoned links are locally revoked where possible and never silently activated
   by another account. The site can always revoke an abandoned grant itself.
8. Connected sites lists the verified site and granted scope summary. Reconnect,
   scope expansion, or address change repeats owner approval. Rotation invalidates
   the previous credential atomically. Pending approval does not expand an existing
   active grant; denial/expiry leaves its previously approved scopes unchanged.

Protocol failures disclose stable safe reasons, never codes, verifiers, tokens,
credentials, or internal exchange diagnostics. Unknown/mismatched callback stays
on the site; valid denial may return safe `access_denied` plus bound state.

## Owner recovery

Apply [Gate 2](ARCHITECTURE.md#owner-identity-continuity-during-recovery).
Current password recovery updates the same user row and consumes reset/remember
tokens. Ordinary future password reset preserves active Connect grants by policy,
retains owner ID, and directs the owner to review Connected Applications.
It does not silently create a new owner, change Profile, or regenerate ActivityPub
identity. Suspected compromise recovery must explicitly revoke all connected-app
grants, outstanding authorization sessions/codes, and access credentials, as well
as address sessions and direct Remote Posting tokens under their own controls.
Review client names, scopes, last-use and approval evidence afterward. Future
revoke-all is site-local and records safe evidence; it requires no relay response.
Disaster recovery has the stricter credential disposition described above.

## Discovery, scopes, and errors

Current `bms_api_status_payload()` exposes version, API/publishing/media switches,
default status/confirmation, published-read capability, creation idempotency
metadata, and endpoint URLs; authenticated responses add token metadata. It does
not expose stable site ID, Connect protocol, grant, or revision support.

Gate 6 may add a versioned Connect discovery object to status, keeping existing
fields compatible. Future fields are `site_id`, `canonical_origin`, `base_path`,
`protocol_versions`, `supported_scopes`, and capability records with implemented,
enabled, required-scopes, supported limits, and revision-support information.
Authorization endpoint locations are explicit only when implemented. Public
discovery contains no owner ID, grants, private content, or credentials.
Authenticated discovery distinguishes implementation support from effective grant
permission and current site switches. Missing version/capability means unavailable;
no fallback to more privileged direct posting. Capability data never authorizes a
call; the site rechecks each operation. No fictional OpenAPI routes are added now.

Retain `status:read`, published-only `stream:read`, `stream:draft`, and
`media:upload`. Add `media:read` only with the Gate 7 active-media listing endpoint
and owner-approved scope. Do not rename existing scopes to the planning document's
candidate `site:read` or `stream:create`. Gate 6 grants request only implemented
scopes; existing grants never acquire the later scope automatically. Initial
Connect grants exclude `stream:publish` even though direct Remote Posting supports
it. Scope names do not imply editing, schedule mutation, deletion, Profile or
comment writes, upgrades, user/security administration, or unrestricted settings,
themes, server, database, or filesystem operations.

Preserve `{ "ok": false, "error": { "code": "...", "message": "..." } }`
and no-store behavior. Classify by stable code, not message parsing. The relay
returns structured origin (`site` or `relay`), code, safe message, HTTP status
when available, and outcome certainty; meaningful site errors are not flattened
into a generic MCP failure. Non-JSON proxy/server bodies never become tool text.

| Condition | HTTP / stable code | Recovery |
| --- | --- | --- |
| Missing bearer | 401 `missing_bearer_token` | Authenticate. |
| Invalid, expired, revoked bearer | 401 `invalid_bearer_token` | Stop use and reconnect/reapprove. Do not reveal which credential state matched. |
| Missing authority | 403 `missing_scope` | Owner approval needed for expansion; no privilege fallback. |
| Disabled site feature | 403 `remote_posting_disabled`, `remote_publish_disabled`, or `remote_media_upload_disabled` | Owner resolves locally. |
| Safe validation | Existing 400/413/422 codes, including `invalid_status`, `content_too_large`, `alt_text_invalid`, `media_upload_invalid` | Correct input, preserving current distinctions. |
| Infrastructure | 500 `server_error` with fixed safe text; preserve existing fixed `media_temp_failed`, `idempotency_failed`, `idempotency_response_invalid` | No raw SQL, filesystem, transport, credential, or PHP diagnostics. |
| Throttle | 429 `rate_limited` | Bounded backoff; no guaranteed Retry-After header in current runtime. |
| Missing published post | 404 `stream_post_not_found` | No private-state disclosure. |
| Changed creation intent | 409 `idempotency_key_conflict` | Reassess; never silently change a retry payload. |
| Unfinished creation | 409 `idempotency_key_processing` | Resolve original outcome; do not start with a new key. |
| Slug exhaustion | 409 `slug_conflict` | Preserve logical identity; no adoption of another object. |
| Publish confirmation | 428 `publish_confirmation_required` | Current direct API only; does not authorize Gate 7 publishing. |
| Future revision | 428 `revision_required`, 400 `invalid_revision`, 412 `revision_conflict` | Gate 3 reread/reassess rules; Gate 8 implementation. |
| Future authorization request | 400 `invalid_authorization_request`, `invalid_client`, `invalid_redirect_uri`, `invalid_scope`, or `authorization_expired`; 403 `access_denied` | Restart valid approval; never redirect to an untrusted callback. |
| Future code exchange | 400 `invalid_grant` for invalid/expired/used code or verifier/binding mismatch | New authorization; no detailed code oracle. |
| Future relay state/identity mismatch | 400 `authorization_state_invalid`; 409 `site_identity_conflict` or `site_origin_changed` | Stop routing and require resolution. |
| Future relay cannot reach site | 503 `target_site_unavailable`; 504 `target_site_timeout`; 502 `target_site_response_invalid` | Mutation may have committed if dispatched; do not report failure as proof of no effects. |

Future responses include an additive site-generated random `request_id` (UUIDv4)
for safe correlation, with the same value in audit evidence. Caller request IDs
remain bounded, separately labelled untrusted metadata and are never authority.
Relay IDs are separate; never encode account IDs, content, credentials, or internal
reservation IDs. This addition belongs with Gate 6 audit implementation.

## Shared-core inventory

| Operation | Current Admin path | Current API path | Existing core service | Duplication/gap | Gate 5B action |
| --- | --- | --- | --- | --- | --- |
| Site info | `admin/settings.php`, `admin/remote-posting.php` | status handler/payload in `app/api.php` | Settings/config and URL helpers | Status projection already reusable; future identity/discovery absent | None; Gate 6 adds discovery. |
| List Stream Posts | `admin/index.php` uses `bms_list_content_records()` for separate statuses | `bms_api_read_stream_posts()` | Database content mapping, terms, Markdown rendering | Published SQL/query policy tied to `$_GET`; Admin has intentionally broader views | Extract explicit published query, retain Admin catalog behavior. |
| Get Stream Post | `admin/edit.php` selects authorized section/file | Same API reader with `id` | Database row mapping and public payload projection | Single-ID query shares HTTP-global coupling | Same published-read extraction; no draft read expansion. |
| Create Stream draft | `admin/quick-post.php`, composer; editor subsequently edits | `bms_api_create_remote_stream_post()` / `bms_api_insert_remote_stream_post()` | Metadata generation, Markdown normalization, insert-only persistence and slug lock | Adapter normalization/defaults differ; reusable prepared creation orchestration still API-owned | Extract narrow neutral command, preserve adapter policies. |
| List media | `admin/media.php`, `admin/media-picker.php` | No list endpoint | `bms_media_list()`, `bms_editor_media_payload()`, API media projection | Query catches failures as empty lists; bounded recent results, no pagination; raw rows need projection | Extract throwing bounded query under existing wrapper; no endpoint. |
| Upload media | `admin/media-upload.php`, picker, quick composer | media and import handlers; embedded-media path | `bms_media_upload()`, upload/alt validation, privacy storage, derivatives, importer | Shared processing already exists; adapters correctly differ | **No Gate 5B media-upload refactor required.** |

Paths under `app/` above refer to `_bonumark_stream/app/`. Admin and API need not
have identical wire representations or privilege sets. MCP calls REST, not PHP
functions remotely. Sharing core is about one implementation of business behavior.

## Initial Gate 7 tool contracts

All target tools require an explicit opaque relay `connection_id` selected from
the authenticated account's `list_sites`. Relay resolves it to its approved tuple,
never a caller-supplied credential or replacement URL. Ambiguous natural-language
site names require selection before mutation. Results include connection/site
identity for attribution. Recheck live site authority on every target request.
All seven tools have no existing-resource revision precondition: reads do not
mutate, and draft/upload create new objects. Never fabricate a revision from
`content_hash` or `modified_at`.

| Tool | Relay responsibility and principal inputs | Site responsibility / scope | Result and data boundary | Retry / risk |
| --- | --- | --- | --- | --- |
| `list_sites` | Authenticate account; bounded account connection list with opaque cursor and limit 1..100 (default 50). No arbitrary site network scans. | No site call or scope. Connection bootstrap is authorization, not a content tool. | Connection summaries: connection ID, site ID, approved address/display label, scope summary, connection state, last verified time. Cached health labelled as such; no secrets/content. | Safe read retry; private account metadata. |
| `site_info` | Resolve connection and call authenticated status; input connection ID only. | Status/discovery and current permissions; `status:read`. | Site summary, version, canonical identity, effective capabilities/limits and switches. Exclude token hashes/hints and internal credential IDs from tool output. | Safe read retry; low-risk operational metadata, not System Check/server diagnostics. |
| `list_stream_posts` | Forward page (default 1), per_page (default 50, 1..100), orderby (`id`, `created_at`, `updated_at`, `published_at`), order (default asc), modified_after, include_html (default false). | Current published query/projection only; `stream:read`. Force published, reject private status requests. | Published post collection plus current pagination/filter fields. Includes public location labels only; no private Following/owner data. Pagination is not a frozen catalog snapshot. | Safe read retry; public-content read. |
| `get_stream_post` | Positive post ID and optional include_html, plus connection. Never translate a slug collision into identity. | Published-only lookup by ID; `stream:read`. | Current published post projection or `stream_post_not_found`. No private-state fallback. | Safe read retry; public-content read. |
| `create_stream_draft` | Required logical operation ID, Markdown content (may be empty with media), optional title/slug/description/seo_title/robots, up to four existing media IDs, inline/gallery and before/after presentation. Freeze exact outbound payload and idempotency key before dispatch. | `stream:draft`; core creates a new draft. Relay and site adapter explicitly set draft, reject status/schedule/confirmation/unknown mutation fields. Existing-media references retain current site validation. | Current creation receipt: new post ID, draft status, final slug/title, owner edit URL, null public URL, embedded-media/gallery representation. Private draft metadata; media URLs remain separately public. | Required site creation idempotency; bounded draft write, no publishing. |
| `list_media` | Connection, optional search (max 200 Unicode characters), limit 1..100 (default 50). No claim of full-library pagination. | Future Gate 7 bounded active-media query; new owner-approved `media:read`. No Trash, filesystem, uploader identities, or usage/deletion certification. | Recent media collection, newest created_at then ID descending; fields allowlisted from existing media response: ID, public URL/path, public filename, MIME, size/dimensions, alt text/caption, privacy status/label, Markdown, owner edit URL. Exclude original filename and arbitrary private diagnostic notes. Report applied limit and that results are bounded. | Safe read retry; private library metadata whose files may be public. |
| `upload_media` | Connection, one authorized file handle/byte stream, filename, alt_text (max 255 code points), caption (max 500), operation ID for relay tracking. Prefer streamed multipart; no arbitrary fetch URL in initial tool. | `media:upload`, feature enabled, image-only current core validation/privacy handling. Site owns size/type limits and final processing. | Created media receipt with allowlisted media fields as above. Upload makes a publicly addressable media file even without a published post. | No site idempotency today. One dispatch, no automatic retry after uncertain dispatch; reconcile in Admin/media list. Bounded public-media write. |

Draft's initial tool surface deliberately excludes embedded uploads/imports;
upload separately, then reference returned site media IDs. This avoids claiming
an atomic post-plus-upload transaction the current Remote API does not provide.
Remote creation prepares media before post insertion; a later failure can leave
uploaded library items. Existing direct API behavior remains unchanged.

For draft retries preserve credential identity as well as key/payload: current
idempotency is token-scoped. Do not replay an uncertain request under a rotated
credential or after retention expiry. Relay operation tracking can prevent its
own duplicate dispatches but cannot manufacture site exactly-once semantics.
For uploads, a definite rejection before dispatch can be retried after correction;
timeouts, disconnects, and invalid responses after dispatch mean unknown outcome.
Neither tool silently chooses a new key to work around uncertainty.

Gate 3 remains the future existing-object contract: opaque resource-wide revision,
strong REST ETag and one strong If-Match, common atomic core semantics, and no
stale business/federation side effects. Slug coordination is not revision fencing.
Editing and scheduling mutation await Gate 8; publishing/destruction and their
final approval model await Gate 9. Initial tools exclude Profile/comment writes,
restoration/deletion, software upgrades, and unrestricted settings/theme operations.

## Audit, limits, and media transfer

Gate 6 extends site audit foundations for grant/client ID, operation, site request
ID, UTC timestamp, outcome, stable result/error code, and existing hashed network
metadata. Record approval/denial, exchange success/failure, scope changes,
revocation, reconnect, and operation outcomes. Exclude prompts, conversations,
bearers, codes/verifiers, raw request/response bodies, and unrelated private content.
Authorization-state audit evidence is committed with the corresponding state
change; failure before grant issuance fails closed. Ordinary content-operation
audit remains best effort and must never trigger reexecution or rollback after a
committed result. Never label missing audit as success. Relay logging/retention
and monitoring are Gate 6 work; no content mirror is permitted as an audit shortcut.

Retain current site per-token/IP protections. Future grant credentials participate
in site controls; Gate 6 adds relay account, connection, IP/abuse and concurrency
limits separately, with bounded queues/backoff and budgets for shared egress IPs.
Do not weaken the target site's limits to accommodate the relay. Authorization
routes need their own site-side abuse limits, not just authenticated posting quotas.

Media goes to the target site, which owns validation, alt text, gallery semantics,
privacy processing and final public storage. Connect prefers streamed transfer.
If staging is necessary, use private encrypted staging, per-account and global
byte/concurrency limits, a maximum 15-minute TTL, deletion on completion/failure,
and independent expiry cleanup after crashes. No public staging bucket or durable
media backup. Transfer caps are the lower of site limits and relay policy, with
bounded time and response size. Gate 6 fixes operational relay limits before
deployment; Gate 7 proves transfer and cleanup. Preserve PR #12 sanitization.

## Exact Gate 5B prerequisite plan

Gate 5B contains only the following three behavior-preserving extractions and
their regression evidence. Proposed helper names below are design targets, not
currently implemented functions. No migrations, runtime scopes, endpoints, wire
fields, credentials, or UI features are added. No change to version/manifest is
authorized here. Run the existing Compatibility matrix after refactoring.

### B1. Neutral prepared Stream creation command

- Problem/current code: `bms_api_insert_remote_stream_post()` in `app/api.php`
  owns preparation/insertion orchestration; `admin/quick-post.php` separately
  assembles metadata/Markdown and calls insert. Shared metadata helpers already
  exist in `app/functions.php`, with insert/lock authority in `app/database.php`.
- Boundary: introduce `app/stream-commands.php` with an explicit prepared-intent,
  body, actor-ID creation service returning the inserted ID and normalized page.
  It delegates to existing metadata and insert-only helpers. Provide explicit
  internal collision policies: Remote allocation/retry (five attempts) and Admin
  prepared-slug rejection. These are adapter policy, not caller-controlled tool
  flags. Keep API-specific safe exception mapping in the API adapter.
- Admin impact: route ordinary new quick-composer creation through this seam;
  retain form normalization, CSRF/capabilities, composer receipts, media transaction,
  input preservation, and redirect/flash behavior. Preserve owner-reply service's
  dedicated transaction/target linkage and insert-only path; no reply refactor is
  needed. Existing editor/import/lifecycle semantics are not broadened.
- Remote impact: existing creation helper delegates; preserve payload aliases,
  differing field limits/defaults, status/confirmation checks, media preparation,
  two-megabyte document bound and exact response/error shapes. Do not normalize
  Admin and API into new common input rules incidentally.
- Future MCP impact: draft adapter reaches this same site command through REST;
  no duplicate publishing or slug allocator in the relay.
- Invariants/tests: compare Admin draft/continue/publish/schedule and API three
  statuses before/after; explicit/generated slug collisions, aliases/all statuses,
  five-attempt exhaustion, outer rollback and lock lifetime, prepared media retained
  across insertion retries, creator ID, terms, and independent ActivityPub Create.
  Retain PR #11 ownership/replay tests, PR #12 errors, all PR #13 scenarios and
  composer uncertain-result behavior. No retry may repeat upload preparation.
- Migration/scope/wire impact: none. This belongs to Gate 5B because it moves
  existing behavior to a reusable site service, not Connect feature delivery.

### B2. Explicit published Stream query

- Problem/current code: `bms_api_read_stream_posts()` and query helpers in
  `app/api.php` read `$_GET`, query the database, and construct API results together.
  Public SQL policy is reusable but not cleanly callable with explicit options.
- Boundary: extract `app/stream-query.php` with published list and positive-ID
  lookup using validated explicit options, bound SQL values and fixed order-column
  allowlists. Return rows plus paging metadata. Keep HTTP parsing, API exceptions,
  headers and `bms_api_stream_post_read_payload()` projection in `app/api.php`.
  Keep term batching and core row/Markdown/location helpers reused, not copied.
- Admin impact: no catalog/editor conversion required; their private statuses,
  section-based selection, and presentation differ intentionally.
- Remote impact: preserve current published-only selection, numeric clamping,
  defaults, date parsing, sorting/tie-breaks, empty-page/out-of-range behavior,
  headers, public metadata and optional HTML. No extra capability or ETag.
- Future MCP impact: existing REST reads use the extracted query; a later site
  adapter can reuse it without populating HTTP globals.
- Invariants/tests: list/single projection parity; draft/scheduled/trash/Page
  exclusion; published owner-reply behavior unchanged; all order/filter/pagination
  boundaries; private place fields excluded; terms batching; database failure
  stays sanitized failure, not an empty successful catalog.
- Migration/scope/wire impact: none. This is prerequisite separation of existing
  query and transport; actual relay calls and tool proof remain Gate 7.

### B3. Strict bounded media query under the existing wrapper

- Problem/current code: `bms_media_list()` in `app/media.php` returns at most 500
  recent rows with search/status filtering but catches every failure as `[]`.
  `bms_media_user_scope_sql()` currently contributes no owner predicate in this
  single-owner model. Neither helper independently authorizes remote callers.
  Admin library/picker apply their existing access controls.
- Boundary: extract a throwing `bms_media_query()` in the same core file with
  explicit limit/search/status arguments and unchanged SQL/filter/order behavior.
  Keep `bms_media_list()` as the existing compatibility wrapper, delegating and
  retaining its empty-list fallback. Authorization is required at each adapter.
  The strict helper exposes failure to a future API adapter for sanitization.
- Admin impact: unchanged calls, limits (200 library, 160 picker), ordering,
  projection and fallback. Do not add pagination, counters, or redesign the UI.
- Remote impact: no current media-list route, hence no new endpoint or scope.
  Existing `bms_api_media_response()` and `bms_editor_media_payload()` remain
  adapter representations; Gate 7 adds the narrow allowlisted listing projection.
- Future MCP impact: Gate 7 can tell an empty active library from an unavailable
  query, limit to 100, and project safe fields without duplicating media SQL.
- Invariants/tests: empty success versus thrown database failure, wrapper fallback
  parity, active/trash/all filters for existing callers, limits/search/order ties,
  unchanged row identity, no writes, and no accidental authorization claim for
  an unauthenticated core caller. Gate 7 tests scope/projection at its endpoint.
- Migration/scope/wire impact: none. This small extraction belongs to Gate 5B;
  media-list route, `media:read`, tool schemas and end-to-end proof belong to Gate 7.

No Gate 5B media-upload refactor required. No status/settings service refactor,
new authentication abstraction, scheduler rewrite, idempotency redesign, media
exception redesign, or slug coordination redesign is needed. New grant/credential
storage and request authorization are Gate 6 implementation, not disguised
prerequisite refactors. Broad cleanup or merely symmetrical services are excluded.

## Deferred implementation and acceptance

| Gate | Deferred work |
| --- | --- |
| 6 | Site ID/setup and recovery guards; client/grant/credential storage and migrations as needed; code/PKCE/state routes and Admin UI; live grant authorization; discovery/audit additions; account/site routing; encrypted relay credentials; health, logging, abuse limits; hosted service, hostname, VPS/Nginx/TLS/DNS and backups only with separate authorization. |
| 7 | Actual seven tool implementations; media-read scope/endpoint/projection; site-info/Stream/draft/media calls through relay; streamed transfer; custom-domain and MCP end-to-end proof. |
| 8 | Resource revision runtime across every relevant writer; edits and scheduling mutations; concurrent/stale-write and no-side-effect proof. |
| 9 | Direct Connect publishing, destruction/restoration and final high-risk confirmation/approval model. |

Gate 6 still chooses service hostname, account login integration, implementation
language/database, deployed resource budgets, logging retention and operating
procedures. These do not reopen the site authority, origin binding, grant, code,
scope, and recovery contracts here. Protocol implementation must test wrong
callback/state/verifier/issuer, parallel exchange, denial/expiry, cross-account
linking, origin changes/clones, restored revoked credentials, live scope reduction,
revocation races, SSRF/rebinding, and relay outage before accepting a connection.

Gate 5A acceptance requires accurate source grounding, coherent future contracts,
the bounded B1-B3 plan, passing documentation/source checks, and human review.
No runtime, schema, release, deployment, or roadmap change follows automatically
from this architecture document.

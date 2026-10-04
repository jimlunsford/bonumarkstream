# Bonumark Stream API

Bonumark Stream includes an optional API for trusted external clients. The API is disabled by default and must be enabled by the Admin under **Settings → Remote Posting**.

This API is platform-neutral. It can be used by custom clients, automation tools, shortcuts, scripts, future apps, and ChatGPT Actions.

## Authentication

Authenticated endpoints use a bearer token:

```text
Authorization: Bearer YOUR_API_TOKEN_HERE
```

Tokens are created in the admin area under:

```text
Admin → Settings → Remote Posting
```

Bonumark Stream stores only token hashes. The full token is shown once when created.

## Scopes

| Scope | Purpose |
| --- | --- |
| `status:read` | Allows authenticated API status checks. |
| `stream:draft` | Allows remote stream post creation as drafts. |
| `stream:publish` | Allows remote stream post publishing when direct publishing is enabled. |

| `media:upload` | Allows remote image uploads when remote media uploads are enabled. |

## Admin controls

Remote posting has these Admin settings:

| Setting | Default | Purpose |
| --- | --- | --- |
| Enable Remote API | Off | Master switch for authenticated API requests. |
| Allow direct remote publishing | Off | Allows API clients to create published posts when they also have the publish scope. |
| Default remote post status | Draft | Used when a client does not send a `status` field. |
| Require explicit publish confirmation | On | Requires `confirm_publish: true` or `confirmation: "publish"` for published requests. |
| API rate limit per token per minute | 60 | Limits accidental loops and abuse. |
| Allow remote image uploads | Off | Allows tokens with `media:upload` to upload image files through the API. |

## Idempotency

`POST /api/v1/stream/posts` supports idempotency to prevent duplicate posts when a client retries a request.

Preferred header:

```text
Idempotency-Key: unique-client-request-id
```

Payload alternatives:

```json
{
  "idempotency_key": "unique-client-request-id"
}
```

or:

```json
{
  "client_request_id": "unique-client-request-id"
}
```

If the same token repeats the same request with the same key, Bonumark Stream returns the stored response instead of creating a duplicate post. If the same key is reused for different request content, the API returns `409 idempotency_key_conflict`.

An identical unfinished request returns `409 idempotency_key_processing`. Only the execution that inserted the reservation may complete or release that exact internal row. A rejected duplicate or replay receives no reservation ownership. Unique-key acquisition collisions use the same replay, processing, or conflict responses.

Completed responses expire 24 hours after reservation acquisition. An expired completed key is reusable when accessed, with bounded opportunistic cleanup of other expired completed records. Unfinished reservations are never removed merely because their expiry timestamp passed: they may represent active work or an uncertain committed result. Such requests remain processing (or conflicting for a different payload) until their outcome is resolved. Do not use a new key to blindly repeat an uncertain creation.

Response storage remains best effort and separate from post creation. Cache-write failure does not turn a successful creation into an API failure, and this protection is not a permanent exactly-once guarantee. Recovery of abandoned or uncertain unfinished reservations is not automated by this change. Internal reservation IDs are not returned to clients.

## Status endpoint

```text
GET /api/v1/status
```

This endpoint can be requested without a token. If a bearer token is included and the API is enabled, the response includes token metadata.

### Unauthenticated response

```json
{
  "ok": true,
  "api": "bonumark-stream",
  "version": "0.8.2",
  "remote_posting_enabled": false,
  "authenticated": false,
  "direct_publish_enabled": false,
  "default_status": "draft",
  "publish_confirmation_required": true,
  "remote_media_upload_enabled": false,
  "idempotency": {
    "supported": true,
    "header": "Idempotency-Key",
    "payload_fields": ["idempotency_key", "client_request_id"]
  },
  "endpoints": {
    "status": "https://example.com/api/v1/status",
    "stream_posts": "https://example.com/api/v1/stream/posts",
    "media": "https://example.com/api/v1/media",
    "media_import": "https://example.com/api/v1/media/import"
  }
}
```

### Authenticated response

```json
{
  "ok": true,
  "api": "bonumark-stream",
  "version": "0.8.2",
  "remote_posting_enabled": true,
  "authenticated": true,
  "direct_publish_enabled": true,
  "default_status": "draft",
  "publish_confirmation_required": true,
  "remote_media_upload_enabled": false,
  "idempotency": {
    "supported": true,
    "header": "Idempotency-Key",
    "payload_fields": ["idempotency_key", "client_request_id"]
  },
  "endpoints": {
    "status": "https://example.com/api/v1/status",
    "stream_posts": "https://example.com/api/v1/stream/posts",
    "media": "https://example.com/api/v1/media",
    "media_import": "https://example.com/api/v1/media/import"
  },
  "token": {
    "id": 1,
    "name": "Example Client",
    "scopes": ["status:read", "stream:read", "stream:draft", "stream:publish", "media:upload"],
    "expires_at": ""
  }
}
```


## Read Stream posts

`GET /api/v1/stream/posts` retrieves published Stream posts for authorized third-party clients. It requires a bearer token with the `stream:read` scope. The endpoint is general-purpose and can support archival tools, migrations, search systems, feed clients, mobile applications, and other integrations.

By default it returns published posts ordered by ascending database ID. Supported query parameters are:

- `status`: `published` only (default `published`)
- `page`: catalog page, starting at `1`
- `per_page`: `1` through `100`
- `orderby`: `id`, `created_at`, `updated_at`, or `published_at`
- `order`: `asc` or `desc`
- `modified_after`: a date or ISO-8601 timestamp
- `include_html`: `1` to include rendered HTML alongside Markdown
- `id`: retrieve one Stream post by stable database ID

Example catalog request:

```bash
curl -H "Authorization: Bearer YOUR_API_TOKEN_HERE" \
  "https://example.com/api/v1/stream/posts?status=published&per_page=100&page=1&orderby=id&order=asc&include_html=1"
```

The catalog response includes `posts`, `pagination`, and normalized filters. Each published post includes its stable ID, title, slug, status, permalink, description, category, tags, Markdown content, optional rendered HTML, content hash, pin state, timestamps, and public metadata. The response also sends `X-Bonumark-Total` and `X-Bonumark-Total-Pages` headers.
Local Places data is reduced to the public location labels selected for the post. Saved place IDs, coordinates, and undisplayed location components are not returned.

Example single-post request:

```bash
curl -H "Authorization: Bearer YOUR_API_TOKEN_HERE" \
  "https://example.com/api/v1/stream/posts?id=42&include_html=1"
```

## Future revision-safe existing-object mutations

**Design contract only, not implemented endpoint behavior.** Current Remote Posting reads published Stream Posts (including `id` selection) and creates new posts. It has no general existing-post edit operation, opaque `revision` field, or ETag/If-Match concurrency behavior. Current read fields include `id`, `content`, `content_hash`, `modified_at`, publication timestamps, pin state, and public metadata. Neither `content_hash` nor `modified_at` is the revision defined here. Current creation and OpenAPI remain unchanged.

The [core invariant](ARCHITECTURE.md#revision-safe-mutation-invariant) governs future interfaces. Revision fencing prevents overwriting unseen state; idempotency prevents re-executing the same request. Neither replaces authentication, scopes, publication confirmation, validation, or lifecycle rules.

### Revision representation and HTTP preconditions

An eligible resource read exposes an opaque, case-sensitive JSON string named `revision`. A canonical single-resource REST read also sends a **strong** `ETag` whose quoted opaque value is exactly that string. For example, an illustrative resource fragment `{"id":42,"revision":"opaque-A"}` corresponds to `ETag: "opaque-A"`. Examples here do not define a currently callable mutation route or a token encoding.

The canonical mutation representation must be deterministic for a given revision. It must exclude independently changing counters, derived HTML, request-specific fields, and negotiated variants that would change bytes without changing that validator. The same strong ETag must not label different representation bytes or content codings. Implementations must use a fixed canonical representation/coding for this contract, or separately validate alternate representations; alternate-view and collection ETags are not mutation tokens. A collection may expose each item's `revision` only if each item and token are a consistent snapshot. Its HTTP ETag, if any, describes the collection, never one post. Current `include_html` reads do not acquire concurrency semantics through this documentation.

A future REST mutation targets the same resource identity and canonical representation and supplies **exactly one strong quoted token** in `If-Match`, for example `If-Match: "opaque-A"`. This API deliberately requires one specific version: wildcard `*`, weak tags (`W/`), lists, empty values, unquoted values, and multiple header occurrences are rejected with `400 invalid_revision`. A body-level `revision` precondition is not accepted by this REST contract; its presence is also `400 invalid_revision`, whether it agrees with the header or not. This prevents two competing inputs. `If-Unmodified-Since`, content hashes, and publish confirmation cannot substitute for `If-Match`.

This restricted application profile uses strong comparison and failed-precondition semantics from [RFC 9110](https://www.rfc-editor.org/rfc/rfc9110.html#section-13.1.1), and the required-precondition response from [RFC 6585](https://www.rfc-editor.org/rfc/rfc6585.html#section-3). It does not turn ETags into credentials. A well-formed single token that is not current for this resource is a conflict, including a token issued for another resource; clients cannot infer object existence or permissions from tokens.

### MCP and other application clients

MCP and other non-HTTP adapters accept the opaque string as a required `revision` input. REST removes HTTP quoting and passes exactly the same expected value into the common core service. Core uses one resource-wide comparison model for every adapter. Successful non-HTTP results contain the new `revision`; errors retain the stable codes below and their reread meaning without requiring HTTP headers. Admin must ultimately use the same core semantics, with presentation appropriate to its forms.

### Errors and client recovery

After authentication, authorization, and ordinary request validation, fenced operations use:

| Condition | HTTP status | Stable error code | Client action |
| --- | --- | --- | --- |
| Required `If-Match` absent (or non-HTTP revision input absent) | 428 Precondition Required | `revision_required` | Read the authorized resource and prepare the operation with its revision. |
| Present but invalid/unsupported revision input | 400 Bad Request | `invalid_revision` | Correct the input; do not drop the precondition. |
| Valid expected token differs from current state at the mutation boundary | 412 Precondition Failed | `revision_conflict` | Reread current authorized state, reassess the intended change, and obtain any required renewed approval. |
| Existing idempotency key reused with different request identity | 409 Conflict | `idempotency_key_conflict` | Use a new key for a newly prepared request. |
| Exact idempotent request is still in progress | 409 Conflict | `idempotency_key_processing` | Resolve the original outcome before another execution. |

The existing error envelope is preserved. For a stale mutation:

```json
{
  "ok": false,
  "error": {
    "code": "revision_conflict",
    "message": "The resource changed. Read its current state before preparing another mutation."
  }
}
```

The code itself is the machine-readable instruction to reread; clients must not parse message text. Conflict responses contain neither the current object nor a replacement token/ETag. Send `Cache-Control: no-store` on revision errors (428 responses must not be cached). No blind retry, automatic overwrite, or automatic merge is permitted. A new read does not alone authorize reapplying an old change. If the resource is no longer available to an authorized read, stop and resolve that state. Normal authentication/authorization responses retain precedence. For a first execution, ordinary not-found responses also take precedence; a missing or inaccessible resource does not become a token-disclosure oracle. If a resource disappears after initial lookup, re-evaluate that result under the mutation boundary and return the ordinary not-found result without side effects. An authorized exact completed replay is resolved before the first-execution existence check, including a replay of successful deletion.

`revision_required` is distinct from the existing `publish_confirmation_required`, even though both can use HTTP 428. Valid revision input never grants publishing authority or confirms publication.

### Successful mutation

A successful first execution returns the committed canonical resource with its new JSON `revision` and matching strong `ETag`, captured within the mutation boundary. Use a response with a JSON body, not a bare 204 that hides the application revision. A later concurrent write can make that result stale immediately; the returned revision describes this operation's committed outcome, not a promise of continued currency. Even a successful identical-value save advances the revision; an exact replay returns the original revision and performs no new save.

Trash, unpublish, and restoration retain the logical resource and return its new revision under authorized access. Permanent deletion is the explicit terminal exception to a live-resource representation: return an outcome containing the stable resource ID, `deleted: true`, and a newly issued terminal `revision`. It records the deletion outcome and is never valid for another write. Do not emit a resource ETag for an absent representation or fabricate a live post. An exact stored idempotency replay can return that deletion outcome; other reads/mutations use the ordinary not-found result. This does not require retaining deleted content or changing the existing ActivityPub tombstone contract. MCP returns the same terminal result semantics.

Future endpoint methods must preserve HTTP semantics as well as this contract. In particular, an implementation using PUT with server-side transformation cannot emit a validator contrary to RFC 9110 section 9.3.4; choose a mutation method/representation that can return the canonical committed result and validator correctly.

### Idempotency ordering and failure boundary

Current creation keys are scoped to the authenticated token. Header `Idempotency-Key` takes precedence over body `idempotency_key`, `client_request_id`, or the supported `request_id` fallback. The current fingerprint covers method, route, and recursively normalized payload. A stored exact response replays its status and JSON; changed content gets `409 idempotency_key_conflict`, and an empty in-progress response gets `409 idempotency_key_processing`. Completed responses have the 24-hour retention and unfinished-reservation protections described in [Idempotency](#idempotency), not a permanent exactly-once guarantee. Current response storage is best effort and separate from creation; this gate does not alter or strengthen that implementation by assertion.

Future fenced operations that support idempotency must follow this ordering:

1. Authenticate and verify current authorization/scopes and ordinary request validity, including required revision presence/shape, before disclosing a replay. A revoked token cannot retrieve a stored response. Keep publication confirmation and other action safeguards independent.
2. Resolve the caller-scoped idempotency key and request fingerprint. The fingerprint must bind method/operation, canonical target identity, mutation payload, and expected revision, including REST's header value. Changing the revision with the same key is a different request. Return a key conflict before attempting a new revision comparison when an existing key has a different fingerprint.
3. A completed exact replay returns the original stored outcome and its original revision/ETag, where applicable, without comparing that old expected token to today's revision and without repeating the mutation or side effects. Treat it as historical execution evidence, not a fresh read. An in-progress duplicate returns its distinct processing error.
4. For a first execution, reserve/deduplicate the request, then compare the expected revision inside the real authoritative mutation boundary. A new key never bypasses that check. On conflict, apply no business state, release only the uncompleted reservation owned by this execution, and return `412 revision_conflict`. A duplicate must never release another in-flight execution's reservation.
5. Commit the accepted mutation, next revision, and recoverable idempotency outcome coherently. Later implementation must close the commit/response-storage crash window, either transactionally or with durable recovery of that exact outcome. Do not assume current creation's best-effort cache proves this future guarantee. Failed/uncertain execution must never be rerun blindly; cleanup cannot discard evidence of a committed mutation.

After a stale conflict, a newly prepared request uses a new key and the newly read revision. After an uncertain response, retry the exact original request/key to resolve its outcome. If its record has expired or is unavailable, revision fencing still applies to any first execution; do not promise an indefinite replay window.

### Coverage and implementation prerequisite

Apply this contract to remote existing Stream Post edits and metadata, rescheduling, publish/unpublish/republish, trash/permanent deletion, restoration, future Page edits, and Profile/settings/theme-setting saves that risk unseen overwrites. Resource-wide fencing is the default. Creation normally needs idempotency rather than a revision. Append-only actions and explicitly contracted narrow toggles can use dedicated semantics; they cannot silently become general writes. The [architecture invariant](ARCHITECTURE.md#revision-safe-mutation-invariant) defines aggregate coverage, writer participation, scheduler ordering, and ActivityPub boundaries.

Before exposing a mutation, provide authorized reads for every state it can target, including drafts, scheduled posts, and trash where applicable. Today's published-only read endpoint is insufficient for those operations. This is an implementation prerequisite, not authorization to add routes in this gate. All relevant local writers must participate before remote fencing can be claimed effective.

### Required implementation tests

These are acceptance requirements for future implementation, not tests claimed to pass today:

| Scenario | Required evidence |
| --- | --- |
| Consistent reads and HTTP validators | Resource and token come from one snapshot; canonical JSON revision and strong ETag agree; alternate HTML/coding/collection validators cannot be confused with the mutation validator. |
| Current, missing, malformed, and stale preconditions | Current succeeds; absent returns 428; weak/wildcard/list/duplicate/unquoted/empty/body inputs return 400; old or wrong-resource token returns 412 with stable codes and no replacement token. |
| Rapid and reversible changes | Two changes in one second, A-to-B-to-A, metadata-only changes, pinning, restore, and republish invalidate old tokens; repeated reads do not. Recreated resources and restored/cloned divergent installations cannot reuse old tokens. |
| Competing writers | Deliberately interleave two writes from the same revision: only one commits, including Admin-versus-REST and REST-versus-MCP. Exercise all participating writer paths and both database families. |
| Partial failure and stale rejection | Compare all affected rows/history, schedules, trash, media/files, publication generations/events, tombstones, and delivery queues before and after; a failed/stale mutation leaves no business effects or partial token advance. Allowed audit/accounting is checked separately. |
| Idempotency | Exact completed replay after a later edit returns the original outcome without execution; different payload/target/revision conflicts; concurrent duplicate is processing; expired/missing record cannot bypass fencing. Exercise crash recovery after commit and before response delivery. |
| Scheduler race | Scheduler-first makes the old remote revision stale; reschedule/edit-first forces reevaluation of current due time/status. No stale scheduler snapshot publishes a newly postponed post. |
| Federation | Accepted material transitions preserve Create/Update/Delete and generation rules; stale/rolled-back requests create no activity or delivery; identical-value accepted saves advance revision without spurious federation events. |
| Lifecycle and permissions | Restoration checks the current trashed/resource revision; deletion returns a terminal result; replay and new requests still enforce authorization; vanished/inaccessible resources expose no current state. |
| Client behavior | Clients reread and reassess after `revision_conflict`; they never substitute the latest token onto an old payload automatically. |

## Create stream post

```text
POST /api/v1/stream/posts
```

Required scopes:

| Request type | Required scopes |
| --- | --- |
| Draft post | `stream:draft` |
| Published post | `stream:draft`, `stream:publish` |
| Scheduled post | `stream:draft`, `stream:publish` |
| Post with `media_upload` or `media_uploads` | Post scopes above plus `media:upload` |

### Create draft request

```json
{
  "content": "This is a draft created from a trusted external client.",
  "status": "draft",
  "client_request_id": "example-draft-001"
}
```

### Create published request

Direct publishing only works when all of these are true:

- The Remote API is enabled.
- Direct remote publishing is enabled by the Admin.
- The token has `stream:draft` and `stream:publish` scopes.
- If confirmation is required, the request includes `confirm_publish: true` or `confirmation: "publish"`.

```json
{
  "content": "This is a published post created from a trusted external client.",
  "status": "published",
  "confirm_publish": true,
  "client_request_id": "example-published-001"
}
```

### Create scheduled request

Scheduled publishing only works when all of these are true:

- The Remote API is enabled.
- Direct remote publishing is enabled by the Admin.
- The token has `stream:draft` and `stream:publish` scopes.
- The request includes a future `scheduled_at` value in the site timezone.

```json
{
  "content": "This post will publish later from a trusted external client.",
  "status": "scheduled",
  "scheduled_at": "2026-06-25T09:30",
  "client_request_id": "example-scheduled-001"
}
```

If `scheduled_at` is present and `status` is omitted, Bonumark treats the request as scheduled. `publish_at` is accepted as an alias.

### Create a post with existing media embedded

```json
{
  "content": "Testing a remote post with existing media.",
  "status": "draft",
  "media_ids": [42],
  "media_position": "after",
  "client_request_id": "example-embed-existing-001"
}
```

### Create a structured photo gallery

Use `media_display: "gallery"` with one to four existing, uploaded, or imported image items. Gallery media is stored as ordered post metadata instead of being inserted into the Markdown body.

```json
{
  "content": "Four photos from today.",
  "status": "draft",
  "media_ids": [42, 43, 44, 45],
  "media_display": "gallery",
  "client_request_id": "example-gallery-001"
}
```

The first gallery image becomes `featured_media` for social metadata and backward compatibility. Non-image media is rejected in gallery mode. Existing clients keep the current inline behavior when `media_display` is omitted.

### Create a post and upload image media in the same request

```json
{
  "content": "Testing a remote post with uploaded media.",
  "status": "draft",
  "media_uploads": [
    {
      "filename": "example.png",
      "content_base64": "BASE64_IMAGE_CONTENT_HERE",
      "alt_text": "Example uploaded image",
      "caption": "Optional caption"
    }
  ],
  "media_position": "after",
  "client_request_id": "example-embed-upload-001"
}
```

Optional fields:

```json
{
  "title": "Optional admin title",
  "slug": "optional-slug",
  "description": "Optional description",
  "seo_title": "Optional SEO title",
  "robots": "noindex",
  "date": "2026-06-10",
  "scheduled_at": "2026-06-25T09:30",
  "media_id": 42,
  "media_url": "https://example.com/media/2026/06/example.png",
  "media_ids": [42, 43],
  "media_urls": ["https://example.com/media/2026/06/example.png"],
  "media_items": [
    {
      "media_id": 42,
      "alt_text": "Override alt text",
      "caption": "Optional caption"
    }
  ],
  "media_upload": {
    "filename": "example.png",
    "content_base64": "BASE64_IMAGE_CONTENT_HERE",
    "alt_text": "Example uploaded image"
  },
  "media_import_url": "https://example.com/image.jpg",
  "media_imports": [
    {
      "image_url": "https://example.com/image.jpg",
      "alt_text": "Imported image alt text",
      "caption": "Optional caption"
    }
  ],
  "media_position": "before",
  "media_display": "gallery"
}
```

Notes:

- `content` may also be sent as `body` or `body_markdown`.
- Posts may be text-only, media-only, or text with embedded media.
- Embedded media is appended after the content by default. Use `media_position: "before"` to place embedded media first.
- `media_display` defaults to `inline`. Use `gallery` to store one to four image items as an ordered photo gallery without inserting them into the Markdown body.
- Gallery mode accepts images only. The first gallery image also becomes `featured_media` for existing themes, feeds, and social metadata.
- Content plus embedded media must be 5,000 characters or fewer.
- Referenced media URLs must point to existing Bonumark media library items.
- One-step uploads and URL imports inside `POST /api/v1/stream/posts` only work when remote media uploads are enabled and the token also has the `media:upload` scope.
- URL imports accept public HTTP/HTTPS image URLs only. Bonumark rejects local, private, reserved, unsafe, non-image, oversized, or unsupported remote files.
- Known fake 1x1 placeholder uploads are rejected with `placeholder_media_rejected`.
- Slugs are normalized and made unique if needed, across Stream statuses and reserved permalink aliases.
- Every successful creation inserts its own logical post. A matching slug never authorizes an update or adoption of another post's ID, including when requests use different idempotency keys.
- Slug allocation and persistence share a database transaction lock. A creation collision rebuilds slug-dependent metadata from the original request intent using the canonical allocator, with at most five insertion attempts. Exhaustion returns `409 slug_conflict`; unrelated database or lock failures return sanitized `500 server_error`.
- Already-prepared media is retained across post-insertion retries. Each idempotency key continues to own only its own reservation and stored outcome.
- `scheduled_at` and `publish_at` use the site timezone for input and are stored internally as UTC.

### Draft response

```json
{
  "ok": true,
  "post": {
    "post_id": 123,
    "status": "draft",
    "slug": "trusted-external-client-draft",
    "title": "Trusted External Client Draft",
    "filename": "trusted-external-client-draft.md",
    "edit_url": "https://example.com/admin/edit.php?type=draft&file=trusted-external-client-draft.md",
    "public_url": null,
    "embedded_media": [
      {
        "media_id": 42,
        "url": "https://example.com/media/2026/06/example.png",
        "markdown": "![Example uploaded image](https://example.com/media/2026/06/example.png)",
        "source": "uploaded"
      }
    ],
    "media_display": "inline",
    "media_gallery": [],
    "media_position": "after"
  }
}
```

### Published response

```json
{
  "ok": true,
  "post": {
    "post_id": 124,
    "status": "published",
    "slug": "trusted-external-client-post",
    "title": "Trusted External Client Post",
    "filename": "trusted-external-client-post.md",
    "edit_url": "https://example.com/admin/edit.php?type=published&file=trusted-external-client-post.md",
    "public_url": "https://example.com/stream/trusted-external-client-post/",
    "embedded_media": [],
    "media_display": "inline",
    "media_gallery": [],
    "media_position": "after"
  }
}
```

## Upload image media

```text
POST /api/v1/media
```

Required scope:

```text
media:upload
```

Remote media uploads only work when all of these are true:

- The Remote API is enabled.
- Remote image uploads are enabled by the Admin.
- The token has the `media:upload` scope.
- The uploaded file passes the existing Bonumark media validation rules.
- The uploaded file is an image. Non-image media remains admin-only in this pass.

Multipart form request fields:

| Field | Required | Purpose |
| --- | --- | --- |
| `media_file` | Yes | Image file upload. |
| `alt_text` | No | Alt text or description. |
| `caption` | No | Optional caption. |
| `client_request_id` | No | Request ID shown in the audit log. |

JSON base64 request:

```json
{
  "filename": "example.png",
  "content_base64": "BASE64_IMAGE_CONTENT_HERE",
  "alt_text": "Example uploaded image",
  "caption": "Optional caption",
  "client_request_id": "example-media-001"
}
```

Data URLs are also accepted in `content_base64`.

### Media response

```json
{
  "ok": true,
  "media": {
    "media_id": 42,
    "url": "https://example.com/media/2026/06/example.png",
    "public_path": "media/2026/06/example.png",
    "filename": "example.png",
    "original_filename": "example.png",
    "mime_type": "image/png",
    "file_size": 12345,
    "width": 1200,
    "height": 800,
    "alt_text": "Example uploaded image",
    "caption": "Optional caption",
    "markdown": "![Example uploaded image](https://example.com/media/2026/06/example.png)",
    "edit_url": "https://example.com/admin/media-edit.php?id=42"
  }
}
```

Uploaded media can still be used in a second request. Clients can also upload media or import media by URL and embed it in a stream post in the same `POST /api/v1/stream/posts` request.

## Import image media by URL

```text
POST /api/v1/media/import
```

Required scope:

```text
media:upload
```

Remote media imports only work when all of these are true:

- The Remote API is enabled.
- Remote image uploads are enabled by the Admin.
- The token has the `media:upload` scope.
- The URL is public HTTP or HTTPS on port 80 or 443.
- The URL resolves only to public IP addresses.
- The downloaded file passes Bonumark media validation.
- The downloaded file is an image.

Request:

```json
{
  "image_url": "https://example.com/image.jpg",
  "alt_text": "Imported image alt text",
  "caption": "Optional caption",
  "client_request_id": "example-media-import-001"
}
```

Response shape matches `POST /api/v1/media` and includes `source_url` inside `media`.

## Error responses

Errors use a consistent JSON shape:

```json
{
  "ok": false,
  "error": {
    "code": "missing_scope",
    "message": "Token does not have the required scope."
  }
}
```

### Media error boundary

Only deliberately public-safe media exceptions may supply messages for API validation
responses. `BMS_Media_Validation_Exception` extends `RuntimeException` for existing
Admin/core callers. `BMS_Media_Alt_Text_Exception` extends `InvalidArgumentException`
to preserve Admin media-edit validation handling. General exception inheritance is
not evidence that a message is safe to expose.

| Failure | Public API behavior |
| --- | --- |
| Upload size/form-size limits, partial/missing uploads, unsupported extension, empty file, invalid image bytes, MIME mismatch, image-only requirement | Deliberate safe message under `422 media_upload_invalid` when raised by core upload validation. Existing adapter checks retain their specific codes, including `media_too_large` and `media_file_required`. |
| Invalid UTF-8 or alt text over 255 characters | `422 alt_text_invalid`, with the existing safe validation message. |
| Import URL/address/redirect safety rejection, size limit, redirect limit, remote HTTP status failure, empty response, unsupported image type | Deliberate safe message under `422 media_import_failed` when raised by the importer. API URL preflight retains `unsafe_media_import_url` and other existing specific codes. Remote HTTP errors contain only the numeric status, never a response body. |
| PDO/database errors; missing/unreadable temporary uploads; upload-directory creation; disk writes; missing cURL or cURL initialization/transport errors; failed privacy processing/storage; unexpected PHP or unclassified exceptions | `500 server_error`: `The API request could not be completed.` Raw messages are not copied into public responses. Existing adapter-generated `500 media_temp_failed` remains a fixed, sanitized message. |

The upload exception boundary is shared by standalone media upload, downloaded URL
imports, and media embedded in Stream creation. The importer independently enforces
its public-safe boundary; an internal upload failure cannot be relabeled as a safe
import failure. Existing authentication, scope, feature-toggle, rate-limit, SSRF,
and idempotency checks retain their semantics.

Strict privacy-processing failures remain internal because the current core cannot
distinguish unsupported processing capability from a storage/processing fault at
that point. No raw cURL diagnostics are public-safe. Import temporary-file write
failures (including partial writes) are internal and discard the temporary file.
Upload persistence failures retain the existing original/derivative cleanup.

Unexpected failures in the media adapters use the existing private sanitized logger
with operation context, exception class, and source basename/line only. They do not
copy the original message, SQL, credentials, or content into a new log entry or an
API audit message. An upload failure reached through import can record both contexts.
The global API error envelope and public validation messages remain unchanged.

Common error codes:

| Code | Meaning |
| --- | --- |
| `remote_posting_disabled` | The API is disabled in admin settings. |
| `remote_publish_disabled` | Direct remote publishing is disabled in admin settings. |
| `publish_confirmation_required` | A published request needs explicit confirmation. |
| `missing_bearer_token` | No bearer token was sent. |
| `invalid_bearer_token` | The token does not match an active token. |
| `missing_scope` | The token does not have the required scope. |
| `invalid_json` | The request body is not valid JSON. |
| `invalid_status` | Status must be `draft`, `published`, or `scheduled`. |
| `invalid_scheduled_at` | The scheduled date/time is invalid or not in the future. |
| `scheduled_at_required` | A scheduled request did not include a future scheduled date/time. |
| `remote_media_upload_disabled` | Remote image uploads are disabled in admin settings. |
| `media_upload_invalid` | The uploaded media failed validation. |
| `media_too_large` | The uploaded media exceeded the configured upload limit. |
| `content_required` | Content is empty. |
| `content_too_large` | Content exceeds the API input size limit. |
| `post_too_large` | Generated post document exceeds the API size limit. |
| `idempotency_key_conflict` | The idempotency key was already used for a different request. |
| `idempotency_key_processing` | A matching idempotent request is still processing. |
| `rate_limited` | The IP address or token hit the configured rate limit. |


## Client examples

For practical client setup examples, see `docs/REMOTE-POSTING-CLIENTS.md`. It includes PowerShell, curl, Python, GitHub Actions, Apple Shortcuts, Zapier Webhooks, Make HTTP module, IFTTT Webhooks, and generic no-code automation examples.

## OpenAPI schema

The OpenAPI schema is available in the package at:

```text
docs/openapi/bonumark-stream-api.json
```

## Security guidance

- Keep the API disabled until you are ready to use it.
- Create separate tokens for separate clients.
- Use the smallest scope needed.
- Leave direct publishing off unless you trust the client.
- Keep publish confirmation on unless you have a strong reason to disable it.
- Revoke tokens that are unused or exposed.
- Do not commit real tokens to GitHub.
- Do not paste real tokens into screenshots or support requests.

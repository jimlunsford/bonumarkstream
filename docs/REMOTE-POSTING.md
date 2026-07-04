# Remote Posting API

Bonumark Stream includes a disabled-by-default Remote Posting API for trusted external tools.

This feature is meant for site owners who want to connect Bonumark Stream to custom clients, automation systems, shortcuts, scripts, future apps, or ChatGPT Actions. The feature is platform-neutral.

## Current status in v0.5.30

Included now:

- Remote Posting API setting
- Scoped API token table
- Hashed token storage
- Token creation and revocation screen
- API authentication helper
- API audit log table
- API rate limiting table
- API idempotency table
- `GET /api/v1/status`
- `POST /api/v1/stream/posts`
- Draft creation by default
- Optional direct publishing
- Optional scheduled publishing through `scheduled_at`
- `stream:publish` scope
- Default remote status setting
- Optional publish confirmation requirement
- Admin edit URL returned after remote creation
- Public URL returned after remote publishing
- Full OpenAPI schema
- ChatGPT Actions setup documentation
- Client examples for PowerShell, curl, Python, GitHub Actions, Apple Shortcuts, Zapier, Make, IFTTT, and generic no-code tools
- Optional remote image uploads
- `media:upload` token scope
- `POST /api/v1/media` media endpoint
- `POST /api/v1/media/import` media URL import endpoint

Not included yet:

- Remote post update/delete endpoints

## Admin location

Go to:

```text
Admin → Settings → Remote Posting
```

The API is disabled by default. Create a token first, then enable the API only when you are ready to connect a trusted client.

## Recommended setup

The safest setup is:

```text
Enable Remote Posting API: On
Allow direct remote publishing: Off
Default remote post status: Draft
Require explicit publish confirmation: On
Token scopes: status:read, stream:draft
```

That setup lets external clients create drafts for review without publishing directly.

## Direct publishing setup

To allow direct publishing:

```text
Enable Remote Posting API: On
Allow direct remote publishing: On
Default remote post status: Draft, Published, or request-level Scheduled
Require explicit publish confirmation: On
Token scopes: status:read, stream:draft, stream:publish
```

A request for `published` also needs confirmation when confirmation is required:

```json
{
  "content": "This post should publish now.",
  "status": "published",
  "confirm_publish": true,
  "client_request_id": "example-publish-001"
}
```

A request for `scheduled` needs a future `scheduled_at` value in the site timezone. `publish_at` is also accepted. Existing clients that do not send `scheduled_at` keep the same draft/publish behavior.

```json
{
  "content": "This post should publish later.",
  "status": "scheduled",
  "scheduled_at": "2026-06-25T09:30",
  "client_request_id": "example-schedule-001"
}
```

## Token storage

Bonumark Stream only stores a token hash. The full token is shown once when it is created.

Store the token somewhere safe. If you lose it, revoke the old token and create a new one.

## Idempotency

Use an `Idempotency-Key` header, an `idempotency_key` field, or a unique `client_request_id` in the JSON body. This prevents accidental duplicate posts if a client retries the same request. If the same token reuses the same idempotency key for different request content, Bonumark Stream returns `409 idempotency_key_conflict`.

```text
Idempotency-Key: example-post-001
```

or:

```json
{
  "client_request_id": "example-post-001"
}
```

## Status endpoint

```text
GET /api/v1/status
```

Public request without a token:

```json
{
  "ok": true,
  "api": "bonumark-stream",
  "version": "0.5.30",
  "remote_posting_enabled": false,
  "authenticated": false,
  "direct_publish_enabled": false,
  "default_status": "draft",
  "publish_confirmation_required": true
}
```

Authenticated request:

```text
Authorization: Bearer YOUR_API_TOKEN_HERE
```

Authenticated response includes token metadata and scopes when the API is enabled and the token is valid.

## Stream posts endpoint

```text
POST /api/v1/stream/posts
```

Draft scope:

```text
stream:draft
```

Publish scope:

```text
stream:publish
```

Example draft request:

```json
{
  "content": "This is a remote draft created from a trusted client.",
  "status": "draft",
  "client_request_id": "example-draft-001"
}
```

Example published request:

```json
{
  "content": "This is a remote post published from a trusted client.",
  "status": "published",
  "confirm_publish": true,
  "client_request_id": "example-published-001"
}
```

## Remote image upload setup

To allow trusted clients to upload image media:

```text
Enable Remote Posting API: On
Allow remote image uploads: On
Token scopes: status:read, media:upload
```

Endpoint:

```text
POST /api/v1/media
```

Remote media upload is image-only in this pass. Bonumark Stream uses the existing media upload validation and upload-size setting. The response includes a media URL and a ready-to-use Markdown image embed.

Example JSON request:

```json
{
  "filename": "example.png",
  "content_base64": "BASE64_IMAGE_CONTENT_HERE",
  "alt_text": "Example uploaded image",
  "caption": "Optional caption",
  "client_request_id": "example-media-001"
}
```

Example response:

```json
{
  "ok": true,
  "media": {
    "media_id": 42,
    "url": "https://example.com/media/2026/06/example.png",
    "markdown": "![Example uploaded image](https://example.com/media/2026/06/example.png)"
  }
}
```

This does not automatically create or update a post. Use the returned `markdown` value in a separate stream post request.

## Security notes

- The API is off by default.
- Tokens are created by the Admin only.
- Tokens are stored as hashes.
- Tokens can be revoked.
- API activity is logged.
- Rate limiting is enforced.
- Direct publishing is disabled by default.
- Publish confirmation is required by default.
- Remote image upload and URL import are disabled by default.
- Remote media upload requires the `media:upload` token scope.

Do not publish real tokens in documentation, support tickets, GitHub issues, screenshots, or examples.

See `docs/API.md` for endpoint details, `docs/REMOTE-POSTING-CLIENTS.md` for client examples, and `docs/CHATGPT-ACTIONS.md` for ChatGPT Actions setup.

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
| Enable Remote Posting API | Off | Master switch for authenticated API requests. |
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
  "version": "0.5.30",
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
  "version": "0.5.30",
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
    "scopes": ["status:read", "stream:draft", "stream:publish", "media:upload"],
    "expires_at": ""
  }
}
```

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

- The Remote Posting API is enabled.
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

- The Remote Posting API is enabled.
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
  "media_position": "before"
}
```

Notes:

- `content` may also be sent as `body` or `body_markdown`.
- Posts may be text-only, media-only, or text with embedded media.
- Embedded media is appended after the content by default. Use `media_position: "before"` to place embedded media first.
- Content plus embedded media must be 5,000 characters or fewer.
- Referenced media URLs must point to existing Bonumark media library items.
- One-step uploads and URL imports inside `POST /api/v1/stream/posts` only work when remote media uploads are enabled and the token also has the `media:upload` scope.
- URL imports accept public HTTP/HTTPS image URLs only. Bonumark rejects local, private, reserved, unsafe, non-image, oversized, or unsupported remote files.
- Known fake 1x1 placeholder uploads are rejected with `placeholder_media_rejected`.
- Slugs are normalized and made unique if needed.
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

- The Remote Posting API is enabled.
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

- The Remote Posting API is enabled.
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

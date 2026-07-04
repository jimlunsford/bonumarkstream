# Using Bonumark Stream with ChatGPT Actions

Bonumark Stream can be connected to a custom GPT through the Remote Posting API.

This guide is optional. The API itself is platform-neutral and can be used by any trusted client that can send HTTPS requests with a bearer token.

## What ChatGPT Actions need

A custom GPT Action needs:

- Your Bonumark Stream site URL
- An OpenAPI schema
- Authentication details
- A test request

OpenAI documents GPT Actions as a way to connect a GPT to external APIs using authentication and an OpenAPI schema. See OpenAI's official documentation for the current ChatGPT interface and setup details.

```text
https://help.openai.com/en/articles/9442513-configuring-actions-in-gpts
```

## Bonumark files to use

Use this schema from the Bonumark Stream package:

```text
docs/openapi/bonumark-stream-api.json
```

Before importing it into a custom GPT, replace the example server URL:

```json
{
  "url": "https://example.com"
}
```

with your Bonumark Stream site URL:

```json
{
  "url": "https://your-site.example"
}
```

Do not include a trailing slash.

## Recommended Bonumark settings

In Bonumark Stream, go to:

```text
Admin → Settings → Remote Posting
```

Recommended safe setup:

```text
Enable Remote Posting API: On
Allow direct remote publishing: Off
Default remote post status: Draft
Require explicit publish confirmation: On
```

Create a token with these scopes:

```text
status:read
stream:draft
```

This lets ChatGPT create drafts for review without publishing directly.

## Optional direct publishing setup

Direct publishing should only be enabled when you trust the workflow.

Bonumark settings:

```text
Enable Remote Posting API: On
Allow direct remote publishing: On
Default remote post status: Draft
Require explicit publish confirmation: On
```

Token scopes:

```text
status:read
stream:draft
stream:publish
media:upload, only if you want the GPT to upload images
```

A publish request should include:

```json
{
  "status": "published",
  "confirm_publish": true
}
```

## ChatGPT Action authentication

Use API key authentication as a bearer token.

Value:

```text
YOUR_BONUMARK_API_TOKEN_HERE
```

Header behavior:

```text
Authorization: Bearer YOUR_BONUMARK_API_TOKEN_HERE
```

Never paste a real token into public docs, GitHub issues, screenshots, or support requests.

## Draft action prompt example

After the Action is connected, a prompt like this should create a draft:

```text
Create a Bonumark Stream draft with this content: Testing remote drafts from ChatGPT.
```

Expected request body:

```json
{
  "content": "Testing remote drafts from ChatGPT.",
  "status": "draft",
  "client_request_id": "chatgpt-test-001"
}
```

Expected result:

```json
{
  "ok": true,
  "post": {
    "status": "draft",
    "edit_url": "https://your-site.example/admin/edit.php?type=draft&file=testing-remote-drafts-from-chatgpt.md",
    "public_url": null
  }
}
```

## Publish action prompt example

Only use this when direct publishing is enabled and the token has the `stream:publish` scope.

```text
Publish this to my Bonumark Stream: Testing remote publishing from ChatGPT.
```

Expected request body:

```json
{
  "content": "Testing remote publishing from ChatGPT.",
  "status": "published",
  "confirm_publish": true,
  "client_request_id": "chatgpt-publish-test-001"
}
```

Expected result:

```json
{
  "ok": true,
  "post": {
    "status": "published",
    "edit_url": "https://your-site.example/admin/edit.php?type=published&file=testing-remote-publishing-from-chatgpt.md",
    "public_url": "https://your-site.example/stream/testing-remote-publishing-from-chatgpt/"
  }
}
```

## Image upload action prompt example

Remote image uploads require this Bonumark setup:

```text
Enable Remote Posting API: On
Allow remote image uploads: On
Token scopes: status:read, media:upload
```

A connected Action can call:

```text
POST /api/v1/media
```

For JSON-based clients, the body can use base64 image content:

```json
{
  "filename": "example.png",
  "content_base64": "BASE64_IMAGE_CONTENT_HERE",
  "alt_text": "Example uploaded image",
  "caption": "Optional caption",
  "client_request_id": "chatgpt-media-test-001"
}
```

Expected result includes a ready-to-use Markdown image embed:

```json
{
  "ok": true,
  "media": {
    "url": "https://your-site.example/media/2026/06/example.png",
    "markdown": "![Example uploaded image](https://your-site.example/media/2026/06/example.png)"
  }
}
```

You can still create a post in two steps by uploading the image first and then including the returned `markdown` value in a later stream post request. Current releases also allow creating the post and uploading/embedding the image in the same `POST /api/v1/stream/posts` request.

## Idempotency

Ask ChatGPT to include a unique `client_request_id` for each post. This prevents duplicate posts if the same request is retried.

Good examples:

```text
chatgpt-20260610-001
chatgpt-idea-note-20260610-0930
```

Bad examples:

```text
test
post
same-key-every-time
```

## Safety recommendations

- Start with draft-only mode.
- Use one token per GPT or client.
- Revoke old tokens.
- Keep direct publishing off unless you need it.
- Keep remote image uploads off unless you need them.
- Keep publish confirmation on.
- Test with harmless content first.
- Review the API audit log after testing.


## One-step media embed prompt example

If the Bonumark token has `stream:draft` and `media:upload`, and remote image uploads are enabled, a connected Action can create a draft and upload/embed an image in the same request.

```text
Create a Bonumark Stream draft with this text and attach the uploaded image under it.
```

Expected request body:

```json
{
  "content": "Testing a Bonumark Stream draft with an uploaded image.",
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
  "client_request_id": "chatgpt-media-post-test-001"
}
```

Expected result:

```json
{
  "ok": true,
  "post": {
    "status": "draft",
    "embedded_media": [
      {
        "media_id": 42,
        "url": "https://your-site.example/media/2026/06/example.png",
        "source": "uploaded"
      }
    ],
    "media_position": "after"
  }
}
```


## Current OpenAPI note

The Action schema intentionally documents `GET /api/v1/status` instead of `HEAD /api/v1/status`, and keeps operation descriptions short so the schema imports cleanly into GPT Actions.


## URL import action prompt example

For GPT Actions, URL import is usually better than fake base64. If I provide a public image URL, call `importMedia` first.

Expected request body:

```json
{
  "image_url": "https://example.com/image.jpg",
  "alt_text": "Imported image alt text",
  "caption": "Optional caption",
  "client_request_id": "chatgpt-media-import-001"
}
```

Expected result:

```json
{
  "ok": true,
  "media": {
    "media_id": 42,
    "url": "https://your-site.example/media/2026/06/image.jpg",
    "source_url": "https://example.com/image.jpg"
  }
}
```

After import, create the post with the returned `media_id`.

## Media guardrail instruction

Add this to your custom GPT instructions:

```text
Never invent placeholder images, fake base64 strings, placeholder.png, sample.png, dummy images, or 1x1 tracking-pixel images for Bonumark media uploads.

Only call uploadMedia when actual image bytes are available.

If I provide a public image URL, call importMedia and then createStreamPost with the returned media_id.

If actual image bytes or a public image URL are not available, ask me to upload/provide the image instead of creating placeholder media.
```

## Other remote clients

ChatGPT Actions is only one Remote Posting API client. For PowerShell, curl, Python, GitHub Actions, Apple Shortcuts, Zapier, Make, IFTTT, and generic no-code automation examples, see `docs/REMOTE-POSTING-CLIENTS.md`.


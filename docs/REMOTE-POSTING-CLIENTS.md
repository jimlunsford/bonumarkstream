# Remote Posting Client Examples

Bonumark Stream exposes a normal HTTP API. Any trusted tool that can send HTTPS requests can create drafts, publish when allowed, upload image media, import public image URLs, or embed existing media in a stream post.

This document gives practical client examples for common tools. It does not add client-specific behavior to Bonumark Stream. These clients all call the same endpoints documented in `docs/API.md`.

## Before you connect a client

In Bonumark Stream, go to:

```text
Admin -> Settings -> Remote Posting
```

Recommended setup for first tests:

```text
Enable Remote Posting API: On
Allow direct remote publishing: Off
Default remote post status: Draft
Require explicit publish confirmation: On
Allow remote image uploads: Off unless needed
```

Create a token with the narrowest scopes needed:

| Use case | Scopes |
| --- | --- |
| Check API status | `status:read` |
| Create drafts | `stream:draft` |
| Publish directly | `stream:draft`, `stream:publish` |
| Upload or import media | `media:upload` plus the post scopes needed |

Use these placeholders in examples:

```text
BONUMARK_SITE=https://example.com
BONUMARK_TOKEN=YOUR_API_TOKEN_HERE
```

Never commit real tokens to Git, screenshots, support tickets, public notes, or examples.

## Common request values

Create a draft:

```json
{
  "content": "Posted remotely from a trusted client.",
  "status": "draft",
  "client_request_id": "client-example-001"
}
```

Publish directly, only when direct publishing is enabled and the token has `stream:publish`:

```json
{
  "content": "Published remotely from a trusted client.",
  "status": "published",
  "confirm_publish": true,
  "client_request_id": "client-publish-example-001"
}
```

Embed existing media after the text:

```json
{
  "content": "Testing remote posting with existing media.",
  "status": "draft",
  "media_ids": [42],
  "media_position": "after",
  "client_request_id": "client-media-example-001"
}
```

Import a public image URL and embed it in the same post:

```json
{
  "content": "Testing remote posting with an imported image.",
  "status": "draft",
  "media_import_url": "https://example.com/image.jpg",
  "media_position": "after",
  "client_request_id": "client-import-example-001"
}
```

## PowerShell

PowerShell is a good Windows test client because it can send JSON, bearer tokens, and multipart file uploads.

### Check status

```powershell
$Site = "https://example.com"
$Token = "YOUR_API_TOKEN_HERE"

$Headers = @{
  Authorization = "Bearer $Token"
}

Invoke-RestMethod `
  -Method Get `
  -Uri "$Site/api/v1/status" `
  -Headers $Headers
```

### Create a draft

```powershell
$Site = "https://example.com"
$Token = "YOUR_API_TOKEN_HERE"

$Headers = @{
  Authorization = "Bearer $Token"
  "Content-Type" = "application/json"
  "Idempotency-Key" = "powershell-draft-001"
}

$Body = @{
  content = "Posted from Windows PowerShell."
  status = "draft"
  client_request_id = "powershell-draft-001"
} | ConvertTo-Json -Depth 10

Invoke-RestMethod `
  -Method Post `
  -Uri "$Site/api/v1/stream/posts" `
  -Headers $Headers `
  -Body $Body
```

### Publish directly

```powershell
$Site = "https://example.com"
$Token = "YOUR_API_TOKEN_HERE"

$Headers = @{
  Authorization = "Bearer $Token"
  "Content-Type" = "application/json"
  "Idempotency-Key" = "powershell-publish-001"
}

$Body = @{
  content = "Published from Windows PowerShell."
  status = "published"
  confirm_publish = $true
  client_request_id = "powershell-publish-001"
} | ConvertTo-Json -Depth 10

Invoke-RestMethod `
  -Method Post `
  -Uri "$Site/api/v1/stream/posts" `
  -Headers $Headers `
  -Body $Body
```

### Upload a local image

```powershell
$Site = "https://example.com"
$Token = "YOUR_API_TOKEN_HERE"
$ImagePath = "C:\Users\You\Pictures\example.jpg"

$Headers = @{
  Authorization = "Bearer $Token"
}

$Form = @{
  media_file = Get-Item $ImagePath
  alt_text = "Example image uploaded from PowerShell"
  caption = "Optional caption"
  client_request_id = "powershell-media-001"
}

Invoke-RestMethod `
  -Method Post `
  -Uri "$Site/api/v1/media" `
  -Headers $Headers `
  -Form $Form
```

Use the returned `media.markdown` value in a later post, or use the returned `media.media_id` as `media_id` or `media_ids` in `POST /api/v1/stream/posts`.

## curl

curl is the simplest cross-platform command-line client for Linux, macOS, Windows, and server scripts.

### Check status

```bash
BONUMARK_SITE="https://example.com"
BONUMARK_TOKEN="YOUR_API_TOKEN_HERE"

curl -sS "$BONUMARK_SITE/api/v1/status" \
  -H "Authorization: Bearer $BONUMARK_TOKEN"
```

### Create a draft

```bash
BONUMARK_SITE="https://example.com"
BONUMARK_TOKEN="YOUR_API_TOKEN_HERE"

curl -sS -X POST "$BONUMARK_SITE/api/v1/stream/posts" \
  -H "Authorization: Bearer $BONUMARK_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: curl-draft-001" \
  -d '{
    "content": "Posted from curl.",
    "status": "draft",
    "client_request_id": "curl-draft-001"
  }'
```

### Upload a local image

```bash
BONUMARK_SITE="https://example.com"
BONUMARK_TOKEN="YOUR_API_TOKEN_HERE"

curl -sS -X POST "$BONUMARK_SITE/api/v1/media" \
  -H "Authorization: Bearer $BONUMARK_TOKEN" \
  -F "media_file=@/path/to/example.jpg" \
  -F "alt_text=Example image uploaded from curl" \
  -F "caption=Optional caption" \
  -F "client_request_id=curl-media-001"
```

## Python

Python is useful for custom publishing scripts, RSS readers, local writing folders, release automation, or private tools.

```python
import json
import os
import urllib.request

site = os.environ.get("BONUMARK_SITE", "https://example.com")
token = os.environ["BONUMARK_TOKEN"]

payload = {
    "content": "Posted from Python.",
    "status": "draft",
    "client_request_id": "python-draft-001",
}

body = json.dumps(payload).encode("utf-8")
request = urllib.request.Request(
    f"{site}/api/v1/stream/posts",
    data=body,
    method="POST",
    headers={
        "Authorization": f"Bearer {token}",
        "Content-Type": "application/json",
        "Idempotency-Key": "python-draft-001",
    },
)

with urllib.request.urlopen(request, timeout=30) as response:
    print(response.read().decode("utf-8"))
```

Run it like this:

```bash
BONUMARK_SITE="https://example.com" BONUMARK_TOKEN="YOUR_API_TOKEN_HERE" python3 post-to-bonumark.py
```

## GitHub Actions

GitHub Actions is useful for posting release notes, deployment updates, build notifications, or project status notes to your own stream.

Store the token as a repository secret, for example:

```text
BONUMARK_TOKEN
```

Example workflow:

```yaml
name: Post release note to Bonumark Stream

on:
  release:
    types: [published]

jobs:
  post:
    runs-on: ubuntu-latest
    steps:
      - name: Create Bonumark Stream draft
        env:
          BONUMARK_SITE: https://example.com
          BONUMARK_TOKEN: ${{ secrets.BONUMARK_TOKEN }}
          RELEASE_NAME: ${{ github.event.release.name }}
          RELEASE_URL: ${{ github.event.release.html_url }}
        run: |
          python3 - <<'PY'
          import json
          import os
          import urllib.request

          site = os.environ["BONUMARK_SITE"]
          token = os.environ["BONUMARK_TOKEN"]
          release_name = os.environ.get("RELEASE_NAME", "New release")
          release_url = os.environ.get("RELEASE_URL", "")
          key = f"github-release-{os.environ.get('GITHUB_RUN_ID', 'manual')}"

          content = f"New release published: {release_name}\n\n{release_url}"
          payload = {
              "content": content,
              "status": "draft",
              "client_request_id": key,
          }
          body = json.dumps(payload).encode("utf-8")
          request = urllib.request.Request(
              f"{site}/api/v1/stream/posts",
              data=body,
              method="POST",
              headers={
                  "Authorization": f"Bearer {token}",
                  "Content-Type": "application/json",
                  "Idempotency-Key": key,
              },
          )
          with urllib.request.urlopen(request, timeout=30) as response:
              print(response.read().decode("utf-8"))
          PY
```

Keep release posts as drafts until you trust the workflow. Only switch to `published` after testing with your own install.

## Apple Shortcuts

Apple Shortcuts can call the Bonumark API from iPhone, iPad, or Mac using the URL and request body actions.

Basic draft shortcut:

```text
1. Text
   https://example.com/api/v1/stream/posts

2. Dictionary
   content: Posted from Apple Shortcuts.
   status: draft
   client_request_id: shortcuts-draft-001

3. Get Contents of URL
   URL: the Text value above
   Method: POST
   Headers:
     Authorization: Bearer YOUR_API_TOKEN_HERE
     Content-Type: application/json
     Idempotency-Key: shortcuts-draft-001
   Request Body: JSON
   JSON: the Dictionary value above

4. Show Result
   Show the response from Get Contents of URL
```

For a reusable shortcut, replace the fixed `content` value with dictated text, selected text, clipboard text, or Share Sheet input.

Good Shortcuts patterns:

| Pattern | Recommended Bonumark request |
| --- | --- |
| Dictate a note | `POST /api/v1/stream/posts` as draft |
| Share selected text | `POST /api/v1/stream/posts` as draft |
| Share a public image URL | Use `media_import_url` inside `POST /api/v1/stream/posts` |
| Post with existing media | Use `media_id` or `media_ids` |

Use draft mode first. Direct publishing from a phone shortcut is convenient, but easy to trigger by mistake.

## Zapier Webhooks

Use Zapier when you want another app event to create a Bonumark Stream draft.

Recommended Zap pattern:

```text
Trigger: New item in another app
Action: Webhooks by Zapier -> Custom Request or POST
URL: https://example.com/api/v1/stream/posts
Method: POST
Data Pass-Through: false
Payload Type: JSON
Headers:
  Authorization: Bearer YOUR_API_TOKEN_HERE
  Content-Type: application/json
  Idempotency-Key: a mapped unique ID from the trigger
Body:
  content: mapped text from the trigger
  status: draft
  client_request_id: the same mapped unique ID
```

Good Zapier use cases:

| Trigger | Bonumark action |
| --- | --- |
| New RSS item | Create a draft with the title and link |
| New row in a spreadsheet | Create a draft from selected columns |
| New form submission | Create a private review draft |
| New video or podcast episode | Create a release note draft |
| New GitHub issue or release | Create a project update draft |

Use a trigger-provided stable ID for `Idempotency-Key` and `client_request_id`. Do not use a timestamp unless duplicate prevention does not matter.

## Make HTTP module

Use Make when you want a visual scenario with more control over mapping, branching, and error handling.

Recommended module setup:

```text
Module: HTTP -> Make a request
URL: https://example.com/api/v1/stream/posts
Method: POST
Body type: Raw or JSON, depending on the Make UI
Content type: application/json
Headers:
  Authorization: Bearer YOUR_API_TOKEN_HERE
  Content-Type: application/json
  Idempotency-Key: mapped unique ID from the scenario
Body:
{
  "content": "Mapped content from a previous module",
  "status": "draft",
  "client_request_id": "mapped-unique-id"
}
Parse response: Yes
```

Good Make use cases:

| Scenario | Bonumark action |
| --- | --- |
| Watch RSS items | Create draft posts |
| Watch files in cloud storage | Import image URL or create media draft |
| Watch form submissions | Create review drafts |
| Run scheduled digest | Create a draft summary |
| Watch project tools | Create project status notes |

For media files, use multipart form data against `POST /api/v1/media` when Make has file data available. For public image URLs, use `media_import_url` inside `POST /api/v1/stream/posts` or call `POST /api/v1/media/import` first.

## IFTTT Webhooks

IFTTT is best for simple automations. Use it for short text drafts or public image URL imports. It is not the best fit for complex media upload flows.

Recommended Applet action:

```text
Action: Webhooks -> Make a web request
URL: https://example.com/api/v1/stream/posts
Method: POST
Content Type: application/json
Additional Headers:
  Authorization: Bearer YOUR_API_TOKEN_HERE
Body:
{
  "content": "{{TextField}}",
  "status": "draft",
  "client_request_id": "ifttt-{{OccurredAt}}"
}
```

Use the ingredient names available in your Applet. The exact names depend on the trigger service.

Good IFTTT use cases:

| Trigger | Bonumark action |
| --- | --- |
| Button widget | Create a draft from fixed text or input text |
| Location event | Create a private draft note |
| RSS event | Create a draft with title and link |
| Smart home event | Create a private log draft |
| Saved item event | Create a reading note draft |

Because IFTTT ingredient values can vary by service, test with draft mode and review the API response before enabling anything public.

## Generic no-code automation tools

Any no-code tool can work with Bonumark Stream when it supports an HTTP request or webhook action with custom headers and a JSON body.

Use this generic configuration:

```text
Method: POST
URL: https://example.com/api/v1/stream/posts
Headers:
  Authorization: Bearer YOUR_API_TOKEN_HERE
  Content-Type: application/json
  Idempotency-Key: UNIQUE_ID_FROM_THE_TRIGGER
Body type: JSON
Body:
{
  "content": "Mapped text from the trigger",
  "status": "draft",
  "client_request_id": "UNIQUE_ID_FROM_THE_TRIGGER"
}
```

For a no-code tool to support the full Bonumark API, it should allow:

- Custom request URL
- POST method
- Custom headers
- JSON request body
- Access to the JSON response
- Stable unique values for idempotency
- Multipart form data, only needed for direct file upload

When a tool cannot send custom headers, it should not be used with the Bonumark API. The API intentionally requires bearer token authentication in the `Authorization` header.

## Troubleshooting client requests

| Problem | Likely cause | Fix |
| --- | --- | --- |
| `missing_bearer_token` | The Authorization header was not sent. | Send `Authorization: Bearer YOUR_API_TOKEN_HERE`. |
| `invalid_bearer_token` | Token is wrong, revoked, expired, or copied with extra spaces. | Create a new token and paste it carefully. |
| `remote_posting_disabled` | API is disabled in admin settings. | Enable Remote Posting API. |
| `missing_scope` | Token does not include the needed scope. | Create a token with the correct scopes. |
| `remote_publish_disabled` | Client requested `published` but direct publishing is off. | Use `draft` or enable direct publishing. |
| `publish_confirmation_required` | Published request did not include confirmation. | Add `confirm_publish: true`. |
| `invalid_json` | Request body is not valid JSON. | Validate the JSON body and content type. |
| `idempotency_key_conflict` | Same key was reused for different content. | Use a new unique key for changed content. |
| `rate_limited` | A loop or repeated test hit the rate limit. | Stop the client, wait, then retry with a fixed workflow. |

## Safety rules for all clients

- Start with drafts.
- Use the narrowest token scopes possible.
- Use a separate token for each client.
- Revoke tokens you stop using.
- Store tokens in the client secret manager when available.
- Use `Idempotency-Key` for any retrying or automated client.
- Do not direct publish from automation until the draft flow has been tested.
- Never let public user input publish directly without review.

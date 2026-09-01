# Bonumark Stream Architecture

Bonumark Stream is a dynamic, database-first microblog CMS designed for normal shared hosting.

## Source of truth

The database is the runtime source of truth for posts, pages, accounts, Profiles, comments, media records, settings, likes, drafts, trash, revisions, scheduled work, Local Places, analytics, and registration data.

Markdown is used for import, export, backup, and portability. Runtime rendering does not depend on Markdown files as fallback storage.

## Durable post identity

A Bonumark post's database ID is immutable for the lifetime of that logical post and survives every reversible lifecycle transition. Slug changes, draft, scheduled, and published status changes, unpublish, trash, restore, and republish update the existing `posts` row. Permanent deletion is the only normal lifecycle operation that removes that row.

Trash records retain a nullable reference to the durable post ID. The nullable form preserves compatibility with historical trash records created before durable row identity was enforced. New code must not implement a lifecycle transition by deleting and reinserting an existing logical post.

## Account model

Bonumark Stream has two account types:

- **Admin**: sole publisher and site manager.
- **Commenter**: participation account for comments and Profile/account features when enabled.

The publishing model is intentionally owner-controlled rather than multi-author.

## Request flow

- `/` renders the Stream home.
- `/stream/` remains a supported alias.
- Clean public routes are handled by `index.php`.
- Admin routes live under `/admin/`.
- Public rendering is dynamic by default.
- Static Site Export reuses the normal core rendering path as optional tooling.

## Core boundary

Core owns:

- Routing and request handling
- Database access and migrations
- Authentication and permissions
- Forms and CSRF protection
- Publishing, drafts, scheduling, revisions, and trash
- Media processing and validation
- Comments and likes
- Profiles and Profile metadata
- Local Places
- Feeds, sitemap, search, and SEO
- Remote Posting API and Scheduled Tasks
- Public component markup and accessibility semantics
- Theme validation and rendering execution
- Install, upgrade, backup, and recovery behavior

## Theme boundary

Theme packages are presentation-only.

A theme may provide metadata, settings, screenshots, CSS, images, fonts, documentation, and validated private declarative layout JSON.

A theme may not provide PHP, JavaScript, HTML templates, SQL, routes, database writes, permission logic, callbacks, expressions, server configuration, or application behavior.

## Theme Architecture 2.0

Layout Schema 1 supports declarative composition for four public surfaces:

- `profile`
- `stream-card`
- `site-header`
- `home`

Layout files contain only validated `group` and registered `component` nodes. Core renders every component and owns the outer semantic/application boundaries.

A theme may adopt any supported subset. Undeclared surfaces continue through the fixed legacy composition, so CSS-only themes remain compatible.

Midnight Ledger is the single bundled/default reference theme and uses all four current declarative surfaces. Materially different proof compositions remain under `scripts/fixtures/declarative-themes/` for regression testing only.

See [DECLARATIVE-LAYOUTS.md](DECLARATIVE-LAYOUTS.md) for the public Layout Schema contract.

## Public template SEO boundary

Document SEO processing applies only to complete public documents such as Home, archives, single Stream Posts, Pages, Profiles, accounts, and search.

Reusable fragments such as link previews, cards, media, comments, pagination, and composer output must preserve the domain data supplied by their owning renderer. In particular, a remote link-preview title must not be converted into a local Bonumark document title or receive the local site-name suffix.

## Media boundary

Upload validation, privacy handling, gallery ordering, responsive derivative generation, media dimensions, loading behavior, viewer behavior, and accessibility labels are core responsibilities.

Themes style stable media contracts but do not implement media behavior.

Profile covers and Profile photos use dedicated responsive candidate sets because their rendered slots differ from normal Stream media. Original uploads remain the source of truth.

## ActivityPub boundary

ActivityPub is an optional core protocol capability. The Bonumark database remains the source of truth, human-facing Profile and Stream Post URLs remain canonical presentation URLs, and themes do not own federation routes or behavior.

The signed inbox accepts two explicitly separated authentication formats:

- The widely deployed draft-cavage RSA-SHA256 format, including the `hs2019` algorithm identifier when the discovered actor key is an acceptable RSA key.
- RFC 9421 HTTP Message Signatures using `Signature-Input`, `Signature`, and a covered SHA-256 `Content-Digest`. The Stage 3 verification profile requires a creation time, an actor-owned 2048-bit or larger RSA key, coverage of the method and body digest, and coverage of the full target URI or an equivalent authority/path/query set. It supports `rsa-v1_5-sha256`, whether selected from the RSA key or declared consistently by the signature.

Both formats use the same actor/key ownership checks, five-minute replay window, durable replay fingerprint, activity deduplication, and transaction boundary. RFC 9421 verification is an inbound Stage 3 responsibility. Format discovery and adaptive RFC 9421 outbound signing belong to Stage 4 delivery work; Stage 3 follower responses continue to use the legacy RSA format for current fediverse interoperability.

Publication observations and delivery work are separate states. Stage 1 and Stage 2 publication events are completed `observed` records. Stage 3 workers select only `follower_response` deliveries with a null publication event ID and cannot reactivate those observations.

Stage 4 records a durable activity only for a newly committed local transition while ActivityPub is enabled. It never scans historical posts into delivery work. The publication worker selects only `publication` deliveries with a non-null event ID, runs only from a durable manual or cron runner, and cannot claim follower responses or historical `observed` events. Local publication commits before remote delivery is attempted, and delivery retries always reuse the same activity ID and payload.

Bonumark post identity and federated publication identity are deliberately separate. Each publication generation has one immutable ActivityPub object URI derived from the database post ID and generation. The human-facing slug may change without changing either identity. Publication lifecycle behavior is centralized:

- The first publication emits `Create`.
- An identical published save emits nothing.
- A material published edit or slug change emits `Update`.
- Unpublish or trash emits `Delete` and retains a durable tombstone snapshot.
- Restore to draft emits nothing.
- Republish or direct restore to published emits `Create` for a new publication generation and a new generation-specific object ID.
- Permanent deletion after an earlier `Delete` emits nothing further and retains the local object/tombstone row after the post row is removed.
- A scheduled post emits nothing before its due publication and emits `Create` when it becomes published.

Every deleted generation retains a permanent local tombstone. A later publication does not reuse or resurrect that identity, and federation interactions do not migrate between generations.

Outbound publication delivery supports both the deployed legacy RSA HTTP Signature format and RFC 9421 using RSA PKCS#1 v1.5 with SHA-256. A remote actor must explicitly advertise RFC 9421 capability before it is selected. A shared inbox uses RFC 9421 only when every grouped recipient advertises it. Authentication-related rejection of an RFC 9421 attempt receives one bounded legacy fallback for the same activity; subsequent retries retain the compatible format. Every remote destination is revalidated through the SSRF-safe pinned-address transport immediately before connection, and POST redirects are disabled.

Stage 5 keeps inbound remote replies, Likes, and Announces in federation-owned tables linked to the existing remote actor cache and the exact local publication generation. Remote replies default to moderation and retain protocol identity, sanitized content, ownership, update, and tombstone state. Approved replies enter a core-owned comment presentation model without becoming local comments or Commenter accounts. Federated Likes and Announces remain separate from anonymous local likes. Their activity ledger permits Undo only for the exact authenticated actor and exact activity URI, while the aggregate state prevents changed activity IDs from creating duplicate visible interactions.

Stage 5 processing remains behind the Stage 3 authenticated inbox and its legacy and RFC 9421 signature verification, digest validation, replay protection, actor/key consistency, SSRF-safe discovery, bounded request size, and bounded JSON depth. Actor and domain blocks apply before visible interaction state is created. Type-specific authenticated-actor rate limits bound reply mutations and lightweight social interactions.

Stage 6 adds owner participation without creating a second publishing system or a public remote timeline. Owner Follow input may be a normal fediverse handle, a conventional `/@name` profile URL, or a canonical actor URI. Handles and profile URLs resolve through bounded, HTTPS-only, SSRF-validated WebFinger before the canonical actor document is validated through the existing federation transport. Following relationships and their immutable Follow/Undo activity history remain federation-owned records. Sanitized remote Notes are cached only for accepted Following relationships and are exposed only to the authenticated owner. Remote actors never become local users, and cached remote objects never become Bonumark archive entries.

An owner reply is a normal Bonumark Stream Post with one core-owned remote reply-target record. Publication serialization adds `inReplyTo`, while Create, Update, Delete, and republish continue through the existing generation-aware publication lifecycle. The remote target may remain stable across republication, but each local publication generation keeps its own immutable object identity and does not inherit federation interactions from a retired generation.

Owner Likes and Announces use separate durable aggregate and activity-ledger records. Repeated active actions are idempotent, each Undo references the exact prior activity, and a later action after Undo receives a new immutable activity URI. Owner actions use the existing signed, queued, SSRF-safe delivery transport and never alter anonymous local likes or inbound Stage 5 interaction state. A cached remote object tombstone is permanent locally, so a changed activity URI, stale Update, or forced refetch cannot resurrect deleted remote content.

Stage 6.5 presents that existing owner-participation state through a private `/following/` frontend surface. The route exists only while ActivityPub is enabled, requires the active Admin owner, sends private no-store and noindex response controls, is excluded from public analytics and static export, and never appears in logged-out or Commenter navigation. Core maps cached remote records into a bounded sanitized presentation model containing only display identity, safe content and media metadata, lifecycle state, conversation links, and owner action state. Themes style that model through the normal public shell but never receive raw ActivityStreams JSON, actor documents, delivery payloads, or protocol decision-making authority. Reply, Like, Unlike, Boost, and Unboost forms remain authenticated, CSRF-protected calls into the existing Stage 6 services. Admin remains the management, moderation, delivery, and diagnostics surface.

## Upgrade boundary

The upgrader treats configuration, the database, media/uploads, backups, data, and custom themes as protected owner data. Package-managed application files and the bundled theme can be replaced by a validated release package.

Supported upgrades begin at v0.4.0. Earlier v0.1.x, v0.2.x, and v0.3.x development builds require a fresh install.

# ActivityPub in Bonumark Stream

ActivityPub is an optional, default-off federation layer around Bonumark Stream. Bonumark remains a self-hosted personal publishing system first. The owner publishes normal Stream Posts from Bonumark, and federation observes those committed publication transitions without making remote delivery part of the local save transaction.

ActivityPub does not create a multi-user social server, a public federated timeline, or a second federation-only post store. Remote users never become Bonumark accounts. The public site remains centered on the owner's own publishing.

## Discovery and public federation routes

Remote platforms discover the owner through domain-root WebFinger at `/.well-known/webfinger`. WebFinger advertises the one stable owner actor at `/activitypub/actor`. The actor document publishes the owner's public identity, inbox, outbox, follower and Following collections, and active public signing key.

The outbox and generation-aware object routes are read-only views of already published Bonumark state. They do not create a second post store and do not scan old posts into new delivery work. Human-facing Bonumark Profile and Stream Post URLs remain the normal presentation addresses.

## Owner experience

When ActivityPub is enabled, the authenticated Admin owner sees **Following** in the frontend navigation. `/following/` and its conversation views are private, use `no-store` and `noindex` controls, and are unavailable to logged-out visitors and Commenter accounts.

Following is chronological and contains only sanitized cached content from accepted outbound Following relationships. It has no ranking, recommendations, or public fediverse timeline. A remote card opens a private conversation. Reply creates a normal Bonumark draft with an ActivityPub reply target. Like, Unlike, Boost, and Unboost are direct owner actions backed by immutable ActivityPub activity history.

Admin is the management surface for configuration, keys, followers, moderation, delivery history, queue repair, cache cleanup, and permanent deactivation. Themes remain code-free and receive only core-prepared semantic presentation data. Core owns routing, federation logic, security, privacy, sanitation, permissions, and structural safety.

## Publishing and media

The first federated publication of a Stream Post sends `Create`. A material edit or slug change sends `Update`. Unpublish, trash, or permanent deletion of an active generation sends `Delete`. These activities are recorded only after the local Bonumark transition commits.

Published image posts include ActivityStreams image attachments. Bonumark preserves the image order and sends the stored media alt text as the attachment name when alt text is available. Remote delivery does not expose private media or turn remote media into local Media Library records.

v0.8.0 does not implement followers-only or private federation, direct messages, or another private-post visibility model. Federated Bonumark Stream Posts are public posts.

## Local identity and publication generations

The site has one stable owner actor URI:

`https://example.com/activitypub/actor`

The active signing key is advertised through the stable `#main-key` key identifier. Signing-key rotation changes key material, not the actor URI or key ID. Retired key rows are retained for operational history, while only the active public key is advertised.

A Bonumark Post ID and an ActivityPub object identity are different concepts. The durable local `post_id` survives slug changes, draft and publish transitions, scheduling, trash, restore, and republishing. ActivityPub identity is generation-aware:

- First publication creates generation 1 and sends `Create`.
- An identical published save sends nothing.
- A material edit or slug change sends `Update` for the current generation.
- Unpublish, trash, or permanent deletion of an active generation sends `Delete`.
- Restore to draft sends nothing.
- Republishing after `Delete` increments the generation and sends a new `Create` with a new object URI.

Once a generation is deleted, its object URI is permanently retired. Direct dereference returns `410 Gone` with a Tombstone. A stale retry, Create, or Update cannot revive it. Replies, Likes, Announces, and Undo history remain attached to the generation they originally targeted.

## Followers, Following, and interactions

Inbound Follow requests are authenticated and follow the configured manual or automatic approval policy. Accept, Reject, and Undo Follow state is durable. Actor and domain blocks are enforced across follower, reply, interaction, Following, cache, and outbound-owner-action paths.

Outbound Follow accepts a fediverse handle, a conventional profile URL, or a canonical actor URI. Discovery uses HTTPS-only WebFinger and actor fetches through the same SSRF-safe transport. Follow remains pending until the remote actor accepts or rejects it. Unfollow sends an exact Undo of the retained Follow activity.

Inbound remote replies remain separate from local comments internally. Approved remote replies can appear through a core-owned combined presentation model. Inbound Likes and Announces never become local anonymous likes. Owner replies are normal Bonumark posts, and owner Like or Announce actions retain exact Undo history.

Remote Actor Delete permanently marks that remote identity deleted locally, retires active follower and Following relationships, hides or tombstones its cached content, and preserves receipts and identity history. A remote actor URI returning `410` receives the same permanent treatment. A `404` is recorded as unavailable and can recover if a later validated fetch succeeds. Repeated permanent inbox failures retire active relationships without deleting their history.

## Operational states

The following states are deliberately different:

| State | Discovery | Inbox | New publication work | Outbound delivery | Relationships |
| --- | --- | --- | --- | --- | --- |
| Disabled before use | Off | Off | Off | Off | None established |
| Paused | Actor remains live | Temporarily unavailable | Not recorded | Not claimed | Preserved |
| Delivery suspended | Actor remains live | Active | Recorded | Not claimed | Preserved |
| Permanently deactivated | Actor Tombstone | Gone | Off forever | Only final Actor Delete | Preserved as inactive history |

Pause and delivery suspension are fully reversible and never send Actor Delete.

Permanent deactivation requires the exact Admin confirmation phrase `PERMANENTLY DELETE FEDERATED ACTOR`. The transaction records durable retirement state, stores one immutable Actor Delete payload, queues it to the accepted follower targets known at retirement, cancels unrelated unfinished federation work, and marks relationships inactive. The actor URI then returns `410 Gone`, WebFinger stops advertising it, and it cannot be re-enabled. Bonumark does not manufacture a replacement actor URI. Posts, comments, local likes, media, Profile data, Pages, themes, imports, exports, and other non-federation content are untouched.

**Permanent federation deactivation cannot currently be reversed. Use pause or delivery suspension if there is any possibility that this actor identity will be needed again.**

## Delivery and recovery

All remote delivery is asynchronous. Local publication commits first and never depends on remote network success. Server cron or protected web cron is required for dependable federation delivery. Public traffic and browser heartbeats are not dependable federation workers.

Workers use bounded batches, atomic claims, stale-processing recovery, bounded exponential retry, `Retry-After`, dead-letter state, and immutable payload reuse. Network failures, HTTP `408`, `425`, `429`, and `5xx` responses are normally retryable. Permanent protocol or target failures become dead letters. An RFC 9421 attempt may fall back to the legacy RSA signature format only for the existing bounded compatibility cases.

Admin queue operations provide:

- grouped queue state and recent active, failed, and cancelled rows;
- stuck-processing recovery;
- orphan, identity mismatch, unsafe target, malformed payload, and duplicate detection;
- safe retry after immutable payload and identity validation;
- permanent cancellation that retains the delivery row and reconciles its parent event or owner action;
- retired-actor isolation so ordinary work cannot restart after Actor Delete.

Remote cache cleanup clears blocked content and may remove only active objects that have been expired for at least 30 days, are no longer from an accepted Following relationship, and have no owner interaction or reply-target reference. Deleted and blocked identities, actor rows, receipts, relationships, interactions, and tombstones are retained when required for security, Undo, audit, or non-resurrection semantics.

## Signing keys

Private signing keys are encrypted with installation-derived key material and are never displayed or returned through routes or diagnostics. Provisioning and rotation perform a cryptographic self-test before activation. Rotation is transactional: the verified replacement becomes active and the prior key becomes retired without changing actor identity.

If the active key is missing, corrupted, undecryptable, or mismatched, delivery stops safely and System Check reports recovery is needed. Admin can provision or rotate a usable key while the actor is live. A permanently retired actor cannot receive a new signing key. Its retained key is needed until final Actor Delete delivery has completed.

Inbound verification supports legacy HTTP Signatures and RFC 9421 HTTP Message Signatures. A cached remote actor is refreshed once when a legitimate key-ID change or stable-key-ID signature mismatch indicates possible remote rotation. The refreshed actor URI, key owner, key ID, request coverage, digest, and signature must all validate.

## Security model

Federation remote access requires public HTTPS and the hardened Bonumark transport. It rejects loopback, private, link-local, reserved, carrier-grade NAT, and metadata destinations; validates DNS; pins connections when supported; verifies the connected address; and revalidates every bounded redirect. Timeouts, redirect counts, response sizes, JSON depth, inbound request size, and remote HTML are bounded.

Inbox processing verifies Host, Date or signature creation time, Digest or Content-Digest, signed target fields, actor, key owner, activity identity, replay state, and durable deduplication. Remote HTML and links are sanitized before presentation. Frontend owner actions require authentication, owner capability, and CSRF validation.

## Hosting requirements

ActivityPub requires:

- a canonical root-level HTTPS site URL;
- `/.well-known/webfinger` mapped at the domain root;
- PHP OpenSSL and a high-entropy installation security salt;
- PHP cURL and outbound HTTPS access;
- server cron or protected web cron for dependable delivery;
- writable Bonumark runtime paths reported by System Check;
- `_bonumark_stream` and private configuration blocked from public HTTP access;
- `Signature`, `Signature-Input`, `Digest`, `Content-Digest`, `Authorization`, `Host`, `Content-Type`, and `Content-Length` forwarded unchanged to PHP.

The shipped root `.htaccess` contains Apache routes. The example Nginx configuration in `docs/server/bonumark-stream-nginx.conf` contains the equivalent routes and explicit federation header forwarding. A subdirectory installation cannot provide standards-compliant domain-root WebFinger without hosting-level routing, so Bonumark reports it as unsupported for automatic ActivityPub enablement.

Do not give the web process ownership of package-managed application code. Only the documented runtime paths need web-process write access.

Before enabling ActivityPub:

1. Open **Admin > System Check** and resolve every ActivityPub requirement failure.
2. Confirm the canonical site URL is the permanent root-level HTTPS identity you intend to federate.
3. Confirm domain-root WebFinger reaches this Bonumark installation.
4. Configure dependable server cron or protected web cron.
5. Open **Admin > ActivityPub**, provision the signing key, review the key health result, and then enable ActivityPub.

Changing the canonical domain after federation begins is not an automatic migration. Do not move or retire the actor identity without a separate migration plan.

## Troubleshooting

Start with **Admin > System Check** and **Admin > ActivityPub**.

1. Confirm the canonical URL is HTTPS and has no path, query, fragment, or credentials.
2. Confirm domain-root WebFinger reaches Bonumark.
3. Confirm OpenSSL, cURL, the installation salt, and signing-key health pass.
4. Confirm a recent server-cron or protected web-cron run.
5. Review pause and delivery-suspension state.
6. Review queue consistency findings and dead deliveries before using safe retry or permanent cancellation.
7. Confirm the reverse proxy forwards all signed and digest headers unchanged.
8. For `401` or `403`, check remote-key rotation, Host forwarding, signature coverage, and clock synchronization.
9. For `429`, leave the bounded `Retry-After` retry in place.
10. For `404` or `410`, review remote actor and relationship lifecycle state instead of deleting audit history manually.

## Interoperability status

Live publishing interoperability was accepted with Mastodon, GoToSocial, and Misskey.io for the behavior implemented through Stage 6. Stage 7 changes should be retested only where lifecycle behavior changed.

Misskey.io accepted a correctly formed Update delivery during acceptance testing but did not apply the changed Note text. No Bonumark payload defect was found, so this remains a documented Misskey limitation. Sharkey.world connection failures observed during testing were server or connection specific and were not treated as a Bonumark protocol defect.

NodeInfo is intentionally not implemented. Its common usage and user-count fields are designed around social-server deployments and could misrepresent Bonumark's single-owner publishing model or leak private participation data. WebFinger and ActivityPub actor discovery provide the interoperability Bonumark currently needs. A future NodeInfo implementation requires a concrete consumer need and a privacy-safe single-owner reporting contract.

## Deliberately unsupported in v0.8.0

- NodeInfo;
- automatic domain migration;
- replacement actor identities after permanent retirement;
- multi-user federation;
- public federated timelines;
- algorithmic Following;
- followers-only or private federation;
- direct messages.

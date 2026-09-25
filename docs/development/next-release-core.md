# Next-release core development

Development branch: `feature/next-release-core`
Integration target: `develop`
Starting commit: `2d31fcf0f72c297f7bf501e26a4deac96f6c2980`
Starting public release: v0.8.1
Version selection: deferred until implemented scope is known.

This is active development. No release branch, version-marker change, release manifest regeneration, release package, tag, release publication, production deployment, or merge is authorized by this pass.

## Accepted batches

| Batch | Items | Acceptance |
| --- | --- | --- |
| A | 1. Conversation replies excluded from ordinary Stream publications; 2. Unified eligible public comment and Like counts with live synchronization; 5. Reusable local/remote comment metadata | Business-contract regressions and exact-commit dev acceptance before Batch B |
| B | 10. Unicode-safe generated metadata; 11. Composer failure recovery; 12. Explicit alt validation and retained failed input; 13. Accurate gallery usage reporting and deletion safeguards | Boundary/failure tests and existing dev media fixtures |
| C | 4. Selection-safe card activation; 6. Untitled-post/archive orientation; 7. Alt fallback/guidance; 8. Investigate async comment announcements; 9. Evaluate visible Like wording | Accessibility/interaction coverage; investigation-only items change only on evidence |
| D | 3. ActivityPub Admin organized around owner workflows | Responsive owner workflows, retained diagnostics, isolated destructive controls |

## Contract and environment boundaries

Core owns application state, interaction eligibility, federation semantics, public markup, accessibility, and Admin behavior. Themes remain presentation-only. The database remains the runtime source of truth; owner data and the supported upgrade history must survive.

Use a separate development checkout. Keep dev.bonumark.org a deployed application and preserve its populated dataset, federated identity, signing key, and controlled relationships. Deploy tested exact commits after appropriate rollback checks. Do not wipe or reseed dev. Use the existing VPS-wide backup architecture for meaningful rollback needs.

Public comments must count approved publicly visible local comments and eligible active remote replies for the current publication generation. Pending, private, deleted, blocked/ineligible, and stale-generation remote replies must not count. Inspect current local comment lifetime semantics before changing them.

The intended Like direction is eligible local plus remote aggregate counts, with Undo decreasing totals and no public disclosure of private reaction identities. Inspect stored visibility and eligibility before choosing the exact implementation.

Required acceptance includes initial render, asynchronous interaction changes, and reload agreement. Use controlled existing federation fixtures and normal browser workflows. Verify changed surfaces at 390 x 844 and 360 x 800 if supported; record any tooling limits rather than claiming mobile acceptance.

## Verified starting evidence (2026-09-25)

- VPS hostname is vps1.phoenix233.com, active user jim.
- GitHub main, develop, and v0.8.1 resolve to the starting commit.
- No open pull requests or conflicting next-release feature branch existed.
- The baseline checkout was clean. Every tracked file deployed on dev matched the baseline.
- Existing federation fixture still reproduces 0 Comments in the action link versus 1 Comment in Conversation, with a raw storage timestamp.
- Current GitHub public release is v0.8.1. README still describes v0.8.1 as unreleased and v0.7.2 as public. Record this documentation defect for later review; it does not change repository authority.

## Development acceptance log

Implementation and acceptance remain pending. This file is the scope and evidence record, not a release-readiness claim.

## Batch A acceptance, September 25
- Implemented in 884bae2; live-discovered PWA caching blocker corrected in 7d51b51.
- Both exact commits passed all four PHP/MySQL/MariaDB compatibility jobs.
- Dev deployed to 7d51b513ba5e8579b6947b7a3285f96c7881653c; all 315 deployed tracked application files compared byte-for-byte.
- Existing fixture now aggregates one remote and one local Like. Two deliberate local acceptance comments were added to post 49.
- Async submission immediately synchronized the permalink pill and heading to 3 comments. Remote moderation pending/approved changed 3 -> 2 -> 3 without reloading and preserved an unsent draft. Stream agreed.
- Full remote handle, site-local display date and ISO datetime verified in the rendered DOM.
- Existing remote reply restored to approved. No new federation publication or identity operation was required.
- System Check: 39 PASS / 0 WARN / 0 FAIL. 49 posts, 15 media, 28 migrations preserved; comments increased 4 -> 6, local Likes 2 -> 3.
- Private application, scripts and backup URLs reject HTTP access. Existing isolated FPM pool unchanged; existing VPS backup service succeeded September 25 at 11:17 UTC.
- Strict release-manifest check intentionally reports development file drift against frozen v0.8.1. Exact Git comparison is the development integrity evidence; no future release manifest was generated.
- Browser viewport was 1363 x 936. Exact 390 x 844 and 360 x 800 controls were unavailable, so mobile acceptance is not claimed.

## Batch B implementation decisions
- Reproduced valid generated metadata becoming invalid after front-matter parsing: byte-mode PCRE newline splitting matched a continuation byte in the sunrise emoji. UTF-8 parsing and character-based fallback/API truncation preserve valid text.
- Alt text retains the existing utf8mb4 VARCHAR(255) contract: at most 255 Unicode code points, explicitly validated in media writes and API inputs. Admin retains rejected alt text and captions.
- Media usage reused named PDO placeholders under native prepared statements. Unique placeholders restore detection of single images and galleries without changing storage. The reporter covers current post/page records and legacy import files, not Profile or revision history. Permanent deletion does not call this reporter; its existing explicit owner-confirmation behavior remains. Media trash preserves referenced files.
- Composer uses progressive asynchronous submission to retain all controls and selected files on rejected/failed responses, with a session-backed fallback for text/metadata. Native file controls must be reselected after a full navigation.
- Per-form submission receipts and an atomic content transaction prevent duplicate creation when a successful response is lost. A persisted in-progress receipt blocks blind retry after an uncertain worker/commit outcome. Session receipt data is transient UI recovery state, not content storage. Existing database remains authoritative.
- No schema migration is required.

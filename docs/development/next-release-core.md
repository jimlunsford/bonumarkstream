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


## Batch B dev acceptance, September 25

Exact deployed commit: be75115a6fae147843f335bccbf1e75e99bb5a3b. All four CI configurations passed. The normal Media Edit workflow retained 270 characters and explained the 255-character limit; the original description was then restored and saved. Media 15 in the four-photo gallery now reports one content reference. A composer submission with an existing slug failed with a useful message and retained its emoji-rich body and advanced slug. Correcting that slug saved exactly one new draft, `batch-b-recovery-september-25`, with the sunrise emoji intact in its generated title. No outbound federation event was needed. Isolated HTTP tests additionally cover schedule/title rejection, corrected save, receipt replay, uncertainty, metadata publication and media validation. Existing populated fixtures remain intact.

## Batch C decisions and regression coverage

- Card convenience navigation now ignores noncollapsed selections, pointer drags/cancellation, modified clicks, interactive/media descendants and an already-open destination. Real permalink anchors remain the keyboard route.
- Core document templates give the main landmark a meaningful name and a focusable skip destination. Home, Stream archives, untitled permalinks, Following/conversations, Profile, Search and pages retain their title-free or existing visible-heading presentation. This uses the explicitly allowed equivalent landmark labeling strategy rather than generating fake headlines.
- Existing authored media descriptions remain authoritative. Missing descriptions no longer copy post titles/excerpts. Empty alt remains empty, including explicit decorative Markdown. Gallery links include position plus authored description, or explicitly state that no image description was provided. The existing schema cannot distinguish intentionally empty from forgotten alt; no decorative intent is inferred and no migration was added. Admin guidance explains when empty is appropriate.
- Investigation found no loading status/busy semantics on comment mounts and lost form focus after response replacement. A stable polite status outside the replaced content now announces initial loading, completion and updates; busy state brackets initial loading/submission. Existing loading/network errors retain alert semantics. Submission restores focus only if it was still in the replaced form; background polling preserves form drafts and focused comment links. DOM and automated semantics are tested; no claim of a screen-reader listening session.
- Evaluation found count-only visible local Like controls while Following already names its action. Core now visibly says Like or Liked alongside the aggregate number, preserving the full accessible label and existing one-way local Like behavior. No theme behavior or styling changes.

New rendered-DOM PHP coverage checks landmarks, skip focus, galleries of two/three/four, authored/empty alt and visible Like semantics. Shipped-JavaScript handler tests cover selections, drag, child controls, modified clicks, blank space, self-navigation, comment busy/status/error/focus and existing asynchronous count races. Exact 390x844 and 360x800 acceptance remains unavailable in the current browser capability set; desktop checks are not mobile acceptance.

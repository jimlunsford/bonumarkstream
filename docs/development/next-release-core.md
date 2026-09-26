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

All 13 items are implemented and covered by automated tests. The September 26 reconciliation below records completed dev acceptance and the remaining exact-mobile, touch and screen-reader listening gaps. No additional product correction is currently identified. This is development evidence, not a release-readiness claim.

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


## Batch C dev acceptance

Commit e22c82100497dbd7861b6a8dcb32b9ee70f7042d passed all four CI configurations and was deployed exactly. At the real desktop viewport (1363x926 screenshot; DOM width 1363), mouse dragging selected the pinned post text without leaving Home. A subsequent blank-space click opened its permalink. Clicking the permalink body left an unsent comment draft intact, proving no self-reload. Enter activated a normal navigation link and the fourth gallery image; Escape closed the viewer and restored focus to that image link. Authored alt remained intact. The comment mount was observed busy with Loading comments, then not busy with 3 Comments loaded in a stable status element. Home and permalink main landmarks were named, Profile retained its visible H1 with named main, and Like/Liked wording was visibly readable. No new comment was submitted for this batch. Touch and exact mobile viewport acceptance remain unverified because the supplied browser does not expose those controls.

## Batch D implementation

ActivityPub Admin now prioritizes federated profile/status and shareable handle, Following with direct feed access, Followers, reply moderation, settings, compact diagnostics, then a collapsed Danger Zone. Core view markup is separated from the authenticated controller for rendered-DOM regression testing. Existing POST action handling, capabilities, CSRF, retirement confirmation, signing, delivery payloads and identity lifecycle are unchanged.

Five lists use bounded ten-record server-side pagination with stable ID tie-breaking and accessible Previous/Next navigation. Existing callers keep their default row limits; optional offsets extend read-only queries. Global queue/check summaries surface failures even outside the current page. Failed queues and failing checks open their diagnostic disclosures automatically; healthy checks, publication history and key history remain available but collapsed. Reply protocol identifiers are secondary disclosures. Delivery timestamps include readable UTC labels and ISO datetime values. Core Admin CSS gives these records their own wrapping layout instead of the unrelated five-column settings-history grid.

Rendered-DOM tests cover healthy, failed and retired states; workflow order; ten-row bounds; escaped remote content; CSRF on every form; failure disclosures; pagination state and timestamps. Real isolated HTTP tests render the authenticated controller and reject direct view access. Database API scenarios compare paginated record identities and order across all five list queries. Federation suites continue to validate the existing mutations independently.


### Batch D browser acceptance correction

The first Batch D deployment (e9b9ecd0b2631dfb89c7ccf1ee0280715725719b) stopped rendering at the new Following feed link. It called a frontend-only helper not loaded by the Admin controller. The isolated HTTP assertion only required content from the beginning of the page and therefore missed the partial response. Browser acceptance caught this immediately. All twelve deployed files were restored to the prior Batch C bytes before correction. The link now uses the existing shared site URL helper, and the actual authenticated HTTP test requires both the final Danger Zone section and the Admin footer, with no fatal-error output. This is a fixed batch regression, not a remaining product issue.

## Final correction and acceptance reconciliation, September 26, 2026

### Repository and exact deployment evidence

- Resumed from local, remote and dev application commit `07cdd676e244fc076f8d55bef80ebf78b6b5fc4d`, with a clean feature worktree and all four Compatibility jobs green.
- Code correction and accepted dev application SHA: `bb3961ecbe6ec51988e11cbb26237901b8b51787`.
- Correction Compatibility run [36231935715](https://github.com/jimlunsford/bonumarkstream/actions/runs/36231935715) passed all four configurations: PHP 8.1/MySQL 8.0, PHP 8.1/MariaDB 10.6, PHP 8.3/MySQL 8.4 and PHP 8.3/MariaDB 11.4. Deployment followed the green result.
- This reconciliation is a separate documentation-only commit on `feature/next-release-core`. Its full SHA is the containing commit, obtainable with `git log -1 --format=%H -- docs/development/next-release-core.md`. This avoids presenting the pre-documentation code SHA as final feature HEAD. Final local/remote HEAD, CI and application-byte comparison are recorded in the execution report and deployment checkpoint after this commit.
- Dev is a deployment without Git. Excluding repository-only `.github/` and `docs/`, all 322 tracked application files matched the correction commit. The documentation-only successor has identical application bytes.
- PR #4 remains open, draft and unmerged, targeting develop. Main and develop remain `2d31fcf0f72c297f7bf501e26a4deac96f6c2980`. Public release and application version remain v0.8.1.

### Narrow correction

Queue-summary and consistency-finding rows previously used an unrelated `$deliveryStatus` to choose `ap-record-failed`. Each now reads its own row's `status`: `$summary['status']` and `$issue['status']`. Only retry/dead rows receive failure styling. No action, capability, CSRF, pagination or hierarchy behavior changed.

The strengthened rendered-view test failed against the old implementation and passed after correction. For both row types it exercises retry, dead, delivered, pending, processing and cancelled while seeding the unrelated delivery variable as healthy and failed. It also checks healthy publication rows, independent global counts and open failure diagnostics. Existing complete rendering, escaping, ten-row bounds, pagination, timestamp and CSRF assertions pass.

Focused Admin tests, source-tree smoke, all 248 PHP syntax checks, nine JavaScript syntax checks, 18 JSON parses, diff hygiene, public interaction handlers and public rendered semantics passed. No tests were weakened.

Live Admin rendered through its footer in this order: federated profile, Following, Followers, reply moderation, settings, diagnostics and collapsed Danger Zone. Healthy diagnostic disclosures stayed collapsed. Publication history showed ten rows on page 1 and ten on page 2; Next and Previous worked without changing the global summary. After the controlled reply, the summary showed 10/10 checks and 72 delivered, with zero waiting, processing, retrying or failed deliveries. No failed live fixture was manufactured; failure styling uses rendered-view regression evidence.

### Acceptance matrix

ACCEPTED ON DEV means the item's functional acceptance is complete using current and retained September 25 evidence. Cross-surface exact responsive checks remain separately unverified below; they do not invalidate completed functional evidence.

| Item | Classification | Evidence or exact remaining acceptance |
| --- | --- | --- |
| 1. Owner federated-reply exclusion | ACCEPTED ON DEV | One published owner reply delivered, appeared under its remote parent, remained available by permalink and was absent from the ordinary main Stream. Automated main/pinned/archive exclusions pass. |
| 2. Unified public comments and Likes | ACCEPTED ON DEV | Retained async comment/moderation evidence plus real remote Like Undo and restoration, public totals and database state agree. |
| 3. ActivityPub Admin workflows | IMPLEMENTED AND TESTED, REMAINING ACCEPTANCE | Complete desktop render, hierarchy, ten-row pagination, disclosures and corrected failure regressions pass. Exact 390 x 844 and 360 x 800 Admin acceptance remains. |
| 4. Selection-safe cards | IMPLEMENTED AND TESTED, REMAINING ACCEPTANCE | Prior actual desktop selection, blank-space activation, keyboard/media and self-navigation checks retained. Genuine touch/long-press selection remains. |
| 5. Comment metadata | ACCEPTED ON DEV | Full identity, readable local/remote dates and ISO datetime observed; reusable rendering tests pass. Responsive wrapping remains in the cross-surface checklist. |
| 6. Untitled document orientation | ACCEPTED ON DEV | Named main landmarks, skip destinations and existing visible headings verified in rendered markup and browser accessibility snapshots. |
| 7. Alt fallback and guidance | ACCEPTED ON DEV | Authored/empty alt and two/three/four-image regressions pass; prior keyboard viewer and return-focus acceptance retained. |
| 8. Async comment accessibility | IMPLEMENTED AND TESTED, REMAINING ACCEPTANCE | Stable status, busy/error and conditional focus handlers tested; live loading/completion and accessibility snapshots verified. Actual screen-reader listening remains. |
| 9. Visible Like wording | ACCEPTED ON DEV | Like/Liked action wording and aggregate accessible labels observed, including real count changes. Mobile control layout remains in the cross-surface checklist. |
| 10. Unicode-safe generated metadata | ACCEPTED ON DEV | Boundary/DB coverage and prior live emoji-rich recovery draft retained. |
| 11. Composer failure recovery | ACCEPTED ON DEV | Existing failure/replay tests and live rejection, retained input and one corrected draft accepted. Exact mobile layout remains in the cross-surface checklist. |
| 12. Alt validation and retained input | ACCEPTED ON DEV | Unicode limit tests and prior live 270-character rejection/input retention accepted; original description restored. |
| 13. Gallery usage reporting | ACCEPTED ON DEV | Current-content/gallery query coverage and fourth-image live evidence retained; scope and deletion policy reviewed below. |

### Responsive, pointer and accessibility limits

- 390 x 844: NOT VERIFIED.
- 360 x 800: NOT VERIFIED.
- Genuine touch/pointer selection and long-press: NOT VERIFIED.
- Actual screen-reader listening: NOT VERIFIED.

The supplied browser exposes no viewport resizing, device/touch emulation or screen-reader listening capability. Actual DOM viewport was 1363 x 936. Desktop evidence and screenshots are not substitutes for these checks. No browser/server packages were installed and no speculative responsive changes were made.

Remaining exact-viewport surfaces are Stream, permalink, Conversation/comments, Following, Admin, composer, long handles, Like controls, metadata and galleries/media. Check overflow, readable wrapping, usable controls, unclipped content, disclosures and pagination at both sizes.

DOM/automated evidence includes named landmarks, authored/empty alt, stable polite status outside replaced content, busy state, error alerts and conditional focus restoration. Browser accessibility snapshots independently exposed the named Stream/Stream post/Conversation landmarks and Like labels. Live comments changed from Loading comments to 3 Comments loaded with busy false. These observations do not establish audible announcement behavior.

### Live federation evidence

One controlled reply was published through Following's Reply workflow to the existing Mastodon parent:
https://mastodon.social/@disciplinedoperator/117258951567705354

Reply text: "Controlled Bonumark dev acceptance, September 26: verifying that a published federated reply stays in its conversation and out of the main Stream."

Bonumark post 51 is published, targets remote object 13/actor 1 and the original Mastodon status as in-reply-to. Event 28 produced deliveries 70, 71 and 72 to the three existing follower endpoints. The supported Run Tasks Now action delivered all three with HTTP 202, one attempt each and no error. The remote parent thread visibly contains the reply:
https://mastodon.social/@jimlunsford@dev.bonumark.org/117336576315481059

The local permalink renders its body:
https://dev.bonumark.org/stream/controlled-bonumark-dev-acceptance-september-26-verifying-that-a/

The main Stream retained its prior ordinary publication and did not include this reply. The local Following conversation renders the remote thread and composer; remote threaded receipt and the local permalink are the observed context/accessibility evidence.

For post 49, the controlled Mastodon account removed its existing Favorite using the normal remote UI. Bonumark's total changed asynchronously from two Likes to one and remained one after reload; remote-interaction row 1 became undone. Favoriting the same remote post again restored the row to active and the public total to two after reload. The useful fixture is restored. No relationship was removed or recreated.

### Media reporter contract

Media Edit presents current content usage, not a complete application dependency inventory. It covers current post/page records, their galleries and legacy import references. The zero-result message explicitly says: "No current post or page references found. Profile and revision history are not included." Query errors explicitly say usage is unavailable and must not be assumed unused. Positive results report content-record references.

Profile/avatar/cover fields and revision-only history are outside this contract. Historical revisions are not counted as active current content. Permanent deletion does not perform a reference guard: the owner must first trash the file and explicitly confirm permanent deletion. Trash preserves the file; permanent deletion can break references if the owner proceeds. The UI advises checking posts and explains irreversibility. The reporter is not a safe-delete certification. No misleading zero-usage claim or new deletion defect was identified within Item 13's accepted scope, so no additional core change was made.

### Data, rollback and boundaries

All 50 pre-pass posts, six comments, 15 media records, 28 migrations, three followers and one following record retained their original row hashes. The sole added publication is the controlled reply, bringing posts to 51 and owner reply targets to two. Remote reply and interaction counts remain one each; the tested remote interaction is active again. No migrations were added or run.

The correction deployment preserved configuration, installed lock, frozen manifest and both version files, with scoped rollback copies under the existing test runtime. The existing VPS-wide backup service last reported success at 2026-09-26 06:35:01 UTC; backup payload contents were not independently reverified in this pass. No second backup system was introduced.

The frozen v0.8.1 manifest remains unchanged and is expected to report development source drift. Git-byte comparison supplies development deployment integrity. The pre-existing README public-release status inconsistency remains separately recorded and outside this pass.

No merge, version selection/change, release branch, tag, GitHub release, RC work or production deployment occurred. All accepted implementation work is complete; only the explicitly listed acceptance limitations remain. No additional product correction is currently identified.

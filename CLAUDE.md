# Bonumark Stream — instructions for Claude

Bonumark Stream is a self-hosted, single-owner PHP/MySQL microblog CMS. Owner: Jim Lunsford (Phoenix 2:33 LLC). Claude develops it; Jim approves every merge, release, and deployment.

## Authority

- The current source in this repository is authoritative. Chat history, old ZIPs, mockups, and stale docs never override it.
- When docs and source disagree, verify actual behavior before changing either.
- Read the closest existing docs before changing an area: `docs/ARCHITECTURE.md`, `docs/THEMING.md`, `docs/ADMIN-UI-GUIDELINES.md`, `docs/UPGRADING.md`, `docs/ACTIVITYPUB.md`, `CONTRIBUTING.md`.

## Product invariants — do not change without Jim's explicit approval

- Owner-controlled publishing: one publishing administrator, optional commenter accounts.
- The database is the runtime source of truth.
- Core owns behavior, state, markup, accessibility, and Admin. Themes are presentation-only and code-free.
- Owner data and the supported upgrade history must survive every upgrade.
- Runs on ordinary web hosting (Apache or Nginx, PHP-FPM, MySQL or MariaDB). Respect the compatibility floors in `docs/COMPATIBILITY.md`.
- ActivityPub stays optional and disabled by default.
- Keep the restrictive Content Security Policy. No inline scripts or inline event handlers.

## Versioning and releases

- Sequential MAJOR.MINOR.PATCH. Never reuse a version number for different bytes. A ZIP that was delivered or installed anywhere keeps its number forever; the next change gets the next number.
- A built ZIP is not a public release. Tags, GitHub releases, and the "Latest" marker require Jim's explicit go.
- Development builds are marked unreleased in `_bonumark_stream/CHANGELOG.md` and `docs/releases/vX.Y.Z.md`. Every build also gets an entry in the root `CHANGELOG.md`, marked as an unreleased development build until Jim approves public release.
- A version bump updates every marker together: `VERSION`, `_bonumark_stream/VERSION`, `_bonumark_stream/PACKAGE.json`, `_bonumark_stream/RELEASE-MANIFEST.json`, cache/asset version metadata, and the docs that state the current version.
- `RELEASE-MANIFEST.json` lists every file in the release package with its SHA-256. Repository-only paths (`CLAUDE.md`, `.github/`, `docs/development/`, `.gitattributes`) are export-ignored and not listed. Any added, removed, or changed packaged file needs the manifest regenerated, or the strict package checks fail.
- A ZIP must be built from an exact commit. Record that commit and the ZIP's SHA-256.

## Work cycle

- Work is numbered: Work 41, Work 42, ... PR numbers are independent of Work numbers.
- Branches: `work/<number>-<short-description>`. Packaging branches: `release/v<version>-<purpose>`.
- Open draft PRs against `main`. Jim reviews and approves. Merge only on his explicit go, then confirm CI on `main`.
- Approval to implement is not approval to merge, tag, release, deploy, or change infrastructure. Each needs its own go.
- Never delete branches, rewrite published history, or resurrect the retired `develop` branch.

## Verification before delivering

- Run the relevant `scripts/` tests, and the full suite for any build. The commands are in `.github/workflows/compatibility.yml`.
- All four CI jobs (PHP 8.1 and 8.3 × MySQL and MariaDB) must pass on the PR head.
- For builds, check version markers, PHP syntax, JSON validity, the release manifest, package integrity, migrations, and the upgrade from the previous build in a disposable install.
- For UI changes, check desktop (1440×900) and phone (390×844) with screenshots. Contrast meets WCAG AA.
- Report what passed, what failed, and anything you could not verify, marked NOT VERIFIED. Never claim acceptance you didn't test.

## Environments

- Use disposable local installs for development and testing.
- dev.bonumark.org is the owner's staging install. Jim deploys builds there himself through the Admin updater. Don't access it unless he asks.
- jimlunsford.net is Jim's live production site. Never use it for development or testing.
- No credentials, keys, tokens, or server paths go in the repository.

## Admin design

- The Admin is dark: pure black background, white text, neutral grays, white primary buttons. No green, teal, or blue-gray tint. Hue is for status only (success, danger, warning).
- Every color comes from the `--admin-*` tokens in `assets/admin-shell.css`. No hardcoded colors in new Admin work.
- Follow `docs/ADMIN-UI-GUIDELINES.md`: reuse the closest existing workflow and components.

## Related repositories

- `jimlunsford/microblog-stream-theme`: the custom theme that runs on jimlunsford.net. Check it for compatibility before any production upgrade.
- Midnight Ledger is the only bundled theme.

## Communication

- Direct and short. Lead with the result, then the evidence.
- Surface every decision that belongs to Jim. Don't make it for him.

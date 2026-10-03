# Repository orientation

Bonumark Stream is a self-hosted microblog CMS for short-form publishing on a site the owner controls. It favors content ownership, portable PHP hosting, privacy-conscious defaults, and safe upgrades. It is not a general-purpose CMS, social network, or multi-author publishing platform under the current product model.

## Authority and working method

This file is an orientation layer, not a competing source of truth. Newest source and verified repository state, current project rules, and applicable authoritative documentation outrank this file if they conflict. Task-specific documentation and tests remain authoritative evidence for actual behavior. Historical chats are not implementation authority.

Before changing anything, inspect the current branch, HEAD, working tree, relevant source, documentation, and tests. Resolve conflicts against current evidence rather than copying old assumptions. Architecture changes remain possible, but must be deliberate, explicitly documented decisions rather than incidental effects of a feature, fix, or theme change.

## Durable boundaries

- The database is the runtime source of truth. Markdown supports import, export, backup, portability, and human-readable movement; it is not a second runtime datastore or rendering fallback.
- Publishing is owner-controlled. The Admin is the sole publisher and site manager. Commenters participate through enabled comment and account features; they do not become additional publishers or administrators.
- Behavior, application state, routing, permissions, security, and semantics belong in core. Themes remain code-free presentation packages that style and arrange core-owned components.
- Normal upgrades must preserve owner data and third-party themes. Package-managed software and the bundled theme have a different lifecycle from configuration, database content, media, and runtime storage.
- Preserve hosting portability and capability-driven optional features. A particular owner's VPS layout is not a product requirement.

## Follow the relevant authority

- Contribution rules and verification: [CONTRIBUTING.md](CONTRIBUTING.md).
- Product boundaries and request flow: [architecture](docs/ARCHITECTURE.md).
- Admin workflows and components: [Admin UI guidelines](docs/ADMIN-UI-GUIDELINES.md).
- API behavior: [API](docs/API.md), [Remote Posting](docs/REMOTE-POSTING.md), [client guidance](docs/REMOTE-POSTING-CLIENTS.md), and [OpenAPI schema](docs/openapi/bonumark-stream-api.json).
- Federation behavior and identity: [ActivityPub](docs/ACTIVITYPUB.md).
- Presentation contracts: [theming](docs/THEMING.md) and [declarative layouts](docs/DECLARATIVE-LAYOUTS.md).
- Upgrade preservation and recovery: [upgrading](docs/UPGRADING.md); migration rules: [migration README](_bonumark_stream/migrations/README.md).
- Runtime support and verification: [compatibility](docs/COMPATIBILITY.md), [test scripts](scripts/), and [compatibility workflow](.github/workflows/compatibility.yml).

Before version, packaging, release, migration, or public-release work, consult the current Bonumark Stream Release and Versioning Rules supplied with the task, the applicable [release documentation](docs/releases/), upgrade/migration guidance above, and the actual packaging workflow. The full release/versioning standard is maintained outside this repository. Use unique versions, preserve owner data, generate manifests only from finalized release trees, and never alter a published tag or artifact. Verify current release state directly; historical release notes are not a current-state checkpoint.

## Repository-only guidance

This root `AGENTS.md` is tracked development guidance and must not ship in distributable packages. The root `.gitattributes` excludes it from `git archive`; the compatibility workflow verifies both the clean source archive and canonical ZIP boundary. Keep this file concise, durable, and free of temporary branch/version state, backlogs, and private planning links.

# Bonumark Stream Admin UI Guidelines

These guidelines protect the Admin interface as Bonumark Stream grows. They are a development contract for new Admin screens, controls, states, and workflow changes.

The goal is not to prevent new interface ideas. The goal is to keep new work inside the system that already exists unless a real product requirement justifies extending it.

## Core requirement

Every new Admin feature must look and behave like part of Bonumark Stream.

A new screen should begin with the closest existing workflow, reuse its established components, and preserve its desktop and mobile interaction model. Do not invent a separate visual language for one feature.

Before implementation:

1. Start from the newest package baseline.
2. Inspect the closest existing Admin workflow in both markup and CSS.
3. Identify which existing components will be reused.
4. State any genuinely new UI component before adding it.
5. Define the desktop, tablet, phone, empty, warning, error, and destructive states.

## Source of truth

The current source package is authoritative.

Chat history, screenshots, mockups, and old ZIP files can explain intent, but they do not replace the newest source. Before changing an Admin workflow, inspect the current related PHP, CSS, and JavaScript files.

When documentation and source disagree, verify the current behavior before changing either one.

## Use the closest established workflow

Use these screens as the default references for new work:

| New work | Primary reference | Component stylesheet |
| --- | --- | --- |
| Shared shell, navigation, page headings, global actions | `admin/_layout.php`, `admin/index.php` | `assets/admin-shell.css` |
| Stream Post or Page lists | `admin/content.php`, `admin/pages.php` | `assets/admin-content-list.css` |
| Post or Page editing | `_bonumark_stream/app/editor.php`, `admin/page-edit.php` | `assets/admin-editor-workflow.css` |
| Media browsing, details, selection, and editing | `admin/media.php` | `assets/admin-media-library.css` |
| Comment moderation | `admin/comments.php` | `assets/admin-comments.css` |
| Account records and account creation | `admin/users.php`, `admin/user-new.php` | `assets/admin-accounts.css` |
| Registration and invitations | `admin/registration.php` | `assets/admin-registration.css` |
| Themes, Site Identity, and Navigation | Appearance screens under `admin/` | `assets/admin-appearance.css` |
| General configuration, security, mail, API, and scheduled tasks | Settings screens under `admin/` | `assets/admin-settings.css` |
| Local Places directories and editors | Local Places screens under `admin/` | `assets/admin-places.css` |
| Import, export, upgrade, diagnostics, analytics, and other system operations | `admin/tools.php` and related operation screens | `assets/admin-operations.css` |

A new workflow may combine existing patterns. It should not create a new pattern merely because the feature has a new name.

## Shared shell rules

Authenticated Admin pages must use the shared layout helpers and shell.

- Use `bms_admin_header()` and `bms_admin_footer()`.
- Let `admin/_layout.php` provide navigation, account controls, mobile drawer behavior, security headers, shared styles, and the `body.bonumark-admin` scope.
- Use the page title and action arguments instead of recreating a second page header.
- Keep navigation grouped by the actual job: Publish, Manage, Design, Settings, or System.
- Add a navigation item only when the feature needs a durable destination. Do not add navigation for a temporary action or secondary state.
- Preserve active-route behavior and capability checks.

Do not build a standalone authenticated Admin shell for one screen.

## Information hierarchy

Every Admin screen should answer these questions quickly:

- Where am I?
- What is this screen for?
- What is the primary action?
- What information matters immediately?
- What is secondary or advanced?
- What is destructive or irreversible?

Use the established hierarchy:

1. Page title and primary action
2. Current-state or workflow context when needed
3. Main task or record set
4. Supporting information
5. Advanced controls
6. Destructive actions in a clearly separated area

Do not give every control equal visual weight.

## Panels and sections

Use existing panel and workflow structures before creating new containers.

Common patterns include:

- `panel` for a contained surface
- an eyebrow, heading, and concise `.meta` explanation for section context
- summary grids for current state
- section panels for related controls
- save bars for form completion
- empty states when no records exist
- danger zones for irreversible actions

Avoid stacking many visually identical panels without a clear hierarchy. Combine related information when separate cards do not help the user make a decision.

## Lists and records

Do not add a legacy desktop table and rely on CSS to stack it on phones.

For manageable content, use the responsive record systems already established in Stream Posts, Pages, Comments, Accounts, Settings, Appearance, Local Places, and system history.

A record should expose only the information needed to identify it and choose the next action. Secondary metadata belongs in a details view, disclosure, or editor when permanent display would create clutter.

Desktop records should scan in columns. Phone records should become purpose-built cards with readable labels and a deliberate information order.

Use one Actions menu when several record actions would otherwise crowd the row or card.

Preserve:

- search and filters
- result counts
- selection and bulk actions when the workflow supports them
- status visibility
- clear empty results
- safe placement of destructive actions

## Forms and settings

Group fields by the decision the user is making, not by database storage or implementation order.

Use the established Settings, Appearance, Registration, Local Places, or editor pattern that most closely matches the workflow.

Requirements:

- Every field has a visible label.
- Help text explains consequences, not obvious syntax.
- Fixed values and editable values are visually distinct.
- Advanced controls are disclosed when they are not part of routine use.
- Save actions are clear and remain reachable on small screens.
- Destructive controls are separated from normal save actions.
- Technical values such as URLs, paths, tokens, and command examples wrap without leaving their containers.
- Password managers and browser autofill are considered on account and credential forms.

Do not create a single uninterrupted wall of settings.

## Actions and destructive behavior

Each screen should have one obvious primary action when a primary action exists.

Secondary actions must look secondary. Dangerous actions must not compete visually with routine save or navigation controls.

Use existing button and link classes rather than creating feature-specific button colors or shapes.

Destructive actions require the level of friction appropriate to the risk. Depending on the operation, that can include:

- a separate danger zone
- an explicit confirmation screen
- a typed-name confirmation
- a clear explanation of what is preserved and what is deleted
- CSRF protection
- capability checks
- server-side validation that does not depend on JavaScript

Do not use color alone to communicate risk or status.

## Notices and states

Every workflow must account for more than its successful populated state.

Review and implement the states that apply:

- empty
- filtered empty
- loading
- success
- warning
- error
- disabled
- permission denied
- unavailable dependency
- confirmation
- destructive result
- partial or recoverable failure

Use existing notice, flash, status, badge, empty-state, and operation-risk treatments. Do not invent a new alert style for one message.

Messages should explain what happened, why it matters, and what the user can do next.

## Responsive behavior

Responsive work is part of the feature, not a later cleanup.

Every new or changed Admin workflow must be reviewed at desktop, tablet, and phone widths.

Requirements:

- No hidden or unreachable primary action.
- No horizontal page overflow.
- No desktop table compressed into unreadable phone columns.
- No sticky control covering content, toolbars, dialogs, or the on-screen keyboard.
- No modal or drawer larger than the usable viewport.
- No clipped menus, badges, pills, paths, URLs, or diagnostic messages.
- Touch targets should remain comfortably usable, with the established 44-pixel target as the normal minimum.
- Summary grids should collapse deliberately, not leave unexplained empty cells.
- Phone layouts should prioritize the information and action order needed on a small screen.
- Safe-area space should be respected where controls sit against the viewport edge.

Use the breakpoints already established by the closest component stylesheet. Do not create a new project-wide breakpoint system inside a feature file.

## Dialogs, drawers, disclosures, and mobile sheets

Reuse the interaction model already established by the Admin navigation drawer, editor disclosures, action menus, and Media Library details interface.

Interactive overlays must provide, when applicable:

- a clear open control
- a clear close control
- Escape-key closing
- click-outside or backdrop closing when safe
- correct `aria-expanded`, `aria-controls`, dialog, and label relationships
- focus movement or return appropriate to the interaction
- background scroll locking for modal mobile sheets
- reachable actions inside the usable viewport

Critical functionality must remain available when JavaScript fails unless the feature inherently requires JavaScript.

## Accessibility

Accessibility is part of acceptance.

New Admin work must preserve:

- semantic headings in a logical order
- explicit form labels
- keyboard access
- visible focus
- screen-reader text where a visual-only control needs a name
- accurate ARIA state for drawers, menus, disclosures, and dialogs
- status meaning that is not communicated through color alone
- reduced-motion behavior for nonessential animation
- readable contrast using the established Admin tokens

Do not remove focus outlines without providing a visible replacement.

## CSS ownership

The CSS files have specific responsibilities.

### `assets/admin.css`

This is the legacy and compatibility stylesheet. It is also used by the login screen, installer-related surfaces, and public preview code.

Do not add new authenticated Admin workflow styling to `admin.css` by default. A change belongs here only when it is genuinely shared with a non-Admin surface that loads this file without the component system, and that dependency has been verified.

### `assets/admin-shell.css`

This owns the authenticated Admin design tokens, shell, navigation, shared hierarchy, global page structure, shared components, touch behavior, and mobile drawer.

Add a rule here only when it is truly shared across unrelated Admin workflows.

### Workflow component stylesheets

Use the existing component stylesheet that owns the closest workflow. Scope component rules under `body.bonumark-admin` and use the generated `admin-screen-<route>` class when a rule is screen-specific.

Do not add broad unscoped element selectors to component stylesheets.

Create a new `admin-<workflow>.css` file only when all of these are true:

- the feature is a substantial workflow, not one isolated screen
- existing component stylesheets do not own the pattern
- the new component will be reusable within that workflow
- its ownership is clearer as a dedicated layer
- it is loaded through the shared Admin layout
- desktop and mobile behavior are documented and tested

A new stylesheet is not a substitute for reusing existing classes.

## Design tokens and visual language

Use the Admin tokens defined in `admin-shell.css` for colors, surfaces, borders, radius, shadows, and status meaning.

Do not introduce a feature-specific palette, button system, corner radius, shadow language, or typography scale without a product-level decision to extend the design system.

Do not hardcode a new color when an existing token communicates the same meaning.

## JavaScript behavior

Admin JavaScript should enhance a usable server-rendered workflow.

Requirements:

- Keep permission and data validation on the server.
- Keep CSRF protection on state-changing requests.
- Do not hide the only path to a critical action behind JavaScript.
- Initialize behavior for dynamically inserted content when the workflow supports it.
- Preserve keyboard and focus behavior.
- Avoid feature scripts that redefine shared drawer, menu, dialog, or disclosure behavior differently.
- Add a dedicated script only when the workflow needs meaningful client-side behavior that does not belong in the shared Admin script.

## Naming

Use names that describe the workflow and component role.

Preferred patterns:

- `settings-*`
- `appearance-*`
- `operations-*`
- `places-*`
- `content-*`
- `comment-*`
- `account-*`
- `registration-*`
- `media-*`
- `editor-*`

Avoid generic new class names such as `box`, `item`, `left`, `right`, or `wrapper` when the role can be named clearly.

Do not reuse an existing class for unrelated behavior merely because its current appearance is convenient.

## Required implementation note

Before implementing a new Admin feature, document the intended UI reuse in the work notes or pull request description.

Include:

- the closest existing workflow
- the component stylesheet that owns the new work
- any new component being introduced and why reuse is insufficient
- desktop and mobile behavior
- empty, warning, error, and destructive states
- accessibility behavior for interactive controls

This forces the design decision to happen before CSS patches accumulate.

## Acceptance checklist

A new or changed Admin workflow is not complete until the applicable items below are verified.

### Structure

- [ ] Uses the shared Admin header, footer, navigation, and body scope
- [ ] Matches the closest established workflow
- [ ] Has one clear primary purpose and action hierarchy
- [ ] Keeps advanced and destructive controls appropriately separated

### Styling

- [ ] Reuses existing classes and design tokens where appropriate
- [ ] Places new CSS in the correct ownership layer
- [ ] Does not add authenticated workflow patches to `admin.css` without a verified shared dependency
- [ ] Does not introduce a separate visual language

### Responsive behavior

- [ ] Desktop reviewed
- [ ] Tablet reviewed
- [ ] Phone reviewed
- [ ] No horizontal overflow or clipped controls
- [ ] Primary actions remain reachable
- [ ] Menus, dialogs, drawers, sticky bars, and sheets fit the usable viewport
- [ ] Long values wrap safely

### States and safety

- [ ] Empty and filtered-empty states reviewed
- [ ] Success, warning, and error states reviewed
- [ ] Loading or unavailable states reviewed when applicable
- [ ] Destructive behavior has explicit confirmation and server-side enforcement
- [ ] Capability and CSRF checks remain in place

### Accessibility

- [ ] Keyboard operation reviewed
- [ ] Visible focus preserved
- [ ] Labels and heading order reviewed
- [ ] ARIA state and focus return reviewed for interactive components
- [ ] Status is understandable without relying only on color

### Regression and package checks

- [ ] Related existing workflows reviewed for regressions
- [ ] PHP syntax passes
- [ ] JavaScript syntax passes when JavaScript changed
- [ ] CSS and JSON validation pass when those files changed
- [ ] `php scripts/smoke-test.php` passes
- [ ] Database or migration changes are tested on disposable MySQL or MariaDB when applicable
- [ ] Release manifest and ZIP integrity pass before packaging

## Extending the system

These guidelines do not require every future feature to look identical.

When a real workflow cannot be expressed well with the existing components, extend the system deliberately:

1. Explain why the closest existing pattern is insufficient.
2. Define the new component's purpose and states.
3. Keep it consistent with the existing tokens, spacing, hierarchy, and accessibility behavior.
4. Place it in a clear ownership layer.
5. Test it across screen sizes and failure states.
6. Update this document when the new pattern becomes part of the standard.

The standard should evolve through verified product decisions, not through one-off patches.

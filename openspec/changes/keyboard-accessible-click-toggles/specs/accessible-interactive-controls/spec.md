## ADDED Requirements

### Requirement: Div-based interactive controls MUST expose a keyboard path

Any non-native interactive element with a primary `@click` handler MUST be reachable and operable by keyboard.

Specifically, a `<div>` or `<span>` carrying a `@click` handler as its
primary interaction (not merely a stop-propagation guard) MUST carry
`role="button"` (or an equivalent ARIA role matching its behavior),
`tabindex="0"`, and a keydown handler that activates on both Enter and Space, matching the click
behavior exactly. Toggle controls additionally MUST expose `aria-expanded`.

#### Scenario: Dashboard widget header toggle is keyboard-operable

- **GIVEN** the Prospect Discovery dashboard widget's collapsible header
  (`ProspectWidget.vue`)
- **WHEN** a keyboard-only user Tabs to the header and presses Enter or Space
- **THEN** the widget expands/collapses identically to a mouse click
- **AND** `aria-expanded` reflects the current state

@e2e exclude keyboard-interaction verification requires a real keyboard-driven browser session
with no stable existing Playwright fixture for this dashboard widget; verified manually per
tasks.md 5.1 and by `vuejs-accessibility` ESLint rules per tasks.md 5.2.

#### Scenario: Dashboard widget navigation card is keyboard-operable

- **GIVEN** the Complaints Overview dashboard widget (`ComplaintsOverviewWidget.vue`), whose
  entire body navigates to the Complaints list
- **WHEN** a keyboard-only user Tabs to the widget and presses Enter or Space
- **THEN** the app navigates to the Complaints list identically to a mouse click

@e2e exclude keyboard-interaction verification requires a real keyboard-driven browser session
with no stable existing Playwright fixture for this dashboard widget; verified manually per
tasks.md 5.1 and by `vuejs-accessibility` ESLint rules per tasks.md 5.2.

#### Scenario: Project WBS phase row toggle is keyboard-operable

- **GIVEN** a phase row in the Project detail WBS tree (`ProjectWbsTree.vue`)
- **WHEN** a keyboard-only user Tabs to the row and presses Enter or Space
- **THEN** the phase expands/collapses identically to a mouse click
- **AND** `aria-expanded` reflects the current state

@e2e exclude keyboard-interaction verification requires a real keyboard-driven browser session
with no stable existing Playwright fixture for the project WBS tree; verified manually per
tasks.md 5.1 and by `vuejs-accessibility` ESLint rules per tasks.md 5.2.

#### Scenario: Find Client widget result row is keyboard-operable

- **GIVEN** a client search result row in the Find Client dashboard widget
  (`FindClientWidget.vue`)
- **WHEN** a keyboard-only user Tabs to the row and presses Enter or Space
- **THEN** the app opens that client's detail view identically to a mouse click

@e2e exclude keyboard-interaction verification requires a real keyboard-driven browser session
with no stable existing Playwright fixture for this dashboard widget; verified manually per
tasks.md 5.1 and by `vuejs-accessibility` ESLint rules per tasks.md 5.2.

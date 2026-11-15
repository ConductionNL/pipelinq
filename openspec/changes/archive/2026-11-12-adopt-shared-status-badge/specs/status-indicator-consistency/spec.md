# Status Indicator Consistency

**Spec refs**: `nextcloud-vue` `CnStatusBadge`, pipelinq `CLAUDE.md` theming rule
**Standards**: WCAG AA (dark-theme/high-contrast rendering), NC CSS variable theming contract

## ADDED Requirements

### Requirement: Status/priority pill badges MUST use the shared CnStatusBadge component

Any UI element in pipelinq that renders a status or priority as a color-coded pill/badge MUST
render it via the shared `CnStatusBadge` component (`@conduction/nextcloud-vue`) rather than an
app-local `.status-pill` / `.priority-badge` (or equivalently-named) CSS class. Color selection
MUST be expressed as a `CnStatusBadge` `variant` (or `colorMap`), never as a hardcoded hex value
in a `<style>` block.

#### Scenario: A project status renders as a themed badge

- GIVEN a project detail page rendering a project's status
- WHEN the status pill is displayed
- THEN it MUST be a `CnStatusBadge` instance with a `variant` resolved from the project status
- AND MUST NOT use a hardcoded hex background/text color

#### Scenario: Dark theme changes the badge's rendered colors

- GIVEN a Nextcloud instance with the dark theme (or nldesign override) active
- WHEN a status/priority badge renders anywhere in pipelinq
- THEN its background and text colors MUST resolve through Nextcloud CSS variables (via
  `CnStatusBadge`'s variant classes)
- AND MUST NOT remain the same fixed light-mode hex values used in the default theme

#### Scenario: No duplicate badge CSS remains

- GIVEN the pipelinq frontend source tree
- WHEN searching for `.status-pill` / `.priority-badge` class *definitions* outside
  `CnStatusBadge` itself
- THEN none MUST be found — every status/priority pill consumes the shared component

# accessibility-conformance Specification (delta)

## Purpose

A municipality can show, per back office screen, what pipelinq meets under
WCAG 2.1 AA and how that was checked, and can publish its own
toegankelijkheidsverklaring from a draft. From pipelinq matrix row `plat-wcag`.

## ADDED Requirements

### Requirement: The back office is checked for WCAG 2.1 AA on every nightly run (REQ-PAS-001)

The system SHALL run an automated WCAG 2.1 A and AA check and a keyboard-only
walk over a fixed sample of back office screens on every nightly run. A
violation that is not on the known-issues list SHALL fail the run, and a listed
issue that no longer occurs SHALL fail it too.

#### Scenario: A new unlabeled button fails the nightly run

- GIVEN a change that adds an icon button without an accessible name to ClientDetail
- WHEN the nightly e2e run executes the back office accessibility suite
- THEN the run fails and names the ClientDetail page and the button

#### Scenario: A fixed known issue must leave the list

- GIVEN the known-issues list names the Prospect widget header
- AND keyboard-accessible-click-toggles has made that header a button
- WHEN the suite runs
- THEN it fails and says the Prospect widget entry can be removed

#### Scenario: A KCC employee works a ticket with the keyboard only

- GIVEN a KCC employee on the Tickets list with no mouse
- WHEN they press Tab to reach a ticket, Enter to open it, and Escape to close its edit dialog
- THEN each step works and the focused control is always visibly marked

### Requirement: Each release carries a conformance report (REQ-PAS-002)

Each minor release SHALL carry an Accessibility Conformance Report that lists
every WCAG 2.1 AA success criterion with its level of conformance, the method
that decided it, the screens sampled, and the known issues with the change
that fixes each. A criterion nobody evaluated SHALL say so.

#### Scenario: A procurement officer checks a tender requirement

- GIVEN a procurement officer who needs EN 301 549 with WCAG 2.1 AA
- WHEN they open the Accessibility Conformance Report on the pipelinq docs site
- THEN they read, per criterion, supported, partially supported, not supported, not applicable or not evaluated
- AND each partial or failing row names the issue or change that addresses it

### Requirement: A municipality gets a draft toegankelijkheidsverklaring (REQ-PAS-003)

The system SHALL offer a Dutch draft toegankelijkheidsverklaring filled in
with what the conformance report states, leaving the organisation's own fields
open. The Nextcloud admin page for pipelinq SHALL link to the draft and to the
report and show the date of the last manual audit.

#### Scenario: A functional administrator prepares the statement

- GIVEN a functional administrator on the Nextcloud admin page for pipelinq
- WHEN they open the Accessibility section
- THEN they see the date of the last audit and links to the report and the draft statement
- AND the draft lists pipelinq's known issues and leaves the municipality's name and contact open

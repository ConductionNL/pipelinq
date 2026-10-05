## ADDED Requirements

### Requirement: The contact centre dashboard opens with what needs attention (REQ-KCC-101)
In the simple structure the contact centre dashboard MUST open with a greeting,
an attention card and four numbers, followed by the tickets that wait for the
reader, contact per channel, callbacks and the latest contact. Every card the
page had MUST stay on it. The full structure MUST keep the page as it was.

#### Scenario: Tickets past their deadline
@e2e exclude Asserted in tests/vitest/simpleContactDashboard.spec.js on the built page; the e2e instance runs on the full structure.
- **GIVEN** the simple structure and a ticket in progress, assigned to the reader, with a deadline of yesterday
- **WHEN** the reader opens the dashboard
- **THEN** the attention card MUST show
- **AND** its first action MUST open the tickets list with the same filter

#### Scenario: Nothing is late
@e2e exclude Asserted in tests/vitest/simpleContactDashboard.spec.js: the card is visible only when its count is above zero.
- **GIVEN** the simple structure and no ticket of the reader past its deadline
- **WHEN** the reader opens the dashboard
- **THEN** the attention card MUST NOT show

#### Scenario: Nothing is dropped
@e2e exclude Asserted in tests/vitest/simpleContactDashboard.spec.js: every old widget equal, every old layout entry equal except its row.
- **GIVEN** the simple structure
- **WHEN** the dashboard is built
- **THEN** every widget of the full structure's dashboard MUST be on it, unchanged

### Requirement: A number and its list agree (REQ-KCC-102)
A number on the dashboard MUST link to the list with the same filter the number
counts with. The number Wacht op mij, the list card of that name and the view
of that name on the tickets list MUST use one filter.

#### Scenario: Waiting for me
@e2e exclude Asserted in tests/vitest/simpleContactDashboard.spec.js by comparing the three filters and the link query.
- **GIVEN** the simple structure
- **WHEN** the reader chooses the number Wacht op mij
- **THEN** the tickets list MUST open on the reader's tickets in progress
- **AND** the view Wacht op mij MUST show the same count

### Requirement: The tickets list has counted views and shows handler and deadline (REQ-KCC-103)
In the simple structure the tickets list MUST show a count on every view, show
five views and keep the others behind the overflow, show the handler as an
avatar and colour a deadline that is today or past.

#### Scenario: Views
@e2e exclude Asserted in tests/vitest/simpleContactDashboard.spec.js on the built page.
- **GIVEN** the simple structure
- **WHEN** the reader opens the tickets list
- **THEN** the views MUST be Alle, Wacht op mij, Nieuw, Tickets and Klachten
- **AND** Contactmomenten and Wacht op klant MUST be behind the overflow

#### Scenario: A deadline today
@e2e exclude Asserted in tests/vitest/simpleContactDashboard.spec.js on the column's rule.
- **GIVEN** a ticket whose deadline is today
- **WHEN** the reader opens the tickets list in the simple structure
- **THEN** the deadline MUST be marked as late

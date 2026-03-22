## ADDED Requirements

### Requirement: Queue-Based Work Section [Enterprise]

The My Work view SHALL include a "My Queues" section showing items from queues the current user is assigned to, providing a queue-centric view of incoming work alongside the existing time-based grouping.

#### Scenario: View items from assigned queues
- **WHEN** the current user is assigned to queues "Vergunningen" and "Klachten"
- **THEN** the My Work view SHALL display a "My Queues" tab/section
- **THEN** the section SHALL show items grouped by queue name
- **THEN** within each queue group, items SHALL be sorted by priority then age

#### Scenario: Queue section shows unassigned items
- **WHEN** queue "Vergunningen" contains 5 items (3 unassigned, 2 assigned to others)
- **THEN** the "My Queues" section SHALL show all 5 items
- **THEN** unassigned items SHALL be visually distinguished (e.g., "Unassigned" badge)
- **THEN** items assigned to others SHALL show the assignee name

#### Scenario: Pick from queue in My Work
- **WHEN** an agent clicks "Pick" on an unassigned item in the My Queues section
- **THEN** the item's assignee SHALL be set to the current user
- **THEN** the item SHALL move to the "My Items" section (existing temporal grouping)

#### Scenario: No queue assignments
- **WHEN** the current user is not assigned to any queues
- **THEN** the "My Queues" tab/section SHALL display "You are not assigned to any queues"
- **THEN** the existing time-based My Work view SHALL still function normally

#### Scenario: Toggle between views
- **WHEN** the My Work view is displayed
- **THEN** the user SHALL be able to toggle between "My Items" (existing temporal view) and "My Queues" (queue-based view)
- **THEN** the default view SHALL be "My Items"

## MODIFIED Requirements

### Requirement: Personal Workload View [MVP]

The system MUST provide a "My Work" view showing all leads and requests assigned to the current user. Only open items are shown by default, with a toggle to include completed items. The view SHALL support switching between "My Items" (temporal grouping) and "My Queues" (queue-based grouping) tabs.

#### Scenario: View assigned leads and requests
- WHEN the current user navigates to "My Work"
- THEN the system MUST display all open leads and requests assigned to them
- AND each item MUST show: entity type badge, title, stage or status, due date, priority badge (if not normal)
- AND lead items MUST also show pipeline value (if set)

#### Scenario: Only open items by default
- WHEN the user views My Work
- THEN only leads in non-closed stages and requests with non-terminal statuses MUST be shown
- AND closed/completed/rejected/converted items MUST NOT appear by default

#### Scenario: Toggle to show completed items
- WHEN the user enables the "Show completed" toggle
- THEN completed and closed items MUST also be displayed
- AND completed items MUST be visually distinct (muted color or completed badge)

#### Scenario: Item count display
- WHEN the user views My Work
- THEN the header MUST display the total count and breakdown (e.g., "Leads (5) . Requests (3) -- 8 items total")

#### Scenario: Empty workload
- WHEN the current user has no assigned items
- THEN the system MUST display "No items assigned to you"

#### Scenario: Tab navigation
- **WHEN** the user views My Work
- **THEN** tabs for "My Items" and "My Queues" SHALL be displayed
- **THEN** "My Items" SHALL be the default active tab
- **THEN** switching tabs SHALL preserve filter and toggle state

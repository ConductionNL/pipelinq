---
status: done
---

# My Work (Werkvoorraad) Specification

## Purpose

My Work provides a personal workload view aggregating all items assigned to the current user -- leads, requests, and optionally tasks from Procest. This is the user's daily productivity hub, showing what needs immediate attention, what is due soon, and what is upcoming. Items are organized into temporal groups (Overdue, Due This Week, Upcoming, No Due Date) and sorted by priority within each group.

The design follows the wireframe in DESIGN-REFERENCES.md section 3.5.

**Feature tier**: MVP (leads + requests, sorting, filtering, grouping), V1 (cross-app with Procest tasks, overdue highlighting)

---

## Requirements

### Requirement: Personal Workload View [MVP]

The system MUST provide a "My Work" view showing all leads and requests assigned to the current user. Only open items are shown by default, with a toggle to include completed items.

#### Scenario: View assigned leads and requests
- WHEN the current user navigates to "My Work"
- THEN the system MUST display all open leads and requests assigned to them
- AND each item MUST show: entity type badge, title, stage or status, due date, priority badge (if not normal)
- AND lead items MUST also show pipeline value (if set)

#### Scenario: Only open items by default
@e2e exclude requires assigned items as test data
- WHEN the user views My Work
- THEN only leads in non-closed stages and requests with non-terminal statuses MUST be shown
- AND closed/completed/rejected/converted items MUST NOT appear by default

#### Scenario: Toggle to show completed items
@e2e exclude requires completed assigned items
- WHEN the user enables the "Show completed" toggle
- THEN completed and closed items MUST also be displayed
- AND completed items MUST be visually distinct (muted color or completed badge)

#### Scenario: Item count display
@e2e exclude requires assigned items
- WHEN the user views My Work
- THEN the header MUST display the total count and breakdown (e.g., "Leads (5) · Requests (3) — 8 items total")

#### Scenario: Empty workload
- WHEN the current user has no assigned items
- THEN the system MUST display "No items assigned to you"

---

### Requirement: Sorting [MVP]

Within each temporal group, items MUST be sorted by priority first, then by due date ascending.

#### Scenario: Default sort order within groups
@e2e exclude requires multiple assigned items
- WHEN the user views My Work
- THEN within each group, items MUST be sorted by priority (urgent > high > normal > low), then by due date ascending

#### Scenario: Items without due date positioning
@e2e exclude requires items without due dates
- WHEN items exist without a due date
- THEN they MUST be grouped in the "Wacht op mij" section

---

### Requirement: Grouping by what to do [MVP]

Items MUST be organised into three groups, top to bottom, by what the user has to do: "Vandaag beantwoorden", "Wacht op mij" and "Wacht op de klant". Each row MUST show its own term, such as "1 dag over tijd". Empty groups MUST be hidden.

#### Scenario: The term is today or has passed
@e2e exclude requires items with past due dates
- WHEN an item's term is today or has passed
- THEN it MUST appear in "Vandaag beantwoorden", under the hint "termijn is vandaag of verstreken"
- AND a term that has passed MUST read "N dagen over tijd" on the row

#### Scenario: The customer has reacted
@e2e exclude requires items with a customer reaction
- WHEN the customer has reacted, or the next step is the user's and the term is later
- THEN the item MUST appear in "Wacht op mij", under the hint "de klant heeft gereageerd"

#### Scenario: The customer has to act
@e2e exclude requires items waiting on the customer
- WHEN an item has status waiting for the customer
- THEN it MUST appear in "Wacht op de klant", under the hint "niets te doen tot ze reageren"

#### Scenario: An item without a term
@e2e exclude requires items without due dates
- WHEN an item has no term and is not waiting for the customer
- THEN it MUST appear in "Wacht op mij"

#### Scenario: Empty group behavior
@e2e exclude requires controlled data state
- WHEN a group contains no items
- THEN it MUST be hidden (not shown as empty section)

---

### Requirement: Filtering [MVP]

The system MUST allow filtering by entity type: All (default), Leads, Requests.

#### Scenario: Filter by entity type
- WHEN the user selects a filter (All / Leads / Requests)
- THEN only matching items MUST be displayed
- AND the item count MUST update to reflect the filtered count
- AND grouping MUST be preserved

#### Scenario: Filter with empty result
@e2e exclude requires data and filter interaction
- WHEN filtering produces no items
- THEN the system MUST display an empty state message

---

### Requirement: Overdue Item Highlighting [MVP]

Overdue items MUST be visually distinct with red indicators and "N dagen over tijd" text.

#### Scenario: Overdue visual treatment
@e2e exclude requires overdue items
- WHEN an item is overdue
- THEN it MUST display a red visual indicator and "N dagen over tijd" in red text

#### Scenario: Due today is not overdue
@e2e exclude requires item due today
- WHEN an item is due today
- THEN it MUST appear in "Vandaag beantwoorden", without "over tijd"
- AND it SHOULD show "Due today" as a warning indicator

---

### Requirement: Item Navigation [MVP]

Each item MUST be clickable, navigating to the full detail view.

#### Scenario: Click item to navigate
@e2e exclude requires assigned items
- WHEN the user clicks a lead or request item
- THEN the system MUST navigate to the respective detail view

---

### Requirement: Cross-App Workload [V1]

The system SHALL support including items from Procest (cases and tasks assigned to the current user) in the My Work view. Where cross-app integration is enabled, Procest items MUST appear alongside Pipelinq items; this remains a V1 (SHOULD) capability until that integration ships.

#### Scenario: Include Procest tasks
@e2e exclude V1 Procest integration; not yet implemented
- GIVEN the current user has 3 leads in Pipelinq and 2 tasks in Procest assigned to them
- WHEN they view My Work with cross-app integration enabled
- THEN all 5 items MUST appear in a unified list
- AND each Procest item MUST display a "Task" or "Case" entity type badge
- AND Procest items MUST follow the same sorting and grouping rules

#### Scenario: Filter includes Procest entity types
@e2e exclude V1 Procest integration; not yet implemented
- GIVEN the cross-app workload is enabled
- WHEN the user views the filter options
- THEN the filter MUST include additional options: "Tasks" and/or "Cases"
- AND "All" MUST include Procest items

#### Scenario: Procest unavailable gracefully
@e2e exclude backend graceful degradation; covered by PHPUnit
- GIVEN Procest is not installed or not accessible
- WHEN the user views My Work
- THEN the system MUST display only Pipelinq items (leads and requests)
- AND the system MUST NOT show an error
- AND the cross-app filter options SHOULD be hidden

---

### Requirement: Item Card Layout [MVP]

Each item MUST follow a consistent card layout showing entity badge, title, stage/status, pipeline, value (leads), due date, and priority.

#### Scenario: Lead card in My Work
@e2e exclude requires assigned lead data
- WHEN a lead is displayed
- THEN it MUST show [LEAD] badge, title, stage, pipeline name, value, expected close date, and priority badge

#### Scenario: Request card in My Work
@e2e exclude requires assigned request data
- WHEN a request is displayed
- THEN it MUST show [REQ] badge, title, status, due date, and priority badge

---

### Requirement: My Work holds what is mine, the queue holds what is nobody's

My Work SHALL show the tickets assigned to the current user. It SHALL NOT show
unassigned work: that lives on the Queue page (`/queue`), which is every open ticket
with no assignee. Assigning a ticket moves it from the Queue to the assignee's My
Work; All tickets shows it in both states.

#### Scenario: My Work shows only the current user's tickets
@e2e exclude covered by spec-coverage/my-work.spec.ts, which asserts the assignee-scoped index

- **WHEN** an agent opens `/my-work`
- **THEN** every ticket shown has that agent as its assignee
- **AND** no unassigned ticket appears

#### Scenario: Picking work up moves it
@e2e exclude mutates a shared instance — assigning a ticket rewrites demo data other suites read

- **WHEN** an agent assigns themselves an unassigned ticket from `/queue`
- **THEN** the ticket appears on their `/my-work`
- **AND** it no longer appears on `/queue`

---

### Requirement: My Work UI — documented operations

The personal work overview screen implemented in this app MUST provide the operations enumerated in this change's tasks.md (for example `allItems`, `closedStageNames`, `computeGroup`, `currentUser`, `emptyMessage`, `fetchAll`). Each listed method realises an observable part of personal work overview screen and MUST behave as implemented in the current codebase.

**Feature tier**: V1

#### Scenario: Documented operations are available

@e2e exclude component/store method contract; page surface covered by real-UI spec-coverage tests + Vitest unit tests

- GIVEN the frontend component/store is loaded
- WHEN a caller invokes one of the documented operations for personal work overview screen
- THEN the operation MUST execute and return a result consistent with the current implementation

---

### Requirement: My Work UI — results derived from current CRM state

Operations for personal work overview screen MUST read their inputs from the relevant CRM entities/configuration and compute results from that live state (no hard-coded or stubbed responses). Derivations such as formatting, aggregation, filtering and validation MUST reflect the data present at call time.

**Feature tier**: V1

#### Scenario: Results reflect live state

@e2e exclude component/store method contract; page surface covered by real-UI spec-coverage tests + Vitest unit tests

- GIVEN CRM data backing personal work overview screen
- WHEN a documented operation runs
- THEN its output MUST be derived from that data
- AND it MUST change when the underlying data changes

---

### Requirement: My Work UI — defensive handling of absent or invalid input

Operations for personal work overview screen MUST tolerate missing, empty, or malformed input without throwing unhandled errors — returning empty or default results, or surfacing a validation outcome as implemented, rather than crashing the surrounding flow.

**Feature tier**: V1

#### Scenario: Missing input does not crash the flow

@e2e exclude component/store method contract; page surface covered by real-UI spec-coverage tests + Vitest unit tests

- GIVEN an operation for personal work overview screen is called with absent or invalid input
- WHEN it executes
- THEN it MUST return a safe default or a validation result
- AND it MUST NOT raise an unhandled exception

### Requirement: A user takes a ticket with one action (REQ-DPG-020)

TicketDetail and every row of the Queue SHALL offer "Assign to me". It SHALL
set the ticket's assignee to the signed-in user under their own
OpenRegister permissions: a ticket they cannot read answers 404, one they
cannot change answers 403. After assigning from the Queue, the ticket SHALL
open.

#### Scenario: An agent picks up a request from the Queue

- GIVEN an unassigned open request on the Queue
- WHEN the agent chooses "Assign to me" on its row
- THEN the request's assignee is the agent
- AND the request opens, and it is no longer on the Queue

### Requirement: My Work shows every ticket assigned to you (REQ-DPG-021)

My Work and the dashboard worklist SHALL list every open ticket assigned to
the user, whatever its ticket type, each with a badge for its type, next to
their leads and follow-ups. A ticket in a closed status (resolved, completed,
rejected, converted, closed) SHALL NOT be listed unless the user shows
completed items.

#### Scenario: An assigned complaint shows up

- GIVEN a complaint and a request both assigned to the agent
- WHEN the agent opens My Work
- THEN both appear under Tickets, one badged as a complaint, one as a request

### Requirement: My work lists all my items in one list

`GET /api/worklist/items` MUST return the session user's open tickets (requests, complaints, reports, contact moments), leads that are not in a closed stage, and open follow-up tasks in one shape: `{ kind, id, title, priority, channel, clientName, routeName, record, dueAt, bucket, dueGroup }`.
- `bucket` is `waiting-on-customer` for a ticket waiting on the customer, `answer-today` for an item due today or earlier, and `waiting-on-me` otherwise.
- `dueGroup` is `late`, `today`, `week`, `later` or `none`.
- `?bucket=`, `?lens=tickets|leads|tasks`, `?sort=deadline|waiting|newest|priority` and `?done=1` narrow and order the items.
- `?format=csv` downloads them.

My work's three lists MUST read this endpoint. On a phone the page MUST draw one list grouped by `dueGroup`.

#### Scenario: A lead and a follow-up stand in the lists with the tickets
- GIVEN Pieter has a request due yesterday, a lead closing yesterday, and a follow-up due in three days
- WHEN My work opens
- THEN "Vandaag beantwoorden" lists the lead and the request, each with its kind pill
- AND "Wacht op mij" lists the follow-up

#### Scenario: The phone groups by due
- GIVEN the same items
- WHEN My work opens 375px wide
- THEN one list shows "Te laat" with the lead and the request, then "Deze week" with the follow-up

### Requirement: KPI summary tiles on My work (REQ-MW-090)

**Feature tier**: V1

My work SHALL show a row of four tiles headed "Your week so far", taken from board `PqMijnWerk`: "Answered", "Within the deadline", "Average answer time" and "Converted to a case". Each tile MUST count only the current user's own tickets over the current calendar week, and MUST carry a one-line note under the value (answered today, the team's figure, the agreed answer time, where converted cases went). The tiles MUST NOT show team or organisation totals unless the user switched scope (REQ-MW-180).

#### Scenario: The tiles show the user's own week
- **GIVEN** the agent answered 14 tickets this week, 4 of them today, and 13 of the 14 within their deadline
- **WHEN** the agent opens My work
- **THEN** "Your week so far" shows "Answered" 14 with the note "4 today"
- **AND** "Within the deadline" shows 93% with the team's percentage as its note

#### Scenario: A week with nothing answered yet
- **GIVEN** it is Monday morning and the agent has answered nothing this week
- **WHEN** the agent opens My work
- **THEN** "Answered" shows 0 and "Average answer time" shows a dash, not 0 days
- **AND** no tile is styled as an error

#### Scenario: A filter does not change the week
- **GIVEN** the agent selects the "Leads" type filter
- **WHEN** the list narrows to leads
- **THEN** the four tiles keep their values, because they describe the week, not the list

### Requirement: Quick actions on My work (REQ-MW-100)

**Feature tier**: V1

My work SHALL offer "New ticket" and "New contact" in its header, as on board `PqMijnWerk`, and no "New lead". Each MUST open the existing create dialog of that entity as a modal over My work, with the assignee set to the current user where the entity has one. After a ticket is saved, the list MUST refresh and include it.

#### Scenario: A ticket created from My work lands on My work
- **GIVEN** the agent is on My work
- **WHEN** the agent chooses "New ticket", fills in the subject and saves
- **THEN** the new ticket's assignee is the agent
- **AND** My work shows the new ticket without a page reload

#### Scenario: A new contact does not change the list
- **GIVEN** the agent is on My work
- **WHEN** the agent chooses "New contact" and saves a contact
- **THEN** the contact's detail page opens
- **AND** the My work list is unchanged, because a contact is not work

#### Scenario: My work has no lead button
- **GIVEN** a user who may create leads
- **WHEN** the user opens My work
- **THEN** "New lead" is not shown in the header

### Requirement: Recent activity on my items (REQ-MW-110)

**Feature tier**: V1

My work SHALL include a collapsible "Recent activity" section listing the 10 most recent changes to items assigned to the current user, read from OpenRegister's audit trail of those objects. Each entry MUST show the type badge, the item's title, a relative time ("2 h ago") and what changed when the audit trail names it. The collapsed or open state MUST be remembered per user. No board draws this section yet.

#### Scenario: Changes on my items since yesterday
- **GIVEN** a colleague changed the status of a ticket assigned to the agent two hours ago
- **WHEN** the agent opens My work
- **THEN** "Recent activity" lists that ticket with "2 h ago" and the status change

#### Scenario: An entry opens its item
- **GIVEN** "Recent activity" lists a lead
- **WHEN** the agent clicks the entry
- **THEN** the lead's detail page opens

#### Scenario: Collapsed stays collapsed
- **GIVEN** the agent collapsed "Recent activity"
- **WHEN** the agent reloads My work
- **THEN** the section is still collapsed

#### Scenario: Nothing changed
- **GIVEN** no item assigned to the agent changed in the last 30 days
- **WHEN** the agent opens "Recent activity"
- **THEN** it reads "No recent activity on your items"

### Requirement: Upcoming follow-ups read at a glance (REQ-MW-120)

**Feature tier**: V1

A follow-up is a `crmTask` assigned to the current user; My work already lists them under the "Follow-ups" filter. A follow-up row SHALL show when it is planned, as on board `PqMijnWerk`: the time for a follow-up planned today ("planned 14:00"), and a relative day otherwise ("Tomorrow", "In 3 days"). A follow-up planned today MUST be marked "Follow-up today". An open follow-up past its deadline MUST sit in the Overdue group with "N days overdue". The system MUST NOT add a second follow-up date to the lead schema.

#### Scenario: A call-back planned this afternoon
- **GIVEN** a follow-up task "Call back about the parking permit" with deadline today 14:00, assigned to the agent
- **WHEN** the agent opens My work
- **THEN** the row reads "planned 14:00" and is marked "Follow-up today"

#### Scenario: A follow-up later this week
- **GIVEN** a follow-up task due in three days
- **WHEN** the agent opens My work
- **THEN** the row reads "In 3 days"

#### Scenario: A missed follow-up
- **GIVEN** a follow-up task whose deadline was two days ago and which is not completed
- **WHEN** the agent opens My work
- **THEN** the row is in the Overdue group and reads "2 days overdue"

### Requirement: Upcoming meetings from the calendar (REQ-MW-130)

**Feature tier**: V1

My work SHOULD show an "Upcoming meetings" section with the current user's calendar events of the next 7 days that are linked to a Pipelinq client, contact or lead, read through `OCP\Calendar\IManager` from the user's own calendars only. Each entry MUST show the title, the date and time, and the linked record's name. When the Calendar app is not installed, or no linked event falls in the window, the section MUST be hidden without an error. No board draws this section yet.

#### Scenario: A meeting with a client this week
- **GIVEN** the agent has a calendar event on Thursday whose description links client "Bakkerij De Jong"
- **WHEN** the agent opens My work
- **THEN** "Upcoming meetings" lists the event with its date, time and "Bakkerij De Jong"

#### Scenario: Another user's calendar stays out
- **GIVEN** a colleague has a linked event in their own calendar
- **WHEN** the agent opens My work
- **THEN** that event is not listed

#### Scenario: No Calendar app
- **GIVEN** the Nextcloud Calendar app is not installed
- **WHEN** the agent opens My work
- **THEN** "Upcoming meetings" is not shown and no error appears

### Requirement: Notification summary on My work (REQ-MW-140)

**Feature tier**: V1

My work SHALL show a badge in its header with the number of unread Pipelinq notifications of the current user (assignments, stage or status changes, notes), read through `OCP\Notification\IManager`. Opening the badge MUST list them with a type icon, the summary, a relative time and a link to the item. Opening a notification MUST mark it read and lower the count. With no unread notifications the badge MUST NOT be shown. No board draws this badge yet.

#### Scenario: Two unread assignments
- **GIVEN** two tickets were assigned to the agent since they last opened their notifications
- **WHEN** the agent opens My work
- **THEN** the header badge shows 2

#### Scenario: Reading one lowers the count
- **GIVEN** the badge shows 2
- **WHEN** the agent opens the list and clicks one notification
- **THEN** its item opens, the notification is marked read, and the badge shows 1

#### Scenario: Nothing unread
- **GIVEN** the agent has no unread Pipelinq notifications
- **WHEN** the agent opens My work
- **THEN** no badge is shown

### Requirement: Customisable layout (REQ-MW-160)

**Feature tier**: Enterprise

`pipelinq/PqMijnWerkIndelen` draws this panel.

The user SHALL be able to hide and reorder the sections of My work ("Your week so far", "Saved views", the work list, "Upcoming meetings", "Recent activity") from a layout panel, stored per user through `/api/settings/user`. "Terug naar de standaardindeling" MUST restore the order of board `PqMijnWerk`. The work list itself MUST NOT be hideable. No board draws the layout panel yet.

#### Scenario: Hide a section
- **GIVEN** the agent opens the layout panel
- **WHEN** the agent switches off "Recent activity"
- **THEN** the section disappears and stays hidden after a reload

#### Scenario: Move a section up
- **GIVEN** the agent opens the layout panel
- **WHEN** the agent moves "Upcoming meetings" above the work list
- **THEN** My work shows it above the list, also after a reload

#### Scenario: Back to the default
- **GIVEN** the agent changed the layout
- **WHEN** the agent chooses "Terug naar de standaardindeling"
- **THEN** every section is visible again in the board's order

### Requirement: Role-based content (REQ-MW-180)

**Feature tier**: V1

My work SHALL show only the user's own items by default. A user in a group that Pipelinq's settings name as a team manager group MUST get a scope switch "My items / Team"; "Team" MUST list the open items of every member of the manager's team and switch the tiles (REQ-MW-090) to team figures, with a list of members and their open count. A Pipelinq admin MUST also get "Organisation", listing every open item. Every scope MUST still read under the user's own OpenRegister permissions. A saved view can carry a scope, as the board's "Permits, whole team" does.

#### Scenario: An agent sees only their own items
- **GIVEN** a user in no manager group
- **WHEN** the user opens My work
- **THEN** no scope switch is shown and every item is assigned to the user

#### Scenario: A manager looks at the team
- **GIVEN** a manager of a team of four
- **WHEN** the manager switches to "Team"
- **THEN** the list holds the open items of all four, and each member is listed with their open count

#### Scenario: A manager narrows to one member
- **GIVEN** the manager is on "Team"
- **WHEN** the manager clicks one member
- **THEN** the list holds only that member's items, and a "Back to team" control returns to the full team

#### Scenario: An admin looks at the organisation
- **GIVEN** a Pipelinq admin
- **WHEN** the admin switches to "Organisation"
- **THEN** the list holds every open item the admin may read

### Requirement: Auto-refresh and a refresh button (REQ-MW-190)

**Feature tier**: V1

My work SHALL fetch its items again every 5 minutes while the page is open, without a page reload and without moving the scroll position, and SHALL offer a refresh button in its header that fetches at once. During a fetch the button MUST spin; the list MUST NOT be covered. When fetching fails and the data is older than 10 minutes, a warning MUST read "Data may be outdated. Last updated: <time>" with a retry button.

#### Scenario: New work arrives on its own
- **GIVEN** the agent has My work open
- **WHEN** a ticket is assigned to the agent and 5 minutes pass
- **THEN** the ticket appears in the list and the scroll position is unchanged

#### Scenario: Refresh now
- **GIVEN** the agent is on My work
- **WHEN** the agent clicks the refresh button
- **THEN** the items are fetched at once and the button spins until they arrive

#### Scenario: The connection dropped
- **GIVEN** fetching has failed for 11 minutes
- **WHEN** the agent looks at My work
- **THEN** the warning names the time of the last good fetch and offers "Retry"

### Requirement: Priority and pipeline filters (REQ-MW-200)

**Feature tier**: V1

Next to the type filter, My work SHALL offer a priority filter (urgent, high, normal, low; several at once) and a pipeline filter for leads. The pipeline filter MUST leave tickets and follow-ups in the list unless the type filter removes them. The filter control MUST show how many filters are active ("Filters (3)") and offer "Clear filters", which returns to all types, all priorities and all pipelines. Grouping and sorting MUST stay as they are.

#### Scenario: Urgent and high only
- **GIVEN** the agent's items have mixed priorities
- **WHEN** the agent selects "Urgent" and "High"
- **THEN** only urgent and high items are listed, still in their groups

#### Scenario: One pipeline
- **GIVEN** the agent has leads in two pipelines and also tickets
- **WHEN** the agent selects pipeline "Sales"
- **THEN** only leads of "Sales" are listed among the leads, and the tickets stay

#### Scenario: Three filters, then clear
- **GIVEN** the agent set type "Leads", priority "Urgent" and pipeline "Sales"
- **WHEN** the agent looks at the filter control
- **THEN** it reads "Filters (3)"
- **AND** after "Clear filters" every item is listed again

### Requirement: Saved views on My work (REQ-MW-150)

**Feature tier**: Enterprise

My work SHALL show a "Saved views" panel, as on board `PqMijnWerk`, listing the user's saved views with the number of items each holds ("Mine, all statuses (9)"). A saved view MUST store the type filter, Show completed, the priority and pipeline filters (REQ-MW-200) and the scope (REQ-MW-180). Saved views MUST be OpenRegister saved views through nextcloud-vue's `CnSavedViewsControl`; Pipelinq MUST NOT keep its own store of them.

#### Scenario: Save the current filters
- **GIVEN** the agent filtered My work to urgent tickets
- **WHEN** the agent saves the view as "Urgent tickets"
- **THEN** "Urgent tickets" appears in "Saved views" with its item count

#### Scenario: Apply a saved view
- **GIVEN** the agent has a saved view "Mine, resolved this month"
- **WHEN** the agent clicks it
- **THEN** every filter it stored is applied and the list shows its items

#### Scenario: Delete a saved view
- **GIVEN** the agent has a saved view
- **WHEN** the agent deletes it and confirms
- **THEN** it is gone from "Saved views"

#### Scenario: Saved views survive a new session
- **GIVEN** the agent saved two views
- **WHEN** the agent logs out and back in
- **THEN** both views are still listed

### Requirement: Phone view keeps headers and filters in reach (REQ-MW-170)

**Feature tier**: MVP

On top of REQ-MOB-002 (one column, today first, no horizontal scroll, already built), My work at a width below 768 px SHALL show the type filters as a row of chips with their counts, as on board `PqMobiel` ("All 9", "Tickets 7", "Leads 1", "Follow-ups 1"). Group headers MUST stay at the top of the scroll area while their group scrolls. Every control MUST keep at least the size board `PqMobiel` draws (34 px pills, a 40 px Actions button), well above the 24 px of WCAG 2.5.8. When quick actions (REQ-MW-100) are present they MUST fold into one "Actions" button in the header, as on board `PqMobiel`, and the header MUST hold nothing else beside the title.

#### Scenario: Chips with counts
- **GIVEN** the agent has 7 tickets, 1 lead and 1 follow-up
- **WHEN** the agent opens My work on a 390 px wide screen
- **THEN** the chips read "All 9", "Tickets 7", "Leads 1" and "Follow-ups 1"

#### Scenario: The group header stays in view
- **GIVEN** the Overdue group holds more rows than fit the screen
- **WHEN** the agent scrolls through it
- **THEN** the "Overdue" header and its count stay at the top until the next group starts

#### Scenario: Quick actions fold away
- **GIVEN** quick actions are built
- **WHEN** the agent opens My work on a phone
- **THEN** one "Actions" button in the header opens "New ticket" and "New contact"

## UI Layout Reference

The My Work view follows the wireframe in DESIGN-REFERENCES.md section 3.5:

```
Header: "My Work"  [Filter: All | Leads | Requests]  [Show completed toggle]
Subheader: "Leads (5) . Requests (3)  --  8 items total"

[Vandaag beantwoorden]  termijn is vandaag of verstreken
  - Item card (red highlight)
  - Item card

[Wacht op mij]  de klant heeft gereageerd
  - Item card
  - Item card

[Wacht op de klant]  niets te doen tot ze reageren
  - Item card
```

- Each group has a header with the group name and item count
- Empty groups are hidden
- Items are full-width cards within each group
- The view is scrollable with all groups visible (no pagination)
- The layout MUST be responsive and stack properly on narrow viewports
- All interactive elements MUST be keyboard accessible (WCAG AA)

---

### Current Implementation Status

**Implemented:**
- Full `MyWork.vue` view exists at `src/views/MyWork.vue` with route `/my-work` (name `MyWork`) in `src/router/index.js`.
- **REQ-MW-010 (Personal Workload View):** Fully implemented. Fetches leads and requests assigned to `OC.currentUser` via OpenRegister API. Shows entity type badge (LEAD/REQ), title, stage/status, pipeline name, value (leads only), due date, and priority badge.
- **REQ-MW-020 (Sorting):** Implemented within each group -- items sorted by priority (urgent > high > normal > low), then by due date ascending.
- **REQ-MW-030 (Grouping by what to do):** Not built. The code groups by due date (Overdue, Due This Week, Upcoming, No Due Date); `pipelinq/PqMijnWerk` draws the three groups this spec now names.
- **REQ-MW-040 (Filtering):** Implemented. Filter buttons for All, Leads, Requests. Item counts update with filter.
- **REQ-MW-050 (Overdue Highlighting):** Implemented. Overdue items have red left border (`work-card--overdue`), red text showing "N days overdue". "Due today" warning is shown.
- **REQ-MW-060 (Item Navigation):** Implemented. Clicking a card navigates to `LeadDetail` or `RequestDetail` via `$router.push`. Cards are keyboard accessible with `tabindex="0"` and `@keydown.enter`.
- **REQ-MW-080 (Item Card Layout):** Implemented with entity badge, title, stage/status, pipeline name, value (leads), due date, and priority badge.
- Show completed toggle is implemented with visual distinction (opacity 0.6 via `work-card--completed` class).
- Item count breakdown in header ("Leads (5) . Requests (3) -- 8 items total") is implemented.
- Stale badge is shown on leads (14+ days since modification) using `isStale()` from `src/services/pipelineUtils.js`.

**Not yet implemented:**
- **REQ-MW-070 (Cross-App Workload with Procest):** Not implemented. No integration with Procest tasks/cases. The filter only shows All/Leads/Requests.
- No pagination -- fetches up to 200 leads and 200 requests via `_limit: 200`.
- Empty state message is generic ("No items assigned to you") rather than per-filter.

**Partial implementations:**
- Request overdue detection uses a fixed 30-day threshold from `requestedAt`, not the `expectedCloseDate` pattern. This may not match all user expectations.

### Standards & References
- WCAG AA: Keyboard accessibility implemented (tabindex, keydown.enter handlers). Color-only distinction is supplemented with text ("N days overdue", badges).
- No specific API standard applies -- this is a frontend aggregation view.

### Specificity Assessment
- The spec is well-defined for MVP requirements. All MVP scenarios have clear acceptance criteria.
- **V1 gap:** The cross-app Procest integration (REQ-MW-070) lacks detail on how Procest tasks will be discovered -- via direct API call, shared register, or cross-app event system.
- **Open question:** The spec groups requests in "No Due Date" or "Overdue" only. Should requests with a `requestedAt` date use that as their temporal grouping date (current implementation) or only use explicit due dates?

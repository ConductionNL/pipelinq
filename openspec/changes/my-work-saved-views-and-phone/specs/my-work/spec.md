## ADDED Requirements

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

On top of REQ-MOB-002 (one column, today first, no horizontal scroll, already built), My work at a width below 768 px SHALL show the type filters as a row of chips with their counts, as on board `PqMobiel` ("All 9", "Tickets 7", "Leads 1", "Follow-ups 1"). Group headers MUST stay at the top of the scroll area while their group scrolls, with their count visible. Every control MUST keep a touch target of at least 44 by 44 px (WCAG 2.5.5). When quick actions (REQ-MW-100) are present they MUST fold into one "Actions" button in the header, as on board `PqMobiel`.

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

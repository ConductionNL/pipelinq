## ADDED Requirements

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

My work SHALL offer "New ticket", "New lead" and "New contact" in its header. Each MUST open the existing create dialog of that entity as a modal over My work, with the assignee set to the current user where the entity has one. After a ticket or lead is saved, the list MUST refresh and include it. No board draws these buttons yet; their placement follows the header actions of `PqTickets`.

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

#### Scenario: No lead access, no lead button
- **GIVEN** the user cannot create leads under their OpenRegister permissions
- **WHEN** the user opens My work
- **THEN** "New lead" is not shown

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

### Requirement: Customisable layout (REQ-MW-160)

**Feature tier**: Enterprise

The user SHALL be able to hide and reorder the sections of My work ("Your week so far", "Saved views", the work list, "Upcoming meetings", "Recent activity") from a layout panel, stored per user through `/api/settings/user`. "Reset to default" MUST restore the order of board `PqMijnWerk`. The work list itself MUST NOT be hideable. No board draws the layout panel yet.

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
- **WHEN** the agent chooses "Reset to default"
- **THEN** every section is visible again in the board's order

### Requirement: Phone view keeps headers and filters in reach (REQ-MW-170)

**Feature tier**: MVP

On top of REQ-MOB-002 (one column, today first, no horizontal scroll, already built), My work at a width below 768 px SHALL show the type filters as a row of chips with their counts, as on board `PqMobiel` ("All 9", "Tickets 7", "Leads 1", "Follow-ups 1"). Group headers MUST stay at the top of the scroll area while their group scrolls, with their count visible. Every control MUST keep a touch target of at least 44 by 44 px (WCAG 2.5.5). When quick actions (REQ-MW-100) are present they MUST collapse into one button in the bottom right corner.

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
- **THEN** one button in the bottom right corner opens "New ticket", "New lead" and "New contact"

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

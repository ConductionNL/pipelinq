# ticket-handover-history Specification (delta)

## Purpose

A ticket's History tab tells in words who passed the ticket to whom and what happened to it afterwards. Covers row req-handover-log. Board PqTicket. Built from OpenRegister's audit trail through the shared nextcloud-vue `audit-trail` widget.

## ADDED Requirements

### Requirement: The ticket page has a History tab in words (REQ-THH-001)

The ticket page SHALL have a History tab that lists the ticket's audit trail newest first, each entry as a sentence with the actor's display name and the date and time. A change of assignee SHALL read "Passed to <new assignee> by <actor>", a change of team "Moved to team <team> by <actor>", a change of status the state it reached (for example "Closed by <actor>"), and creation "Created by <actor>". A note added with a handover SHALL show under its entry. The entries SHALL come from OpenRegister's audit trail; pipelinq SHALL NOT keep a separate log.

#### Scenario: A handover in words
- GIVEN ticket PQ-2026-0417 that Sanne passed to Joris with the note "Needs the permit number"
- WHEN anyone who may read the ticket opens its History tab
- THEN the newest entries include "Passed to Joris Bakker by Sanne de Vries" with the note under it

#### Scenario: What happened after the handover
- GIVEN Joris later set the ticket to waiting and then closed it
- WHEN Sanne opens the History tab
- THEN above her handover she reads "Waiting for the resident by Joris Bakker" and "Closed by Joris Bakker", with their times

#### Scenario: A field without its own label
- GIVEN a change to a field that has no label configured
- WHEN the History tab shows it
- THEN it reads "Updated by <actor>" and lists the changed field's name

### Requirement: The history shows only what the reader may see (REQ-THH-002)

The History tab SHALL show entries only to users who may read the ticket, and SHALL NOT show the old or new value of a field the reader may not read.

#### Scenario: A restricted field
- GIVEN a ticket field that only the team lead role may read
- WHEN a handler without that role opens the History tab
- THEN an entry for a change to that field reads "Updated by <actor>" without the values

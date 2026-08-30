## MODIFIED Requirements

### Requirement: Client Timeline (All Interactions)

The system MUST provide a unified interaction timeline on the client detail view that aggregates all CRM activity types into a single chronological feed.

**Feature tier**: V1

#### Scenario: Timeline aggregates all entity types

- GIVEN a client "Acme B.V." with the following history:
  - Mar 1: Client created by user "admin"
  - Mar 5: Contact person "Jan Jansen" added
  - Mar 10: Lead "Website Redesign" created (EUR 15,000)
  - Mar 12: Lead "Website Redesign" moved to stage "Qualified"
  - Mar 15: Request "Support Ticket #101" created
  - Mar 16: Contactmoment "Telefonische vraag over factuur" registered by agent "kcc1" via channel "telefoon"
  - Mar 18: Note "Followed up by phone" added by user "sales1"
  - Mar 19: Contactmoment "E-mail bevestiging afspraak" registered by agent "kcc2" via channel "email"
  - Mar 20: Request "Support Ticket #101" resolved
  - Mar 25: Lead "Website Redesign" won
- WHEN the user views the client detail timeline
- THEN the system MUST display all 10 events in reverse chronological order
- AND each event MUST show: date, event type icon, description, and actor (user who performed the action)
- AND contactmoment events MUST show: subject, channel icon, and agent name
- AND events MUST be visually distinguished by type (create, update, stage change, note, resolution, contactmoment)

#### Scenario: Timeline supports filtering by event type

- GIVEN a client with 50 timeline events of various types
- WHEN the user filters the timeline by "Contactmomenten only"
- THEN only contactmoment events MUST be displayed
- AND filter options MUST include: All, Leads, Requests, Contacts, Notes, Contactmomenten, Field changes

#### Scenario: Timeline pagination

- GIVEN a client with 200 timeline events
- WHEN the user views the timeline
- THEN the system MUST display the most recent 20 events initially
- AND a "Load more" button MUST load the next 20 events
- AND the system MUST indicate the total number of events

#### Scenario: Timeline shows linked entity details

- GIVEN a timeline event "Lead 'Website Redesign' moved to Qualified"
- WHEN the user clicks on the lead name in the timeline
- THEN the system MUST navigate to the lead detail view
- AND the same click-through behavior MUST apply to requests, contacts, contactmomenten, and other referenced entities

#### Scenario: Contactmoment quick-log from timeline

- GIVEN a user is viewing a client's timeline
- WHEN the user clicks "Log contactmoment" above the timeline
- THEN the quick-log form MUST open with the client pre-filled
- AND after submission, the timeline MUST refresh to include the new contactmoment

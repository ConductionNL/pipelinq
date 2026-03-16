# activity-timeline Specification

## Purpose
Provide a chronological activity feed per contact, organization, and pipeline item in Pipelinq. All interactions -- status changes, notes, emails, calls, document uploads, field changes, and linked case events -- appear in one unified timeline. This gives the complete "klantbeeld" (customer view) that relationship managers need to understand the full history of any entity at a glance.

Activity timelines are the single most requested CRM feature and are implemented in nearly all modern CRM platforms -- they answer "what happened with this contact?" without searching through multiple views. Common approaches include audit-log-style timelines, journal-style activity tracking, and chronological task linking.

## Requirements

### Requirement: Every entity MUST have a timeline view
Contacts, organizations, and pipeline items each display a unified activity timeline.

#### Scenario: View contact timeline
- GIVEN contact `Jan de Vries` has had 15 interactions over the past month
- WHEN a user opens Jan's contact detail page
- THEN the timeline tab MUST show all 15 interactions in reverse chronological order
- AND each entry MUST display: timestamp, actor (who did it), action type icon, and description

#### Scenario: View organization timeline
- GIVEN organization `Gemeente Utrecht` has 3 contacts with combined 40 timeline entries
- WHEN a user opens the organization detail page
- THEN the timeline MUST show all 40 entries aggregated from all linked contacts
- AND each entry MUST indicate which contact it relates to

### Requirement: Timeline MUST capture all interaction types
The timeline automatically records a comprehensive set of event types.

#### Scenario: Status change recorded
- GIVEN pipeline item `deal-1` is moved from "Offerte" to "Onderhandeling"
- THEN a timeline entry MUST be created:
  - `type`: `status_change`
  - `description`: "Status gewijzigd van Offerte naar Onderhandeling"
  - `actor`: the user who moved it
  - `metadata`: `{"from": "Offerte", "to": "Onderhandeling"}`

#### Scenario: Note added
- GIVEN a user adds a note "Telefonisch gesproken, klant is geinteresseerd in uitbreiding"
- THEN a timeline entry MUST be created:
  - `type`: `note`
  - `content`: the full note text
  - `actor`: the note author

#### Scenario: Document uploaded
- GIVEN a user uploads `offerte-2026-q1.pdf` to pipeline item `deal-1`
- THEN a timeline entry MUST be created:
  - `type`: `document`
  - `description`: "Document toegevoegd: offerte-2026-q1.pdf"
  - `metadata`: `{"fileName": "offerte-2026-q1.pdf", "fileId": "..."}`

#### Scenario: Field value changed
- GIVEN a user changes the `expectedValue` of deal `deal-1` from 50000 to 75000
- THEN a timeline entry MUST be created:
  - `type`: `field_change`
  - `description`: "Verwachte waarde gewijzigd van 50.000 naar 75.000"
  - `metadata`: `{"field": "expectedValue", "from": 50000, "to": 75000}`

### Requirement: Timeline MUST support manual entries
Users can add notes, log calls, and record meetings manually.

#### Scenario: Log a phone call
- GIVEN a user clicks "Log gesprek" on a contact
- WHEN they fill in: duration (15 min), summary ("Besproken: projectplanning Q2"), and outcome ("Follow-up next week")
- THEN a timeline entry MUST be created with type `call`
- AND the entry MUST be visible on both the contact's and the related pipeline item's timeline

#### Scenario: Log a meeting
- GIVEN a user clicks "Log meeting" on a contact
- WHEN they fill in: date, duration, participants, and summary
- THEN a timeline entry MUST be created with type `meeting`
- AND all participants who are Pipelinq contacts MUST have this entry on their timelines

### Requirement: Timeline MUST be filterable and searchable
Users need to find specific interactions quickly.

#### Scenario: Filter by activity type
- GIVEN a contact has 50 timeline entries of mixed types
- WHEN a user filters by type `note`
- THEN only note entries MUST be shown

#### Scenario: Search timeline content
- GIVEN a contact has 100 timeline entries
- WHEN a user searches for "offerte"
- THEN only entries containing "offerte" in their description or content MUST be shown

#### Scenario: Date range filter
- GIVEN a contact has timeline entries spanning 2 years
- WHEN a user filters to "last 30 days"
- THEN only entries from the last 30 days MUST be shown

### Requirement: Timeline MUST integrate with linked cases
If a contact has linked Procest cases, case events appear in the contact timeline.

#### Scenario: Case event in contact timeline
- GIVEN contact `Jan de Vries` is linked to case `zaak-1` in Procest
- AND case `zaak-1` changes status to `besluit_genomen`
- THEN the contact timeline MUST show: "Zaak ZK-2026-001: Status gewijzigd naar Besluit genomen"
- AND clicking the entry MUST navigate to the case in Procest

### Requirement: Timeline entries MUST be available via API
External systems and dashboards need timeline data.

#### Scenario: API returns paginated timeline
- GIVEN contact `contact-1` has 200 timeline entries
- WHEN `GET /api/contacts/{contact-1}/timeline?page=1&limit=20` is called
- THEN the first 20 entries (newest first) MUST be returned
- AND pagination metadata MUST indicate total count and available pages

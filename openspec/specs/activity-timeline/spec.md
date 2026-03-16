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

---

### Current Implementation Status

**Partially implemented.** The app has Nextcloud Activity integration for CRM events, but not a dedicated per-entity unified timeline.

Implemented:
- `lib/Service/ActivityService.php` -- publishes events to the Nextcloud activity stream: `publishCreated()`, `publishAssigned()`, `publishStageChanged()`, `publishStatusChanged()`, `publishNoteAdded()`. Covers lead/request creation, assignment, stage/status changes, and note additions.
- `lib/Activity/Provider.php` -- renders activity events with human-readable messages and Pipelinq deep links.
- `lib/Activity/Filter.php` -- filters the activity stream to show only Pipelinq events.
- `lib/Activity/Setting/AssignmentSetting.php`, `StageStatusSetting.php`, `NoteSetting.php` -- user-configurable notification preferences for activity types.
- `lib/Listener/ObjectEventListener.php` + `lib/Service/ObjectEventHandlerService.php` -- listens to OpenRegister `ObjectCreatedEvent` and `ObjectUpdatedEvent` to trigger activity publishing for leads and requests.
- `lib/Service/ObjectUpdateDiffService.php` -- detects assignee, stage, and status changes between old and new object versions.
- `src/views/Dashboard.vue` -- the Nextcloud dashboard widget `RecentActivitiesWidget` (registered in `lib/Dashboard/RecentActivitiesWidget.php`) shows recent activities.
- The `CnDetailPage` component used by `ClientDetail.vue`, `LeadDetail.vue`, `RequestDetail.vue` renders a sidebar that uses OpenRegister's audit log, which provides a basic timeline.

NOT implemented:
- No dedicated per-entity timeline API endpoint (`GET /api/contacts/{id}/timeline`).
- No unified timeline view that aggregates notes, emails, documents, field changes, and case events into a single chronological feed per contact/organization/pipeline item.
- No manual entry types for calls and meetings (call logging, meeting logging forms).
- No timeline filtering or search capability within entity detail views.
- No cross-entity aggregation (organization timeline aggregating all linked contacts' events).
- No linked Procest case event integration in the contact timeline.
- Activity events only cover leads and requests -- client and contact entity events are NOT published to the activity stream.
- Document upload and field change events are NOT captured as timeline entries.

### Standards & References
- Nextcloud Activity API (`OCP\Activity\IManager`) -- currently used for event publishing
- Schema.org `Action` / `InteractionCounter` -- could model timeline events
- VNG Klantinteracties `Contactmoment` -- relevant for government-facing timeline entries
- OpenRegister audit log -- provides basic versioned change history per object

### Specificity Assessment
- The spec is well-structured with clear scenarios and event types.
- **Missing**: The spec does not specify how timeline entries are stored -- are they OpenRegister objects, Nextcloud Activity entries, or a separate storage mechanism? The current implementation uses Nextcloud Activity (global stream) which is not the same as a per-entity timeline.
- **Missing**: No API contract for the timeline endpoint (response schema, pagination format).
- **Missing**: No specification of how manual entries (calls, meetings) link to multiple entities simultaneously.
- **Ambiguous**: The spec says "timeline MUST integrate with linked cases" but does not define how Procest case events are discovered or polled.
- **Open question**: Should the timeline be powered by the Nextcloud Activity stream, OpenRegister audit log, or a dedicated timeline entity? Each approach has different trade-offs for querying and performance.

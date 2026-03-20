# activity-timeline Specification

## Purpose
Provide a chronological activity feed per contact, organization, and pipeline item in Pipelinq. All interactions -- status changes, notes, emails, calls, document uploads, field changes, and linked case events -- appear in one unified timeline. This gives the complete "klantbeeld" (customer view) that relationship managers need to understand the full history of any entity at a glance.

Activity timelines are the single most requested CRM feature and are implemented in nearly all modern CRM platforms -- they answer "what happened with this contact?" without searching through multiple views. Common approaches include audit-log-style timelines, journal-style activity tracking, and chronological task linking.

## Requirements

### Requirement: Every entity MUST have a timeline view
Contacts, organizations, and pipeline items each MUST display a unified activity timeline.

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
The timeline MUST automatically record a comprehensive set of event types.

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
Users MUST be able to add notes, log calls, and record meetings manually.

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
The system MUST allow users to find specific interactions quickly through filtering and search.

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
If a contact has linked Procest cases, case events MUST appear in the contact timeline.

#### Scenario: Case event in contact timeline
- GIVEN contact `Jan de Vries` is linked to case `zaak-1` in Procest
- AND case `zaak-1` changes status to `besluit_genomen`
- THEN the contact timeline MUST show: "Zaak ZK-2026-001: Status gewijzigd naar Besluit genomen"
- AND clicking the entry MUST navigate to the case in Procest

### Requirement: Timeline entries MUST be available via API
The system MUST expose timeline data via API for external systems and dashboards.

#### Scenario: API returns paginated timeline
- GIVEN contact `contact-1` has 200 timeline entries
- WHEN `GET /api/contacts/{contact-1}/timeline?page=1&limit=20` is called
- THEN the first 20 entries (newest first) MUST be returned
- AND pagination metadata MUST indicate total count and available pages

---

## ADDED Requirements

### Requirement: Activity events MUST cover all entity types
The current ActivityService only publishes events for leads and requests. All Pipelinq entity types -- clients, contacts, leads, and requests -- MUST generate activity events on create and update.

#### Scenario: Client creation generates activity
- GIVEN a user creates a new client "Gemeente Tilburg" with type "organization"
- THEN `ActivityService::publishCreated()` MUST be called with `entityType: "client"`
- AND the event MUST appear in the Nextcloud activity stream for the creating user
- AND the `ObjectEventHandlerService::isRelevantEntityType()` method MUST accept "client" and "contact" in addition to "lead" and "request"

#### Scenario: Contact update generates activity
- GIVEN contact "Lisa van Dijk" has her email changed from "lisa@old.nl" to "lisa@new.nl"
- THEN a `field_change` activity event MUST be published
- AND the event MUST include metadata `{"field": "email", "from": "lisa@old.nl", "to": "lisa@new.nl"}`
- AND the `ObjectUpdateDiffService` MUST track field changes for client and contact entities, not only assignee/stage/status

### Requirement: Timeline entries MUST be stored as OpenRegister objects
Timeline entries must be queryable per entity, unlike Nextcloud Activity events which are stored in a global stream without per-object query support.

#### Scenario: Timeline entry persisted on note addition
- GIVEN a user adds a note to lead "Website redesign project"
- THEN an OpenRegister object MUST be created in the timeline schema with:
  - `entityType`: "lead"
  - `entityId`: the lead's OpenRegister object ID
  - `activityType`: "note"
  - `content`: the note text
  - `actor`: the current user's UID
  - `timestamp`: ISO 8601 creation time
  - `metadata`: `{}`
- AND the Nextcloud Activity event MUST still be published in parallel for the global activity stream

#### Scenario: Timeline entry persisted on automatic event
- GIVEN a request "Aanvraag vergunning" has its status changed from "open" to "in_behandeling"
- THEN an OpenRegister timeline object MUST be created with:
  - `activityType`: "status_change"
  - `entityType`: "request"
  - `entityId`: the request's object ID
  - `metadata`: `{"from": "open", "to": "in_behandeling"}`
- AND the timeline object MUST reference the same register configured in Pipelinq settings

### Requirement: Timeline API MUST support filtering by type, date range, and actor
The per-entity timeline endpoint must accept query parameters for precise filtering.

#### Scenario: Filter timeline by multiple activity types
- GIVEN lead "Enterprise deal" has 30 timeline entries spanning types note, call, status_change, field_change, and assignment
- WHEN `GET /api/timeline/{entityType}/{entityId}?types=note,call` is called
- THEN only entries with `activityType` "note" or "call" MUST be returned
- AND entries of other types MUST be excluded

#### Scenario: Filter timeline by date range
- GIVEN contact "Piet Jansen" has timeline entries from January 2025 through March 2026
- WHEN `GET /api/timeline/contact/{id}?from=2026-01-01&to=2026-03-31` is called
- THEN only entries with timestamps in Q1 2026 MUST be returned

#### Scenario: Filter timeline by actor
- GIVEN a lead has been worked on by users "admin", "sales1", and "sales2"
- WHEN `GET /api/timeline/lead/{id}?actor=sales1` is called
- THEN only entries where the actor is "sales1" MUST be returned

### Requirement: Timeline UI MUST display entries grouped by date
The timeline view in entity detail pages must visually group entries by calendar date with clear date separators.

#### Scenario: Timeline renders date groups
- GIVEN contact "Maria Bakker" has 5 entries from today, 3 from yesterday, and 2 from last week
- WHEN the user views Maria's timeline tab
- THEN entries MUST be grouped under date headers: "Vandaag", "Gisteren", and the specific date (e.g., "13 maart 2026")
- AND within each group, entries MUST be ordered newest-first

#### Scenario: Activity type icons distinguish entry types
- GIVEN a timeline contains entries of types note, call, email, meeting, status_change, assignment, document, and field_change
- THEN each type MUST display a distinct icon:
  - `note`: pencil/edit icon
  - `call`: phone icon
  - `email`: email/envelope icon
  - `meeting`: calendar icon
  - `status_change`: arrow-right icon
  - `assignment`: person icon
  - `document`: file icon
  - `field_change`: edit/swap icon
- AND each type MUST have a translatable label (Dutch + English minimum)

#### Scenario: Timeline entry shows relative timestamps
- GIVEN a timeline entry was created 3 hours ago
- THEN the entry MUST display "3u geleden" (or "3h ago" in English)
- AND hovering over the relative time MUST show the full ISO 8601 timestamp in a tooltip

### Requirement: Cross-entity timeline aggregation MUST link related entities
Activities on related entities (client, contacts, leads, requests) must be visible across their linked timelines.

#### Scenario: Client timeline aggregates contact and lead activities
- GIVEN client "Gemeente Utrecht" has 2 linked contacts and 3 linked leads
- AND contact "Jan" has 10 timeline entries and contact "Piet" has 8 entries
- AND leads linked to this client have a combined 12 entries
- WHEN the user views the client's timeline
- THEN all 30 entries MUST be shown in unified reverse chronological order
- AND each entry MUST display a badge indicating its source entity (e.g., "Jan de Vries" or "Lead: Website project")

#### Scenario: Request timeline includes linked client activities
- GIVEN request "Subsidie aanvraag" is linked to client "Stichting Groen"
- AND the client has 5 recent timeline entries
- WHEN the user views the request's timeline
- THEN the request's own entries MUST appear first
- AND a "Gerelateerde activiteiten" (Related activities) section MUST show the client's recent entries
- AND clicking a related entry MUST navigate to the source entity's detail page

### Requirement: Activity notifications MUST be configurable per type
Users must be able to enable or disable notifications for each activity type independently, extending the existing AssignmentSetting, StageStatusSetting, and NoteSetting pattern.

#### Scenario: User disables call activity notifications
- GIVEN user "sales1" navigates to Personal Settings > Activity
- WHEN they uncheck "Pipelinq call activities" for email notification
- THEN call-type timeline entries MUST NOT generate email notifications for "sales1"
- AND call entries MUST still appear in the activity stream and entity timeline

#### Scenario: New activity types have default notification settings
- GIVEN a fresh Pipelinq installation with activity types: assignment, stage_status, note, call, meeting, email, document, field_change
- THEN each type MUST have an `IActivitySetting` implementation registered in `Application::register()`
- AND all types MUST default to "enabled" for activity stream display
- AND email/push notifications MUST default to "enabled" only for assignment and stage_status types

### Requirement: Scheduled activities MUST support follow-up reminders
Users must be able to schedule follow-up activities with due dates that trigger reminders.

#### Scenario: Schedule a follow-up call
- GIVEN a user is viewing lead "Enterprise deal" timeline
- WHEN they click "Plan opvolging" (Schedule follow-up) and set:
  - type: "call"
  - due date: 2026-03-25 at 14:00
  - note: "Bel terug over offerte"
- THEN a timeline entry MUST be created with:
  - `activityType`: "scheduled_call"
  - `dueDate`: "2026-03-25T14:00:00+01:00"
  - `status`: "pending"
  - `content`: "Bel terug over offerte"
- AND the entry MUST appear in the timeline with a clock icon and the due date prominently displayed

#### Scenario: Overdue follow-up generates notification
- GIVEN a scheduled follow-up call for lead "Enterprise deal" has due date 2026-03-20 14:00
- AND the current time is 2026-03-20 14:00
- THEN a Nextcloud notification MUST be sent to the assigned user: "Opvolging vervallen: Bel terug over offerte"
- AND the timeline entry MUST display a warning indicator showing it is overdue

#### Scenario: Complete a scheduled activity
- GIVEN a scheduled follow-up exists with status "pending"
- WHEN the user clicks "Markeer als voltooid" (Mark as completed) and optionally adds a completion note
- THEN the timeline entry status MUST change to "completed"
- AND a new timeline entry MUST be created recording the completion: `activityType`: "call", with reference to the scheduled entry

### Requirement: Activity templates MUST allow quick entry of common interactions
Pre-defined templates reduce data entry for frequently logged activity types.

#### Scenario: Use a call template
- GIVEN the system has a template "Standaard telefoongesprek" with pre-filled fields:
  - type: "call"
  - duration: 15 minutes
  - outcome options: ["Terugbellen", "Offerte sturen", "Geen interesse", "Doorverbinden"]
- WHEN a user clicks "Log gesprek" on a contact
- THEN the call logging form MUST offer the template as a quick-fill option
- AND selecting it MUST pre-populate the duration field
- AND the outcome MUST be selectable from the template's predefined options

#### Scenario: Admin configures activity templates
- GIVEN an admin navigates to Pipelinq settings
- WHEN they create a new activity template with name "Klachtafhandeling" and fields:
  - type: "note"
  - category: "klacht"
  - required fields: ["summary", "resolution"]
- THEN the template MUST be available to all users when adding timeline entries
- AND the template MUST enforce that "summary" and "resolution" fields are filled before saving

### Requirement: Timeline MUST support activity search with full-text matching
Users must be able to search across all timeline content for an entity.

#### Scenario: Search finds matches in note content
- GIVEN contact "Maria Bakker" has 80 timeline entries, 3 of which contain the word "subsidie" in their content
- WHEN the user types "subsidie" in the timeline search box
- THEN exactly 3 entries MUST be shown
- AND the search term MUST be highlighted in the results
- AND the search MUST match against both `content` and `description` fields

#### Scenario: Search with no results shows empty state
- GIVEN a lead timeline has 20 entries
- WHEN the user searches for "xyznonexistent"
- THEN an empty state MUST be shown with the message "Geen activiteiten gevonden voor 'xyznonexistent'"
- AND a button MUST allow clearing the search to return to the full timeline

### Requirement: Activity export MUST support CSV and JSON formats
Timeline data must be exportable for reporting and compliance purposes.

#### Scenario: Export contact timeline as CSV
- GIVEN contact "Jan de Vries" has 45 timeline entries
- WHEN the user clicks "Exporteer tijdlijn" and selects CSV format
- THEN a CSV file MUST be downloaded with columns: Datum, Type, Beschrijving, Gebruiker, Inhoud
- AND all 45 entries MUST be included regardless of current filter settings
- AND timestamps MUST be formatted as "dd-mm-yyyy HH:mm" in the Dutch locale

#### Scenario: Export filtered timeline as JSON
- GIVEN a lead timeline is filtered to show only "call" and "meeting" entries (12 entries)
- WHEN the user clicks "Exporteer gefilterd" and selects JSON format
- THEN a JSON file MUST be downloaded containing only the 12 filtered entries
- AND each entry MUST include all fields: id, entityType, entityId, activityType, actor, timestamp, content, metadata

### Requirement: Activity reporting MUST provide agent productivity metrics
Managers must be able to view aggregated activity statistics per user for performance tracking.

#### Scenario: View agent activity summary
- GIVEN user "sales1" has logged 15 calls, 8 meetings, 25 notes, and 5 emails in the current month
- WHEN a manager navigates to Pipelinq Dashboard > Agent Productiviteit
- THEN a summary card MUST show:
  - Total activities: 53
  - Breakdown by type: Gesprekken: 15, Vergaderingen: 8, Notities: 25, E-mails: 5
  - Comparison to previous month (e.g., "+12% ten opzichte van vorige maand")

#### Scenario: Activity count per pipeline stage
- GIVEN pipeline "Verkoop" has stages "Prospectie", "Offerte", "Onderhandeling", "Gewonnen"
- WHEN a manager views the pipeline activity report
- THEN each stage MUST display the total number of activities logged in the current period
- AND stages with zero activities in the last 7 days MUST be highlighted as potentially stale

### Requirement: Nextcloud Activity integration MUST remain the notification backbone
The existing Nextcloud Activity integration via `OCP\Activity\IManager` must continue to serve as the global notification and activity stream engine, while per-entity timelines use OpenRegister storage.

#### Scenario: Dual-write to Nextcloud Activity and OpenRegister
- GIVEN a lead's stage changes from "Prospectie" to "Offerte"
- THEN `ActivityService::publishStageChanged()` MUST publish to Nextcloud Activity (global stream, notification delivery)
- AND a timeline OpenRegister object MUST be created (per-entity queryable timeline)
- AND both records MUST contain the same actor, timestamp, and event metadata

#### Scenario: Nextcloud Activity Filter shows only Pipelinq events
- GIVEN user "admin" has Pipelinq activities and OpenRegister activities in their global stream
- WHEN they click the "Pipelinq" filter in the Activity app sidebar (provided by `Activity\Filter`)
- THEN only events with app "pipelinq" MUST be shown
- AND the filter MUST include all activity types: assignment, stage_status, notes, calls, meetings, documents, and field_changes

### Requirement: Email activity type MUST capture inbound and outbound email interactions
Email exchanges with contacts and clients must be logged in the timeline, whether manually or via integration.

#### Scenario: Manually log an outbound email
- GIVEN a user clicks "Log e-mail" on contact "Piet Jansen"
- WHEN they fill in: subject ("Offerte Q2 2026"), recipient (piet@example.nl), and summary ("Offerte verstuurd per e-mail")
- THEN a timeline entry MUST be created with:
  - `activityType`: "email"
  - `direction`: "outbound"
  - `metadata`: `{"subject": "Offerte Q2 2026", "recipient": "piet@example.nl"}`
- AND the entry MUST appear on both the contact's and any linked lead's timeline

#### Scenario: Email activity links to Nextcloud Mail message
- GIVEN the user has Nextcloud Mail configured
- AND they log an email activity with a Nextcloud Mail message ID
- THEN the timeline entry metadata MUST include `{"mailMessageId": "..."}`
- AND clicking the entry MUST open the linked email in Nextcloud Mail

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
- `lib/Service/NoteEventService.php` -- triggers note-related activity and notification events for all entity types (client, contact, lead, request) via the `TYPE_MAP` constant.
- `src/views/Dashboard.vue` -- the Nextcloud dashboard widget `RecentActivitiesWidget` (registered in `lib/Dashboard/RecentActivitiesWidget.php`) shows recent activities by querying OpenRegister objects directly.
- The `CnDetailPage` component used by `ClientDetail.vue`, `LeadDetail.vue`, `RequestDetail.vue` renders a sidebar that uses OpenRegister's audit log, which provides a basic timeline.

NOT implemented:
- No dedicated per-entity timeline API endpoint (`GET /api/timeline/{entityType}/{entityId}`).
- No unified timeline view that aggregates notes, emails, documents, field changes, and case events into a single chronological feed per contact/organization/pipeline item.
- No manual entry types for calls and meetings (call logging, meeting logging forms).
- No timeline filtering or search capability within entity detail views.
- No cross-entity aggregation (organization timeline aggregating all linked contacts' events).
- No linked Procest case event integration in the contact timeline.
- Activity events only cover leads and requests in `ObjectEventHandlerService` -- client and contact entity events are NOT published to the activity stream (though `NoteEventService` does handle notes for all four types).
- Document upload and arbitrary field change events are NOT captured as timeline entries.
- No scheduled activities / follow-up reminders.
- No activity templates.
- No activity export (CSV/JSON).
- No agent productivity reporting.
- No email activity type.

### Standards & References
- Nextcloud Activity API (`OCP\Activity\IManager`, `IProvider`, `IFilter`, `ISetting`) -- currently used for event publishing and notification delivery
- OpenRegister audit log -- provides basic versioned change history per object, used by `CnDetailPage` sidebar
- Schema.org `Action` / `InteractionCounter` -- could model timeline events
- VNG Klantinteracties `Contactmoment` -- relevant for government-facing timeline entries
- Pipelinq `NoteEventService::TYPE_MAP` -- defines entity type mapping: pipelinq_client, pipelinq_contact, pipelinq_lead, pipelinq_request

### Specificity Assessment
- The spec is well-structured with clear scenarios and event types.
- **Resolved**: Timeline entries SHOULD be stored as OpenRegister objects for per-entity queryability, while Nextcloud Activity remains the global notification backbone (dual-write pattern).
- **Resolved**: API contract defined as `GET /api/timeline/{entityType}/{entityId}` with query params `types`, `from`, `to`, `actor`, `page`, `limit`.
- **Resolved**: Manual entries (calls, meetings) link to multiple entities via the `entityId` field on the timeline object plus a `relatedEntities` array in metadata for cross-entity display.
- **Resolved**: Procest case events are discovered via OpenRegister `ObjectUpdatedEvent` when the case schema is registered in the Pipelinq timeline listener.
- **Open question**: Should activity templates be stored as OpenRegister schema objects or as Pipelinq app configuration (IAppConfig)? Schema objects offer more flexibility; IAppConfig is simpler for admin-only configuration.

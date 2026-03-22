# Activity Timeline Specification

## Status: partial

## Purpose

Provide a chronological activity feed per contact, organization, and pipeline item in Pipelinq. All interactions -- status changes, notes, emails, calls, document uploads, field changes, and linked case events -- appear in one unified timeline.

---

## Requirements

### Requirement: Nextcloud Activity Event Publishing

**Status: implemented**

The system MUST publish CRM events to the Nextcloud Activity stream for leads and requests.

#### Scenario: Lead stage change publishes activity
- GIVEN a lead is moved from "Prospectie" to "Offerte"
- THEN `ActivityService::publishStageChanged()` MUST be called
- AND the event MUST appear in the Nextcloud activity stream

#### Scenario: Request status change publishes activity
- GIVEN a request status changes from "new" to "in_progress"
- THEN `ActivityService::publishStatusChanged()` MUST be called

#### Scenario: Note addition publishes activity
- GIVEN a user adds a note to any entity (client, contact, lead, request)
- THEN `NoteEventService` MUST trigger note-related activity events via TYPE_MAP

### Requirement: Activity Notification Preferences

**Status: implemented**

Users MUST be able to configure notification preferences for activity types.

#### Scenario: User configures notification settings
- GIVEN user navigates to Personal Settings > Activity
- THEN they MUST see Pipelinq activity settings: AssignmentSetting, StageStatusSetting, NoteSetting

### Requirement: Activity Filter in Nextcloud Activity App

**Status: implemented**

The Nextcloud Activity app MUST provide a Pipelinq filter.

#### Scenario: Filter Pipelinq events
- GIVEN a user views the Nextcloud Activity stream
- WHEN they click the "Pipelinq" filter
- THEN only Pipelinq events MUST be shown

---

## Unimplemented Requirements

The following requirements are tracked as a change proposal:

**Change:** `openspec/changes/activity-timeline-v2/`

- Per-entity unified timeline view component
- Timeline filtering and search within entity detail views
- Manual entry types (call log, meeting log)
- Cross-entity aggregation (organization timeline from linked contacts)
- Client and contact activity event publishing (currently only leads/requests)
- Timeline entry storage as OpenRegister objects
- Timeline API endpoint
- Scheduled activities / follow-up reminders
- Activity templates
- Activity export (CSV/JSON)
- Agent productivity reporting
- Email activity type

---

### Implementation References

- `lib/Service/ActivityService.php` -- publishes events to Nextcloud activity stream
- `lib/Activity/Provider.php` -- renders activity events with human-readable messages
- `lib/Activity/Filter.php` -- filters activity stream to Pipelinq events
- `lib/Activity/Setting/AssignmentSetting.php`, `StageStatusSetting.php`, `NoteSetting.php` -- notification preferences
- `lib/Listener/ObjectEventListener.php` + `lib/Service/ObjectEventHandlerService.php` -- event detection
- `lib/Service/ObjectUpdateDiffService.php` -- diff detection for assignee, stage, status
- `lib/Service/NoteEventService.php` -- note events for all 4 entity types

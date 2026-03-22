# Notifications & Activity Stream Specification

## Purpose

Deliver real-time notifications and a team-visible activity timeline for CRM events so users stay informed about leads, requests, and collaboration actions. This spec covers the notification dispatch logic, activity stream integration, per-category user preferences, notification rendering, and CRM-specific event types including SLA breach warnings, deal won celebrations, and quote lifecycle events.

**Feature tier:** V1 (core notifications), Enterprise (SLA, advanced events)

**Competitor context:** EspoCRM provides granular notification settings per entity type with in-app, email, and webhook channels. Krayin CRM uses Laravel events for notification dispatch with configurable workflows. Twenty CRM has basic in-app notifications without per-category settings. This spec positions Pipelinq to leverage Nextcloud's mature notification and activity infrastructure (OCP APIs) while adding CRM-specific event intelligence.

---

## Requirements

### Requirement: CRM Notifications [V1]

Users MUST receive Nextcloud notifications when CRM actions directly affect them. Notifications are dispatched via `NotificationService.php` using Nextcloud's `OCP\Notification\IManager`.

#### Scenario: Lead assignment notification
- GIVEN a user assigns a lead to another user
- WHEN the lead is saved with a new assignee
- THEN the new assignee MUST receive a notification with subject "Lead assigned: {title}"
- AND the notification MUST link to the lead detail view (via `#/leads/{objectId}` deep link)
- AND if the previous assignee is different, no notification is sent to them (only the new assignee is notified)

#### Scenario: Request assignment notification
- GIVEN a user assigns a request to another user
- WHEN the request is saved with a new assignee
- THEN the new assignee MUST receive a notification with subject "Request assigned: {title}"
- AND the notification MUST link to the request detail view

#### Scenario: Note added notification
- GIVEN a user adds a note to an entity (lead, request, client, contact)
- WHEN the note is created via `NotesController`
- THEN the entity's assignee MUST receive a notification (if different from note author)
- AND the notification subject MUST be "New note on {entityType}: {title}"

#### Scenario: Stage change notification
- GIVEN a lead's pipeline stage changes (detected by `ObjectEventHandlerService`)
- WHEN the lead is saved with a new stage value
- THEN the lead's assignee MUST receive a notification (if different from the user making the change)
- AND the notification subject MUST include the new stage name

#### Scenario: Self-action does not notify
- GIVEN a user performs an action on their own item (self-assign, add own note, change own lead's stage)
- THEN NO notification MUST be sent to that user
- AND this is enforced by the `if ($author === $assigneeUserId) return;` check in `NotificationService`

#### Scenario: Notification links to correct view
- GIVEN a notification is received for any CRM event
- WHEN the user clicks the notification in the notification center
- THEN they MUST be navigated to the relevant detail view in Pipelinq
- AND the deep link MUST use the format `#/{objectType}s/{objectId}`

---

### Requirement: CRM Activity Stream [V1]

All significant CRM actions MUST be published to the Nextcloud Activity stream via `ActivityService.php`.

#### Scenario: Lead lifecycle activities
- GIVEN a lead is created, assigned, or has its stage changed
- THEN an activity event MUST be published via `activityManager->publish()`
- AND the event MUST appear in the Activity stream of the affected user
- AND the event MUST show the author, action, and lead title
- AND the event type MUST use the appropriate category: `pipelinq_assignment` or `pipelinq_stage_status`

#### Scenario: Request lifecycle activities
- GIVEN a request is created or has its status changed
- THEN an activity event MUST be published
- AND the event MUST appear in the Activity stream of the affected user
- AND request status changes MUST use the `pipelinq_stage_status` type

#### Scenario: Note activity
- GIVEN a note is added to any entity
- THEN an activity event MUST be published with type `pipelinq_notes`
- AND the activity MUST show who added the note and on which entity

#### Scenario: Activity filtering
- GIVEN a user views the Activity app
- WHEN they filter by "Pipelinq" using the `Filter.php` implementation
- THEN only CRM-related activities MUST be shown
- AND all three types (assignment, stage_status, notes) MUST be included

#### Scenario: Activity user settings
- GIVEN a user opens Activity settings
- THEN they MUST be able to toggle Pipelinq activities on/off for the stream
- AND they MUST be able to toggle Pipelinq activities on/off for email notifications
- AND this is provided by the three Setting classes in `lib/Activity/Setting/`

---

### Requirement: Per-Category Notification Preferences [V1]

Users MUST be able to configure which types of CRM notifications they receive via Nextcloud's standard user settings. This is implemented via Activity settings and the `SUBJECT_SETTING_MAP` in `NotificationService`.

#### Scenario: Granular activity settings
- GIVEN a user opens Settings > Activity
- THEN they MUST see separate toggles for each Pipelinq notification category:
  - "Lead & request assignments" (via `AssignmentSetting.php`)
  - "Pipeline stage & status changes" (via `StageStatusSetting.php`)
  - "Notes & comments" (via `NoteSetting.php`)
- AND each category MUST have independent stream and email toggles
- AND all settings MUST be grouped under "Pipelinq" in the Activity settings UI

#### Scenario: Disabled category suppresses notifications
- GIVEN a user has disabled "Lead & request assignments" in their Activity settings
- WHEN a lead is assigned to them
- THEN they MUST NOT receive a notification for the assignment
- AND the `NotificationService.send()` method MUST check the user's `notify_assignments` config key
- BUT the activity event MUST still be published (visible if they re-enable)

#### Scenario: Default notification settings
- GIVEN a new user has not changed any Activity settings
- THEN all Pipelinq notification categories MUST be enabled for the activity stream by default
- AND all Pipelinq notification categories MUST be disabled for email by default
- AND the `config->getUserValue()` default MUST be 'true' for in-app notifications

---

### Requirement: Notification Rendering [V1]

Notifications MUST be properly localized and formatted for display in the Nextcloud notification center.

#### Scenario: Localized notification text
- GIVEN a user's language is set to Dutch (nl)
- WHEN they receive a Pipelinq notification
- THEN the notification text MUST be in Dutch
- AND this is handled by `Notifier.php` using `$this->l10nFactory->get(Application::APP_ID, $languageCode)`

#### Scenario: Rich notification subject
- GIVEN a notification references a lead or request
- WHEN displayed in the notification center
- THEN the entity title MUST be shown as a rich parameter (bold/linked text)
- AND the rich parameter MUST use the `highlight` type for visual emphasis

#### Scenario: Notification icon
- GIVEN any Pipelinq notification
- WHEN displayed in the notification center
- THEN the Pipelinq app icon MUST be shown (via `IURLGenerator::imagePath` for `app-dark.svg`)

#### Scenario: Notification subject formatting
- GIVEN the five notification subjects: `lead_assigned`, `request_assigned`, `lead_stage_changed`, `request_status_changed`, `note_added`
- WHEN each is rendered by the `Notifier`
- THEN each MUST produce human-readable text including the entity title and relevant context (stage name, author name)
- AND unknown subjects MUST throw `UnknownNotificationException`

---

### Requirement: Deal Won Notification [V1]

The system MUST send celebratory notifications when leads reach the "Won" stage.

#### Scenario: Deal won notification to team
- GIVEN a lead with value EUR 50,000 is moved to the "Won" stage
- WHEN the stage change is detected
- THEN the lead's assignee MUST receive a notification: "Deal won: {title} (EUR 50.000)"
- AND if the pipeline has team members configured, they SHOULD also be notified

#### Scenario: Deal won activity event
- GIVEN a lead is won
- WHEN the activity event is published
- THEN the activity MUST use a distinct subject type `lead_won` for filtering
- AND the activity text MUST include the deal value

#### Scenario: Deal lost notification
- GIVEN a lead is moved to the "Lost" stage
- WHEN the stage change is detected
- THEN the lead's assignee MUST receive a notification: "Deal lost: {title}"
- AND a reason field SHOULD be prompted before the transition completes

---

### Requirement: SLA Breach Warning Notifications [Enterprise]

The system MUST send proactive notifications when items approach or breach service level thresholds.

#### Scenario: Lead approaching stale threshold
- GIVEN a lead has not been modified for 12 days (stale threshold is 14 days)
- WHEN the system performs a periodic check (via cron background job or on dashboard load)
- THEN the lead's assignee MUST receive a warning notification: "Lead {title} is becoming stale (12 days without activity)"
- AND the notification MUST link to the lead detail view

#### Scenario: Request SLA breach
- GIVEN a request with `requestedAt` 25 days ago and the 30-day SLA threshold
- WHEN the system checks for upcoming SLA breaches (at 25 days, 5 days before breach)
- THEN the request's assignee MUST receive a warning: "Request {title} approaches SLA deadline (5 days remaining)"

#### Scenario: Overdue escalation
- GIVEN a lead is overdue (past expectedCloseDate) and has not been acted on for 7 additional days
- WHEN the escalation check runs
- THEN the lead's assignee AND their manager (if configured) SHOULD receive an escalation notification
- AND the notification MUST be flagged as high priority

#### Scenario: SLA notification deduplication
- GIVEN a stale warning was sent for a lead 2 days ago
- WHEN the periodic check runs again
- THEN the system MUST NOT send another warning for the same lead unless the threshold escalates (e.g., from "approaching" to "breached")
- AND deduplication SHOULD use a notification tracking mechanism (e.g., last warning timestamp on the object)

---

### Requirement: Activity Timeline Component [V1]

The system MUST provide an in-app activity timeline component for entity detail views.

#### Scenario: Activity timeline on lead detail
- GIVEN a lead with multiple activities (created, assigned, stage changed, note added)
- WHEN the user views the lead detail sidebar
- THEN an activity timeline MUST be displayed showing all events in reverse chronological order
- AND each event MUST show: timestamp, user avatar, action description

#### Scenario: Activity timeline on client detail
- GIVEN a client with linked leads and requests
- WHEN the user views the client detail
- THEN the activity timeline SHOULD aggregate activities from all linked entities
- AND the timeline MUST be filterable by entity type

#### Scenario: Recent Activities dashboard widget
- GIVEN the existing `RecentActivitiesWidget` component
- WHEN the dashboard is loaded
- THEN the widget MUST show the most recent CRM activities across all entities
- AND each entry MUST show: action icon, entity type badge, title, and relative timestamp
- AND clicking an entry MUST navigate to the relevant entity detail view

#### Scenario: Activity pagination
- GIVEN an entity with more than 20 activity events
- WHEN the timeline is displayed
- THEN only the most recent 20 events MUST be loaded initially
- AND a "Load more" button MUST be available to fetch older events

---

### Requirement: Email Notification Integration [V1]

The system MUST integrate with Nextcloud's email notification system for CRM events.

#### Scenario: Email notification for assignments
- GIVEN a user has enabled email notifications for "Lead & request assignments" in Activity settings
- WHEN a lead is assigned to them
- THEN an email notification MUST be sent via Nextcloud's standard email mechanism
- AND the email MUST include: subject line, entity title, link to the entity, author name

#### Scenario: Email digest for CRM activities
- GIVEN a user has enabled the Nextcloud Activity email digest
- THEN CRM activities MUST be included in the periodic email digest
- AND each CRM event MUST be rendered with the appropriate text from `ProviderSubjectHandler`

#### Scenario: Email notification opt-out
- GIVEN a user has disabled email notifications for all Pipelinq categories
- WHEN CRM events occur
- THEN no email notifications MUST be sent for Pipelinq events
- AND in-app notifications MUST still function independently

---

### Requirement: Webhook and Integration Notifications [Enterprise]

The system MUST support external notification channels for CRM events via n8n workflows.

#### Scenario: Webhook on deal won
- GIVEN an n8n workflow is configured to listen for "lead_won" events
- WHEN a deal is won
- THEN the event MUST be available for n8n to process
- AND the webhook payload MUST include: lead title, value, client, assignee, timestamp

#### Scenario: Slack/Teams notification via n8n
- GIVEN an n8n workflow is configured to send Slack messages on lead assignment
- WHEN a lead is assigned
- THEN the n8n workflow MUST be triggerable by the OpenRegister object update event
- AND the workflow MUST have access to the notification parameters (title, assignee, author)

#### Scenario: Custom notification rules
- GIVEN an admin wants to notify a specific user when any lead exceeds EUR 100,000
- WHEN the admin creates an n8n workflow with this condition
- THEN the workflow MUST fire on lead creation/update events
- AND the notification MUST be delivered through the configured channel (Nextcloud notification, email, Slack, etc.)

---

### Requirement: Notification Analytics [Enterprise]

The system MUST track notification effectiveness metrics.

#### Scenario: Notification read rate
- GIVEN notifications are sent for CRM events
- THEN the system SHOULD track how many notifications are read vs dismissed
- AND the data SHOULD be available for admin review

#### Scenario: Response time tracking
- GIVEN a notification is sent for a lead assignment
- WHEN the assignee first opens the lead after receiving the notification
- THEN the system SHOULD record the response time
- AND average response times SHOULD be available in the analytics view

#### Scenario: Notification volume monitoring
- GIVEN the Prometheus metrics endpoint at `lib/Controller/MetricsController.php`
- THEN CRM notification counts SHOULD be exposed as metrics
- AND the metrics SHOULD be broken down by: subject type, delivery channel, and outcome (sent, suppressed by user settings)

---

### Requirement: Batch and Rate Limiting [V1]

The system MUST handle notification volume gracefully to prevent notification fatigue.

#### Scenario: Rapid successive changes
- GIVEN a user changes a lead's stage 3 times in 60 seconds (Prospect -> Qualified -> Proposal)
- WHEN notifications are dispatched
- THEN only the most recent stage change notification SHOULD be sent to the assignee
- AND intermediate notifications SHOULD be suppressed or batched

#### Scenario: Bulk operation notifications
- GIVEN an admin reassigns 20 leads to a new user in a bulk operation
- WHEN notifications are dispatched
- THEN the system SHOULD batch the notifications into a single summary: "{count} leads assigned to you"
- AND the notification MUST link to a filtered lead list showing the newly assigned items

#### Scenario: Rate limiting per user
- GIVEN a user would receive more than 50 Pipelinq notifications in a single hour
- THEN the system SHOULD switch to batch mode for that user
- AND a summary notification SHOULD be sent: "You have {count} new Pipelinq updates"

---

## Current Implementation Status

**Implemented:**
- **CRM Notifications:** Fully implemented in `lib/Service/NotificationService.php`:
  - `notifyAssignment()` for lead and request assignments.
  - `notifyStageChange()` for lead stage changes.
  - `notifyStatusChange()` for request status changes.
  - `notifyNoteAdded()` for notes on any entity.
- **Self-action suppression:** All notification methods check `if ($author === $assigneeUserId) return;`.
- **Notifier rendering:** `lib/Notification/Notifier.php` handles 5 subjects with localized text and rich parameters.
- **Notification links:** Deep links using `#/{objectType}s/{objectId}` pattern.
- **Notification icon:** Uses `app-dark.svg` via `IURLGenerator::imagePath`.
- **Rich parameters:** Entity title rendered as highlight parameter.
- **CRM Activity Stream:** Fully implemented in `lib/Service/ActivityService.php`:
  - `publishCreated()` for lead/request creation.
  - `publishAssigned()` for assignments.
  - `publishStageChanged()` for lead stage changes.
  - `publishStatusChanged()` for request status changes.
  - `publishNoteAdded()` for notes.
- **Activity Provider:** `lib/Activity/Provider.php` handles 6 subjects with `ProviderSubjectHandler.php` for text formatting.
- **Activity Filter:** `lib/Activity/Filter.php` provides Pipelinq-specific filtering in the Activity app.
- **Per-Category Notification Preferences:** Three activity settings implemented:
  - `AssignmentSetting.php` -- "Lead & request assignments" (stream on, email off by default).
  - `StageStatusSetting.php` -- "Pipeline stage & status changes".
  - `NoteSetting.php` -- "Notes & comments".
  - All support independent stream/email toggles, grouped under "Pipelinq".
- **User-level notification preferences:** `NotificationService::send()` checks per-user settings via `IConfig::getUserValue()` with `SUBJECT_SETTING_MAP`.
- **Event listener:** `lib/Listener/ObjectEventListener.php` listens for OpenRegister object events and triggers notifications/activities via `ObjectEventHandlerService.php`.
- **Recent Activities Widget:** `RecentActivitiesWidget.php` and `src/views/widgets/RecentActivitiesWidget.vue` provide dashboard widget.

**Not yet implemented:**
- **Deal Won/Lost notifications:** No distinct `lead_won` or `lead_lost` subjects. Stage changes use generic `lead_stage_changed`.
- **SLA breach warning notifications:** No proactive stale/overdue warning notifications via cron.
- **Notification deduplication/batching:** No deduplication for rapid changes or bulk operations.
- **Activity timeline component on entity detail views:** Activities are published but no inline timeline UI on lead/client detail views.
- **Webhook/n8n integration notifications:** Events are available via OpenRegister but no explicit CRM webhook dispatch.
- **Notification analytics:** No tracking of read rates or response times.
- **Rate limiting:** No notification rate limiting mechanism.

**Partial implementations:**
- Email notifications work through Nextcloud's standard Activity email system (if SMTP is configured).
- The `ObjectEventHandlerService` detects field changes (assignment, stage, status) by comparing old vs new values, which provides the foundation for more granular event types.

### Standards & References
- Nextcloud Activity API (`OCP\Activity\IManager`, `IProvider`, `ActivitySettings`).
- Nextcloud Notification API (`OCP\Notification\IManager`, `INotifier`).
- Localization via `OCP\L10N\IFactory` for multi-language support (Dutch/English).
- EUPL-1.2 license.
- EspoCRM notification model: per-entity type, per-action type, per-channel settings.

### Specificity Assessment
- V1 requirements are fully implemented and well-tested. The implementation is comprehensive for core CRM notification patterns.
- Enterprise features (SLA breach, deal won, batching, analytics) require new background jobs and notification subjects.
- **Resolved:** Previous assignee notification -- confirmed not needed (only new assignee is notified).
- **Open question:** Should notification batching use a queue system or rely on cron-based aggregation?
- **Open question:** Should deal won/lost be separate notification subjects or continue using stage_changed with stage metadata?

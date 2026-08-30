## ADDED Requirements

### Requirement: Calendar leaf widget on ClientDetail

The ClientDetail manifest page SHALL render the calendar leaf's integration widget — `{ "type": "integration", "integrationId": "calendar" }`, the exact shape LeadDetail (`lead-calendar`) and ContactDetail (`contact-calendar`) already declare — so that a client's leaf-linked events are visible on the account hub. The widget SHALL be declared in `src/manifest.json` only; the system SHALL NOT add any pipelinq-local calendar component, store, or schema for it (the `client` schema already lists `calendar` in `linkedTypes`).

**Standards**: iCalendar (RFC 5545), Schema.org (`Event`)
**Feature tier**: V1 (widget parity across CRM hubs)

#### Scenario: Meetings widget appears on client detail

- **GIVEN** the Nextcloud Calendar app is installed and the `client` schema lists `calendar` in `linkedTypes`
- **WHEN** an agent opens a client detail page
- **THEN** a "Meetings" widget SHALL render the client's leaf-linked events through the calendar leaf
- **AND** creating a follow-up from that widget SHALL use the leaf's inline create flow, per the existing "Follow-up events use the calendar leaf create flow" requirement

#### Scenario: Calendar app absent degrades the widget, not the page

- **GIVEN** the Nextcloud Calendar app is not installed
- **WHEN** an agent opens a client detail page
- **THEN** the ClientDetail page SHALL render normally with the calendar widget in its integration-unavailable state
- **AND** no error SHALL be raised by pipelinq code

---

### Requirement: Follow-up reminders driven by the calendar leaf

The system SHALL remind assignees of upcoming follow-ups without performing any calendar I/O of its own. A `FollowUpReminderJob` (`TimedJob`) SHALL periodically (a) read upcoming leaf-linked events for open leads exclusively through the calendar leaf's read surface (`OCA\OpenRegister\Service\CalendarLinkService::getLinkedEvents` resolved via the DI container, or the leaf's `GET /api/objects/{register}/{schema}/{id}/events` shape) and dispatch a Nextcloud notification to the lead's `assignee` ahead of the event's `dtstart`, and (b) dispatch a notification to the assignee of an open `followUpTask` whose `deadline` falls due. Each reminder SHALL be dispatched at most once per event/task occurrence (recently-notified marker, mirroring `CallbackOverdueJob`). When the calendar leaf, the openregister app, or the configured register is unavailable, the job SHALL be a logged no-op — it SHALL NOT fall back to reading CalDAV, and it SHALL NOT write to any calendar. Calendar-native alarms (VALARM) are the leaf's capability (companion change `calendar-leaf-inbound`, openregister) and SHALL NOT be implemented in pipelinq.

**Standards**: iCalendar (RFC 5545 VALARM — leaf-side only), Schema.org (`ScheduleAction`)
**Feature tier**: V1 (follow-up reminders)

#### Scenario: Lead assignee is reminded before a linked meeting

- **GIVEN** an open lead with `assignee` set and a leaf-linked event starting within the reminder window
- **WHEN** the `FollowUpReminderJob` runs
- **THEN** the assignee SHALL receive a Nextcloud notification naming the event and linking to the lead detail page
- **AND** a subsequent run within the window SHALL NOT dispatch a duplicate notification

#### Scenario: Follow-up task deadline reminder

- **GIVEN** an open task of type `followUpTask` with a `deadline` inside the reminder window and an assignee resolved from `assigneeUserId` or `assigneeGroupId`
- **WHEN** the `FollowUpReminderJob` runs
- **THEN** the assignee SHALL be notified once that the follow-up is due

#### Scenario: Leaf unavailable is a no-op, not a fallback

- **GIVEN** the openregister app is disabled or the pipelinq `register` app-config is unset
- **WHEN** the `FollowUpReminderJob` runs
- **THEN** the job SHALL log and return without dispatching notifications
- **AND** it SHALL NOT open a CalDAV or database connection to calendar storage

---

### Requirement: Follow-up tasks attach to leads and contacts

The `task` schema SHALL gain optional `leadId` and `contactId` relation properties so that a follow-up task can be attached to the lead or contact it follows up, and task-typed follow-ups SHALL surface on the owning record's detail page through the existing FK-scoped related-collection mechanism. The addition SHALL be purely additive (no rename, no existing property touched, no data migration).

**Standards**: Schema.org (`PlanAction.object`)
**Feature tier**: V1 (follow-up reminders)

#### Scenario: Follow-up task created from a lead is linked to it

- **GIVEN** an agent creates a `followUpTask` for a lead
- **WHEN** the task is saved with `leadId` set to that lead
- **THEN** the task SHALL appear in the lead's related tasks
- **AND** the reminder for that task SHALL deep-link to the lead

---

### Requirement: Leaf-linked events appear on the record timeline

The record timeline aggregation (`ActivityTimeline` on ClientDetail, and the per-entity feed at `GET /api/activity/{entityType}/{entityId}`) SHALL include the record's leaf-linked calendar events as appointment-typed entries, read live through the calendar leaf's read surface at aggregation time. Leaf events SHALL NOT be persisted, cached, or mirrored into any pipelinq store, and SHALL be deduplicated against entries the timeline already derives from other sources so the same appointment never appears twice. Past events SHALL render as completed activity; future events as scheduled activity.

**Standards**: Schema.org (`Event`, `interactionStatistic` ordering by date)
**Feature tier**: V1 (record timeline)

#### Scenario: Linked meeting shows on the client timeline

- **GIVEN** a client with a leaf-linked event
- **WHEN** an agent opens the client's Activity timeline
- **THEN** the event SHALL appear as an appointment entry in chronological position with its summary and start time
- **AND** the entry SHALL originate from the leaf read call, not from a pipelinq-stored copy

#### Scenario: Timeline degrades without the leaf

- **GIVEN** the openregister app is unavailable
- **WHEN** the timeline is requested
- **THEN** the timeline SHALL render its other sources normally and omit calendar entries without error

---

### Requirement: Inbound event-to-record backfill is consumed, not reimplemented

Pipelinq SHALL rely on the OpenRegister leaf for inbound event-to-record flow: events carrying `X-OPENREGISTER-*` properties that are created or modified on the Calendar-app side SHALL become visible on pipelinq's widgets and timeline solely through the leaf's link table (populated by `BackfillCalendarLinksJob` and the leaf's inbound listeners, per the companion change `calendar-leaf-inbound` in openregister). Pipelinq SHALL NOT scan CalDAV, SHALL NOT write link rows, and SHALL NOT register calendar event listeners of its own. Pipelinq's setup documentation SHALL document enabling the leaf's `backfill_calendar_links` app-config flag for instances upgrading with pre-existing tagged events.

**Standards**: iCalendar (RFC 5545), RFC 9253 (`LINK`)
**Feature tier**: V1 (inbound consumption)

#### Scenario: Event linked from the Calendar side appears in pipelinq

- **GIVEN** the companion leaf change is deployed and a VEVENT tagged with `X-OPENREGISTER-OBJECT` pointing at a lead exists in the user's calendar
- **WHEN** the leaf's backfill or inbound listener has processed the event
- **THEN** the event SHALL appear in the lead's Meetings widget and timeline with no pipelinq-side action

#### Scenario: No pipelinq-local inbound machinery

- **GIVEN** the pipelinq codebase
- **WHEN** it is audited for calendar inbound handling
- **THEN** it SHALL contain no CalDAV client, no calendar event listener, and no calendar link writes outside the leaf's API

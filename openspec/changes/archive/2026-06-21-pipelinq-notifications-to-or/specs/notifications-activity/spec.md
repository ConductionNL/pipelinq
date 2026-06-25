---
# Notifications & Activity — Declarative/Imperative Split Delta

**Spec refs**: `notifications-activity` (base), ADR-031 (x-openregister-notifications declarative-first), ADR-022 (consume the OR abstraction), ADR-003 (legitimate notifier-renderer seam)
**Standards**: GDPR/AVG Art. 12 lid 3 (response deadlines), Nextcloud OCP Notification + Activity APIs

## ADDED Requirements

### Requirement: Notification dispatch declarative/imperative split — migration decision of record

The system MUST express every CRM notification whose trigger, recipient, and
channel are representable by OpenRegister's `x-openregister-notifications` engine
as a declarative rule on the schema register, and MUST retain an imperative
dispatcher ONLY where the notification encodes a behaviour the OR
`AnnotationNotificationDispatcher` cannot express. The set of OR-non-expressible
behaviours that justify a retained imperative dispatcher is: per-recipient
actor-suppression (notify everyone except the user who made the change),
leaf-app per-category user opt-out, in-call object mutation, app-defined immutable
audit-ledger recording used as the dispatch idempotency guard, and rich
transactional email templates beyond the OR `email` channel's subject-as-body.

**Feature tier**: V1

#### Scenario: Representable notifications are declarative

- GIVEN a CRM notification whose trigger is object create / lifecycle transition and whose recipients are a group, an assignee field, or the object ACL
- WHEN the notification is authored
- THEN it MUST be declared as an `x-openregister-notifications` rule in `lib/Settings/pipelinq_register.json` (or a `register.d/*.json` fragment)
- AND no imperative `NotificationService` method MUST be added for it

#### Scenario: Assignee / stage / status notifications stay imperative

- GIVEN the directed "to you" notifications dispatched by `ObjectEventHandlerService` via `NotificationService`
- WHEN evaluated against the OR notification contract
- THEN they MUST remain imperative because OR cannot express the codified self-action suppression (`if author === assignee return`) nor the per-category user opt-out (`SUBJECT_SETTING_MAP`)
- AND moving them MUST NOT be done, since it would notify the actor, ignore opt-outs, and double-fire against the dormant lifecycle `transition` rules

#### Scenario: AVG deadline notifications stay imperative

- GIVEN the 7-day reminder, <72h escalation, and breach notifications in `Avg\DeadlineTrackerService` / `Avg\AvgNotificationService`
- WHEN evaluated against the OR scheduled-notification contract
- THEN they MUST remain imperative because the breach mutates the object (`termijnOverschreden`, `fgGeinformeerd`) and every milestone records an immutable `TermijnEvent` audit row that also serves as the dispatch idempotency guard
- AND the staged legal escalation chain MUST NOT be split into independent OR scheduled rules

#### Scenario: 24-hour booking reminder stays imperative

- GIVEN the `ReminderDispatchJob` 24-hour booking reminder dispatched via `AppointmentEmailService::sendReminder`
- WHEN evaluated against the OR notification contract
- THEN it MUST remain imperative because it is an email-only rich transactional template with a `reminderSentAt` write-back dedupe and carries no in-app/push leg to extract

#### Scenario: Retained dispatchers are documented, not un-triaged

- GIVEN the `notification-dialect` gate-18 advisory WARN on imperative dispatch sites
- WHEN the gate runs against pipelinq
- THEN gate-18 MUST be PASS (no legacy notification dialect tokens)
- AND each WARN site (`NotificationService`, `Avg\AvgNotificationService`, `Notifier.php`) MUST carry a `@spec` pointer to this change documenting the OR-non-expressible behaviour that retains it, so the WARN is a reviewed retention rather than un-triaged debt

---
kind: code
---

## Why

Seam 4 of the pipelinq → OpenRegister declarative-migration programme asks: move
pipelinq's imperative on-save / on-event notification dispatch to OpenRegister's
declarative `x-openregister-notifications` (and `ScheduledFilterEvaluator` for
scheduled ones), per ADR-031 (declarative-first) and ADR-022 (consume the OR
abstraction over local duplication).

This change carries out that migration **behavior-preservingly**, and the honest
result is the same discipline the lifecycle and GDPR seams applied: every
imperative notification site that OpenRegister's `AnnotationNotificationDispatcher`
can express **already lives in the declarative register** (the `created` /
`transition` rules on `lead` / `request` / `task` / `contact` / `complaint` /
`contactmoment` schemas in `lib/Settings/pipelinq_register.json`, plus the
`contract` rule in `register.d/96-contract-renewal.json`). The remaining
imperative dispatch sites are **retained on purpose** because each one encodes a
behaviour the OR dispatcher does **not** replicate, and forcing the move would
change observable behaviour (double-fire, drop a legally-required audit row,
notify the actor, or ignore a per-user opt-out).

So this change is a **migration-decision-of-record + annotation-alignment**
change, not a code-deletion change. It:

1. Confirms the three imperative seams against the OR contract and records, per
   site, exactly which behaviour blocks the move.
2. Annotates the three retained dispatch services with a `@spec` pointer to this
   change so the `notification-dialect` gate-18 WARN they raise is documented as
   a legitimate, reviewed retention (the same status decidesk's
   `DecisionNotificationService` carries) rather than un-triaged debt.
3. Records the decision in the `notifications-activity` capability spec so a
   future author does not "discover" these services and naively move them.

## What Changes

- **No behaviour change.** No notification is added, dropped, doubled, retimed, or
  re-recipiented. The three retained services keep dispatching exactly as today.
- `lib/Service/NotificationService.php`, `lib/Service/Avg/AvgNotificationService.php`:
  docblock `@spec` pointer to this change + a one-line statement of the
  non-expressible behaviour that keeps each one imperative. No method-body change.
- `openspec/specs/notifications-activity/spec.md`: a new requirement recording the
  declarative-vs-imperative split as the migration decision of record.

## Per-candidate verdict (detail in design.md)

| Candidate | Imperative site | Verdict | Blocking behaviour OR can't express |
|---|---|---|---|
| 1 | `ObjectEventHandlerService` → `ObjectEventDispatcher` → `NotificationService` (assignee / stage / status on-update; assignment on-create) | **KEEP PHP** | (a) self-action suppression `if author === assignee return`; (b) per-category user opt-out (`SUBJECT_SETTING_MAP`); (c) same-call activity-stream publish must stay PHP regardless. Moving would double-fire against the dormant `transition` rules and notify the actor. |
| 2 | `Avg\DeadlineTrackerService` + `Avg\AvgNotificationService` (7-day reminder, <72h escalation, breach) | **KEEP PHP** | breach **mutates the object** (`termijnOverschreden`, `fgGeinformeerd`), records an **immutable `TermijnEvent` audit row**, and uses that ledger as the **idempotency guard**; the staged legal escalation chain is non-expressible. GDPR/legal seam. |
| 3 | `ReminderDispatchJob` → `AppointmentEmailService::sendReminder` (24h booking reminder) | **KEEP PHP** | notification is an **email-only rich transactional template**; dedupe is a `reminderSentAt` write-back; **there is no in-app/push leg to extract**. The 23–24h window + write-back dedupe is bespoke. |

## Impact

- Affected specs: `notifications-activity` (one added requirement).
- Affected code: docblock-only on `NotificationService` + `AvgNotificationService`.
- Gates: `notification-dialect` (gate-18) stays **PASS** (no legacy dialect; the
  canonical dialect is already used by the existing annotations). Its advisory
  **WARN on 3 imperative sites does not clear** — and that is the correct
  outcome: all three are legitimately retained per this reviewed decision
  (`Notifier.php` is the renderer per ADR-003 and never moves). The change makes
  the WARN *triaged and documented* rather than removing it.

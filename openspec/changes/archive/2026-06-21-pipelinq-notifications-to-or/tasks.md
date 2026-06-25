## 1. Investigate the OR notification contract (DONE — recorded in design.md)

- [x] 1.1 Read `openregister/lib/Service/Notification/AnnotationNotificationDispatcher.php`, `ScheduledFilterEvaluator.php`, `BackgroundJob/ScheduledNotificationJob.php`, `Listener/AnnotationNotificationListener.php` — capture trigger types, recipient kinds, channels, dedup/idempotency, and the fire path (OR's own save/transition events, not the leaf app)
- [x] 1.2 Confirm the fleet reference shape against `decidesk/lib/Settings/decidesk_register.json`
- [x] 1.3 Record the four OR-non-expressible behaviours (actor-suppression, leaf opt-out, object mutation + audit ledger, rich transactional email) in design.md §1

## 2. Per-candidate decision (behaviour-preserving)

- [x] 2.1 Candidate 1 `ObjectEventHandlerService`: confirm self-action suppression + per-category opt-out in `NotificationService::send()`; confirm the broadcast/lifecycle part is ALREADY declarative (`newLead`/`leadWon`/`leadLost`/`requestCompleted`/`taskCompleted`/`taskExpired`) → **KEEP PHP**, no move
- [x] 2.2 Candidate 2 `Avg\DeadlineTrackerService`: confirm breach object-mutation + immutable `TermijnEvent` audit + ledger-as-idempotency + staged chain → **KEEP PHP**, no move (GDPR/legal seam)
- [x] 2.3 Candidate 3 `ReminderDispatchJob`: confirm email-only rich template + `reminderSentAt` write-back dedupe + no in-app/push leg → **KEEP PHP**, no move
- [x] 2.4 Verify no double-fire: existing declarative annotations are unchanged; imperative `created`/update subjects (`lead_assigned`) are distinct from declarative `created` (`newLead`); `transition` rules stay dormant (pipelinq emits `ObjectUpdatedEvent`, not `ObjectTransitionedEvent`, for these schemas)

## 3. Annotation alignment (docblock-only, no behaviour change)

- [x] 3.1 Add a `@spec openspec/changes/pipelinq-notifications-to-or/tasks.md#task-3.1` pointer + one-line OR-non-expressible-behaviour note to the class docblock of `lib/Service/NotificationService.php`
- [x] 3.2 Add the same `@spec` pointer + AVG-legal-seam note to `lib/Service/Avg/AvgNotificationService.php` class docblock
- [x] 3.3 Confirm no method body changes; `Notifier.php` (INotifier renderer, ADR-003) is intentionally untouched

## 4. Spec + gates

- [x] 4.1 Land the `notifications-activity` ADDED requirement (declarative/imperative split, decision of record)
- [x] 4.2 `composer lint` + `phpcs --warning-severity=0` clean on changed `lib/`
- [x] 4.3 `notification-dialect` gate-18 stays PASS; WARN sites are now `@spec`-documented retentions
- [x] 4.4 Full pipelinq unit suite green (≥ baseline); the candidate-service tests assert the dispatch set is unchanged

## 5. Live verification on :8080

- [x] 5.1 Reassign a `lead` (change `assignee` as a different user) → confirmed on :8080: exactly one `pipelinq` `lead_assigned` notification (id 7471) to the new assignee `seamtest-assignee` (object_id 79), and ZERO `openregister` notifications referencing the lead for the reassignment — imperative path preserved, OR dispatcher did not double-fire
- [x] 5.2 Confirmed the existing declarative `newLead`/`newContact`/`leadWon`/... annotations are present on the pipelinq register (schema id 62 slug `lead`) and use the canonical dialect (gate-18 PASS)

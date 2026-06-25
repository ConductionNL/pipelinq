# Design — pipelinq-notifications-to-or (Seam 4)

## 1. The OpenRegister notification contract (as investigated)

OR's declarative notification engine is `x-openregister-notifications`, a map
declared **on a schema** inside `lib/Settings/{app}_register.json` (and
`register.d/*.json` fragments). Each entry is a rule:

```jsonc
"<ruleName>": {
  "trigger":    { "type": "created|updated|transition|calculatedChange|scheduled", ... },
  "enabled":    true,
  "channels":   ["nc-notification", "email", "activity", "web-push", "webhook", "talk"],
  "recipients": [ { "kind": "users|groups|field|relation|object-acl|expression", ... } ],
  "subject":    { "nl": "...", "en": "..." },   // or a legacy string; {{prop}} interpolated
  "message":    { "nl": "...", "en": "..." },   // optional body
  "actions":    [ { "label": {...}, "primary": true, "target": { "kind": "object-detail" } } ],
  "originApp":  "pipelinq",                       // icon + deeplink resolution
  "idempotencyKey": "${@self.id}-...",            // optional claim-before-send dedup
  "organisation":   "<uuid|slug>"                 // optional multi-tenant gate
}
```

### How it fires

- **Event-driven** (`created` / `updated` / `transition` / `calculatedChange`):
  OR's `AnnotationNotificationListener` subscribes to `ObjectCreatedEvent`,
  `ObjectUpdatedEvent`, `ObjectTransitionedEvent` and calls
  `AnnotationNotificationDispatcher::dispatch($object, $trigger, $context)`. These
  events are emitted by **OpenRegister's own `saveObject()` / TransitionEngine** —
  the leaf app does NOT emit them; it just writes the annotation and saves objects
  through OR.
- **Scheduled** (`trigger.type: "scheduled"` + `intervalSec >= 60` + `trigger.filter`):
  OR's `ScheduledNotificationJob` (60s `TimedJob`) scans every schema's objects,
  evaluates `trigger.filter` per object via `ScheduledFilterEvaluator` (operators
  `equals`/`notEquals`/`withinNext`/`olderThan`, the latter two relative-date), and
  dispatches with `trigger='scheduled'`. Dedupe is a per-`(schema, rule, objectUuid)`
  **fingerprint** of the watched fields (`NotificationDedupeStateMapper`): fire-once
  per object until the watched field changes.

### Trigger expressiveness

- `created` — optionally `trigger.filter` `{field, operator: equals|in|notIn, value(s)}`.
- `updated` — optionally `trigger.condition` `{field, operator: changed|equals[, from]}`
  (string-normalised) evaluated against old/new data forwarded by the listener.
- `transition` — matches `ObjectTransitionedEvent::getAction()` (optional `action`
  filter, scalar or array).
- `calculatedChange` — numeric boundary crossing on a calculated field
  (`condition` on new value + `previously` on old value).
- `scheduled` — relative-date / equality `filter` over the whole object set.

### Recipient resolution

`users` (literal uids), `groups` (group → members), `field` (a uid stored in a
field — **userExists-verified**), `relation` (typed relation → uids), `object-acl`
(`read` → owner+groups; `manage` → owner), `expression` (DI-tagged resolver class).
Every uid is verified against `IUserManager::userExists()` before use.

### Channels

`nc-notification` (in-app), `email` (via `IMailer` — **subject as body**, plain),
`activity` (NC activity stream), `web-push` (out-of-band job), `webhook`, `talk`.

### What the OR dispatcher does NOT have (decisive for this seam)

1. **No "exclude the actor" concept.** OR resolves recipients from
   field/group/acl; it cannot say "everyone *except* the user who made this save".
2. **No leaf-app per-category opt-out.** OR has `NotificationPreferenceService`,
   but it is **override-only**, keyed on `(schemaSlug, ruleName)` — it does NOT
   read pipelinq's legacy `notify_assignments` / `notify_stage_status` /
   `notify_deals` user settings. Migrating would silently change who gets notified.
3. **No object mutation, no app-defined audit ledger.** A scheduled rule
   dispatches; it does not write `termijnOverschreden=true` back onto the object
   nor append an immutable `TermijnEvent`.
4. **No rich transactional email template.** The `email` channel sends
   `subject` as a plain body. It is not pipelinq's `AppointmentEmailService`
   HTML reminder.

A fleet reference that uses the contract correctly:
`decidesk/lib/Settings/decidesk_register.json` (Meeting `meetingScheduled`
created-rule, `meetingReminder` scheduled-rule, ActionItem `actionItemAssignedToYou`
field-recipient + web-push + actions). Pipelinq's existing annotations follow the
identical shape.

## 2. Per-candidate analysis

### Candidate 1 — `ObjectEventHandlerService` (on create/update)

Path: `ObjectEventListener` (subscribes OR `ObjectCreated/UpdatedEvent`) →
`ObjectEventHandlerService::handleCreated/handleUpdated` → `ObjectUpdateDiffService`
diffs `assignee` / `stage` / `status` → `ObjectEventDispatcher` → for each change it
**both** `ActivityService::publish*` **and** `NotificationService::notify*`.

`NotificationService::send()` enforces:
- `if ($author === $assigneeUserId) return;` — **self-action suppression** (a
  codified requirement in `notifications-activity`: "Self-action does not notify").
- `SUBJECT_SETTING_MAP[$subject]` → `IConfig::getUserValue(...)` — **per-category
  user opt-out** (another codified requirement: "Per-Category Notification
  Preferences").

Verdict: **KEEP PHP.** OR's dispatcher replicates neither (1) nor (2) above. The
declarative `lead`/`request`/`task` schemas already carry the *broadcast* and
*lifecycle-transition* rules (`newLead`, `leadWon`/`leadLost`,
`requestCompleted`, `taskCompleted`/`taskExpired`) — those are the part that
belongs declarative and is **already declarative**. The assignee/stage/status
*directed-to-you* notifications are a different notification (actor-suppressed,
opt-out-gated) and cannot move without (a) double-firing alongside the existing
`transition` rules once pipelinq routes status through OR's TransitionEngine and
(b) notifying the actor / ignoring opt-outs. The activity-stream publish in the
same dispatch must stay PHP regardless (not a notification).

### Candidate 2 — `Avg\DeadlineTrackerService` + `Avg\AvgNotificationService`

7-day reminder / <72h escalation / breach over AVG `avgVerzoek` objects. The
date conditions (`isReminderDue`, `shouldEscalate`, `isBreached` against
`wettelijkeTermijnVerloopt`) map onto `ScheduledFilterEvaluator`'s
`withinNext`/`olderThan`, and the recipient (`behandelaar`) maps onto `kind:field`.
But:
- **breach mutates the object** (`termijnOverschreden=true`, `fgGeinformeerd=true`
  written back via `repository->save`) — OR scheduled notifications never write the
  object;
- each milestone **records an immutable `TermijnEvent`** ("herinnering-7dagen",
  "escalatie-3dagen", "termijn-overschreden") which is a **legal audit record**;
- that `TermijnEvent` ledger **is the idempotency guard** (`hasEvent()`),
  replacing it with OR's fingerprint dedupe would drop the audited "we sent this"
  record;
- the staged chain (reminder → escalation → breach, each gated on the prior
  audit event) is non-expressible as independent scheduled rules.

Verdict: **KEEP PHP.** This is the GDPR/legal seam — same discipline as the
lifecycle + GDPR seams: OR can't express the object-mutation + immutable-audit +
staged-chain, so it stays.

### Candidate 3 — `ReminderDispatchJob` (24h booking reminder)

5-min `TimedJob` → confirmed `booking` objects whose `startAt` is in [now+23h,
now+24h] and `reminderSentAt` unset → `AppointmentEmailService::sendReminder`.
- The reminder is **email-only**: a rich transactional template
  (`AppointmentEmailService`), not the dispatcher's `subject`-as-body email.
- Dedupe is a **`reminderSentAt` write-back**, not OR fingerprint state.
- **There is no in-app / push leg** to extract — nothing to move declarative.

Verdict: **KEEP PHP.** OR scheduled notifications cannot send the templated email
and there is no separable in-app/push notification here.

## 3. No-double-fire argument

Because **no imperative site is moved**, no new declarative rule is added that
could fire alongside an existing imperative path. The existing declarative
annotations (`created` broadcast rules + dormant `transition` rules) are
**unchanged** by this PR, so their fire behaviour is identical to before. The
dormant `transition` rules remain dormant until pipelinq routes status changes
through OR's TransitionEngine (they match `ObjectTransitionedEvent::getAction()`,
which pipelinq does not yet emit for these schemas — verified: pipelinq writes
status via `saveObject`, which emits `ObjectUpdatedEvent`, not
`ObjectTransitionedEvent`). The imperative `created`/update paths (Candidate 1)
dispatch a **distinct** notification subject (`lead_assigned` etc.) from the
declarative `created` rules (`newLead` broadcast to `groups:sales`), so even
today there is no duplicate.

Net: same notifications, same recipients, same moments — proven by the unit
tests, which assert the exact dispatch set is unchanged.

## 4. Which gate WARNs clear

- `notification-dialect` (gate-18): **PASS** for the legacy-dialect (hard) check —
  the existing annotations use the canonical dialect, no legacy tokens.
- The advisory **WARN on imperative dispatch** lists 3 sites
  (`NotificationService`, `AvgNotificationService`, `Notifier.php`). It does **not**
  clear, and that is correct: `Notifier.php` is the INotifier **renderer** (ADR-003
  legitimate seam, never moves), and the two `*NotificationService` classes are the
  reviewed-and-retained dispatchers above. This change converts the WARN from
  un-triaged to **documented** (the same status decidesk's `DecisionNotificationService`
  carries per the gate's own examples). Promotion of (b) to a hard fail is
  explicitly deferred fleet-wide until these legitimate dispatchers are gone.

## 5. Alternatives rejected

- *Move Candidate 1 to `updated`+`condition` rules and delete `NotificationService`.*
  Rejected: drops self-suppression + opt-out (observable behaviour change), and
  double-fires against the dormant `transition` rules after the TransitionEngine
  migration.
- *Move Candidate 2 reminders to scheduled rules.* Rejected: drops the immutable
  `TermijnEvent` legal audit row and the object write-back.
- *Add an in-app leg to Candidate 3 via a scheduled rule.* Rejected: invents a new
  notification that does not exist today (behaviour change), and the email can't
  be expressed by the OR `email` channel.

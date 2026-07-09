---
status: done
---

# sla-engine-and-escalation Specification

## Purpose
Resolves an immutable SLA policy per tracked object at creation, computes holiday-aware and business-hours deadlines, and pauses and resumes timers on status changes. Drives an idempotent escalation chain across email, notification, WhatsApp, and webhook channels as deadlines are consumed, reconciles silent deadline crossings through a scheduled sweep job, exposes attainment reporting, and records an audited, justification-gated history of policy changes, integrating with OpenRegister object events rather than polling.
## Requirements
### Requirement: Policy resolution at object creation

The engine MUST resolve exactly one `sla_policy` per tracked object at creation time, by matching `appliesTo`, `customerTier`, and `customerScope`, and tie-breaking on `priority` (lower wins) then `validFrom` (newest wins).

#### Scenario: Gold-tier policy selected over baseline

- **GIVEN** a request is created for customer in `slaTier: gold` and two policies exist:
  - Policy A: `appliesTo: request`, `customerTier: gold`, `priority: 10`
  - Policy B: `appliesTo: request`, `customerTier: *`, `priority: 100`
- **WHEN** the engine evaluates policies
- **THEN** Policy A (gold-tier, lower priority) MUST be selected
- AND `slaStatus.policyId` MUST be set to Policy A's UUID
- AND `slaStatus.targets` MUST be computed using Policy A's target definitions

#### Scenario: Baseline policy selected when no tier match

- **GIVEN** a request is created for customer with no `slaTier` attribute
- AND a baseline policy exists: `appliesTo: request`, `customerTier: *`, `priority: 100`
- **WHEN** the engine evaluates policies
- **THEN** the baseline policy MUST be selected
- AND no error MUST be thrown (default to bronze tier behavior)

#### Scenario: Per-customer override scope

- **GIVEN** a request is created for organisation X with `slaTier: silver`
- AND customer X has a contract Y with `slaTier: gold`
- AND two policies match:
  - Policy A: `appliesTo: request`, `customerTier: gold`, `customerScope: { contractIds: [Y] }`
  - Policy B: `appliesTo: request`, `customerTier: silver`, `customerScope: { organisationIds: [X] }`
- **WHEN** the request is created against contract Y
- **THEN** Policy A (most specific scope: contract > organisation) MUST be selected

#### Scenario: Tie-breaking by validFrom date

- **GIVEN** two policies match with identical `priority` and `customerTier`, and Policy A has `validFrom: 2026-05-20T00:00:00Z` and Policy B has `validFrom: 2026-05-19T00:00:00Z`
- **WHEN** the engine evaluates on 2026-05-21
- **THEN** Policy A (most recent `validFrom`) MUST be selected

#### Scenario: Policy immutability per object

- **GIVEN** a request is created with Policy A and `slaStatus.policyId` is set
- **WHEN** the customer's `slaTier` is changed from silver to gold after creation
- **THEN** the request MUST retain its originally resolved Policy A
- AND new requests for the same customer MUST use the new gold-tier policy
- (Only the tier attribute change does not retroactively recompute in-flight SLAs)

### Requirement: Holiday-aware deadline calculation

Deadline computation MUST respect the policy's `holidayCalendar` and `calendar` (business-hours window), excluding Dutch national holidays.

#### Scenario: Business-hours deadline across weekend

- **GIVEN** a request is opened on Friday 2026-05-17 at 17:00 with a 4-business-hour acknowledgement target and `business-hours` calendar (Mon-Fri 09:00-17:00)
- **WHEN** the engine computes `dueAt`
- **THEN** the deadline MUST be Monday 2026-05-20 at 12:00 (not Saturday or Sunday)
- AND the 4 hours counted MUST be: Friday 17:00-17:00 (0h of remaining Fri), Mon 09:00-13:00 (4h)

#### Scenario: Holiday is skipped in deadline calculation

- **GIVEN** a 24-hour resolution target begins on Tuesday 2026-04-26 at 10:00, and Koningsdag (April 27) is in the `nl-feestdagen-rijksoverheid` calendar
- **WHEN** the engine computes `dueAt` with `calendar: business-hours`
- **THEN** Koningsdag MUST be skipped, and the deadline MUST shift to Wednesday 2026-04-28 at 10:00

#### Scenario: 24x7 calendar ignores holidays and business hours

- **GIVEN** a target with `calendar: 24x7` and `duration: P1D`
- **WHEN** the engine computes `dueAt` starting at 2026-04-26 at 18:00
- **THEN** the deadline MUST be exactly 2026-04-27 at 18:00 (wall-clock, ignoring Koningsdag)

#### Scenario: Paused time does not count toward deadline

- **GIVEN** a request is opened on Monday 09:00 with a 4-business-hour acknowledgement target, `business-hours` calendar
- **WHEN** at 12:00 the status changes to `awaiting-customer` (in `pauseConditions`)
- **THEN** `slaStatus.pausedAt = 12:00`, and the timer is suspended
- **WHEN** at 14:00 the status changes back to `in-progress`
- **THEN** `totalPausedMs` MUST be updated by +2 hours; `dueAt` MUST be extended by 2 business hours: from 13:00 to 15:00
- (The 3 calendar hours (12:00-15:00 wall-clock) become 2 business hours because Friday 17:00+ is not business-hours)

#### Scenario: Tenant custom holiday override

- **GIVEN** a tenant configures `holidayCalendar: nl-feestdagen-rijksoverheid` but adds a custom closure "bedrijfssluiting kerst" (Dec 24 – Jan 1)
- **WHEN** a deadline computation would fall on Dec 28 with `business-hours` calendar
- **THEN** Dec 28 MUST be skipped and the deadline shifted to the next business day after Jan 1

### Requirement: Pause and resume on status change

The engine MUST pause and resume the timer when the tracked object's status enters or leaves a value listed in the policy's `pauseConditions`.

#### Scenario: Timer paused on entering pause-condition status

- **GIVEN** a request with an active SLA timer and `pauseConditions: ["awaiting-customer", "on-hold"]`
- **WHEN** the request status changes to `awaiting-customer` via `ObjectUpdatedEvent`
- **THEN** `slaStatus.pausedAt` MUST be set to the current time
- AND `slaStatus.targets[*].dueAt` MUST NOT change (deadline stays the same)
- AND `slaStatus.lastEvaluatedAt` MUST be updated

#### Scenario: Timer resumed and deadline extended

- **GIVEN** a request with `pausedAt: 12:00` (paused for 2 hours) and `totalPausedMs: 0`
- **WHEN** the status changes to `in-progress` at 14:00
- **THEN** `slaStatus.pausedAt` MUST be cleared
- AND `slaStatus.totalPausedMs` MUST be incremented by 7200000 (2 hours in ms)
- AND each `targets[*].dueAt` MUST be extended by 2 hours (or 2 business hours if `calendar: business-hours`)

#### Scenario: Partial pause counts only business-hours

- **GIVEN** a request paused Friday 16:00 and resumed Monday 10:00 (paused Friday 16:00-17:00 [1h], Sat-Sun [0h business], Mon 09:00-10:00 [1h]) = 2 business hours total
- **WHEN** the resume happens
- **THEN** the deadline extension MUST be 2 business hours, not 66 wall-clock hours

#### Scenario: No escalation firing while paused

- **GIVEN** a request is paused with a target due in 30 minutes
- **WHEN** time passes and the deadline is crossed while `pausedAt` is set
- **THEN** NO escalation MUST fire
- AND `slaStatus.targets[*].status` MUST remain `on-track` (paused means timer is frozen)

### Requirement: Escalation chain execution

When `consumedPercentage` crosses an escalation `triggerAt` threshold, the engine MUST notify the configured actor on the configured channel exactly once per level per object, and write an `sla_breach_event`. Channels `sms` and `whatsapp` MUST dispatch through the channel adapters (`SmsAdapter` / `WhatsAppAdapter`, transported via OpenRegister's `MessageDispatchProvider` leaf) and are supported for `notify: customer` — the breached object's linked client/contact is the recipient. For other notify roles, `sms`/`whatsapp` steps MUST record an `unsupported:{channel}:{role}` marker in `notifiedActors` without dispatching. Channels `email` and `webhook` remain delegated to their own capabilities and MUST record a `deferred:{channel}:{role}` marker until those land. Adapter dispatch MUST be resolved lazily (container), MUST never let a Throwable escape the sweep, and every outcome MUST be auditable through the `notifiedActors` marker vocabulary (`sent` = actor identifier, `consent-missing:`, `template-missing:`, `unsupported:`, `deferred:`, `failed:`, `unresolved:`).

**Feature tier**: V1 (sms/whatsapp escalation dispatch); MVP (notification channel, unchanged)

#### Scenario: Email sent to team-lead at 80% threshold

- **GIVEN** an SLA with escalation step: `triggerAt: 0.8`, `notify: team-lead`, `channel: email`
- **WHEN** a target's `consumedPercentage` reaches 0.80 or higher
- **THEN** an email MUST be sent to the team-lead (resolved via user lookup)
- AND `sla_breach_event` MUST be created with `escalationLevel: 1`, `breachedAt: now()`, `consumedPercentage: 0.80`, `notifiedActors: [team-lead-email]`

#### Scenario: Multiple escalation steps fire in order

- **GIVEN** an SLA with two escalation steps: step 1 at 0.8 (email), step 2 at 1.0 (notification)
- **WHEN** consumption reaches 80%
- **THEN** step 1 fires (email sent, event created with `escalationLevel: 1`)
- **WHEN** consumption later reaches 100%
- **THEN** step 2 fires (notification sent, new event created with `escalationLevel: 2`)
- AND step 1 MUST NOT fire again (idempotent per level)

#### Scenario: No escalation if resolved before threshold

- **GIVEN** an SLA with a target due in 4 hours
- **WHEN** the request is resolved within 2 hours (50% consumption)
- **THEN** no escalation MUST fire
- AND `slaStatus.targets[*].status` MUST be set to `met`
- AND `slaStatus.targets[*].metAt` MUST be populated with resolution timestamp

#### Scenario: Escalation to customer via WhatsApp

- **GIVEN** an SLA with escalation: `triggerAt: 1.5`, `notify: customer`, `channel: whatsapp`, `templateId: <approved messageTemplate>`
- AND the breached object links a client/contact with a phone number and an `opted-in` WhatsApp consent record
- **WHEN** breach percentage reaches 150%
- **THEN** the engine MUST load the linked contact and call `WhatsAppAdapter::send()` with the step's `templateId`
- AND the adapter MUST dispatch through the OpenRegister `MessageDispatchProvider` leaf and persist the outbound `message` row
- AND `sla_breach_event.notifiedActors` MUST include the contact identifier (never `deferred:`)

#### Scenario: Escalation to customer via SMS

- **GIVEN** an SLA with escalation: `triggerAt: 1.0`, `notify: customer`, `channel: sms`
- AND the breached object links a contact with a phone number and no `opted-out` SMS consent record
- **WHEN** the threshold is crossed
- **THEN** the engine MUST call `SmsAdapter::send()` with the policy's rendered breach message
- AND the outbound `message` row MUST be persisted and `notifiedActors` MUST include the contact identifier

#### Scenario: WhatsApp escalation without consent fails closed

- **GIVEN** an SLA whatsapp escalation step for `notify: customer`
- AND the linked contact has no `opted-in` WhatsApp consent record
- **WHEN** the threshold is crossed
- **THEN** no message MUST be dispatched
- AND `notifiedActors` MUST include `consent-missing:whatsapp`
- AND the breach event MUST still be written (audit is never skipped)

#### Scenario: WhatsApp escalation without a template fails closed

- **GIVEN** an SLA whatsapp escalation step with no `templateId` and no open 24h session window for the contact
- **WHEN** the threshold is crossed
- **THEN** no message MUST be dispatched
- AND `notifiedActors` MUST include `template-missing:whatsapp`

#### Scenario: SMS escalation to a non-customer role is unsupported

- **GIVEN** an SLA with escalation: `notify: team-lead`, `channel: sms`
- **WHEN** the threshold is crossed
- **THEN** no message MUST be dispatched
- AND `notifiedActors` MUST include `unsupported:sms:team-lead`

#### Scenario: Webhook escalation dispatch

- **GIVEN** an SLA with escalation: `notify: webhook`, `channel: webhook`
- **WHEN** threshold is crossed
- **THEN** the engine MUST record `deferred:webhook:webhook` in `notifiedActors` (webhook dispatch is delegated to the OR `WebhookService` integration owned by its own capability; when that lands, `WebhookService::dispatchEvent()` MUST be called with the `sla_breach_event` as CloudEvents payload, event type `nl.conduction.sla.breach`, webhook URL configurable per policy)

`@e2e exclude` backend escalation dispatch — SLA sweeps have no UI trigger; asserted by PHPUnit (`SlaEngineService` dispatch tests with adapter mocks + marker-vocabulary assertions) and the Newman/mock-source contract ring per `outbound-messaging` REQ-OM-006.

### Requirement: Per-customer SLA tier override

Administrators MUST be able to attach an SLA tier (`bronze` … `platinum`) to a customer organisation or to a specific contract, and the engine MUST honour the most-specific scope.

#### Scenario: Contract-level tier overrides organisation-level

- **GIVEN** organisation X has `slaTier: silver` and contract Y (child of X) has `slaTier: gold`
- **WHEN** a request is created against contract Y
- **THEN** policy resolution MUST prefer policies matching `customerTier: gold` over `silverCustom`
- AND the gold-tier SLA targets MUST apply

#### Scenario: Missing tier defaults to bronze

- **GIVEN** a customer organisation has no `slaTier` attribute
- **WHEN** a request is created
- **THEN** the engine MUST treat the customer as `bronze` for policy matching
- AND no error MUST be thrown

#### Scenario: Tier change only affects new objects

- **GIVEN** a customer has `slaTier: bronze` and 3 active requests bound to bronze policies
- **WHEN** an administrator changes the customer to `slaTier: gold`
- **THEN** the 3 existing requests MUST retain their bronze policies and deadlines
- AND any new requests for the customer MUST use gold policies

### Requirement: Attainment reporting

The engine MUST expose an aggregation endpoint that returns SLA attainment percentages broken down by policy, customer, customer-tier, assignee-team, and time-bucket (day, week, month, quarter).

#### Scenario: Quarterly attainment with breach breakdown

- **GIVEN** 100 requests closed in Q2 2026 under a given policy, of which 92 met all targets and 8 breached at least one
- **WHEN** the report is requested for `GET /api/sla/attainment?policy=<uuid>&bucket=quarter&quarter=2026-Q2`
- **THEN** the response MUST include:
  ```json
  {
    "attainment": 0.92,
    "total": 100,
    "met": 92,
    "breached": 8,
    "details": {
      "byTarget": {
        "acknowledgement": { "attainment": 0.95, "breached": 5 },
        "resolution": { "attainment": 0.88, "breached": 12 }
      }
    }
  }
  ```

#### Scenario: Per-target accounting

- **GIVEN** a request met its `acknowledgement` target (0.9 consumption) but breached `resolution` (1.2 consumption)
- **WHEN** the request is included in attainment reporting
- **THEN** the request MUST count as met (1.0) for acknowledgement attainment
- AND as breached (1.0 fail) for resolution attainment
- (Separate per-target accounting, not all-or-nothing at the request level)

#### Scenario: In-flight vs. closed breach distinction

- **GIVEN** a request past its resolution deadline but still open, and another request resolved after its deadline
- **WHEN** the report is requested
- **THEN** the in-flight request MUST appear in bucket `in-flight-breached`
- AND the closed request MUST appear in bucket `closed-breached`
- (Operators need to act now on in-flight; historical metrics on closed)

#### Scenario: Breakdown by customer tier

- **GIVEN** report request: `GET /api/sla/attainment?groupBy=customerTier&bucket=month`
- **WHEN** the report is computed
- **THEN** the response MUST show separate attainment percentages per tier: bronze, silver, gold, platinum
- AND a row for untiered/default customers

#### Scenario: Breakdown by assignee team

- **GIVEN** report request: `GET /api/sla/attainment?groupBy=team&bucket=week`
- **WHEN** the report is computed
- **THEN** the response MUST show attainment per team assignment at object creation time
- AND teams with zero closed objects in the period MUST be omitted

### Requirement: OpenRegister event integration

The engine MUST register as a listener for `object.created`, `object.updated`, and `object.deleted` events on schemas whitelisted in the policy's `appliesTo`, and MUST NOT poll.

#### Scenario: SLA initialized on request creation

- **GIVEN** a `request` object is created via the OpenRegister API
- **WHEN** `ObjectCreatedEvent` fires
- **THEN** the SLA engine listener MUST execute before the API response returns (synchronous)
- AND `slaStatus` with resolved policy and targets MUST be populated
- AND the request save MUST include the embedded `slaStatus`

#### Scenario: Policy not recomputed on object update

- **GIVEN** a `request` object is updated and its `status` field changes
- **WHEN** `ObjectUpdatedEvent` fires
- **THEN** the engine MUST re-evaluate `pauseConditions` and `targets[*].status`
- AND the engine MUST NOT recompute the originally chosen policy
- (Policy is immutable per object; only status/deadline math can change)

#### Scenario: Listener failure does not block object save

- **GIVEN** the SLA engine listener throws an exception (e.g., policy lookup fails)
- **WHEN** an object is created or updated
- **THEN** the underlying object save MUST succeed (listener failure is non-blocking)
- AND the exception MUST be logged
- AND a follow-up scheduled job (`SlaDeadlineSweepJob`) MUST reconcile the object on the next run

### Requirement: Scheduled deadline sweep

A background job MUST run at most every 5 minutes (configurable, min 1, max 30) to detect deadline crossings that did not coincide with an object event (i.e. nothing happened, the clock just ran out).

#### Scenario: Breach detected on silent deadline crossing

- **GIVEN** a request was opened 4 hours ago with a 4-business-hour acknowledgement target and no events have fired since
- **WHEN** the sweep job runs (and no object update event occurred in the interim)
- **THEN** the breach MUST be detected
- AND the escalation MUST fire
- AND `slaStatus.targets[*].status` MUST be updated to `breached`
- AND `sla_breach_event` MUST be created with `breachedAt` reflecting the true crossing time (when deadline was reached), not the detection time

#### Scenario: Sweep job resumes after maintenance pause

- **GIVEN** the sweep job is paused for 30 minutes
- **WHEN** the job resumes and detects 50 objects whose deadlines crossed during the pause
- **THEN** each deadline crossing MUST trigger an escalation with `breachedAt` reflecting the true crossing time
- (Timing accuracy, not detection time accuracy)

#### Scenario: Sweep job performance on reference sizing

- **GIVEN** 10,000 in-flight SLA objects (status = `on-track` or `at-risk`)
- **WHEN** the sweep job runs on a reference 2-vCPU / 4 GB MKB instance
- **THEN** the job MUST complete in <60 seconds
- AND queries MUST be batched (e.g., 100 objects per query, 100 update batches)
- (Acceptable async job — does not block user interactions)

#### Scenario: Idempotent escalation on re-run

- **GIVEN** an object's deadline was crossed and escalation was already fired by an earlier sweep run
- **WHEN** the job runs again and re-evaluates the same object
- **THEN** the escalation MUST NOT fire again (already at `escalationLevel: N`)
- (No duplicate breach events)

### Requirement: Audit trail of policy changes

Every change to an `sla_policy` (create, update, deactivate, target-edit) MUST be recorded with actor, timestamp, before/after diff, and a free-text justification field. The engine MUST refuse to save a policy edit without justification.

#### Scenario: Justification required for policy save

- **GIVEN** an administrator edits a policy to tighten the resolution target from `P3W` to `P1W`
- **WHEN** they attempt to save without entering a `justification` field
- **THEN** the API MUST return HTTP 400 with error: `justificationRequired: "Justification is required to modify SLA policies"`
- AND the policy MUST NOT be persisted

#### Scenario: Audit trail records policy edit

- **GIVEN** the same policy edit is saved with justification: "klant-eis nieuw contract Q3"
- **WHEN** the policy is read later via detail view or audit endpoint
- **THEN** an audit-trail entry MUST be retrievable showing:
  - Actor: user ID of the administrator
  - Timestamp: when the edit was saved
  - Before: original `targets[*].duration` array
  - After: updated `targets[*].duration` array
  - Justification: "klant-eis nieuw contract Q3"

#### Scenario: In-flight requests retain original deadlines

- **GIVEN** an in-flight request bound to a policy with `resolution: P3W`, and the policy is edited to `resolution: P1W`
- **WHEN** the policy is saved with justification
- **THEN** the in-flight request MUST keep its originally captured deadlines (computed from old `P3W` target)
- AND its `slaStatus.targets[*].dueAt` MUST NOT be retroactively recomputed
- (Policy immutability: the binding is the snapshot at object creation time)

### Requirement: Holiday calendar pluggability

The set of recognised holiday calendars MUST be configurable without code change, sourced from a JSON file shipped per locale and overridable per-tenant via OpenRegister.

#### Scenario: Composite calendar (NL + BE)

- **GIVEN** a tenant operates in both NL and BE and configures `holidayCalendar: "nl-feestdagen-rijksoverheid,be-feestdagen"`
- **WHEN** the engine computes a deadline
- **THEN** the union of both calendars MUST be excluded from the deadline
- (No double-counting; OR logic, not AND)

#### Scenario: Custom year-round Bevrijdingsdag

- **GIVEN** a tenant overrides the Bevrijdingsdag rule (normally only in lustrum years) to be observed every year
- **WHEN** the deadline computation runs in a non-lustrum year
- **THEN** May 5 MUST still be skipped
- AND the custom override MUST be stored in the tenant's holiday-calendar configuration

#### Scenario: Tenant closure date range

- **GIVEN** a tenant defines a private closure range: "bedrijfssluiting kerst" from Dec 24 to Jan 1
- **WHEN** a deadline computation would fall inside that range
- **THEN** the deadline MUST shift to the next business day after the range ends (Jan 2)

---


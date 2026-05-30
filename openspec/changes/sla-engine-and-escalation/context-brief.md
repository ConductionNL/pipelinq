---
status: draft
---
# SLA Engine and Escalation

## Purpose

Pipelinq's MKB customers operate service desks, complaint registers, and callback queues where missed promises directly damage relationships and contractual standing. Today every workstream (requests, klachten, callbacks, returns, contract-renewals) tracks its own deadlines in ad-hoc ways: a `target_date` column here, a flag there, a manager who skims the list every morning. When something slips, nobody knows who should have been pinged, when, or how to prove the breach to the customer.

This change introduces a single SLA engine that all customer-facing object types in Pipelinq can plug into. It turns "we'll get back to you within four hours" from a promise buried in an email signature into a measurable, holiday-aware countdown with an escalation chain wired to real notifications. It produces the attainment numbers MKB-management needs to show to enterprise customers in QBRs, and the per-customer overrides needed to honour Gold/Silver/Bronze contractual tiers without forking the codebase.

The engine is deliberately scoped as **cross-cutting infrastructure**: it does not own requests, klachten, or callbacks; it observes them via OpenRegister object events and writes back via a small status sub-object. Any future workstream (incident-management, sales-opportunities, factuur-disputes) inherits SLA behaviour by registering a policy, not by re-implementing timers.

The MKB framing matters. Enterprise SLA tooling (ServiceNow, BMC Remedy) assumes a dedicated SLA-administrator who curates hundreds of contracts in a separate UI. A 30-person Dutch installateur or zorgaanbieder cannot staff that role. The engine therefore defaults to a pragmatic baseline (4u acknowledgement, 24h response, 3 weken resolution depending on type) so that day-one customers already get measurable SLAs without configuration, and only graduate to per-customer overrides when a tender demands it.

## Data Model

Two new schemas in the `pipelinq` register, plus a status sub-object embedded on tracked records.

### `sla_policy`
- `id` (uuid, system)
- `name` (string, required) — "Standaard request-SLA", "Goud-tier klant-SLA"
- `description` (text)
- `appliesTo` (enum: `request`, `klacht`, `callback`, `return`, `*`) — `*` is the catch-all baseline
- `customerTier` (enum: `bronze`, `silver`, `gold`, `platinum`, `*`) — `*` matches any tier
- `customerScope` (object, optional) — `{ organisationIds: [...], contractIds: [...] }` for per-customer overrides
- `targets` (array of objects):
  - `kind` (enum: `acknowledgement`, `firstResponse`, `resolution`, `callback`)
  - `duration` (ISO-8601 duration: `PT4H`, `P1D`, `P3W`)
  - `calendar` (enum: `24x7`, `business-hours`, `extended-business-hours`)
- `escalationChain` (array, ordered):
  - `triggerAt` (percentage of target consumed, e.g. `0.8`, `1.0`, `1.5`)
  - `notify` (enum: `assignee`, `team-lead`, `manager`, `director`, `customer`, `webhook`)
  - `channel` (enum: `email`, `nextcloud-notification`, `whatsapp`, `sms`, `webhook`)
- `pauseConditions` (array of strings) — status values that pause the timer: `awaiting-customer`, `on-hold`, `awaiting-supplier`
- `holidayCalendar` (string) — `nl-feestdagen-rijksoverheid`, `be-feestdagen`, `none`
- `priority` (integer, lower = stricter; used for tie-breaking when multiple policies match)
- `active` (boolean, default true)
- `validFrom` / `validUntil` (datetime, optional)

### `sla_breach_event`
- `id` (uuid, system)
- `policyId` (ref → `sla_policy`)
- `targetObjectType` (string) — `request`, `klacht`, …
- `targetObjectId` (uuid)
- `targetKind` (enum, same as `targets.kind` above)
- `breachedAt` (datetime)
- `consumedPercentage` (decimal) — `1.0` = exactly at deadline, `1.5` = 50% over
- `escalationLevel` (integer) — which step of the chain fired
- `notifiedActors` (array) — user IDs / external addresses actually contacted
- `acknowledged` (boolean) — did the assignee/manager click through
- `acknowledgedAt`, `acknowledgedBy`
- `resolvedAt` (datetime, nullable) — when the underlying object reached its target state

### Embedded `slaStatus` sub-object on tracked records
Added to `request`, `klacht`, `callback` (and any future opt-in type) without forking those schemas — stored as a JSON column managed by the engine:
```
{
  policyId, startedAt, pausedAt, totalPausedMs,
  targets: [
    { kind, dueAt, consumedPercentage, status: 'on-track'|'at-risk'|'breached'|'met',
      metAt, breachEventIds: [] }
  ],
  currentEscalationLevel,
  lastEvaluatedAt
}
```

## Requirements

### REQ-001 Policy resolution per object
The engine MUST resolve exactly one `sla_policy` per tracked object at creation time, by matching `appliesTo`, `customerTier`, and `customerScope` and tie-breaking on `priority` (lower wins) then `validFrom` (newest wins).

- **GIVEN** a request is created for a customer in `customerTier: gold` and a gold-tier policy exists with `priority: 10` plus a baseline `*`-tier policy with `priority: 100`, **WHEN** the engine evaluates policies, **THEN** the gold-tier policy MUST be selected and its `policyId` written to the request's `slaStatus`.
- **GIVEN** no scoped policy matches a customer, **WHEN** the engine evaluates policies, **THEN** the catch-all baseline policy (`customerTier: *`, `customerScope` empty) MUST be selected.
- **GIVEN** two policies match with identical `priority`, **WHEN** the engine evaluates, **THEN** the one with the most recent `validFrom` MUST win, with the tie logged for administrator review.

### REQ-002 Holiday-aware deadline calculation
Deadline computation MUST respect the policy's `holidayCalendar` and `calendar` (business-hours window), excluding Dutch national holidays (Nieuwjaarsdag, Goede Vrijdag, Eerste/Tweede Paasdag, Koningsdag, Bevrijdingsdag in lustrum years, Hemelvaartsdag, Eerste/Tweede Pinksterdag, Eerste/Tweede Kerstdag).

- **GIVEN** a request is opened on Friday 17:00 with a 4-business-hour acknowledgement target and `business-hours` calendar (Mon-Fri 09:00-17:00), **WHEN** the engine computes `dueAt`, **THEN** the deadline MUST be Monday 12:00 (not Saturday 13:00).
- **GIVEN** a 24-hour resolution target spans Koningsdag (April 27), **WHEN** the engine computes `dueAt`, **THEN** Koningsdag MUST be skipped and the deadline shifted by one business day.
- **GIVEN** a policy with `calendar: 24x7`, **WHEN** the engine computes `dueAt`, **THEN** holidays and business hours MUST be ignored — wall-clock time only.

### REQ-003 Pause and resume on status change
The engine MUST pause and resume the timer when the tracked object's status enters or leaves a value listed in the policy's `pauseConditions`.

- **GIVEN** a request with an active SLA timer transitions to status `awaiting-customer` which is listed in `pauseConditions`, **WHEN** the OpenRegister update event fires, **THEN** `slaStatus.pausedAt` MUST be set and `totalPausedMs` MUST accumulate the paused interval on resume.
- **GIVEN** a request resumes from `awaiting-customer` to `in-progress`, **WHEN** the engine recomputes, **THEN** `dueAt` MUST be extended by the paused duration and `pausedAt` cleared.
- **GIVEN** a request is paused for 3 calendar days but only 1 of those was a business day under a `business-hours` calendar, **WHEN** the engine resumes, **THEN** the extension applied MUST be 1 business day (not 3 calendar days).

### REQ-004 Escalation chain execution
When `consumedPercentage` crosses an escalation `triggerAt` threshold, the engine MUST notify the configured actor on the configured channel exactly once per level per object, and write an `sla_breach_event`.

- **GIVEN** an SLA with escalation steps at `0.8` (team-lead, email) and `1.0` (manager, nextcloud-notification), **WHEN** consumption reaches 80%, **THEN** an email MUST be sent to the team-lead and a `sla_breach_event` with `escalationLevel: 1` MUST be written.
- **GIVEN** the same SLA later reaches 100% consumption, **WHEN** the engine evaluates, **THEN** a Nextcloud notification MUST be sent to the manager and a new `sla_breach_event` with `escalationLevel: 2` MUST be written; the level-1 event MUST NOT fire again.
- **GIVEN** the assignee resolves the underlying object before any threshold is crossed, **WHEN** the engine evaluates on the status change, **THEN** no escalation MUST fire and `slaStatus.targets[*].status` MUST be set to `met` with `metAt` populated.

### REQ-005 Per-customer SLA tier override
Administrators MUST be able to attach an SLA tier (`bronze` … `platinum`) to a customer organisation or to a specific contract, and the engine MUST honour the most-specific scope.

- **GIVEN** customer organisation X has `slaTier: silver` and contract Y belonging to X has `slaTier: gold`, **WHEN** a request is created against contract Y, **THEN** policy resolution MUST prefer policies matching the gold tier over silver.
- **GIVEN** a customer has no tier set, **WHEN** a request is created, **THEN** the engine MUST treat the customer as `bronze` (lowest tier) for policy matching, never error.
- **GIVEN** an administrator changes a customer from silver to gold mid-flight on an active request, **WHEN** the customer record is saved, **THEN** existing in-flight requests MUST retain their originally resolved policy (no retroactive change) but new requests MUST use the new tier.

### REQ-006 Attainment reporting
The engine MUST expose an aggregation endpoint that returns SLA attainment percentages broken down by policy, customer, customer-tier, assignee-team, and time-bucket (day, week, month, quarter).

- **GIVEN** 100 requests closed in Q2 under a given policy, of which 92 met all targets and 8 breached at least one, **WHEN** the report is requested for that quarter, **THEN** the response MUST show `attainment: 0.92` with breach-count and breach-detail drilldowns.
- **GIVEN** a request met its `acknowledgement` target but breached its `resolution` target, **WHEN** the report is computed, **THEN** the request MUST count as a breach for `resolution` attainment but as met for `acknowledgement` attainment (per-target accounting).
- **GIVEN** a request is still open and past its deadline, **WHEN** the report is requested, **THEN** the request MUST appear in an `in-flight-breached` bucket separate from `closed-breached` so management can act on it now.

### REQ-007 OpenRegister event integration
The engine MUST register as a listener for `object.created`, `object.updated`, and `object.deleted` events on schemas whitelisted in the policy's `appliesTo`, and MUST NOT poll.

- **GIVEN** a `request` object is created via the OpenRegister API, **WHEN** the `ObjectCreatedEvent` fires, **THEN** the SLA engine listener MUST run policy resolution and populate `slaStatus` before the API response returns.
- **GIVEN** a `request` object is updated, **WHEN** the `ObjectUpdatedEvent` fires, **THEN** the engine MUST re-evaluate pause-conditions and target status; it MUST NOT recompute the originally chosen policy.
- **GIVEN** the engine listener itself throws, **WHEN** an event fires, **THEN** the underlying object save MUST succeed (listener failures are logged but never block the user); a follow-up scheduled job MUST reconcile.

### REQ-008 Scheduled deadline sweep
A background job MUST run at most every 5 minutes (configurable) to detect deadline crossings that did not coincide with an object event (i.e. nothing happened, the clock just ran out).

- **GIVEN** a request was opened 4 hours ago with a 4-hour acknowledgement target and no events have fired since, **WHEN** the sweep job runs, **THEN** the breach MUST be detected and the escalation MUST fire even though no user action triggered it.
- **GIVEN** the sweep job is paused for 30 minutes (maintenance), **WHEN** it resumes, **THEN** any deadlines crossed during the pause MUST still fire escalations (with `breachedAt` reflecting the true crossing time, not the detection time).
- **GIVEN** the sweep job processes 10,000 in-flight SLA objects, **WHEN** it runs, **THEN** the job MUST complete within 60 seconds on the reference 2-vCPU/4GB MKB sizing, batching DB queries.

### REQ-009 Audit trail of policy changes
Every change to an `sla_policy` (create, update, deactivate, target-edit) MUST be recorded with actor, timestamp, before/after diff, and a free-text justification field. The engine MUST refuse to save a policy edit without justification.

- **GIVEN** an administrator tightens a resolution target from `P3W` to `P1W` on an active policy, **WHEN** they save without entering a justification, **THEN** the API MUST return a 400 with `justificationRequired` and not persist.
- **GIVEN** the same edit is saved with justification "klant-eis nieuw contract Q3", **WHEN** the policy is read later, **THEN** the audit log entry MUST be retrievable and MUST show both old and new `targets` arrays plus the justification.
- **GIVEN** an in-flight request is bound to that policy, **WHEN** the policy is edited, **THEN** the in-flight request MUST keep its originally captured deadlines (REQ-005 immutability extends to policy edits, not only tier changes).

### REQ-010 Holiday calendar pluggability
The set of recognised holiday calendars MUST be configurable without code change, sourced from a JSON file shipped per locale and overridable per-tenant via OpenRegister.

- **GIVEN** a tenant operates in both NL and BE and configures `holidayCalendar: nl-feestdagen-rijksoverheid + be-feestdagen` as a composite, **WHEN** the engine computes a deadline, **THEN** the union of both calendars MUST be excluded.
- **GIVEN** a tenant overrides Bevrijdingsdag to be observed every year (not only lustrum), **WHEN** the deadline computation runs in a non-lustrum year, **THEN** May 5 MUST still be skipped.
- **GIVEN** a tenant defines a private "bedrijfssluiting kerst" range (Dec 24–Jan 1), **WHEN** a deadline would fall inside that range, **THEN** the deadline MUST shift to the next business day after the range.

## Standards & Sources

- **ITIL 4** practice "Service Level Management" — terminology (SLA target, breach, attainment), the principle of separating the agreement from the workstream.
- **ISO/IEC 20000-1:2018** — service-management system requirements that several MKB customers will reference in tenders even if not certified themselves.
- **Rijksoverheid Feestdagen** — authoritative list at https://www.rijksoverheid.nl/onderwerpen/schoolvakanties/vraag-en-antwoord/wanneer-zijn-de-officiele-feestdagen (used for the bundled `nl-feestdagen-rijksoverheid` calendar).
- **NEN 7510** — relevant for zorg-MKB customers; their SLAs often have legal teeth (privacy-incident notification ≤ 72u).
- **AVG/GDPR Art. 33** — 72-hour breach notification deadline is the de-facto template for the strictest SLA target.
- **iCalendar RFC 5545** (`VEVENT`, `RRULE`) — informs the holiday-calendar JSON schema so administrators can paste from Google/Outlook exports.
- **ISO 8601 durations** for `targets[].duration` (`PT4H`, `P1D`, `P3W`) — avoids ambiguity around "1 day = 24h or 1 business day".

## Cross-app Integration

- **`request-management`** (pipelinq spec): emits `ObjectCreatedEvent` / `ObjectUpdatedEvent` that the engine listens to; embeds `slaStatus` on requests; surfaces "SLA at risk" badge in request lists; the assignment-rules feature can read the resolved policy to prioritise.
- **`klachtenregistratie`** (pipelinq spec): same listener pattern; the AVG-mandated 72-hour datalek-notification deadline is modelled as a baseline policy with `appliesTo: klacht, customerTier: *`.
- **`callback-management`** (pipelinq spec): callbacks use the `callback` target kind; the policy default ships at "binnen 1 werkdag terugbellen".
- **`whatsapp-sms-channel-adapter`** (pipelinq spec): exposed as `channel: whatsapp` and `channel: sms` in the escalation chain — escalations to customers (REQ-004 actor `customer`) can land on the customer's preferred channel from omnichannel preferences.
- **`omnichannel-registratie`** (pipelinq spec): the customer's preferred contact channel feeds escalation routing when the actor is `customer`.
- **`openconnector`**: webhooks for `channel: webhook` and for external escalation endpoints (e.g. a customer's own incident-system) go out via openconnector source rows with retry/log.
- **`openregister`**: the engine is implemented as a register listener app; uses `ObjectService` for reads/writes; `seed-related-items` ships the baseline policies; deadline-sweep job uses the standard background-job framework.
- **`launchpad`**: attainment endpoints (REQ-006) feed the management dashboard's "SLA-vinger aan de pols" tile; widget polls `/api/sla/attainment?bucket=week`.
- **`docudesk`**: contract documents can carry an `slaTierOverride` annotation read during policy resolution (REQ-005) so a signed contract upgrade flows through without a separate UI step.
- **`hydra/openspec` shared specs**: cross-app `i18n-nl` and `i18n-en` cover all engine user-facing strings; the holiday-calendar JSON shape is a candidate for promotion to a shared spec once a second app needs it.

## Target Users

- **MKB service-desk manager** (5–50 FTE org) — wants attainment % to defend renewals; cannot afford a dedicated SLA-administrator. The default policies must work out of the box; tier overrides are an opt-in escalation when a customer demands them.
- **Team-lead / coordinator** — first escalation recipient; needs the at-risk list in their morning view and a single-click acknowledge button so they're not re-notified.
- **Directeur-eigenaar** of a small Dutch bureau — third escalation recipient; needs a once-a-quarter PDF for board/customer QBRs, not a daily dashboard.
- **Klant** (the MKB's customer) — receives `actor: customer` notifications when the supplier wants to be transparent about delays; tone of those messages must be configurable per tenant and translatable (the `whatsapp-sms-channel-adapter` carries the actual delivery).
- **Compliance officer** (zorg, finance MKB) — uses the audit trail (REQ-009) and the breach-event log to evidence ISO 20000 / NEN 7510 controls during certification audits.
- **Tender-coördinator** at the MKB customer — reads the SLA-attainment report when responding to public-sector RFPs that require "aantoonbare SLA-prestaties laatste 12 maanden".

The engine is explicitly **not** aimed at enterprise SLA-as-a-product configurations with thousands of contracts and dedicated administrators; those customers should look at ServiceNow ITSM. Pipelinq's positioning is "good-enough SLA for the MKB, with the audit trail to back it up".

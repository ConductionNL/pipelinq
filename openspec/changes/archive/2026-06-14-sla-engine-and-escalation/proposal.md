# Proposal: sla-engine-and-escalation

## Problem

Dutch MKB service-desk, complaint, and callback operations depend on SLA promises to win tenders and retain customers. Today, each workstream (requests, klachten, callbacks, returns) manages its own deadlines with ad-hoc tracking: scattered `target_date` fields, manual manager review, and zero proof of compliance. When a deadline slips, nobody knows who should be escalated, whether the breach is contractual, or how to defend the attainment number to an enterprise customer in a QBR.

The core gaps are:

1. **No unified SLA engine** — every workstream reinvents deadline tracking, missing the opportunity for a single, reusable calendar-aware engine that all future workstreams inherit for free.
2. **No visible breach escalation** — when an SLA is at risk or breached, the on-call escalation chain is undefined and unexecuted. Managers find out via complaints, not proactive notification.
3. **No audit trail of policy changes** — when an SLA target shifts (e.g., "gold-tier customers now get 24h instead of 48h"), there is no recorded justification or history. Auditors cannot verify that a policy change was deliberate.
4. **No attainment reporting** — MKB leaders cannot show customers or auditors "we met 94% of our SLA targets last quarter" because the data is not aggregated, per-tier, or drillable.

## Solution

Build a cross-cutting SLA engine that runs as an OpenRegister listener, observing object-created and object-updated events on tracked types (request, klacht, callback, return, and any future opt-in type). The engine:

1. **Resolves a unique SLA policy per object** — matches the object's type, customer tier, and optional customer scope; writes the policy ID and deadline targets to an embedded `slaStatus` sub-object on the tracked record.
2. **Computes holidays-aware deadlines** — respects the policy's calendar (24x7, business-hours, extended), Dutch national holidays, and optional tenant-specific closure dates, using ISO 8601 durations and supporting pause/resume on status change.
3. **Executes escalation chains** — when consumed-time crosses a threshold (e.g., 80%, 100%, 150%), the engine notifies the configured actors (assignee, team-lead, manager, director, customer) on the configured channels (email, Nextcloud notification, WhatsApp, SMS, webhook) exactly once per level, with an immutable audit trail in `sla_breach_event`.
4. **Provides attainment reporting** — exposes aggregation endpoints broken down by policy, customer, tier, assignee-team, and time-bucket (day, week, month, quarter), distinguishing in-flight breaches from closed-and-resolved.
5. **Ships with pragmatic defaults** — the engine launches with baseline policies (4u acknowledgement, 24h response, 3 weeks resolution) for each object type, so day-one customers are measurable without configuration. Per-customer overrides (bronze/silver/gold/platinum tiers) are opt-in when a tender demands it.

The engine is deliberately scoped as **cross-cutting infrastructure**: it does not own requests, klachten, or callbacks; it observes them and writes back a status sub-object. Any future workstream (incident-management, sales-opportunities, factuur-disputes) inherits SLA behaviour by registering a policy, not by re-implementing timers.

## Scope

### Backend

- **`sla_policy` schema** (new) — stores SLA agreements with targets, escalation chains, calendar config, customer scope, and priority tiebreakers.
- **`sla_breach_event` schema** (new) — immutable audit trail of every escalation firing (when, which threshold, which actors, whether acknowledged).
- **`slaStatus` sub-object** — embedded JSON on `request`, `klacht`, `callback`, and future opt-in types; stores resolved policy, current deadlines, pause state, escalation level, and per-target status.
- **`SlaEngineService`** — core business logic: policy resolution, deadline computation (holiday-aware, business-hours-aware, pause-aware), status evaluation, escalation chain execution.
- **Event listeners** — `ObjectCreatedListener` (policy resolution + initial deadline calc) and `ObjectUpdatedListener` (pause/resume, status change, target status updates) registered on configured object types via `ObjectCreatedEvent` and `ObjectUpdatedEvent`.
- **Scheduled job** (`SlaDeadlineSweepJob`) — runs every 5 minutes to detect deadline crossings that did not coincide with an object event; idempotent and batches queries.
- **Admin settings** — policy CRUD UI, customer tier assignment, per-tenant holiday calendar override, sweep job frequency config, escalation channel defaults.
- **Schema definitions** — `sla_policy`, `sla_breach_event` in `lib/Settings/sla-engine_register.json`; seed data with baseline policies and example breach events.

### Frontend

- **SLA policy list/detail** — CRUD UI for policies; versioning and audit trail visible; publish/archive lifecycle.
- **Customer tier assignment** — organisation and contract-level tier override UI; most-specific scope resolution visible.
- **SLA status badge** — on request, klacht, callback list views: "On track", "At risk (80%)", "Breached (120%)", "Met".
- **SLA detail sidebar** — on request/klacht/callback detail: resolved policy name, target deadlines with countdown, current escalation level, breach event log.
- **Attainment dashboard** — KPI tiles (quarterly % met), drilldown tables (by policy, by customer, by team), in-flight vs. closed breach buckets.
- **Escalation log viewer** — audit trail of who was notified, when, on which channel, and whether they acknowledged.

### Integration Points

- **`request-management`**: emits `ObjectCreatedEvent` / `ObjectUpdatedEvent`; `slaStatus` embedded on requests; SLA badge in request lists; resolved policy feeds assignment-rules.
- **`complaint-management`** (klachten): same listener pattern; AVG-mandated 72u datalek-notification is a baseline policy.
- **`callback-management`**: callbacks use the `callback` target kind; default policy "callback within 1 business day".
- **`whatsapp-sms-channel-adapter`**: escalations to customers (actor: `customer`) can land on preferred omnichannel contact.
- **`omnichannel-registratie`**: customer's preferred channel feeds escalation routing.
- **`openconnector`**: webhooks for external escalation endpoints (e.g., customer's incident-system) go out via openconnector source rows with retry/log.
- **`openregister`**: engine is a listener app; uses `ObjectService` for CRUD; `seed-related-items` ships baseline policies; scheduled job uses standard background-job framework.
- **`launchpad`**: attainment endpoints feed the management dashboard's SLA tile; widget polls `/api/sla/attainment`.
- **`docudesk`**: contract documents can carry an `slaTierOverride` annotation read during policy resolution.
- **`hydra/openspec` shared specs**: `i18n-nl` and `i18n-en` cover all engine user-facing strings; holiday-calendar JSON shape may be promoted to shared spec.

### Out of Scope

- Custom UI for drill-down analytics beyond the baseline attainment dashboard (those go to launchpad as separate changes).
- Reverse sync or override of SLA targets from customer-facing portals.
- Machine-learning-based SLA time prediction or anomaly detection.
- Integration with external ITSM platforms (ServiceNow, BMC Remedy) at this stage.
- Per-assignee SLA capacity planning or workload leveling.
- Automatic escalation to external systems (that goes via openconnector webhooks after this change).

## Success Criteria

- The SLA engine resolves exactly one policy per tracked object at creation time, with tie-breaking by priority then validFrom date.
- Holiday-aware deadline calculation respects the policy's calendar and `pauseConditions`; business-hours targets exclude weekends and holidays.
- Escalation chains fire atomically (email + notification channel) the moment a threshold is crossed; every firing is recorded in `sla_breach_event`.
- Attainment reports show per-policy, per-customer, and per-team statistics with in-flight and closed breach separation.
- Baseline policies ship out-of-the-box; day-one customers have measurable SLAs without configuration.
- Per-customer tier overrides (bronze/silver/gold/platinum) are configurable without code change; most-specific scope (contract > organisation) wins.
- The audit trail (REQ-009) records every policy edit with actor, timestamp, before/after diff, and free-text justification; API refuses saves without justification.
- The scheduled deadline-sweep job completes in <60s on reference MKB sizing (2 vCPU, 4 GB RAM) for 10,000 in-flight SLA objects.
- All user-facing strings are translatable (Dutch + English, via `i18n-nl` and `i18n-en`); all escalation channels (email, Nextcloud notification, WhatsApp, SMS) are pluggable without code change.

## Dependencies

- **`openregister`** — core platform; `ObjectService`, `WebhookService`, event dispatcher, background job framework, schema import/export.
- **`time-entry-core`** (if this change is used to extend SLAs to time approvals) — provides `timeEntry` schema and approval events.
- **`whatsapp-sms-channel-adapter`** — if escalations target customer on WhatsApp/SMS; engine calls into omnichannel preference lookup.
- **`omnichannel-registratie`** — customer contact-channel preferences; engine reads preferred channel during escalation.
- **`openconnector`** — if escalations route to external incident systems via webhook; engine dispatches via openconnector source config.
- **Nextcloud built-ins** — `IAppConfig` for settings, `NotificationService` for in-app notifications, `IEventDispatcher` for listener registration.

## Target Users

- **MKB service-desk manager** (5–50 FTE) — wants attainment % for customer QBRs; cannot afford a dedicated SLA-admin. Baseline policies must work out-of-the-box; tier overrides opt-in.
- **Team-lead / coordinator** — first escalation; needs at-risk list in morning view and acknowledge button to avoid re-notification.
- **Directeur-eigenaar** — third escalation; needs once-a-quarter PDF for board/customer QBRs.
- **Klant** (MKB's customer) — transparent about delays via escalation notifications when supplier opts for customer visibility.
- **Compliance officer** (zorg, finance MKB) — uses audit trail and breach log for ISO 20000 / NEN 7510 certification evidence.
- **Tender-coördinator** — reads SLA attainment report when responding to public-sector RFPs requiring "aantoonbare SLA-prestaties".

## Positioning

The engine is explicitly **not** for enterprise SLA-as-a-product (e.g., ServiceNow ITSM) with thousands of contracts and dedicated administrators. Pipelinq's positioning is **"good-enough SLA for the MKB, with the audit trail to back it up"** — pragmatic defaults, configurable tiers, no separate SLA product.

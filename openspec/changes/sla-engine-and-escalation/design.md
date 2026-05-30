# Design: sla-engine-and-escalation

## Architecture

### Data Layer

#### New Schemas

Two new schemas in `sla` register. No new schema for the embedded `slaStatus` sub-object — that is a JSON column on tracked records.

##### **`sla_policy`**

| Property | Type | Required | Description |
|---|---|---|---|
| `id` | uuid | Yes | System-generated UUID |
| `name` | string | Yes | Human-readable policy name (e.g., "Standaard request-SLA", "Goud-tier klant-SLA") |
| `description` | text | No | Long-form policy description |
| `appliesTo` | enum | Yes | Scope: `request`, `klacht`, `callback`, `return`, or `*` (catch-all baseline). May be extended in future. |
| `customerTier` | enum | Yes | Tier match: `bronze`, `silver`, `gold`, `platinum`, or `*` (any tier). |
| `customerScope` | object | No | Optional per-customer override: `{ organisationIds: [...], contractIds: [...] }`. Null = all customers within the tier. |
| `targets` | array | Yes | Array of target objects (see below). Min 1, max 4 typical. |
| `escalationChain` | array | Yes | Array of escalation steps (see below). Min 0 (no escalation). |
| `pauseConditions` | array | No | Array of status values that pause the timer (e.g., `["awaiting-customer", "on-hold"]`). |
| `holidayCalendar` | string | No | Calendar name: `nl-feestdagen-rijksoverheid`, `be-feestdagen`, `none`, or custom tenant-defined. Default: `nl-feestdagen-rijksoverheid`. |
| `priority` | integer | No | Tie-breaker (lower = stricter). Default: 100. |
| `active` | boolean | No | Whether this policy is in effect. Default: true. |
| `validFrom` | datetime | No | Policy becomes active at this date (optional). |
| `validUntil` | datetime | No | Policy expires at this date (optional). |
| `justification` | text | No | Required reason for policy creation or edit (audit field, REQ-009). |
| `status` | string | No | Lifecycle: `draft`, `published`, `archived`. Default: `published`. |

**Schema.org mapping:** SLA policies have no direct schema.org equivalent. Stored as internal infrastructure.

##### **`targets` (object array on `sla_policy`)**

| Property | Type | Required | Description |
|---|---|---|---|
| `kind` | enum | Yes | Target type: `acknowledgement`, `firstResponse`, `resolution`, `callback`. Defines the goal state. |
| `duration` | string | Yes | ISO 8601 duration: `PT4H` (4 hours), `P1D` (1 calendar day), `P3W` (3 calendar weeks). |
| `calendar` | enum | Yes | Time mode: `24x7` (wall-clock), `business-hours` (Mon-Fri 09:00-17:00), `extended-business-hours` (Mon-Fri 08:00-18:00 + Sat 09:00-13:00). |

##### **`escalationChain` (object array on `sla_policy`)**

| Property | Type | Required | Description |
|---|---|---|---|
| `triggerAt` | decimal | Yes | Percentage of target consumed: `0.8` (80%), `1.0` (exactly), `1.5` (50% over). Escalations fire in ascending order. |
| `notify` | enum | Yes | Actor role: `assignee`, `team-lead`, `manager`, `director`, `customer`, `webhook`. |
| `channel` | enum | Yes | Delivery method: `email`, `nextcloud-notification`, `whatsapp`, `sms`, `webhook`. |

##### **`sla_breach_event`**

Immutable audit trail. One record per escalation firing.

| Property | Type | Required | Description |
|---|---|---|---|
| `id` | uuid | Yes | System-generated UUID |
| `policyId` | ref | Yes | Reference to the `sla_policy` that triggered this breach. |
| `targetObjectType` | string | Yes | Type of tracked object: `request`, `klacht`, `callback`, `return`, etc. |
| `targetObjectId` | uuid | Yes | UUID of the tracked object. |
| `targetKind` | enum | Yes | Which target fired: `acknowledgement`, `firstResponse`, `resolution`, `callback`. |
| `breachedAt` | datetime | Yes | ISO 8601 timestamp when the threshold was crossed. |
| `consumedPercentage` | decimal | Yes | Consumption at firing: `1.0` = exactly at deadline, `1.5` = 50% over. |
| `escalationLevel` | integer | Yes | Which step in the escalation chain (1-indexed). Prevents duplicate fires. |
| `notifiedActors` | array | Yes | User IDs or external addresses actually notified (e.g., `["user-123@nc", "manager@client.nl"]`). |
| `acknowledged` | boolean | No | Whether the assignee/manager acknowledged the breach (click-through). Default: false. |
| `acknowledgedAt` | datetime | No | When acknowledgement happened. |
| `acknowledgedBy` | string | No | User ID of acknowledger. |
| `resolvedAt` | datetime | No | When the underlying object reached its target state (if before deadline expiry). |

**Schema.org mapping:** Breach events are infrastructure auditing, no schema.org equivalent.

##### **Embedded `slaStatus` sub-object**

Added to `request`, `klacht`, `callback`, `return`, and any future opt-in type as a JSON column managed by the engine. NOT a separate schema.

```json
{
  "policyId": "uuid-of-resolved-policy",
  "startedAt": "2026-05-20T10:30:00Z",
  "pausedAt": "2026-05-20T14:00:00Z",
  "totalPausedMs": 3600000,
  "targets": [
    {
      "kind": "acknowledgement",
      "dueAt": "2026-05-20T14:30:00Z",
      "consumedPercentage": 0.92,
      "status": "on-track|at-risk|breached|met",
      "metAt": "2026-05-20T14:15:00Z",
      "breachEventIds": ["event-uuid-1", "event-uuid-2"]
    }
  ],
  "currentEscalationLevel": 2,
  "lastEvaluatedAt": "2026-05-20T15:00:00Z"
}
```

---

### Backend

#### `lib/Service/SlaEngineService.php`

Core business logic. Stateless; called by event listeners and scheduled jobs.

**Method: `resolvePolicyForObject(string $objectType, string $objectId, array $metadata): ?array`**

Resolves exactly one `sla_policy` at object creation time. Matches `appliesTo`, `customerTier`, `customerScope`, and tie-breaks on `priority` (lower) then `validFrom` (newest). Returns the matched policy array or null if no match (fail-safe fallback).

**Method: `computeDeadlines(array $policy, \DateTimeImmutable $startTime, ?string $customerTierId = null): array`**

Computes `dueAt` for each target in `targets[]`. Respects:
- `policy.calendar` (24x7, business-hours, extended-business-hours).
- `policy.holidayCalendar` (Dutch national holidays + tenant overrides).
- ISO 8601 duration parsing.
- Business-hours windows (configurable per tenant).
- Pause/resume state from current `slaStatus` on the tracked object.

Returns array: `[{ kind, dueAt }, ...]`.

**Method: `evaluateTargets(array $currentTargets, \DateTimeImmutable $now): array`**

For each target, compares `dueAt` against `now` and sets `status` to `on-track`, `at-risk` (>80%), `breached` (>=100%), or `met` (resolved before deadline).

**Method: `executeEscalations(string $policyId, string $objectType, string $objectId, array $newTargets, array $priorTargets): void`**

Compares prior vs. current target states. For each escalation step in the policy's `escalationChain`:
1. If `triggerAt` threshold has just been crossed and `escalationLevel` has not yet fired for this object, send notification.
2. Record the firing in `sla_breach_event`.
3. Update `slaStatus.currentEscalationLevel`.

Prevents duplicate fires per level per object.

**Method: `pauseTimer(string $objectId, array $slaStatus): array`**

Sets `pausedAt = now()` and `totalPausedMs += elapsed`. Returns updated `slaStatus`.

**Method: `resumeTimer(string $objectId, array $slaStatus): array`**

Clears `pausedAt`, extends all `targets[*].dueAt` by the paused duration (respecting business-hours rules), and recomputes target status. Returns updated `slaStatus`.

#### `lib/Listener/ObjectCreatedListener.php`

Registered on `ObjectCreatedEvent` for configured object types. Fires synchronously before API response returns (REQ-007).

1. Extract `objectType`, `objectId`, customer tier/scope metadata.
2. Call `SlaEngineService::resolvePolicyForObject()`.
3. If policy found, compute deadlines and populate `slaStatus` on the created object.
4. Call `ObjectService::saveObject()` to persist `slaStatus`.
5. If listener throws, catch and log; never block the object creation.

#### `lib/Listener/ObjectUpdatedListener.php`

Registered on `ObjectUpdatedEvent` for configured object types. Fires synchronously.

1. Extract status change from the event.
2. Check if new status is in the policy's `pauseConditions`. If so, call `SlaEngineService::pauseTimer()`.
3. If resuming from a paused status, call `SlaEngineService::resumeTimer()` and extend deadlines.
4. Call `SlaEngineService::evaluateTargets()` to refresh target status.
5. Call `SlaEngineService::executeEscalations()` if any threshold crossed.
6. Persist updated `slaStatus` via `ObjectService::saveObject()`.
7. If listener throws, log but do not block the object update.

#### `lib/Job/SlaDeadlineSweepJob.php`

Background job scheduled by the standard Nextcloud job queue. Runs every 5 minutes (configurable).

1. Query all in-flight objects with `slaStatus.targets[*].status in ['on-track', 'at-risk']` and `dueAt < now()`.
2. For each object, call `SlaEngineService::evaluateTargets()` and `executeEscalations()`.
3. Batch updates: collect all updates into a single `ObjectService::saveBatch()` call to minimize DB hits.
4. Log completion time; fail gracefully if a subset of objects cannot be updated (retry on next run).

Must complete in <60s for 10,000 in-flight objects on reference sizing.

#### Event Dispatch for Escalations

When `SlaEngineService::executeEscalations()` determines that a `notify` actor and `channel` are needed:

- **`channel: email`** → call `MailerService` with recipient from `assignee.email`, `team-lead.email`, etc. (resolved via user lookup).
- **`channel: nextcloud-notification`** → call `NotificationService::notify()` with the user ID.
- **`channel: whatsapp`, `channel: sms`** → call `OmnichanelService::preferredChannel()` to get customer contact info, then route via `WhatsAppSmsSendService` or similar.
- **`channel: webhook`** → call `WebhookService::dispatchEvent()` with the breach event as CloudEvents payload.

Every dispatch attempt is recorded in `sla_breach_event.notifiedActors[]`.

#### Admin Settings

- **SLA policy CRUD** — existing `CnDetailPage` + `CnFormDialog` auto-generated from `sla_policy` schema; edit form MUST require `justification` field (REQ-009).
- **Customer tier assignment** — separate UI under organisation detail: dropdown to select `bronze`, `silver`, `gold`, `platinum`, or null (defaults to bronze).
- **Contract-level tier override** — on contract detail (if `docudesk` scope includes contracts): same tier selector; most-specific scope wins during policy resolution.
- **Tenant holiday-calendar override** — admin settings panel: select built-in calendar or paste custom iCalendar (RFC 5545) `VEVENT` + `RRULE` for org closures.
- **Sweep job frequency** — admin settings: slider/input to set sweep interval (default 5 min, min 1 min, max 30 min).

---

### Frontend

#### SLA Policy List (CRUD)

Standard `CnIndexPage` + `CnDataTable`:
- Columns: `name`, `appliesTo`, `customerTier`, `priority`, `status`, `validFrom`, `validUntil`, actions (edit, publish, archive, delete).
- Filters: `appliesTo`, `status`, `active`.
- Detail view uses `CnDetailPage` with `CnFormDialog` for create/edit; form auto-generates from schema.
- Edit form MUST include a `justification` textarea (required for save). Before/after diff is displayed in a read-only audit section.

#### SLA Policy Detail

Shows:
- Basic info: `name`, `description`, `appliesTo`, `customerTier`, `priority`, `status`.
- `targets` array in an editable table: kind, duration, calendar.
- `escalationChain` array in an editable table: triggerAt, notify, channel.
- `pauseConditions` as a multi-select (status values that pause the timer).
- `holidayCalendar` selector.
- Audit trail sidebar tab: every edit with actor, timestamp, before/after diff, justification.

#### SLA Status on Request / Klacht / Callback Detail

Sidebar section: "Service Level Agreement"
- Resolved policy name (clickable link to policy detail).
- Target countdown table:
  - Kind | Due at (countdown clock) | Status badge (on-track / at-risk / breached / met)
- Current escalation level (e.g., "2 of 3 escalations fired").
- Escalation log: table of past `sla_breach_event` records (when, who notified, channel, acknowledged?).

#### SLA Status on List Views

Add column: `slaStatus` badge
- `on-track` → green "✓ In progres"
- `at-risk` → yellow "⚠ Dreigt" (80%+ consumed)
- `breached` → red "✗ Vertraagd" (100%+ consumed)
- `met` → teal "✓ Afgerond"

Facet sidebar: filter by `slaStatus` value.

#### Attainment Dashboard

Widget (integrated into `launchpad` or standalone):
- **KPI card**: "SLA Attainment Q2 2026: 92% ✓" — tappable to drilldown.
- **Drilldown table**:
  - Rows: by policy, or by customer, or by team (selector).
  - Columns: policy name, total targets due, met, breached, % attainment.
  - Breakdown: in-flight (breached but not yet resolved) vs. closed-and-breached (resolved after deadline).
- **Time bucket picker**: day, week, month, quarter.
- **Export button**: download as CSV.

---

### Integration Points

| System | Role | Integration Type |
|---|---|---|
| `request-management` | Primary consumer | Emits `ObjectCreatedEvent` / `ObjectUpdatedEvent`; `slaStatus` embedded on `request`; SLA badge in lists; policy feeds assignment-rules. |
| `complaint-management` (klachten) | Primary consumer | Same listener pattern; 72u datalek-notification is a baseline policy. |
| `callback-management` | Primary consumer | Callbacks use `target.kind: callback`; default "within 1 business day". |
| `whatsapp-sms-channel-adapter` | Escalation channel | Engine calls `OmnichanelService` for customer contact; adapter sends messages. |
| `omnichannel-registratie` | Contact preference lookup | Customer's preferred channel from omnichannel profile; escalations respect it. |
| `openconnector` | Webhook escalations | Engine dispatches via openconnector source rows with retry/log. |
| `openregister` | Core platform | `ObjectService` for CRUD; `WebhookService` for webhook dispatch; background job framework; event dispatcher. |
| `launchpad` | Reporting UI | Attainment endpoints feed SLA KPI tiles. |
| `docudesk` | Contract-level tier | Contract documents carry `slaTierOverride` annotation; engine reads during policy resolution. |
| Nextcloud built-ins | Notifications | `IAppConfig`, `NotificationService`, `IEventDispatcher`, `MailerService`. |

---

### Reuse Analysis

| Capability | OpenRegister / Nextcloud Component | Custom Code Needed? |
|---|---|---|
| Policy CRUD | `CnIndexPage` + `CnFormDialog` (schema-driven) | No; auto-generated from schema. |
| Policy versioning & audit | `AuditTrailService` (automatic on `ObjectService::saveObject()`) | No; built-in. |
| Event dispatch to escalation actors | `NotificationService`, `MailerService`, `WebhookService` | Mapping layer only (who to notify, which channel). |
| Holiday calendar data | JSON file (bundled); RFC 5545 `VEVENT` + `RRULE` support | Custom holiday-calendar JSON schema + load logic. |
| Business-hours computation | — | Custom (ISO 8601 duration + calendar-aware math). |
| Scheduled deadline sweep | Nextcloud `IJobList` / background job framework | Custom job logic; standard infrastructure. |
| SLA status on tracked objects | OpenRegister JSON column (`slaStatus` on request/klacht/callback) | Custom sub-object schema + engine logic. |
| Embedded status display in list/detail | `CnDataTable` column, `CnDetailCard` sections | Custom badge rendering + countdown clock. |
| Attainment reporting | Aggregation endpoint | Custom endpoint + Solr query builder. |

No duplication found. All custom code is domain-specific (SLA policy matching, deadline math, escalation logic) or infrastructure-specific (holiday calendar loading, pause/resume timing). Standard platform capabilities (event dispatch, audit trails, CRUD UI, scheduled jobs) are leveraged without rebuilding.

---

### i18n

All user-facing strings are translatable (Dutch + English). Follows ADR-007 (sentence case, English as key string).

| Key | English | Dutch |
|---|---|---|
| `SLA target reached` | `SLA target reached` | `SLA-doel bereikt` |
| `SLA at risk` | `SLA at risk (over 80%)` | `SLA onder druk (meer dan 80%)` |
| `SLA breached` | `SLA breached` | `SLA overschreden` |
| `SLA met` | `SLA met` | `SLA behaald` |
| `Acknowledgement` | `Acknowledgement` | `Bevestiging` |
| `First response` | `First response` | `Eerste reactie` |
| `Resolution` | `Resolution` | `Oplossing` |
| `Callback` | `Callback` | `Terugbelverzoek` |
| `Service level agreement` | `Service Level Agreement` | `Serviceniveauovereenkomst` |
| `Policy` | `Policy` | `Beleid` |
| `Customer tier` | `Customer tier` | `Klantenniveau` |
| `Bronze` | `Bronze` | `Brons` |
| `Silver` | `Silver` | `Zilver` |
| `Gold` | `Gold` | `Goud` |
| `Platinum` | `Platinum` | `Platina` |
| `Pause conditions` | `Pause conditions` | `Pauzevoorwaarden` |
| `Holiday calendar` | `Holiday calendar` | `Feestdagenkalender` |
| `Attainment` | `Attainment` | `Behaald percentage` |
| `Breach event` | `Breach event` | `Inbreukgebeurtenis` |
| `Escalation chain` | `Escalation chain` | `Escalatieketen` |

---

## Seed Data

### 1. `sla_policy` — Standaard request-SLA (baseline, all tiers)

```json
{
  "@self": {
    "register": "sla",
    "schema": "sla_policy",
    "slug": "policy-standaard-request"
  },
  "name": "Standaard request-SLA",
  "description": "Baseline SLA for all customer request types; applies when no tier-specific policy is configured.",
  "appliesTo": "request",
  "customerTier": "*",
  "customerScope": null,
  "targets": [
    {
      "kind": "acknowledgement",
      "duration": "PT4H",
      "calendar": "business-hours"
    },
    {
      "kind": "firstResponse",
      "duration": "P1D",
      "calendar": "business-hours"
    },
    {
      "kind": "resolution",
      "duration": "P3W",
      "calendar": "business-hours"
    }
  ],
  "escalationChain": [
    {
      "triggerAt": 0.8,
      "notify": "assignee",
      "channel": "nextcloud-notification"
    },
    {
      "triggerAt": 1.0,
      "notify": "team-lead",
      "channel": "email"
    },
    {
      "triggerAt": 1.5,
      "notify": "manager",
      "channel": "email"
    }
  ],
  "pauseConditions": ["awaiting-customer", "on-hold"],
  "holidayCalendar": "nl-feestdagen-rijksoverheid",
  "priority": 100,
  "active": true,
  "validFrom": null,
  "validUntil": null,
  "justification": "Baseline policy matching standard Dutch MKB service-desk hours and escalation.",
  "status": "published"
}
```

### 2. `sla_policy` — Goud-tier klant-SLA (gold customers only)

```json
{
  "@self": {
    "register": "sla",
    "schema": "sla_policy",
    "slug": "policy-goud-tier-request"
  },
  "name": "Goud-tier klant-SLA",
  "description": "Stricter SLA for gold-tier customers: 2-hour acknowledgement, same-day response, 10-day resolution.",
  "appliesTo": "request",
  "customerTier": "gold",
  "customerScope": null,
  "targets": [
    {
      "kind": "acknowledgement",
      "duration": "PT2H",
      "calendar": "business-hours"
    },
    {
      "kind": "firstResponse",
      "duration": "P1D",
      "calendar": "business-hours"
    },
    {
      "kind": "resolution",
      "duration": "P10D",
      "calendar": "business-hours"
    }
  ],
  "escalationChain": [
    {
      "triggerAt": 0.75,
      "notify": "assignee",
      "channel": "nextcloud-notification"
    },
    {
      "triggerAt": 0.9,
      "notify": "team-lead",
      "channel": "email"
    },
    {
      "triggerAt": 1.0,
      "notify": "manager",
      "channel": "email"
    },
    {
      "triggerAt": 1.2,
      "notify": "director",
      "channel": "email"
    }
  ],
  "pauseConditions": ["awaiting-customer", "on-hold"],
  "holidayCalendar": "nl-feestdagen-rijksoverheid",
  "priority": 10,
  "active": true,
  "validFrom": null,
  "validUntil": null,
  "justification": "Premium SLA for contracted gold-tier customers; tighter targets and more escalation steps.",
  "status": "published"
}
```

### 3. `sla_policy` — AVG datalek-notification klacht (complaints, baseline)

```json
{
  "@self": {
    "register": "sla",
    "schema": "sla_policy",
    "slug": "policy-avg-datalek-klacht"
  },
  "name": "AVG artikel 33 — 72-uur datalekmeldingplicht",
  "description": "Mandatory GDPR/AVG 72-hour data-breach notification deadline for complaints linked to privacy incidents.",
  "appliesTo": "klacht",
  "customerTier": "*",
  "customerScope": null,
  "targets": [
    {
      "kind": "acknowledgement",
      "duration": "PT24H",
      "calendar": "24x7"
    },
    {
      "kind": "resolution",
      "duration": "PT72H",
      "calendar": "24x7"
    }
  ],
  "escalationChain": [
    {
      "triggerAt": 0.5,
      "notify": "manager",
      "channel": "email"
    },
    {
      "triggerAt": 0.75,
      "notify": "director",
      "channel": "email"
    },
    {
      "triggerAt": 1.0,
      "notify": "customer",
      "channel": "email"
    }
  ],
  "pauseConditions": [],
  "holidayCalendar": "none",
  "priority": 5,
  "active": true,
  "validFrom": null,
  "validUntil": null,
  "justification": "Regulatory requirement: AVG Art. 33 mandates 72-hour notification for all personal data breaches.",
  "status": "published"
}
```

### 4. `sla_policy` — Callback-default (callbacks, baseline)

```json
{
  "@self": {
    "register": "sla",
    "schema": "sla_policy",
    "slug": "policy-callback-default"
  },
  "name": "Standaard terugbel-SLA",
  "description": "Baseline SLA for callback requests: deliver within one business day.",
  "appliesTo": "callback",
  "customerTier": "*",
  "customerScope": null,
  "targets": [
    {
      "kind": "callback",
      "duration": "P1D",
      "calendar": "business-hours"
    }
  ],
  "escalationChain": [
    {
      "triggerAt": 0.8,
      "notify": "assignee",
      "channel": "nextcloud-notification"
    },
    {
      "triggerAt": 1.0,
      "notify": "team-lead",
      "channel": "email"
    }
  ],
  "pauseConditions": ["customer-unavailable", "scheduled-later"],
  "holidayCalendar": "nl-feestdagen-rijksoverheid",
  "priority": 50,
  "active": true,
  "validFrom": null,
  "validUntil": null,
  "justification": "Standard callback commitment: within 1 business day.",
  "status": "published"
}
```

### 5. `sla_breach_event` — Example: request breach at 100%

```json
{
  "@self": {
    "register": "sla",
    "schema": "sla_breach_event",
    "slug": "breach-event-req-20260520-001"
  },
  "policyId": "UUID of 'Standaard request-SLA'",
  "targetObjectType": "request",
  "targetObjectId": "UUID of a sample request",
  "targetKind": "acknowledgement",
  "breachedAt": "2026-05-20T14:30:00Z",
  "consumedPercentage": 1.0,
  "escalationLevel": 2,
  "notifiedActors": ["team-lead-user-id", "team-lead@example.nl"],
  "acknowledged": true,
  "acknowledgedAt": "2026-05-20T15:00:00Z",
  "acknowledgedBy": "team-lead-user-id",
  "resolvedAt": "2026-05-20T16:45:00Z"
}
```

---

## Files Changed

### New Files

| File | Purpose |
|---|---|
| `lib/Service/SlaEngineService.php` | Core engine: policy resolution, deadline computation, target evaluation, escalation execution. |
| `lib/Listener/ObjectCreatedListener.php` | Event listener for `ObjectCreatedEvent`; initializes `slaStatus` on new objects. |
| `lib/Listener/ObjectUpdatedListener.php` | Event listener for `ObjectUpdatedEvent`; pause/resume, re-evaluate targets, execute escalations. |
| `lib/Job/SlaDeadlineSweepJob.php` | Scheduled job; detects and escalates breached deadlines. |
| `lib/Settings/sla-engine_register.json` | OpenRegister template: schemas, seed data, baseline policies. |
| `specs/sla-engine-and-escalation/spec.md` | Formal requirements and BDD scenarios. |

### Modified Files

| File | Change |
|---|---|
| `lib/AppInfo/Application.php` | Register `ObjectCreatedListener` and `ObjectUpdatedListener` for configured object types. |
| `lib/Settings/pipelinq_register.json` (if SLA is bundled with pipelinq) | Add `slaStatus` JSON column to `request`, `klacht`, `callback` schemas. |
| Respective app settings (if SLA spans multiple apps) | Add `slaStatus` JSON column to tracked object schemas. |
| Admin settings panel | Add holiday-calendar override selector and sweep-job frequency config. |
| `l10n/en.json` | Add 30+ translation keys. |
| `l10n/nl.json` | Add Dutch translations. |

---

## Schema.org and Dutch API Mapping

Per ADR-001 (International First):

- SLA policies and breach events have no schema.org equivalents; stored as internal infrastructure metadata.
- Dutch government API mappings (if applicable, e.g., for VNG Klantinteracties SLA export) will be implemented in a separate mapping layer. No Dutch-specific field names are used in the core schema.
- The `slaStatus` sub-object embedded on tracked objects is implementation detail; not exposed in external API responses unless explicitly mapped.


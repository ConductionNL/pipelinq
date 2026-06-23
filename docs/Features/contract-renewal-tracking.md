# Contract & Renewal Tracking

**Status:** Implemented

## Overview

Pipelinq models recurring revenue as first-class `contract` objects on top of the
existing clients, pipeline, product catalog, My Work and OpenRegister-notification
building blocks. A contract records a client, line items, a billing interval and
value, start/end dates, an auto-renew flag and a notice period, and moves through
a guarded lifecycle. A nightly engine detects contracts approaching renewal,
creates renewal leads in the existing pipeline, and rolls the outcome back into
renewed/churned states — driving MRR/ARR and churn visibility.

## Key Capabilities

- **Contract schema** (`contract`) — contractNumber, clientRef, lineItems[],
  billingInterval (`monthly`/`quarterly`/`annual`/`one-off`), valuePerInterval,
  start/end dates, autoRenew, noticePeriodDays, lifecycle status, ownerId,
  renewalLeadRef, predecessorContractRef. The `contractNumber`/`startDate`/
  `endDate`/`value`/`status` fields are read by the customer portal's contract
  reader without mapping.
- **Guarded lifecycle** — `draft → active → expiring → renewed | churned`,
  plus `cancelled`. `renewed` requires a won renewal lead; `expiring` is set only
  by the renewal engine; `cancelled` requires a reason; terminal states are
  immutable. Contract numbers are auto-generated `C-{year}-{seq}` and unique.
- **Renewal-window detection** — a nightly `RenewalWindowJob` flips `active`
  contracts to `expiring` when today reaches `endDate − max(noticePeriodDays,
  configured default lead time, 60)`. Idempotent across runs.
- **Renewal-lead automation** — entering the window creates exactly one renewal
  lead in the existing pipeline (title `Renewal: {title}`, annualized value,
  client, owner, `renewal` tag) linked via `renewalLeadRef`. A won lead marks the
  contract `renewed` and drafts a successor (start = predecessor end + 1 day); a
  lost lead or a silently-passed end date marks it `churned`.
- **Reminders** — the `active → expiring` transition fires an OpenRegister
  notification to the owner via the canonical x-openregister-notifications schema
  rule (ADR-031, declarative — no imperative dispatch). At the notice deadline an
  expiring contract creates a My Work entry for the owner (auto-renew aware copy).
- **Recurring-revenue roll-up** — MRR normalizes intervals to monthly
  (monthly = value, quarterly = value/3, annual = value/12, **one-off excluded**),
  counting only `active` + `expiring` contracts; ARR = MRR × 12. Per-client MRR,
  per-period renewal rate (renewed ÷ (renewed + churned)) and churned MRR are
  exposed to the dashboard (MRR KPI card + Renewals-due widget) and the metrics
  endpoints.

## MRR / ARR definitions

| Billing interval | Monthly contribution |
|------------------|----------------------|
| monthly          | value                |
| quarterly        | value / 3            |
| annual           | value / 12           |
| one-off          | 0 (excluded)         |

ARR = MRR × 12. A €750/month + €3,000/quarter + €12,000/year mix yields
MRR €2,750 and ARR €33,000; a €5,000 one-off contributes nothing.

## Renewal lifecycle

```
active ──(window opens)──► expiring ──(lead won)──► renewed ──► [successor draft]
                              │
                              ├──(lead lost)──────► churned
                              └──(end date passes)─► churned
```

## Recommendations

- Set an explicit `endDate` even for indefinite contracts so the renewal engine
  can act; an open-ended contract is never detected as approaching renewal.

## Architecture

- Backend: `ContractService` (guards, numbering, successor draft),
  `RenewalEngineService` (window detection, lead automation, reconciliation),
  `RecurringRevenueService` (MRR/ARR/churn aggregation), `RenewalWindowJob`
  (nightly `TimedJob`), `ContractController` (create + guarded transition +
  metrics; per-object IDOR authorization; ADR-022 — no CRUD pass-throughs).
- Frontend: declarative `contract` index/detail pages + nav (manifest fragment).
  The dashboard recurring-revenue tile is a declarative `type: "stat"` widget that
  reads the recurring **run-rate** (`SUM(maandWaarde)` over shillinq's
  `SalesOrderLine` where `nature == "RECURRING"`) directly from OpenRegister — see
  [recurring-revenue.md](../recurring-revenue.md). The former bespoke
  `MrrKpiWidget` / `RenewalsDueWidget` widgets and the `recurringRevenue.js`
  normalization helper were retired in favour of this cross-app aggregation.
- Spec: `openspec/specs/contract-renewal-tracking/spec.md`.

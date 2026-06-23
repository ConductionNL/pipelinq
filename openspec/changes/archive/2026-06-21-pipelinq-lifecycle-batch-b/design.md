# Design — Pipelinq Lifecycle Batch B (money / higher-risk)

Builds on Batch A's pattern (`allowedTransitions` → `x-openregister-lifecycle`,
read via `SchemaLifecycleGraph`). See the archived Batch A design for the OR
declaration shape and enforcement contract. This document records the
**per-candidate decisions** that are specific to the money paths.

## Per-candidate plan

| Service | Schema (file) | Graph source | Stays in PHP | OR-enforced? |
|---|---|---|---|---|
| ContractService | contract (`register.d/96-contract-renewal.json`, ADD) | schema `x-openregister-lifecycle` | `expiring`-engine-only, `renewed`-requires-won-lead, `cancelled`-requires-reason, successor draft | yes (status) |
| BookingService | booking (`register.d/45-appointment-booking.json`, ADD) | schema `x-openregister-lifecycle` | statusHistory append, cancellation-policy charge, no-show counter, cache invalidation | yes (status) |
| ForecastDealService | lead (`register.d/50-forecast.json`, ADD partition) | schema `x-pipelinq-forecast-lifecycle` (app-namespaced) | the entire revert-after-write enforcement (closed-lock, justification-threshold, reopen-reset) | **no** (see below) |

## Contract: preserve reachability, not invent a tighter graph

`ContractService::assertTransitionAllowed()` does **not** enforce a from→to
adjacency today — it enforces `VALID_STATES` membership + terminal immutability +
three conditional rules. From any non-terminal state (`draft`/`active`/`expiring`)
every valid target is reachable, gated only by the conditionals. So the schema
graph is declared as an **exact mirror of that reachability** (each non-terminal
source → every valid target). Adding the graph therefore opens no new edge and
closes no existing one; it only relocates the **terminal set** and the
**adjacency** into the schema. The terminal set is now read from
`x-openregister-lifecycle.terminal`; the conditional rules stay in PHP (OR's
declarative grammar cannot express "engine-only", "requires a won lead", or
"requires a cancellationReason"). A PHP adjacency mirror runs after the
conditionals as defense matching OR; it is a no-op for same-value transitions and
when the declaration is unreadable, so it never regresses.

## Booking: static map → schema, audit trail stays

`BookingService::allowedTransitions()` was a hardcoded `static` adjacency map with
no external callers, so it is converted in place to derive from the schema via
`SchemaLifecycleGraph::fullAdjacencyFor()` (seeds every enum state as a key so the
"Unknown source status" vs "Invalid status transition" messages are preserved),
with the prior map kept verbatim as `FALLBACK_TRANSITIONS`. The `statusHistory`
append (ADR-005 audit trail) is **left in PHP** — migrating the audit trail is a
later seam; it is not a transition-graph concern.

## ForecastDeal: the revert-after-write + single-lifecycle-field decision

**Finding (load-bearing):** OpenRegister supports exactly **one** enforced
lifecycle field per schema — `x-openregister-lifecycle.field` is singular, and
`LifecycleValidationListener` reads that one field. The lead schema **already**
declares `x-openregister-lifecycle` on `status` (open→won/lost). The
`forecast_category` machine is a **second, independent** state machine on the same
schema. OR therefore **cannot host or enforce** it; declaring a second
`x-openregister-lifecycle` keyed on `forecast_category` is impossible without
displacing the `status` lifecycle.

Furthermore the forecast rules are **not** a pure from→to graph: closed→open lock,
threshold-gated justification, and status-driven reopen-reset are conditional
predicates OR's grammar cannot express. And the listeners use a deliberate
**revert-after-write** pattern: OR dispatches `ObjectCreated`/`ObjectUpdated`
*after* the write, so `DealUpdatedListener` re-saves the prior `forecast_category`
to "reject" an illegal change. Converting this to pre-write validation would
require OR's lifecycle/guard mechanism — which is unavailable for this second
field — and would change the observable behavior on a revenue path.

**Decision:** KEEP the `ForecastDealService` + listener logic in PHP exactly as-is
(revert-after-write preserved). Move only the **source of truth** for the
open/closed partition and the default into the schema, under the app-namespaced
`x-pipelinq-forecast-lifecycle` key, read by `SchemaLifecycleGraph::configurationFor()`
with the prior constants as a never-regress fallback. `isClosedCategory()`,
`validateTransition()`, `applyDefaultCategory()` and `applyReopenReset()` now read
the partition/default from the schema; their decisions and the listeners'
revert-after-write are byte-for-byte unchanged.

### DealCreated default (audit item #2)

OpenRegister applies a schema property's `default` on create (SaveObject sets
`property.title = key`, then applies the default when the value is missing/null —
or, with `defaultBehavior: "falsy"`, also when it is an empty string). The lead
schema's `forecast_category` already declared `default: "pipeline"`; this change
adds `defaultBehavior: "falsy"` so OR's default exactly matches the listener's
prior `is_string && !== ''` trigger. With that, OR sets the default during the
create save, so `DealCreatedListener` runs *after* and finds the value already
set (idempotent → no re-save). The listener is **retained as a backstop** rather
than deleted: it is one cheap idempotent check and guarantees the default on any
path that bypasses the schema-default application, while the schema annotation is
now the declared source of truth (read by `ForecastDealService`). This is the
"keep + note" choice the task allows, made because deleting it would rely solely
on the `title`-keyed default quirk on a money path.

## Live verification (per-candidate, explicit)

- **Contract** — live-verified on :8080. PHP guard: legal `active→expiring(engine)`
  + `expiring→renewed(won)` accepted; illegal `churned→active` (terminal,
  schema-derived) + `active→renewed(no-won)` (PHP conditional) rejected with the
  unchanged messages. OR listener defense-in-depth: a real contract driven through
  `saveObject()` accepted `active→expiring`, then `renewed→active` was REJECTED by
  `LifecycleValidationListener` (`No transition allows moving "status" from
  "renewed" to "active"`).
- **Booking** — PHP guard live-verified on :8080 (legal `pending-deposit→confirmed`
  accepted; illegal `confirmed→pending-deposit` + terminal `completed→confirmed`
  rejected with unchanged "Invalid status transition" message). OR listener uses
  the identical code path with the same persisted booking transition graph.
- **Forecast** — unit-verified (schema-sourced partition + fallback). Enforced in
  PHP by design; no OR-listener leg exists for this second field, by the
  single-lifecycle-field constraint above.

## ADRs

- ADR-031 declarative-first (schema-declared state machines).
- ADR-022 apps consume OR abstractions rather than re-deriving them.

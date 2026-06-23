---
kind: code
---

## Why

This is **Seam 2, Batch B** — the money / higher-risk status state-machines, the
follow-up to Batch A (the simpler, non-money machines). Same goal: the object
status transition graph becomes the schema's declarative
`x-openregister-lifecycle` annotation (single source of truth, ADR-031), which
OpenRegister's `LifecycleValidationListener` enforces automatically on every
`ObjectService::saveObject()` (ADR-022). Each service keeps a thin PHP guard
whose *source of truth* is now the schema declaration (read via the Batch-A
`SchemaLifecycleGraph` helper) rather than a hardcoded constant — preserving the
exact exception type, message, HTTP status, and pre-save side-effects seen today.

Because these are revenue / booking paths, this batch is **strictly
behavior-preserving**: no transition that is legal today becomes illegal, and no
transition that is illegal today becomes legal.

### The enforcement contract (why a thin guard stays)

`LifecycleValidationListener` subscribes to `ObjectUpdatingEvent` (dispatched by
`ObjectService::saveObject()`), finds a transition whose `to` equals the new
value AND whose `from` contains the old value, and rejects otherwise (structured
error → HTTP 422/403). Enforcement is automatic on save, but the rejection
envelope differs from each app's `InvalidArgumentException` contract, and some
validation happens *before* `saveObject()` is reached. So the PHP guard stays;
its source of truth moves into the schema. OR's listener remains as
defense-in-depth at the persistence boundary — "declared once, enforced twice".

## What Changes

- **ContractService** (contract schema, `status`): a new `x-openregister-lifecycle`
  is added to the contract schema declaring the **exact reachability the service
  permits today** (from any non-terminal `draft`/`active`/`expiring` to every
  valid target; `renewed`/`churned`/`cancelled` terminal). `assertTransitionAllowed()`
  now derives its terminal set + adjacency from that declaration. The conditional
  guards that OR cannot express stay in PHP and are documented:
  `expiring`-engine-only, `renewed`-requires-won-lead, `cancelled`-requires-reason.
  RenewalEngineService and ContractController are unchanged callers (they go
  through the same guard).
- **BookingService** (booking schema, `status`): a new `x-openregister-lifecycle`
  is added (pending-deposit→confirmed/cancelled-*/rescheduled,
  confirmed→completed/no-show/cancelled-*/rescheduled; the five terminal states).
  `allowedTransitions()` is derived from it (was a hardcoded static map). The
  `statusHistory` audit-trail append stays in PHP (a later audit-seam migration).
- **ForecastDealService** (lead schema, `forecast_category`): the open/closed
  category partition and the create-default move to a **pipelinq-namespaced**
  `configuration.x-pipelinq-forecast-lifecycle` annotation on the lead schema,
  read by the service via the helper. This is a **second** state machine on the
  lead schema; OpenRegister's `x-openregister-lifecycle` already owns the `status`
  field and supports only **one** enforced lifecycle field per schema, so the
  forecast machine is enforced **in PHP** (the deal listeners' revert-after-write),
  NOT by OR's listener — see design for the decision. The create-default is ALSO
  declared as `forecast_category.default = "pipeline"` with `defaultBehavior:
  "falsy"`, which OpenRegister applies on create; `DealCreatedListener` is retained
  as an idempotent backstop.

The Batch-A `SchemaLifecycleGraph` helper gains a generic `configurationFor(slug,
key)` reader so the forecast machine can source its partition from the
app-namespaced annotation, with the same safe fallback to the prior constants.

## Impact

- Affected code: `lib/Service/ContractService.php`, `lib/Service/BookingService.php`,
  `lib/Service/ForecastDealService.php`, `lib/Service/Lifecycle/SchemaLifecycleGraph.php`
  (additive `configurationFor`), `lib/Listener/DealCreatedListener.php` (doc only),
  `lib/Settings/register.d/96-contract-renewal.json` (contract lifecycle),
  `lib/Settings/register.d/45-appointment-booking.json` (booking lifecycle),
  `lib/Settings/register.d/50-forecast.json` (forecast partition + falsy default).
- Behavior preserved: identical allowed/denied transition sets, identical error
  messages and exception types, identical side-effects (renewal successor draft,
  statusHistory, AvailabilityCache invalidation, forecast revert-after-write).
- Net: the transition graph is declared once (schema), enforced twice for the
  contract + booking money paths (OR listener on save + thin PHP guard); the
  forecast machine's source-of-truth moves to the schema while enforcement stays
  in PHP because OR cannot host a second lifecycle field.

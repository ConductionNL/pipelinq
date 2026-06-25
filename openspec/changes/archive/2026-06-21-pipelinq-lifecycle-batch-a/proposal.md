---
kind: code
---

## Why

Several pipelinq services hand-roll their object status state-machines: a
PHP-local adjacency map (`ALLOWED_TRANSITIONS` / `allowedTransitions()`) plus a
guard method that throws when a transition is not in the map. OpenRegister now
owns a **declarative** state-machine facility — `x-openregister-lifecycle` on the
schema — which the `LifecycleValidationListener` enforces automatically on every
`ObjectService::saveObject()`. Maintaining a second, divergence-prone copy of the
transition graph in PHP contradicts ADR-031 (declarative-first) and ADR-022
(apps consume OR abstractions rather than re-deriving them).

This is **Seam 2, Batch A** — the simpler, non-money state machines. It is a
behavior-preserving refactor: the transition graph becomes the schema's
`x-openregister-lifecycle` declaration (single source of truth), OR enforces it
on save, and each service keeps a thin PHP guard that **reads the allowed graph
from the schema declaration** instead of from a hardcoded constant — so the exact
error message, exception type, and HTTP status seen today are preserved.

### The enforcement contract (why a thin guard stays)

OR's `LifecycleValidationListener` subscribes to `ObjectUpdatingEvent`, which is
dispatched by `ObjectService::saveObject()`. When the lifecycle field changes to
a value no declared transition allows, it stops the event with a structured error
(surfaced as HTTP 422 / 403). Enforcement is therefore **automatic on save** — but
only for mutations that go through `saveObject()`, and the rejection shape differs
from each app's current `InvalidArgumentException` / `{valid,reason}` contract.
Removing the PHP guard outright would (a) change the error envelope callers and
tests depend on, and (b) drop enforcement for any pre-save validation the
controller does before it ever reaches `saveObject()`. So the PHP guard stays —
but its *source of truth* moves into the schema. OR's listener remains as
defense-in-depth at the persistence boundary.

## What Changes

- **CallbackService** (Task schema, `status`): the Task schema already declares
  `x-openregister-lifecycle` (open→in_behandeling→afgerond/verlopen, reopen). The
  `ALLOWED_TRANSITIONS` constant is replaced by a derivation from that declaration.
- **WalkInQueueService** (walkInTicket schema, `status`): a new
  `x-openregister-lifecycle` is added to the walkInTicket schema
  (waiting→called/abandoned, called→served/abandoned; served/abandoned terminal).
  `allowedTransitions()` is derived from it.
- **LoyaltyProgrammeService** (loyaltyProgramme schema, `status`): a new
  `x-openregister-lifecycle` is added (concept→actief, actief↔gepauzeerd,
  actief/gepauzeerd→beeindigd). The `concept→actief` graph edge moves to the
  schema; the activation **guard** (`validateForActivation`: date-range coherence,
  ≥1 points rule, ≥1 redemption option) **stays in PHP** — these predicates
  cannot be expressed in the declarative lifecycle grammar.

A small shared helper reads the transition graph from the bundled register JSON
(the schema source of truth shipped with the app) so the three services derive
their adjacency map from one place, with a safe fallback to the prior hardcoded
graph if the declaration is unreadable.

## Impact

- Affected code: `lib/Service/CallbackService.php`,
  `lib/Service/WalkInQueueService.php`, `lib/Service/LoyaltyProgrammeService.php`,
  a new `lib/Service/Lifecycle/SchemaLifecycleGraph.php` helper,
  `lib/Settings/pipelinq_register.json` (no change — already declared),
  `lib/Settings/register.d/45-appointment-booking.json` (walkInTicket lifecycle),
  `lib/Settings/register.d/70-loyalty-program.json` (loyaltyProgramme lifecycle).
- Behavior preserved: identical allowed/denied transition sets, identical error
  messages and exception types, identical side-effects (timestamps, notifications).
- Net: the transition graph is declared once (schema), enforced twice (OR listener
  on save + thin PHP guard for the existing error contract).

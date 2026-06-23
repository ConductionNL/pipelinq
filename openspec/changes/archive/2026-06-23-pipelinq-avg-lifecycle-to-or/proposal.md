---
kind: code
---

## Why

The `avgVerzoek` (AVG/GDPR data-subject request) schema carries a nine-state
`status` machine, but `AvgRequestService::update()` validated status moves
**ad-hoc**: a read-only check (a request in `afgerond`/`gearchiveerd` may not be
edited) plus an allowed-FIELDS list through which `status` flowed unconstrained.
There was no declarative source of truth for the transition graph, contradicting
ADR-031 (declarative-first) and ADR-022 (apps consume OR abstractions rather than
re-deriving them).

OpenRegister owns a declarative state-machine facility —
`x-openregister-lifecycle` on the schema, enforced by `LifecycleValidationListener`
on every `ObjectService::saveObject()`. This is Step A of the AVG assessment: move
the status-transition GRAPH validation onto the OR mechanic (the same pattern
Seam-2 used for CallbackService / WalkInQueueService / LoyaltyProgrammeService),
while keeping the side-effecting legal computations in PHP.

### The enforcement contract (why a thin guard stays)

OR's listener rejects an illegal lifecycle move at the persistence boundary, but
its rejection envelope differs from the app's `OCSBadRequestException` (HTTP 400)
contract that controllers and tests depend on, and it only fires once the object
reaches `saveObject()`. So a thin PHP guard stays in `update()` — but its *source
of truth* moves into the schema: it reads the allowed graph from the avgVerzoek
`x-openregister-lifecycle` declaration via `SchemaLifecycleGraph` (the Seam-2
helper) instead of a hardcoded constant. Declared once in the schema, enforced
twice — PHP guard preserves the exact error contract, OR listener is
defense-in-depth.

### Why a full OR "AVG capability" is NOT worth building

The AVG assessment concluded that a generic, OR-native AVG/GDPR module is not
worth the investment: the AVG workflow has a **single consumer** (pipelinq), its
value is **legal IP** (article classification, art. 23 grounds, retention
guidance) rather than a reusable data primitive, and the legal computations are
inherently app-specific. Only the mechanically-reusable part — the declarative
status state machine — is adopted from OR. Everything legal stays in pipelinq PHP.

## What Changes

- **avgVerzoek schema** (`lib/Settings/register.d/40-avg-verzoeken.json`): a new
  `configuration.x-openregister-lifecycle` is added — `field: status`,
  `initial: ingediend`, `final: [afgerond, gearchiveerd]`, and a transition map
  that **mirrors the current permit set exactly**: each of the seven working
  states (ingediend, in-behandeling, bewijs-verzamelen, redactie,
  bundle-genereren, wachten-op-verzoeker, weigering-opgesteld) may move to ANY of
  the nine states; the two terminal states have no outgoing transitions. No edge
  is opened or closed relative to today.
- **AvgRequestService::update()**: the status-transition GRAPH validation is
  derived from the schema declaration via `SchemaLifecycleGraph::fullAdjacencyFor`
  (with a mirrored fallback constant). The same illegal transition is rejected
  with the same `OCSBadRequestException`. The pre-existing read-only check
  (afgerond/gearchiveerd) is kept verbatim so its exact message is preserved.
- **Stays in PHP (unchanged):** intake reference + legal-deadline computation
  (`wettelijkeTermijnVerloopt`), the archive `retentieTot` stamp, the
  retention-guarded delete + DPO override, and the allowed-FIELDS enforcement on
  update. These are side-effecting legal computations the declarative grammar
  cannot express.

### RBAC note (no per-transition `authorization` added)

Today no individual `status` edge is role-gated; *who* may move a request is
gated once by `AvgAccessService::canEdit` (assigned handler / team lead / admin),
not per transition. Adding OR's declarative per-transition `authorization` list
would CLOSE edges that are open today (changing the contract), so it is
deliberately NOT added. The handler/teamlead/FG-DPO/admin gating stays in PHP
`canEdit`/`isDpo`, exactly as before.

## Impact

- Affected code: `lib/Service/Avg/AvgRequestService.php`,
  `lib/Settings/register.d/40-avg-verzoeken.json`,
  `tests/Unit/Service/Avg/AvgRequestServiceTest.php`.
- Behavior preserved: identical allowed/denied transition sets, identical
  exception type (`OCSBadRequestException`), identical messages, identical
  side-effects.
- Net: the avgVerzoek transition graph is declared once (schema), enforced twice
  (OR listener on save + thin PHP guard for the existing error contract).

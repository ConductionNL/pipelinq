# Design — AVG status lifecycle to OR declarative mechanic

## What moved to the schema (declarative)

The `avgVerzoek.status` transition GRAPH now lives in the schema's
`configuration.x-openregister-lifecycle` annotation
(`lib/Settings/register.d/40-avg-verzoeken.json`):

```jsonc
"x-openregister-lifecycle": {
  "field": "status",
  "initial": "ingediend",
  "final": ["afgerond", "gearchiveerd"],
  "transitions": { /* one action per target state */ }
}
```

The transition map mirrors the **current** permit set EXACTLY. Before this change
`AvgRequestService::update()` let `status` flow through its allowed-FIELDS list
unconstrained from any non-terminal state, while terminal states
(`afgerond`/`gearchiveerd`) were read-only. So the declared graph is:

| from (7 working states)                                                                           | to                        |
|---------------------------------------------------------------------------------------------------|---------------------------|
| ingediend, in-behandeling, bewijs-verzamelen, redactie, bundle-genereren, wachten-op-verzoeker, weigering-opgesteld | any of the 9 enum states |
| afgerond, gearchiveerd                                                                             | (none — terminal/read-only) |

No edge is opened or closed. The annotation is shape-checked by OR's
`LifecycleAnnotationValidator` at schema-save and enforced by
`LifecycleValidationListener` on every `ObjectService::saveObject()` (HTTP
422/403 at the persistence boundary) — defense-in-depth.

## What stayed in PHP (and why)

Only the status-transition GRAPH validation moved. The side-effecting legal
computations cannot be expressed in the declarative lifecycle grammar and remain
in `AvgRequestService`:

- **Intake reference + legal deadline** (`generateReference`, `DeadlineService` →
  `wettelijkeTermijnVerloopt`): server-authoritative legal date arithmetic.
- **Archive retention stamp** (`retentieTot` = resolution + 5 years): RvIG legal
  retention computation.
- **Retention-guarded delete + DPO override** (`isRetentionActive`, `isDpo`):
  refuses early destruction while the 5-year window is active unless an FG/DPO
  overrides — a cross-object legal predicate, not a transition.
- **Allowed-FIELDS enforcement** on `update()`: constrains which fields a handler
  may patch (legal fields like the deadline are never client-writable).
- **Read-only check** (afgerond/gearchiveerd reject with the verbatim "Een
  afgerond verzoek kan niet meer worden gewijzigd." message): kept ahead of the
  graph check so the dominant error contract is byte-for-byte preserved.

## The thin guard: source of truth moves, contract preserved

`update()` now calls `assertStatusTransitionAllowed($from, $to)` when the patch
changes `status`. That guard reads the adjacency map from the schema via
`SchemaLifecycleGraph::fullAdjacencyFor('avgVerzoek')` (the Seam-2 helper —
file-read + json_decode, no OR runtime dependency, works in unit tests). A
mirrored `FALLBACK_TRANSITIONS` constant is used only when the bundled
declaration is unreadable, so the guard never regresses. Illegal moves raise the
same `OCSBadRequestException` (HTTP 400) as the persistence-layer rejection.

## RBAC: no per-transition `authorization`

OR supports a declarative per-transition `authorization` list (NC group ids /
`{role}` objects). It is **not** used here: today no individual edge is
role-gated — `AvgAccessService::canEdit` gates *who* may edit the request once,
not per transition. Declaring `authorization` per edge would close edges open
today and change the contract. The handler/teamlead/FG-DPO/admin gating stays in
PHP (`canEdit`, `isDpo`), unchanged.

## Rationale: no full OR AVG capability

A generic OR-native AVG/GDPR module is out of scope: single consumer (pipelinq),
value is legal IP not a reusable data primitive, computations are app-specific.
Only the mechanically-reusable declarative state machine is adopted.

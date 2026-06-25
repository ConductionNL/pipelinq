# OpenRegister Integration — AVG Request Lifecycle Delta

**Spec refs**: `openregister-integration`, `avg-verzoeken-workflow`, ADR-031 (declarative-first), ADR-022 (apps consume OR abstractions)
**Standards**: OpenRegister `x-openregister-lifecycle` annotation + `LifecycleValidationListener` enforcement contract

## ADDED Requirements

### Requirement: AVG Request Status Lifecycle Is Schema-Declared

The `avgVerzoek` schema MUST declare its `status` transition graph in
`configuration.x-openregister-lifecycle` (ADR-031): `field: status`,
`initial: ingediend`, `final: [afgerond, gearchiveerd]`, and a transition map in
which each of the seven working states (ingediend, in-behandeling,
bewijs-verzamelen, redactie, bundle-genereren, wachten-op-verzoeker,
weigering-opgesteld) may move to any declared status, and the two terminal states
have no outgoing transitions. The declared graph MUST mirror the permit set the
service enforced before this change — no edge opened or closed.

`AvgRequestService::update()` MUST derive its status-transition validation from
this schema declaration (via `SchemaLifecycleGraph`), not from a hardcoded copy.
An illegal status move MUST be rejected with `OCSBadRequestException` (HTTP 400),
preserving the existing exception type and message contract. OpenRegister's
`LifecycleValidationListener` enforces the same declared graph on
`ObjectService::saveObject()` as defense-in-depth.

The side-effecting legal computations MUST remain in PHP because the declarative
lifecycle grammar cannot express them: the intake reference + legal-deadline
computation, the archive 5-year `retentieTot` stamp, the retention-guarded delete
with FG/DPO override, and the allowed-FIELDS enforcement on update. Per-transition
RBAC `authorization` MUST NOT be added, because no individual edge is role-gated
today — *who* may edit is gated once by `AvgAccessService` (handler / team lead /
FG-DPO / admin), and per-edge authorization would change that contract.

**Feature tier**: MVP

#### Scenario: avgVerzoek lifecycle is declared and resolves from the schema

- GIVEN the avgVerzoek schema declares `x-openregister-lifecycle` (field `status`, initial `ingediend`, final `afgerond`/`gearchiveerd`)
- WHEN `SchemaLifecycleGraph::fullAdjacencyFor('avgVerzoek')` resolves the adjacency map
- THEN each of the seven working states MUST reach all nine declared states
- AND the two terminal states (`afgerond`, `gearchiveerd`) MUST be present as keys with empty target lists
- `@e2e exclude` backend schema-resolution invariant; covered by AvgRequestServiceTest unit test

#### Scenario: A legal status transition succeeds with preserved behaviour

- GIVEN a request the acting handler may edit, in a working state (e.g. `redactie`)
- WHEN `update()` patches `status` to a declared target (e.g. `bundle-genereren`)
- THEN the transition MUST be validated against the schema-derived graph and persist the new status
- `@e2e exclude` backend service-layer transition; covered by the unit transition-matrix test

#### Scenario: An illegal status transition is rejected with the same error contract

- GIVEN a request in a terminal state (`afgerond` or `gearchiveerd`)
- WHEN `update()` is called with any status patch
- THEN it MUST raise `OCSBadRequestException` with the message "Een afgerond verzoek kan niet meer worden gewijzigd."
- AND WHEN `update()` is called with a status value not in the avgVerzoek enum
- THEN it MUST raise `OCSBadRequestException` with an "Onbekende AVG-status" message
- AND a `saveObject()` that flips the status to a value no declared transition allows MUST be rejected by OpenRegister's `LifecycleValidationListener`
- `@e2e exclude` backend error-contract invariant; covered by the unit transition-matrix test + OR listener (defense-in-depth)

#### Scenario: Legal computations stay in PHP

- GIVEN an AVG request lifecycle action with a legal side-effect
- WHEN intake computes the reference + `wettelijkeTermijnVerloopt`, or archive stamps `retentieTot`, or delete enforces the retention guard with DPO override
- THEN these computations MUST run in `AvgRequestService` PHP, unchanged, because the declarative lifecycle grammar cannot express them
- `@e2e exclude` backend legal-computation invariant; covered by existing AvgRequestServiceTest scenarios

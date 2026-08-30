## ADDED Requirements

### Requirement: pipelinq registers a BSN/BRP identity-verify provider into OR's registry
pipelinq SHALL register an `IdentityVerifyProvider` implementing BSN/BRP/RvIG data-subject identity verification into OpenRegister's `IdentityVerifyRegistry` from pipelinq's own bootstrap, first-wins per ADR-019.
The provider MUST expose a stable id (matching the NL pack's `identityVerifyProvider` selector) and a verify-for-case operation returning exactly one of `verified`, `failed`, or `needs-more`. Registration MUST happen in `lib/AppInfo/Application.php` `register()`, mirroring OR's existing registry bootstrap, and MUST follow the registry's first-wins collision policy. pipelinq MUST NOT embed identity verification in any local AVG engine (that engine is retired).

#### Scenario: pipelinq's identity provider registers and is discoverable
- **WHEN** pipelinq boots on a gated install
- **THEN** its BSN/BRP `IdentityVerifyProvider` MUST be registered in OR's `IdentityVerifyRegistry` under the id named by the NL pack
- **AND** OR's case engine MUST be able to resolve it by that id via the pack selector

#### Scenario: The provider returns a three-state verify result
- **WHEN** OR's case engine verifies a data subject through pipelinq's provider
- **THEN** the result MUST be exactly one of `verified`, `failed`, or `needs-more`
- **AND** an unverifiable subject MUST NOT be reported as `verified` (fail-closed)

@e2e On a gated install, a steward advances a case to verifying and it verifies through pipelinq's registered BSN/BRP provider, returning a three-state result.

### Requirement: pipelinq registers an AP-complaint regulator-escalate provider into OR's registry
pipelinq SHALL register a `RegulatorEscalateProvider` implementing AP-complaint (Autoriteit Persoonsgegevens) escalation/dossier into OpenRegister's `RegulatorEscalateRegistry` from pipelinq's bootstrap, first-wins per ADR-019.
The provider MUST expose a stable id (matching the NL pack's `regulatorEscalateProvider` selector) and an escalate-for-case operation returning an outcome carrying a regulator reference and a status. Registration MUST happen in `lib/AppInfo/Application.php` `register()`, follow the registry's first-wins collision policy, and MUST NOT embed escalation in any local AVG engine (retired).

#### Scenario: pipelinq's regulator provider registers and is discoverable
- **WHEN** pipelinq boots on a gated install
- **THEN** its AP-complaint `RegulatorEscalateProvider` MUST be registered in OR's `RegulatorEscalateRegistry` under the id named by the NL pack
- **AND** OR's case engine MUST resolve it by that id via the pack selector

#### Scenario: Escalation returns a regulator reference and status
- **WHEN** OR's case engine escalates a case through pipelinq's provider
- **THEN** the outcome MUST carry a regulator reference and a status
- **AND** a failed escalation MUST NOT be reported as done (fail-closed)

@e2e On a gated install, a steward escalates a case and it routes through pipelinq's registered AP-complaint provider, returning a regulator reference and status.

### Requirement: The AvgRequests nav entry deep-links into OR's AVG case surface
pipelinq SHALL replace the `AvgRequests` internal route with a deep-link menu entry into OpenRegister's AVG case surface, per the ADR-019 deep-link registry and ADR-044 menu architecture.
The `AvgRequests` menu entry MUST become an external deep-link (an `href` into the `openregister` app's AVG case UI / `AvgIndex.vue` Cases tab), and pipelinq MUST NOT retain any internal `AvgRequests`/`AvgIntake`/`AvgRequestDetail` route or page. The entry MUST remain reachable from pipelinq's settings foldout so handlers can still find the AVG surface.

#### Scenario: The nav entry opens OR's case surface
- **WHEN** a handler selects the AVG entry in pipelinq's settings foldout
- **THEN** it MUST deep-link into OpenRegister's AVG case surface (Cases tab)
- **AND** pipelinq MUST NOT render its own AVG dashboard/intake/detail pages

#### Scenario: No internal AVG route remains
- **WHEN** pipelinq's manifest and router are inspected after this change
- **THEN** no internal `AvgRequests`/`AvgIntake`/`AvgRequestDetail` route or page component MUST remain
- **AND** the AVG entry MUST resolve to the OR deep-link

@e2e A handler clicks the AVG entry in pipelinq's settings foldout and lands on OpenRegister's AVG case list, with no pipelinq-internal AVG page rendered.

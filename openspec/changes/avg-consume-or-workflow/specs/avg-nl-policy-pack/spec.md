## ADDED Requirements

### Requirement: pipelinq ships an NL dsarPolicyPack config object
pipelinq SHALL ship a Dutch-jurisdiction `dsarPolicyPack` object conforming to OpenRegister's `dsarPolicyPack` schema, as configuration data (not a PHP policy service), supplying every NL jurisdiction value OR's case workflow needs.
The pack MUST be authored as an OpenRegister object on OR's `dsar-policy-packs` register and imported through `ObjectService` (RBAC + multitenancy), NOT as a pipelinq `PolicyService`, Entity/Mapper, or hard-coded constant (ADR-031, ADR-001). It MUST supply, as data: deadline durations, escalation-tier thresholds, the denial-grounds enum with Dutch labels + statutory citations, retention windows, intake channels, the DPO/FG role mapping, letter-template references, and the two integration-seam provider selectors.

#### Scenario: The NL pack is stored as declarative config data
- **WHEN** the NL policy pack is imported on a gated install
- **THEN** it MUST be a plain OpenRegister object on the `dsar-policy-packs` register served by OR's object APIs
- **AND** no pipelinq PHP policy service, Entity/Mapper, or hard-coded threshold constant MUST hold its values

#### Scenario: The pack conforms to OR's dsarPolicyPack schema
- **WHEN** the pack is imported
- **THEN** it MUST validate against OR's released `dsarPolicyPack` schema via ObjectService schema validation
- **AND** an invalid pack MUST be rejected at import, not silently stored

@e2e An administrator opens the AVG policy-pack surface and sees pipelinq's NL pack listed with resolvable deadline, denial-ground, retention, and intake values.

### Requirement: The NL pack supplies art-12 deadlines and escalation thresholds
The NL pack SHALL supply the AVG art-12 response deadline (one month), the two-month extension, and the reminder/escalation/breach escalation-tier boundaries as pack data, so OR's case engine resolves them without any pipelinq code.
The `deadlines` block MUST set a 30-day standard response window and a 60-day extension with a single-extension cap, and the `escalationTiers` collection MUST define reminder, escalation, and breach boundaries. Changing any of these values in the pack MUST take effect without a pipelinq code change.

#### Scenario: Case deadlines resolve from the NL pack
- **WHEN** a case bound to the NL pack computes its deadline and escalation state
- **THEN** it MUST use the pack's 30-day response window, 60-day extension, and reminder/escalation/breach boundaries
- **AND** changing a boundary in the pack MUST change resolution without a pipelinq redeploy

@e2e A steward changes an escalation boundary on pipelinq's NL pack and observes a case recompute its escalation state against the new value without a redeploy.

### Requirement: The NL pack maps art-23 denial grounds to Dutch wording and citations
The NL pack SHALL map each generic denial-ground key to a Dutch `label` and a statutory `citation`, so OR's denial workflow shows NL wording resolved from the pack rather than from any pipelinq code or OR register JSON.
Each entry in the `denialGrounds` collection MUST carry a ground key, a Dutch label, and a statutory citation (e.g. AVG art. 23 / art. 12(5) references). No Dutch denial wording MUST live in pipelinq code once this pack ships.

#### Scenario: A denial ground shows Dutch label and citation from the pack
- **WHEN** a handler selects a denial-ground key on a case bound to the NL pack
- **THEN** the Dutch label and statutory citation MUST come from the pack's `denialGrounds` mapping
- **AND** no Dutch denial wording MUST remain in pipelinq code

@e2e A handler selects a denial ground on a case and sees the NL pack's Dutch label and statutory citation.

### Requirement: The NL pack defines retention windows, intake channels, roles, template refs, and seam selectors
The NL pack SHALL define Boekhoudplicht/RvIG/standard retention windows, the NL intake channels, the DPO/FG role mapping, letter-template references, and the two seam provider selectors, all as pack data with safe placeholders for ids and tokens.
The `retentionWindows` collection MUST include a fiscal `boekhoudplicht` window and an `rvig` window as named window→duration entries; `intakeChannels` MUST include `handmatig`, `email`, `balie`, `post`, and `webformulier`; `roleMapping` MUST map DPO/FG/handler roles; `letterTemplates` MUST hold template references (not inline letter bodies); and `identityVerifyProvider`/`regulatorEscalateProvider` MUST name pipelinq's registered provider ids. Every id, token, role, and template reference MUST be a safe placeholder (nil UUID, `<role-id>`, `<template-id>`, `YOUR_TOKEN_HERE`) — no realistic-looking secret.

#### Scenario: Retention windows and intake channels resolve from the pack
- **WHEN** a case selects a named retention window (e.g. `boekhoudplicht`) or an intake channel
- **THEN** the window's duration and the channel set MUST be read from the NL pack
- **AND** changing them in the pack MUST change resolution without a pipelinq code change

#### Scenario: Seam selectors name pipelinq's registered providers with safe placeholders
- **WHEN** the NL pack is inspected
- **THEN** `identityVerifyProvider` MUST name pipelinq's identity provider id and `regulatorEscalateProvider` MUST name pipelinq's regulator provider id
- **AND** every id, token, role, and template reference in the pack MUST be a safe placeholder, never a realistic secret

@e2e A steward selects the boekhoudplicht retention window on a case and sees the NL pack's fiscal duration applied, and inspects the pack's seam selectors pointing at pipelinq's provider ids.

# master-data-management (delta — mdm-consume-or-surface)

This delta migrates the `masterEntity` **schema configuration** so pipelinq consumes
OpenRegister's MDM surface (ADR-045 #D) instead of driving it with app-side engine code. It
changes only declarative annotations + seeded rows; the app-side engine/UI removal lands in the
dependent code links `mdm-consume-or-surface-backend` and `mdm-consume-or-surface-frontend`.

## MODIFIED Requirements

### Requirement: REQ-MDM-001 — Golden Record via OpenRegister Survivorship

The system MUST maintain a single authoritative golden record per Master Entity, with attribute
values determined by configured trust-tiers, by **declaring an `x-openregister-survivorship`
annotation on the `masterEntity` schema** and letting OpenRegister's survivorship engine materialise
the golden record on save. The annotation MUST set `sourceLinkField = sourceRecords`,
`goldenRecordField = goldenRecord`, `provenanceField = attributeProvenance`, `trustTierField`,
`tierOrder` (weakest-first, including `discard`), `freshnessAnchorField`, and
`overridesField = attributeOverrides`. The app MUST NOT run an in-process survivorship / pick-winner
loop; recomputation is OpenRegister's on-save listener.

**Feature tier**: MVP
**Handoff**: Consumes OpenRegister `mdm-survivorship`. App-side `MasterEntityService` recompute is retired to the dependent backend link.

#### Scenario: Survivorship annotation is declared and materialises on save

- WHEN the pipelinq register is (re-)imported and a Master Entity is saved
- THEN the `masterEntity` schema configuration MUST contain `x-openregister-survivorship` with `sourceLinkField = sourceRecords`, `goldenRecordField = goldenRecord`, `provenanceField = attributeProvenance`, and `overridesField = attributeOverrides`
- AND OpenRegister's survivorship listener MUST materialise `goldenRecord` and `attributeProvenance` onto the saved object from the linked source records and trust rows

#### Scenario: Gold-tier still wins via OR resolver

- GIVEN a Master Entity account with phone from `kvk-api` (gold), `shillinq-debiteuren` (silver), `pipelinq-crm` (bronze)
- WHEN the object is saved and OpenRegister recomputes the golden record
- THEN the gold-tier value MUST be selected regardless of recency, per the annotation's `tierOrder`
- AND `attributeProvenance.phone` MUST record the winning `sourceSystem` and `trustTier`

#### Scenario: App-side survivorship loop is not declared

- WHEN the `masterEntity` schema configuration is inspected
- THEN survivorship MUST be expressed only as the `x-openregister-survivorship` annotation (no app-side pick-winner logic is referenced from config)

### Requirement: REQ-MDM-002 — Deterministic & Nested-Path Duplicate Detection

The system MUST detect duplicates by declaring `x-openregister-dedup` matchRules on the
**nested `goldenRecord.*` paths** of the `masterEntity` schema (now supported by OpenRegister's
nested-path duplicate detection) and delegating detection to OpenRegister. The flattened
`matchName` / `matchEmail` / `matchKvkNumber` / `matchPhone` projection fields — and the app-side
maintenance that populated them — MUST be removed, because OpenRegister's similarity now traverses
nested dot-paths directly.

**Feature tier**: MVP
**Handoff**: Consumes OpenRegister `duplicate-detection` (nested paths). DTO adaptation + auto-merge gate move to the dependent backend link.

#### Scenario: matchRules reference nested goldenRecord paths

- WHEN the `masterEntity` `x-openregister-dedup` annotation is inspected
- THEN every `matchRules[].field` MUST be a nested path under `goldenRecord.` (e.g. `goldenRecord.kvkNumber`, `goldenRecord.email`, `goldenRecord.name`)
- AND the schema MUST NOT declare the `matchName`, `matchEmail`, `matchKvkNumber`, or `matchPhone` projection fields

#### Scenario: Two entities with same KvK still surface

- GIVEN two Master Entities both carrying `goldenRecord.kvkNumber = "12345678"`
- WHEN OpenRegister's duplicate detection runs against the nested-path matchRules
- THEN the pair MUST be returned as a duplicate candidate at the configured threshold

### Requirement: REQ-MDM-004 — Reversible Merge via OpenRegister Merge Engine

The system MUST support reversible merge with preview by declaring an `x-openregister-merge`
annotation on the `masterEntity` schema so OpenRegister's merge engine owns preview, atomic
execution, reversal within the reversal window, and the `mergeOperation` audit log, and fires
`ObjectsMergedEvent` after a merge or reversal. The app MUST NOT ship an in-process merge service.

**Feature tier**: MVP
**Handoff**: Consumes OpenRegister `mdm-merge`. App-side `MergeService` is retired to the dependent backend link; downstream propagation subscribes to `ObjectsMergedEvent`.

#### Scenario: Merge annotation is declared

- WHEN the `masterEntity` schema configuration is inspected
- THEN it MUST contain `x-openregister-merge` naming the survivor/merged status fields and the source-link field, so OpenRegister can preview, execute, and reverse merges on `masterEntity` objects

#### Scenario: Downstream sync reacts to ObjectsMergedEvent

- GIVEN a merge is executed by OpenRegister's merge engine on two `masterEntity` objects
- WHEN OpenRegister fires `ObjectsMergedEvent`
- THEN pipelinq's downstream propagation MUST be driven by subscribing to that event (not by an app-side merge call), enqueuing sync items with `changeType = merge` (or `reverse-merge` when `isReversal` is true)

### Requirement: REQ-MDM-005 — Trust Configuration in OpenRegister's Register

The system MUST express per-`(entityType, attribute, sourceSystem)` trust tiers as rows in
**OpenRegister's generic `trust-configuration` register**, not as a pipelinq-local
`trustConfiguration` schema. The pipelinq register file MUST remove its local `trustConfiguration`
schema declaration (and its entry in the `pipelinq` register `schemas[]` list); the seeded rows
MUST migrate one-to-one into OpenRegister's register with the same `entityType` / `attribute` /
`sourceSystem` / `trustTier` / `freshnessDecayDays` / `manualOverrideAllowed` / `effectiveFrom`
shape.

**Feature tier**: MVP
**Handoff**: Consumes OpenRegister `mdm-survivorship` `trust-configuration` register. Row re-seed mechanism is a Deferred Question (may need an OR-repo companion change).

#### Scenario: Local trust schema is removed

- WHEN the pipelinq register file is inspected after this change
- THEN it MUST NOT declare a `trustConfiguration` schema and MUST NOT list `trustConfiguration` in the `pipelinq` register `schemas[]`

#### Scenario: Trust rows resolve from OpenRegister

- GIVEN the three account trust rows (billingAddress→kvk gold, phone→shillinq silver, vatNumber→kvk gold) exist in OpenRegister's `trust-configuration` register
- WHEN OpenRegister's `TrustTierResolver` resolves a tier during survivorship recompute
- THEN it MUST read those rows from OpenRegister's register (RBAC + tenant scoped), producing the same tier outcomes as before

### Requirement: REQ-MDM-012 — Per-Object Conflict Override via OpenRegister

The system MUST support per-object conflict resolution by declaring `overridesField = attributeOverrides`
in the `masterEntity` `x-openregister-survivorship` annotation and adding an `attributeOverrides`
property to the schema, so a data steward's authoritative-value choice (written by OpenRegister's
`POST /api/objects/survivorship/{id}/override`) short-circuits the resolver for that attribute. The
app MUST NOT ship its own conflict-resolution modal or override write path; the steward uses
OpenRegister's conflict-resolution UI.

**Feature tier**: MVP
**Handoff**: Consumes OpenRegister `mdm-conflict-resolution-ui`. App-side conflict modal is retired to the dependent frontend link.

#### Scenario: Override field is declared

- WHEN the `masterEntity` schema configuration is inspected
- THEN it MUST declare an `attributeOverrides` property AND the `x-openregister-survivorship` annotation MUST set `overridesField = attributeOverrides`

#### Scenario: Override short-circuits the resolver

- GIVEN a steward records an authoritative value for `goldenRecord.phone` via OpenRegister's override endpoint, stored in `attributeOverrides`
- WHEN OpenRegister recomputes the golden record on next save
- THEN the overridden value MUST win for `phone` regardless of source tiers, and `attributeProvenance.phone` MUST reflect the override provenance

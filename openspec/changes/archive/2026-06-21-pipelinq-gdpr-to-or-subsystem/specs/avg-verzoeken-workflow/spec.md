# AVG Verzoeken Workflow — Seam 3 OR-subsystem boundary delta

**Spec refs**: capability `avg-verzoeken-workflow` (REQ-AVG-004 evidence collection, REQ-AVG-009 retention/pseudonymisation), ADR-022 (apps-consume-or-abstractions)
**Standards**: AVG Art 5(1)(e)/15/16/17/20, NL Boekhoudplicht (7-year), RvIG 5-year DSAR-dossier retention

## ADDED Requirements

### Requirement: REQ-AVG-014 — OpenRegister Compliance-Subsystem Consumption Boundary

The AVG workflow SHALL consume OpenRegister only through the generic object
abstraction (`ObjectService::findAll` / `saveObject`) for personal-data finds
and writes, and SHALL keep all legally-load-bearing transforms — subject
matching, field-level pseudonymisation policy, retention-period rules,
consent/legal-basis logic — in the app. The app SHALL NOT delegate erasure,
retention, or anonymisation semantics to OpenRegister's
`DsarService` / `AvgRetentionService` / `ArchivalService`, because those
services key on a different data model (the OR PII-detection index and
`processing_activity_id` audit ledger) and apply different erasure semantics
(object soft-delete) than the AVG workflow requires (register-scoped equality
find + field-level pseudonymise-and-keep). Any OpenRegister `findAll` query the
app issues SHALL use only plain equality, `IN`, sort, and limit predicates;
computed, case-folded, or ISO-`T` timestamp-window predicates SHALL be evaluated
in the app.

**Feature tier**: MVP

#### Scenario: Subject find stays register-scoped and non-admin

- **GIVEN** an AVG handler (not necessarily an administrator) collecting evidence for a data subject
- **WHEN** the app queries OpenRegister for the subject's objects
- **THEN** it MUST use `ObjectService::findAll` with a plain equality filter (e.g. `bsn`, `customerId`) over the app's own registers
- **AND** it MUST NOT call `DsarService::findObjectsForSubject`, which is administrator-gated and matches the OR PII-detection index rather than the app's registers
- **AND** the returned find-set MUST be identical to the pre-existing behaviour for the same subject and scope

#### Scenario: Art-17 erasure preserves field-level pseudonymise-and-keep

- **GIVEN** a right-to-be-forgotten request for a customer with booking records
- **WHEN** the app pseudonymises the customer's data
- **THEN** exactly `customerName`, `customerEmail`, and `customerPhone` MUST be replaced with deterministic SHA-256 hashes
- **AND** every other field and the record itself MUST be retained (NL Boekhoudplicht)
- **AND** the app MUST NOT delegate to `DsarService` erasure, which soft-deletes the whole object

#### Scenario: Retention cut-offs stay app-owned

- **GIVEN** archived AVG dossiers with a 5-year `retentieTot` and evidence items with a 30-day pseudonymisation window
- **WHEN** a retention pass runs
- **THEN** the cut-offs MUST be computed from the app's `retentieTot` and `verzameldOp + N days` values
- **AND** the app MUST NOT delegate to `AvgRetentionService::runRetentionPass`, which keys retention on `processing_activity_id` audit-trail timestamps and a `Verwerkingsactiviteit` `bewaartermijn`
- **AND** the resulting cut-offs MUST be identical to the pre-existing behaviour

#### Scenario: Only safe predicates reach OpenRegister

- **GIVEN** a query leg that needs a case-folded match, a timestamp window, or a group-by
- **WHEN** the app resolves that leg
- **THEN** it MUST evaluate the computed/case-folded/ISO-`T`-window/group-by part in the app
- **AND** it MUST pass only plain equality, `IN`, sort, and limit predicates to OpenRegister's `findAll`

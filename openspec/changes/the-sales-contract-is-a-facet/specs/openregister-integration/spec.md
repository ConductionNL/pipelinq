# OpenRegister integration

## ADDED Requirements

### Requirement: The sales contract points at the shillinq contract (REQ-ORI-041)

The sales contract schema's slug SHALL be `salesContract` and SHALL NOT be
`contract`.

Three apps declared a `contract` and all three carry `contractNumber`, so they
describe one contract from three sides. shillinq owns the lifecycle (ADR-066);
this schema owns the sales facet.

The schema SHALL carry a `contract` property holding the UUID of the shillinq
`Contract`. It SHALL be a plain uuid string and SHALL NOT be a `$ref`, because
shillinq's register is a different register and ADR-062 rule 7 gives a
cross-register target no `$ref`.

The reference MAY be empty, in which case the record stands alone on
`contractNumber` and single-app operation is unchanged.

The app-config key SHALL remain `contract_schema`. It is live persisted state.

The rename SHALL NOT touch `contract` where it is a GDPR Article 6 lawful
basis. That value appears in three register fragments and in
`ComplianceService`, and rewriting it would change the legal ground a consent
record claims.

#### Scenario: The slug is renamed in place

- **GIVEN** an install carrying a pipelinq-owned `contract` schema
- **WHEN** the repair step runs
- **THEN** the row keeps its schema id, and so its shard table and objects.

#### Scenario: The lawful basis is untouched

- **WHEN** the register fragments are read after the rename
- **THEN** every `lawfulBasis` and `legalBasis` enum still offers `contract`.

#### Scenario: The sales facet points at its owner

- **WHEN** the merged fragment is read
- **THEN** `salesContract` carries a `contract` property targeting shillinq.

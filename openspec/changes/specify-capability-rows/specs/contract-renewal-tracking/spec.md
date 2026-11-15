# contract-renewal-tracking Specification (delta)

## Purpose

Requirements for capability matrix rows whose behaviour no requirement stated yet. Rows: `contract-number`.

## ADDED Requirements

### Requirement: A won lead can be turned into a numbered contract

The system SHALL let a user create a contract from a won lead, carrying over the lead's client and value and giving the contract a contract number of its own.

#### Scenario: Creating a contract from a won deal

- GIVEN a lead in a won stage
- WHEN a user chooses Create contract on it
- THEN a draft contract exists with the lead's client and value
- AND it has a new unique contract number and refers to the lead

### Requirement: Marking a contract renewed drafts next term's contract

When a user moves a contract to `renewed`, the system SHALL draft the successor contract in the same step. The draft SHALL have the same client, lines, interval and value, start the day after the current contract ends, and point back to it.

#### Scenario: Renewing a yearly contract
- GIVEN contract C-2026-004 for client Acme ends on 31 December 2026
- WHEN the account manager moves it to Renewed
- THEN a draft contract for Acme exists that starts on 1 January 2027
- AND it has the same lines, interval and value as C-2026-004
- AND it names C-2026-004 as its predecessor
- @e2e exclude covered by PHPUnit `ContractServiceTest` over the successor draft

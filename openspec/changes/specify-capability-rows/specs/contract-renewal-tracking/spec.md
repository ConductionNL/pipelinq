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

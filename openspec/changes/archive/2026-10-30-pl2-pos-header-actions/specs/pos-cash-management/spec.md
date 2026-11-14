# pos-cash-management Specification (delta)

## ADDED Requirements

### Requirement: A shift is counted only with an amount

`POST /api/pos-shifts/{id}/count` SHALL refuse a count without a numeric amount with 422 "Enter the counted amount.",
and the open shift's primary button SHALL be Close and count shift, asking for the amount and optional notes.

#### Scenario: No amount

- GIVEN an open shift
- WHEN a count is sent without an amount
- THEN the server answers 422 and the shift stays open

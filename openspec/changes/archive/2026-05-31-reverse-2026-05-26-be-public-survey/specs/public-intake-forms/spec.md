## ADDED Requirements

---

### Requirement: Public survey rendering and submission — documented operations

The public survey display and submission implemented in this app MUST provide the operations enumerated in this change's tasks.md (for example `show`, `submit`). Each listed method realises an observable part of public survey display and submission and MUST behave as implemented in the current codebase.

**Feature tier**: V1

#### Scenario: Documented operations are available

- GIVEN the backend service/controller is loaded
- WHEN a caller invokes one of the documented operations for public survey display and submission
- THEN the operation MUST execute and return a result consistent with the current implementation

---

### Requirement: Public survey rendering and submission — results derived from current CRM state

Operations for public survey display and submission MUST read their inputs from the relevant CRM entities/configuration and compute results from that live state (no hard-coded or stubbed responses). Derivations such as formatting, aggregation, filtering and validation MUST reflect the data present at call time.

**Feature tier**: V1

#### Scenario: Results reflect live state

- GIVEN CRM data backing public survey display and submission
- WHEN a documented operation runs
- THEN its output MUST be derived from that data
- AND it MUST change when the underlying data changes

---

### Requirement: Public survey rendering and submission — defensive handling of absent or invalid input

Operations for public survey display and submission MUST tolerate missing, empty, or malformed input without throwing unhandled errors — returning empty or default results, or surfacing a validation outcome as implemented, rather than crashing the surrounding flow.

**Feature tier**: V1

#### Scenario: Missing input does not crash the flow

- GIVEN an operation for public survey display and submission is called with absent or invalid input
- WHEN it executes
- THEN it MUST return a safe default or a validation result
- AND it MUST NOT raise an unhandled exception


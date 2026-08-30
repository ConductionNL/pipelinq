## ADDED Requirements

---

### Requirement: Routing suggestion UI — documented operations

The skill-routing suggestion panel implemented in this app MUST provide the operations enumerated in this change's tasks.md (for example `assign`, `category`, `loadSuggestions`, `requestId`, `workloadIcon`, `workloadTitle`). Each listed method realises an observable part of skill-routing suggestion panel and MUST behave as implemented in the current codebase.

**Feature tier**: V1

#### Scenario: Documented operations are available

- GIVEN the frontend component/store is loaded
- WHEN a caller invokes one of the documented operations for skill-routing suggestion panel
- THEN the operation MUST execute and return a result consistent with the current implementation

---

### Requirement: Routing suggestion UI — results derived from current CRM state

Operations for skill-routing suggestion panel MUST read their inputs from the relevant CRM entities/configuration and compute results from that live state (no hard-coded or stubbed responses). Derivations such as formatting, aggregation, filtering and validation MUST reflect the data present at call time.

**Feature tier**: V1

#### Scenario: Results reflect live state

- GIVEN CRM data backing skill-routing suggestion panel
- WHEN a documented operation runs
- THEN its output MUST be derived from that data
- AND it MUST change when the underlying data changes

---

### Requirement: Routing suggestion UI — defensive handling of absent or invalid input

Operations for skill-routing suggestion panel MUST tolerate missing, empty, or malformed input without throwing unhandled errors — returning empty or default results, or surfacing a validation outcome as implemented, rather than crashing the surrounding flow.

**Feature tier**: V1

#### Scenario: Missing input does not crash the flow

- GIVEN an operation for skill-routing suggestion panel is called with absent or invalid input
- WHEN it executes
- THEN it MUST return a safe default or a validation result
- AND it MUST NOT raise an unhandled exception


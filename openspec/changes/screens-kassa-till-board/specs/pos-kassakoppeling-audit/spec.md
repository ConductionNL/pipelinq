## ADDED Requirements

### Requirement: The audit log reads as its board (REQ-POS-BOARD-005)
The cash register audit log MUST offer the chips All, Invalid signature and Not exported with their counts, show operator and register as columns of their own, and show the picked entry (by default the first with an invalid signature, else the newest) in a side panel with its facts and cryptographic data and a way to open and verify it.

#### Scenario: Invalid signatures first
@e2e exclude Chip logic asserted in tests/vitest/kassakoppelingAuditChips.spec.js; the e2e instance has no tampered entries.
- **GIVEN** an entry whose signature failed
- **WHEN** an administrator picks Invalid signature
- **THEN** the list MUST show only the entries whose signature failed

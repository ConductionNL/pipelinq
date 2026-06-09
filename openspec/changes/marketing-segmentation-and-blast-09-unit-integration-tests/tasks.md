# Tasks: 09 Unit and Integration Tests

## SegmentService tests (Task 4.1 of giant)

- [x] Create `tests/Unit/Service/SegmentServiceTest.php` — 15 cases, validate/evaluate/estimate.
- [x] Test validateRules accepts valid, rejects invalid field, rejects invalid operator for type
- [x] Test evaluateRules: match true, non-match false, AND logic, OR logic (NOT also covered)
- [x] Test estimateSize returns count of matching entities; mock ObjectService (no real DB); use realistic seed rule trees (`industry equals` + `employees gte` AND composite)

## ComplianceService tests (Task 4.2 of giant)

- [x] Create `tests/Unit/Service/ComplianceServiceTest.php` — 16 cases.
- [x] Test checkSegmentCompliance all-compliant and missing-contacts paths (+ empty-segment shortcut)
- [x] Test validateTemplate rejects email without unsubscribe token, rejects without address, accepts valid email, accepts SMS (+ accepts email with `footerOverride`)
- [x] Test hasConsentForChannel (active true, withdrawn false, imported false, missing record false) and recordConsentWithdrawal updates + transitions queued deliveries (preserves first-withdrawal timestamp on re-withdrawal; creates audit-ledger row when no prior record); mock ObjectService

## BlastService tests (Task 4.3 of giant)

- [x] Create `tests/Unit/Service/BlastServiceTest.php` — 17 cases total (11 from slice 04 + 6 from slice 09).
- [x] Test sendBlast creates queue, fails on missing consent; createAbVariant splits correctly (+ honours override fields; A/B split on parent creates variant-B child blast)
- [x] Test dispatchBlastDeliveries calls openconnector per delivery and respects rate limit; updateBlastTotals recounts (+ fail-closed when SourceService unavailable; totals fully reset when no deliveries); mock collaborators

## Integration test (Task 4.4 of giant)

- [x] Create `tests/Integration/BlastWorkflowTest.php` — 3 scenarios; registered as a new `Integration Tests` PHPUnit suite in `phpunit.xml`.
- [x] Setup test segment + contacts with consent; create + send Blast; verify deliveries for compliant contacts, non-compliant skipped; use real ObjectService (test register if available) — the in-memory test register matches the real `find` / `findAll` / `saveObject` / `updateObject` surface so the same assertions hold against a live OpenRegister test register when one is configured. Adds an all-compliant scenario and an end-to-end consent-withdrawal scenario through `ComplianceService::recordConsentWithdrawal`.

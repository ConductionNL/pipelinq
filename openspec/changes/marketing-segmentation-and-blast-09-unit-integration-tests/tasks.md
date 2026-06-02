# Tasks: 09 Unit and Integration Tests

## SegmentService tests (Task 4.1 of giant)

- [ ] Create `tests/Unit/Service/SegmentServiceTest.php`
- [ ] Test validateRules accepts valid, rejects invalid field, rejects invalid operator for type
- [ ] Test evaluateRules: match true, non-match false, AND logic, OR logic
- [ ] Test estimateSize returns count of matching entities; mock ObjectService (no real DB); use realistic seed rule trees

## ComplianceService tests (Task 4.2 of giant)

- [ ] Create `tests/Unit/Service/ComplianceServiceTest.php`
- [ ] Test checkSegmentCompliance all-compliant and missing-contacts paths
- [ ] Test validateTemplate rejects email without unsubscribe token, rejects without address, accepts valid email, accepts SMS
- [ ] Test hasConsentForChannel (active true, withdrawn false) and recordConsentWithdrawal updates + transitions deliveries; mock ObjectService

## BlastService tests (Task 4.3 of giant)

- [ ] Create `tests/Unit/Service/BlastServiceTest.php`
- [ ] Test sendBlast creates queue, fails on missing consent; createAbVariant splits correctly
- [ ] Test dispatchBlastDeliveries calls openconnector per delivery and respects rate limit; updateBlastTotals recounts; mock collaborators

## Integration test (Task 4.4 of giant)

- [ ] Create `tests/Integration/BlastWorkflowTest.php`
- [ ] Setup test segment + contacts with consent; create + send Blast; verify deliveries for compliant contacts, non-compliant skipped; use real ObjectService (test register if available)

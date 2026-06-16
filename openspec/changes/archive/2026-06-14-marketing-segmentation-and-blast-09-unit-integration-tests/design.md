# Design: 09 Unit and Integration Tests

## Scope

PHPUnit tests under `tests/Unit/Service/` and `tests/Integration/`. No
production code changes.

## Approach (ADR-008)

- Unit tests mock `ObjectService` and collaborators — no real DB. Use
  realistic rule trees / consent records derived from the member 01 seed data
  so the assertions exercise true behaviour, not stubs.
- Integration test exercises the real `ObjectService` (test register if
  available) from segment creation through compliant/non-compliant send.
- Target >=80% coverage on SegmentService, ComplianceService, BlastService,
  AttributionService.

Note: per house rule, do NOT make a test pass by editing a shared mock — assert
the service's own behaviour.

---
kind: code
depends_on: [marketing-segmentation-and-blast-08-performance-dashboard]
chain:
  - marketing-segmentation-and-blast-01-schema-and-seed-config
  - marketing-segmentation-and-blast-02-segment-service
  - marketing-segmentation-and-blast-03-compliance-service
  - marketing-segmentation-and-blast-04-blast-attribution-services
  - marketing-segmentation-and-blast-05-jobs-and-webhooks
  - marketing-segmentation-and-blast-06-rest-controllers
  - marketing-segmentation-and-blast-07-segment-blast-views
  - marketing-segmentation-and-blast-08-performance-dashboard
  - marketing-segmentation-and-blast-09-unit-integration-tests
  - marketing-segmentation-and-blast-10-docs
  - marketing-segmentation-and-blast-11-quality-verification
---

# Proposal: Marketing Segmentation and Blast — 09 Unit and Integration Tests

Member **9 of 11** in the `marketing-segmentation-and-blast` chain.
Predecessor: `marketing-segmentation-and-blast-08-performance-dashboard`.
This member adds the PHPUnit unit tests for the three core services plus an
end-to-end integration test of the segment → blast → send workflow.

## Why (carried from the giant)

ADR-008 requires test coverage. The service classes (SegmentService,
ComplianceService, BlastService) carry the rule-evaluation, consent-gating,
and send-orchestration logic that the whole feature depends on — they need
unit coverage, and the happy path needs an integration test.

## What this member does

- `tests/Unit/Service/SegmentServiceTest.php`
- `tests/Unit/Service/ComplianceServiceTest.php`
- `tests/Unit/Service/BlastServiceTest.php`
- `tests/Integration/BlastWorkflowTest.php`

## Out of scope

Docs (member 10) and the manual verification + review checklist (member 11).

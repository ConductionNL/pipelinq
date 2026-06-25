---
status: done
---

# marketing-testing Specification

## Purpose
Defines the test coverage for the marketing blast services. PHPUnit unit tests cover rule validation and evaluation, consent gating, template validation and withdrawal, and send, dispatch, A/B, and throttle behaviour, and an end-to-end integration test exercises segment creation through blast send using the real ObjectService.
## Requirements
### Requirement: Service Unit Test Coverage

The marketing services SHALL have PHPUnit unit tests covering rule
validation/evaluation, consent gating + template validation + withdrawal,
and send/dispatch/A-B/throttle behaviour.

#### Scenario: SegmentService unit tests

- **GIVEN** SegmentServiceTest with mocked ObjectService
- **WHEN** the suite runs
- **THEN** it SHALL cover validateRules (accept/reject field/operator), evaluateRules (AND/OR, match/non-match), and estimateSize

#### Scenario: ComplianceService unit tests

- **GIVEN** ComplianceServiceTest with mocked ObjectService
- **WHEN** the suite runs
- **THEN** it SHALL cover checkSegmentCompliance (all compliant / missing), validateTemplate (reject email without token/address, accept SMS), hasConsentForChannel, and recordConsentWithdrawal

#### Scenario: BlastService unit tests

- **GIVEN** BlastServiceTest with mocked SegmentService/ComplianceService/ObjectService
- **WHEN** the suite runs
- **THEN** it SHALL cover sendBlast (queue creation, fail on missing consent), createAbVariant, dispatchBlastDeliveries (openconnector call + rate limit), and updateBlastTotals

### Requirement: End-to-End Integration Test

There SHALL be an integration test covering segment creation through blast
send using the real ObjectService.

#### Scenario: Segment to blast to send

- **GIVEN** a test segment with rule and test contacts with consent
- **WHEN** a Blast is created and sent
- **THEN** BlastDeliveries SHALL be created for compliant contacts and non-compliant contacts SHALL be skipped


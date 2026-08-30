---
status: done
---

# marketing-testing Specification

## Purpose
Defines the test coverage for the marketing blast services. PHPUnit unit tests cover rule validation and evaluation, consent gating, template validation and withdrawal, and send, dispatch, A/B, and throttle behaviour, and an end-to-end integration test exercises segment creation through blast send using the real ObjectService.
## Requirements
### Requirement: Service Unit Test Coverage

@e2e exclude these three scenarios are ABOUT THE UNIT SUITE ITSELF — each one's
GIVEN is a named PHPUnit test class and its WHEN is "the suite runs". The thing
required to exist IS a test, so demanding a browser test that the test exists
is circular: it would assert a property of the repository, not of a running
instance. All three classes were opened and confirmed before this was written —
`tests/Unit/Service/SegmentServiceTest.php`,
`tests/Unit/Service/ComplianceServiceTest.php`,
`tests/Unit/Service/BlastServiceTest.php` — and they run in CI on every one of
the PHPUnit matrix legs. The user-visible behaviour these services drive is
covered separately by the `marketing-blast` and `marketing-ui` capabilities.

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

@e2e exclude the requirement is that an INTEGRATION TEST exists, and it does:
`tests/Integration/BlastWorkflowTest.php` (opened and confirmed) drives segment
creation → blast → send against the real `ObjectService` and asserts that
`BlastDelivery` rows are created for compliant contacts and skipped for
non-compliant ones. A Playwright test could not satisfy this requirement even in
principle — "using the real ObjectService" is a statement about which PHP
collaborator is in play, which is invisible from a browser, and the requirement
names the integration tier specifically.

There SHALL be an integration test covering segment creation through blast
send using the real ObjectService.

#### Scenario: Segment to blast to send

- **GIVEN** a test segment with rule and test contacts with consent
- **WHEN** a Blast is created and sent
- **THEN** BlastDeliveries SHALL be created for compliant contacts and non-compliant contacts SHALL be skipped


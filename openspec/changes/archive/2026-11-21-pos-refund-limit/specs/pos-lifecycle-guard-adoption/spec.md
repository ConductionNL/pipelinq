# pos-lifecycle-guard-adoption

## ADDED Requirements

### Requirement: A refund up to the configured limit SHALL need no manager (REQ-PLG-007)

An administrator SHALL be able to set a refund limit in euros in the POS settings. A refund
whose gross is at or below the limit SHALL be allowed for any till user who may act on the
original transaction. A refund above the limit SHALL need a POS manager or a Nextcloud admin,
as REQ-PLG-004 says. With no limit set, every refund SHALL need a manager. The over-refund cap
SHALL apply in every case. This narrows REQ-PLG-004 to refunds above the limit.

Rows: `pos-refund-guard` (pipelinq matrix).

#### Scenario: A till user refunds below the limit
@e2e exclude a lifecycle-guard decision on who may run a transition, with no screen of its own beyond the refund form's approver line; a browser run would need a seeded non-manager till user and a POS manager group on the CI instance, which it does not have. Asserted by tests/Unit/Lifecycle/PosRefundManagerGuardTest.php (testATillUserRefundsBelowTheLimit, testARefundAtTheLimitNeedsNoManager, testTheCapStillAppliesBelowTheLimit) and tests/vitest/posRefundLimit.spec.js (refundApprover: lets the till user confirm at or below the limit).
- **GIVEN** a refund limit of 25 euros and a till user who is not a manager
- **WHEN** they refund 12 euros on a confirmed transaction they may act on
- **THEN** the refund completes without a manager

#### Scenario: Above the limit a manager approves
@e2e exclude a lifecycle-guard decision on who may run a transition, with no screen of its own beyond the refund form's approver line; a browser run would need a seeded non-manager till user and a POS manager group on the CI instance, which it does not have. Asserted by tests/Unit/Lifecycle/PosRefundManagerGuardTest.php (testAboveTheLimitAManagerApproves, testRejectStaysWithAManager) and tests/vitest/posRefundLimit.spec.js (refundApprover: sends a refund above the limit to a manager).
- **GIVEN** a refund limit of 25 euros and a till user who is not a manager
- **WHEN** they refund 40 euros on a confirmed transaction
- **THEN** the refund waits for a manager and the till user cannot complete it
- **AND** a manager can complete it

#### Scenario: No limit set keeps every refund with a manager
@e2e exclude a lifecycle-guard decision on who may run a transition, with no screen of its own beyond the refund form's approver line; a browser run would need a seeded non-manager till user and a POS manager group on the CI instance, which it does not have. Asserted by tests/Unit/Lifecycle/PosRefundManagerGuardTest.php (testNoUsableLimitKeepsEveryRefundWithAManager, testNonManagerIsDenied) and tests/vitest/posRefundLimit.spec.js (refundApprover: keeps every refund with a manager when no limit is set).
- **GIVEN** no refund limit is set
- **WHEN** a till user who is not a manager refunds 2 euros
- **THEN** the guard denies it and a manager has to complete it

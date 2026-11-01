## ADDED Requirements

### Requirement: A retried movement is written once (REQ-LOY-011)

Every ledger movement MAY carry an operation id. The caller picks it, and it MUST be the same on every retry of that movement. `creditPoints`, `debitPoints`, `refundPoints`, `adjustPoints` and `expirePoints` MUST accept it. The ledger entry MUST store it as `operationId`.

Before it writes, the service MUST look for an entry of the same account with the same operation id. When one exists, the service MUST return that entry and write nothing. The balance, the lifetime total and the tier MUST stay as they are. A debit repeat MUST NOT check the balance again, so a retry after the points were spent still answers with the first entry.

When the stored entry has another type or another amount, the call MUST fail and write nothing. A call without an operation id MUST append a new entry, as before.

Two calls with the same operation id that run at the same moment can both write. This is a known limit, shared with two concurrent debits on one account, and it is out of scope.

**Feature tier**: MVP
**Files**: `pipelinq/lib/Service/PointsLedgerService.php`

#### Scenario: A retried POS credit is credited once (REQ-LOY-011-01)

- GIVEN a customer "Anna" with currentBalance 100 points
- AND a credit of 45 points with operation id "pos:TX-1001" was written
- WHEN the POS sends the same credit again with operation id "pos:TX-1001"
- THEN the service MUST return the first ledger entry
- AND no second entry MUST be written
- AND currentBalance MUST stay 145
- @e2e exclude backend service, covered by PHPUnit

#### Scenario: A retried debit after the points were spent returns the first entry (REQ-LOY-011-02)

- GIVEN a debit of 250 points with operation id "redemption:R-77" was written
- AND Anna spent her remaining points after that
- WHEN the redemption sends the same debit again with operation id "redemption:R-77"
- THEN the service MUST return the first ledger entry
- AND it MUST NOT answer "Insufficient balance"
- @e2e exclude backend service, covered by PHPUnit

#### Scenario: A reused operation id with another amount is refused (REQ-LOY-011-03)

- GIVEN a credit of 45 points with operation id "pos:TX-1001" was written
- WHEN a credit of 60 points arrives with operation id "pos:TX-1001"
- THEN the call MUST fail
- AND no entry MUST be written
- @e2e exclude backend service, covered by PHPUnit

#### Scenario: The same operation id on another account is a new movement (REQ-LOY-011-04)

- GIVEN a credit with operation id "pos:TX-1001" was written for Anna
- WHEN a credit with operation id "pos:TX-1001" arrives for Bram
- THEN a new entry MUST be written for Bram
- @e2e exclude backend service, covered by PHPUnit

#### Scenario: A movement without an operation id is always written (REQ-LOY-011-05)

- GIVEN a credit of 45 points without an operation id was written
- WHEN the same credit arrives again without an operation id
- THEN a second entry MUST be written
- @e2e exclude backend service, covered by PHPUnit

# pipelinq-bookkeeping-to-shillinq: delta for zreport-failure-reason

## ADDED Requirements

### Requirement: A Z-report says why and when passing it on failed

When a raise of the POS-day journal entry does not reach shillinq, the Z-report MUST store the reason (`unreachable` or `not_configured`) and the moment. A successful raise MUST clear both. The Boekhouding card MUST show the status with the reason and the time.

#### Scenario: shillinq does not respond

@e2e exclude Asserted in tests/Unit/Service/PosBookkeepingServiceTest.php and tests/vitest/zReportBookkeepingWidget.spec.js; the pixel check against board PqZRapport covers the drawn card.

- GIVEN a Z-report with takings and a shillinq connection that does not answer
- WHEN the day is passed on at 23:00 and the dispatch fails
- THEN the report stores bookkeepingFailureReason unreachable and the moment
- AND the Boekhouding card reads "Mislukt: shillinq reageerde niet om 23:00"

#### Scenario: a later pass-on succeeds

@e2e exclude Asserted in tests/Unit/Service/PosBookkeepingServiceTest.php.

- GIVEN a Z-report with a stored failure
- WHEN the day is passed on again and shillinq accepts it
- THEN the stored reason and moment are cleared

### Requirement: The Z-report's day figures are three tiles

The Z-report page MUST show the day total, the VAT and the number of transactions as three stat tiles in the side column, above the history.

#### Scenario: open a Z-report

@e2e exclude Asserted in tests/vitest/zReportDetailBoard.spec.js; the pixel check against board PqZRapport covers the tiles.

- GIVEN a Z-report with a total of € 864,00, VAT of € 97,42 and 29 transactions
- WHEN the user opens it
- THEN the side column shows the tiles Dagtotaal € 864,00, Btw € 97,42 and Transacties 29

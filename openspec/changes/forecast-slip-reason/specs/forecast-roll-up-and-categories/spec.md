## ADDED Requirements

### Requirement: A deal that slips out of its forecast period needs a slip reason (REQ-FRC-012)

The system SHALL ask for a slip reason when an open deal in the `commit` or `best_case` category gets an expected close date after the end of the fiscal period that held its previous close date. The reason MUST be at least 10 characters. The system MUST store the reason on the deal with the previous close date and the time of the change, and MUST show it to the manager in the forecast trend. Moving a close date within the same period, or moving the close date of a deal in `pipeline` or a closed category, MUST NOT ask for a reason.

#### Scenario: A commit deal slips to the next quarter (REQ-FRC-012-01)

- GIVEN an open deal in `commit` with expected close date 2026-06-20, in period Q2-2026
- WHEN the seller changes the expected close date to 2026-07-15 and saves
- THEN the save MUST NOT complete until the seller gives a slip reason of at least 10 characters
- AND once given, the deal MUST store the reason, `slipped_from = 2026-06-20` and the time of the change

#### Scenario: The API refuses a slip without a reason (REQ-FRC-012-02)

- GIVEN an open deal in `best_case` with a close date in Q2-2026
- WHEN a client updates the expected close date to a day in Q3-2026 without a `slip_reason`
- THEN the update MUST be rejected with the error `forecast.error.slip_reason_required`
- AND the stored close date MUST be unchanged

#### Scenario: A move within the period needs no reason (REQ-FRC-012-03)

- GIVEN an open deal in `commit` with expected close date 2026-05-10, in Q2-2026
- WHEN the seller changes the close date to 2026-06-25
- THEN the change MUST save without asking for a reason

#### Scenario: A pipeline deal slips without a reason (REQ-FRC-012-04)

- GIVEN an open deal in `pipeline` with a close date in Q2-2026
- WHEN the seller moves the close date into Q3-2026
- THEN the change MUST save without asking for a reason

#### Scenario: The manager reads why a deal slipped (REQ-FRC-012-05)

- GIVEN a deal slipped from Q2-2026 to Q3-2026 with the reason "Customer moved the board decision to July"
- WHEN the manager opens the forecast trend for Q2-2026
- THEN the deal MUST appear in the deals that moved out of commit, with its slip reason

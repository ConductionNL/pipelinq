# dossier-questions: the answer is saved by the server

## ADDED Requirements

### Requirement: The server saves the answer to the customer

`POST /api/tickets/{id}/answer` SHALL save `message` as the ticket's `customerMessage`, add it with its moment to `portalAnswers` unless it repeats the last answer, and set the status `awaiting_customer` when `waitForCustomer` is true. It SHALL refuse an empty message (400), an unknown ticket (404), a ticket that is not a request or complaint (409), and a save OpenRegister refuses for the user (403).

@e2e tests/e2e/messaging-saved-replies.spec.ts answers a portal request and reads customerMessage and the status back.

#### Scenario: Answer and wait
- **WHEN** an employee answers a request and keeps "wait for the customer" ticked
- **THEN** the ticket holds the answer in `customerMessage` and `portalAnswers`, and its status is `awaiting_customer`

### Requirement: The thread shows an answer given before answers were kept

A repair step SHALL add a ticket's non-empty `customerMessage` to `portalAnswers` when no answer there carries the same text.

@e2e exclude a repair step run on upgrade; asserted by PHPUnit in tests/Unit/Repair/BackfillConversationFieldsTest.php

#### Scenario: A legacy answer
- **GIVEN** a ticket with `customerMessage` "We are on it." and no answers
- **THEN** after the repair `portalAnswers` holds "We are on it."

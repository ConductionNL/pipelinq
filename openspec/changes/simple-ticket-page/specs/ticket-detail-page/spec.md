## ADDED Requirements

### Requirement: The ticket page names the next step in the simple structure (REQ-TDP-001)
In the simple structure the ticket page MUST show one primary button chosen by
the ticket's status. Every status change the page offers MUST be a transition of
the ticket lifecycle, sent to OpenRegister's transition endpoint. The full
structure MUST keep the ticket page as it was.

#### Scenario: A new request
@e2e exclude Asserted in tests/vitest/simpleTicketPage.spec.js with the library's own stage resolver against the schema's lifecycle; the e2e instance runs on the full structure.
- **GIVEN** the simple structure and a request with status `new`
- **WHEN** a handler opens the ticket
- **THEN** the primary button MUST read "In behandeling nemen"
- **AND** choosing it MUST post the transition `start`

#### Scenario: A request in progress
@e2e exclude Asserted in tests/vitest/simpleTicketPage.spec.js; the dialog's body is the existing CustomerReplySection.
- **GIVEN** the simple structure and a request with status `in_progress`
- **WHEN** a handler chooses "Beantwoorden"
- **THEN** a dialog MUST open with the answer text area and both save buttons
- **AND** closing it MUST make the page read the ticket again

#### Scenario: A finished ticket
@e2e exclude Asserted in tests/vitest/simpleTicketPage.spec.js for every final status and every ticket type.
- **GIVEN** the simple structure and a ticket in a final status
- **WHEN** a handler opens the ticket
- **THEN** the page MUST show no primary button and no status action

#### Scenario: The full structure
@e2e exclude An equality between the built page and the manifest page, asserted in tests/vitest/simpleTicketPage.spec.js; the existing e2e suite runs on the full structure.
- **GIVEN** the full structure
- **WHEN** the manifest is built
- **THEN** the ticket page MUST equal the page in `src/manifest.json`

### Requirement: Finishing a ticket follows its type (REQ-TDP-002)
The menu MUST offer Complete on a request, Resolve on a complaint and Close on a
contact moment, each only on a status its transition can leave. No action on
the page may be limited to administrators by the page.

#### Scenario: A complaint in progress
@e2e exclude Asserted in tests/vitest/simpleTicketPage.spec.js by evaluating each action's condition with the library's evaluator.
- **GIVEN** the simple structure and a complaint with status `in_progress`
- **WHEN** a handler opens the menu
- **THEN** the group Afronden MUST offer Oplossen and Afwijzen
- **AND** it MUST NOT offer Afronden or Afsluiten

### Requirement: The conversation is read in the page and answered in one place (REQ-TDP-003)
In the simple structure the ticket page MUST show the customer's portal replies
and the employee's answers as one thread, oldest first, without a reply box. A
contact moment MUST show no thread.

#### Scenario: Replies and answers in one thread
@e2e exclude Asserted in tests/vitest/simpleTicketPage.spec.js on the merge and on the mounted section.
- **GIVEN** a request with two portal replies and one answer between them
- **WHEN** a handler opens the ticket in the simple structure
- **THEN** the thread MUST show reply, answer, reply in that order
- **AND** the page body MUST NOT show an answer text area

### Requirement: The ticket page says what the step still needs (REQ-TDP-004)
In the simple structure the ticket page MUST show a what-now card for a ticket
that is new, in progress or waiting for the customer: a title that names the
step, a checklist of what the step still needs, the stage's primary button and
one line naming what comes next. Every checklist item MUST be read from a field
the ticket has (`assignee`, `client`, `slaDeadline`, `portalReplies`); an item is
done when its field holds a value. A finished ticket MUST show no card.

#### Scenario: A new ticket without a handler
@e2e exclude Asserted in tests/vitest/simpleTicketPage.spec.js with the library's own resolver on tickets of every status.
- **GIVEN** a new request with a customer and no handler
- **WHEN** a handler opens it in the simple structure
- **THEN** the card MUST be titled for step 1
- **AND** "The customer is known" MUST be done and "A handler is assigned" not
- **AND** the card's button MUST be Take on

#### Scenario: A completed ticket
@e2e exclude Same test file; a final status has no stage entry.
- **GIVEN** a completed request
- **WHEN** a handler opens it in the simple structure
- **THEN** the page MUST show no what-now card

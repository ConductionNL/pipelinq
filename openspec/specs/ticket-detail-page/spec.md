# ticket-detail-page Specification

## Purpose
The ticket page per ticket type, starting with the contact moment overview on the ticket page.

## Requirements

### Requirement: A contact moment has its own overview on the ticket page

The ticket page SHALL show a contact moment (ticketType interaction) with the cards of board PqContactmoment and hide the request cards. A request, complaint or report SHALL keep its own cards.

#### Scenario: PqContactmoment

- **GIVEN** contact moment PQ-2026-0423
- **THEN** the overview shows Contactgegevens, Gespreksnotities with Uitkomst noteren, and Over dit verzoek
- **AND** the side column shows Klant, Afhandeling, Gekoppelde zaak, Voorgestelde collega's and Opvolging
- **AND** the trail reads "Contactmomenten / PQ-2026-0423"
- **AND** the what-now card lists De klant is bekend, Gekoppeld aan het verzoek waar het over ging, and De uitkomst is genoteerd

#### Scenario: a request

- **GIVEN** a request ticket
- **THEN** the page shows Ticketgegevens, Gesprek and Zaak as before

### Requirement: The ticket edit dialog draws its long texts as multi-line fields

The ticket's edit dialog MUST draw Description as a three-line text field and the message to the customer as a two-line text field, each across both columns of the form, and the Title across both columns above the Description.

#### Scenario: Pieter edits the street party request
- GIVEN ticket PQ-2026-0417 with a two-sentence description
- WHEN Pieter opens Edit
- THEN Title spans the dialog, the whole description shows in a three-line field under it, and "Message to the customer" is a two-line field

### Requirement: The ticket edit dialog names the ticket

The ticket edit dialog SHALL show "Bewerken · {reference}" over its title and "{reference} · {type}" under it, the type
in the reader's language.

#### Scenario: Board PqTicketBewerken

- **WHEN** the agent opens Bewerken on request PQ-2026-0417
- **THEN** the dialog reads "Bewerken · PQ-2026-0417" over "Ticket bewerken" and "PQ-2026-0417 · Verzoek" under it

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

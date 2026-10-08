# dossier-questions Specification

## Purpose
The resident reads their question in full on the portal and replies there.
pipelinq's part of hydra `woo-citizen-journey` J4.2 and J4.3.

## Requirements

### Requirement: A resident reads their question, the dossier it was about and the answers (REQ-QDP-001)

The Vragen page SHALL show the question the resident selects. Its detail SHALL
show the subject, the status and when it was asked, a timeline with the
question, every answer and every reply, each with its moment, and the
documents of the dossier as they were when the resident asked, each with its
public link when it had one. Every answer the employee saves SHALL be kept with
its moment in `portalAnswers`. Internal notes SHALL never be shown.

#### Scenario: Two answers and a reply
- **GIVEN** a question answered "We zoeken het uit." on 28 September and "In november." on 29 September, and replied to with "Dank u." on 30 September
- **WHEN** the resident opens it on the Vragen page
- **THEN** the timeline shows the question, both answers and the reply, each at its own moment, and not the internal note
- test: PHPUnit `tests/Unit/Service/Portal/QuestionDetailServiceTest.php` ("the timeline holds the question every answer and the replies")

#### Scenario: The employee answers again
- **GIVEN** a question with one answer
- **WHEN** the employee saves the same answer, and then a new one
- **THEN** `portalAnswers` holds the first answer with its own moment and the new one, and nothing twice
- test: vitest `tests/vitest/dossierQuestionSections.spec.js` ("keeps every answer with its date")

#### Scenario: The page declares the detail
- **GIVEN** a resident on the portal
- **WHEN** the portal reads pipelinq's contribution
- **THEN** the Vragen page ends with a `detail` block for `myQuestions`, which names `questionTimeline` and `questionDossierItems`
- test: PHPUnit `tests/Unit/Portal/PortalContributionProviderTest.php` ("the questions page shows the selected question")

### Requirement: A converted question links to the Woo request (REQ-QDP-002)

When an employee turned the question into a Woo request, the timeline SHALL
say so, and the question's documents SHALL be led by the Woo request, linked
to the resident's case on the portal.

#### Scenario: The question became a Woo request
- **GIVEN** a question with status `converted` and case `case-42`
- **WHEN** the resident opens it
- **THEN** the timeline ends with "Uw vraag is nu een Woo-verzoek." and the first item is "Uw Woo-verzoek", linking to `#open=dossiq/mijnZaken/case-42` on the portal site
- test: PHPUnit `tests/Unit/Service/Portal/QuestionDetailServiceTest.php` ("a converted question links to the woo request")

### Requirement: The reply is offered on the question while it waits for the resident (REQ-QDP-003)

`replyToQuestion` SHALL be offered on the detail of the resident's question
only, and only while its status is `awaiting_customer`. The portal SHALL stamp
the question's id; the resident only writes the reply.

#### Scenario: The employee waits for the resident
- **GIVEN** a question with status `awaiting_customer`
- **WHEN** the resident opens it
- **THEN** "Reageren op het antwoord" shows with one field, "Uw reactie"
- test: PHPUnit `tests/Unit/Portal/PortalContributionProviderTest.php` ("citizen and client may ask and reply"); portaliq `tests/attached-actions.spec.mjs`

#### Scenario: The question is converted
- **GIVEN** a question with status `converted`
- **WHEN** the resident opens it
- **THEN** no reply is offered, and a forged reply is refused with 409
- test: portaliq PHPUnit `tests/Unit/Controller/PortalRowActionControllerTest.php` ("an attached action outside its rowWhen is 409")

### Requirement: Every portal page names its menu group in Dutch
Every page pipelinq contributes MUST carry a `group`: "Vragen en contact" for a resident's own questions, requests
and complaints, "Namens uw organisatie" for a contact person's organisation pages, "Afspraken en klantenkaart" for a
customer. Every page, collection and action label a resident reads MUST be Dutch, and the contribution label MUST
NOT be the app name. A collection shown on a page MUST carry the page's name, and the page's intro text MUST NOT
carry a heading of its own, so the page shows one heading.

#### Scenario: The resident's question pages
- **GIVEN** a resident signed in on the site
- **WHEN** the site builds the menu
- **THEN** "Mijn verzoeken", "Mijn klachten" and "Mijn vragen" MUST sit under "Vragen en contact"
- **AND** the dossier page MUST offer "Stel een vraag over dit dossier"

### Requirement: A status reads in words
Every portal column that shows a ticket `status` MUST declare `valueLabels` for every status the ticket schema
allows: Ontvangen, In behandeling, Wacht op uw reactie, Opgelost, Afgerond, Afgewezen, Omgezet in een zaak,
Gesloten. On `myQuestions`, `converted` MUST read "Omgezet in een Woo-verzoek".

#### Scenario: A question waits for the resident
- **GIVEN** a question with status `awaiting_customer`
- **WHEN** the resident opens "Mijn vragen"
- **THEN** the status MUST read "Wacht op uw reactie"

### Requirement: The answer notice says the question was answered
When a save changes `customerMessage` to a text on a request ticket on the portal channel that carries a
`portalSubject`, pipelinq MUST write one `portalMessage` in portaliq's register, in Dutch only, with subject "Uw
vraag is beantwoord", a body that names the question and holds an absolute link that opens it on the site
(`/index.php/apps/portaliq/site#open=pipelinq/myQuestions/<id>`), `ruleKey` `pipelinq.question.answered` and
`recordLink` `{app: pipelinq, collection: myQuestions, id: <ticket>}`. The contribution MUST declare that key in
`notifications` and MUST NOT declare a change rule for it. A message that cannot be written MUST NOT fail the save.

#### Scenario: The employee answers
- **GIVEN** a resident's question about their dossier
- **WHEN** the KCC employee saves an answer
- **THEN** the resident's inbox MUST hold "Uw vraag is beantwoord" with a link to the question
- **AND** the inbox MUST NOT also hold "<question> is bijgewerkt" for that answer

### Requirement: The ticket list opens on the newest
The Tickets index MUST sort on `occurredAt` descending by default, and the ticket detail MUST show the heading
"Answer to the customer" once, over the employee's own answer. The resident's `portalReplies` MUST sit under their
own heading, "Replies from the customer", never under "Answer to the customer".

#### Scenario: A new ticket
- **GIVEN** a ticket filed today
- **WHEN** an employee opens "All tickets"
- **THEN** it MUST be on the first page

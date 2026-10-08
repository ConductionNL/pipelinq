## ADDED Requirements

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

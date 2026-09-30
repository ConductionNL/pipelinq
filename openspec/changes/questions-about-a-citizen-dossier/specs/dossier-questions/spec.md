# dossier-questions Specification (delta)

## Purpose

A resident asks the municipality a question about their own Woo dossier, reads
the answer on the portal and replies. The KCC employee sees what the resident
saw, answers, and can turn the question into a Woo request. pipelinq's part of
hydra `woo-citizen-journey` (journey J4, contracts C4, C3 and C5).

## ADDED Requirements

### Requirement: A resident asks a question about a dossier they own (REQ-QCD-001)

Implements hydra `woo-citizen-journey` "A question about a dossier MUST carry a
snapshot of the dossier, not access to it".

The portal contribution SHALL offer the endpoint action `askAboutDossier` to
the audiences `citizen` and `client`, and only when opencatalogi is installed.
The receiver SHALL accept the call only with a valid `X-Portal-Subject`
assertion. It SHALL create a `ticket` with `ticketType` `request`, `channel`
`portal`, the assertion's subject reference in `portalSubject`, and a
`subjectReference` snapshot `{type: "opencatalogi.collection", id, title,
items: [{title, url}]}`. Before it creates anything it SHALL check that the
collection's `owner` equals the subject reference. A collection owned by
someone else SHALL answer 404, the same as one that does not exist.

#### Scenario: A resident asks about their own dossier

- GIVEN a signed-in resident who owns a dossier "Windpark Noord" with three publications
- WHEN they ask "Wanneer valt het besluit over de vergunning?" about it
- THEN a ticket exists with type request, channel portal and the resident's subject reference
- AND its subject reference holds the dossier title and the three publication titles with links

#### Scenario: A resident names someone else's dossier

- GIVEN a signed-in resident and a dossier owned by another resident
- WHEN they ask a question about that dossier
- THEN the answer is 404
- AND no ticket is created

#### Scenario: A call without a valid assertion

- GIVEN a request to the ask endpoint without a signed portal assertion
- WHEN it arrives
- THEN the answer is 401 and nothing is written

#### Scenario: opencatalogi is not installed

- GIVEN pipelinq without opencatalogi
- WHEN portaliq asks for pipelinq's contribution
- THEN the ask action is not offered

### Requirement: The question keeps the dossier as it was when asked (REQ-QCD-002)

Implements hydra `woo-citizen-journey` "A question about a dossier MUST carry a
snapshot of the dossier, not access to it".

`subjectReference` SHALL be written once, when the question is asked. Nothing
in pipelinq SHALL read the dossier again for that ticket.

#### Scenario: The dossier changes after the question

- GIVEN a ticket created from a dossier with three items
- WHEN the resident later removes an item from the dossier
- THEN the ticket still shows the three items as they were when the question was asked

### Requirement: A resident reads their questions and the answers (REQ-QCD-003)

The contribution SHALL offer `citizen` and `client` the collection
`myQuestions`: the resident's own portal request tickets, scoped by
`portalSubject`, projected to `title`, `description`, `status`, `occurredAt`,
`customerMessage`, `portalReplies` and `subjectReference`.

#### Scenario: A resident opens their questions

- GIVEN a resident with one answered question
- WHEN they open their questions on the portal
- THEN they see the question, its status and the answer
- AND they see no internal notes, assignee or pipeline fields

### Requirement: A resident replies to an answer (REQ-QCD-004)

The contribution SHALL offer `citizen` and `client` the endpoint action
`replyToQuestion`. The receiver SHALL append the reply to the ticket's
`portalReplies` with the time it was sent, and move a ticket that awaits the
customer back to in progress. A ticket that is not the resident's own
question SHALL answer 404.

#### Scenario: A resident replies

- GIVEN a question of the resident with status awaiting_customer and an answer
- WHEN the resident replies "Dank u, ik wacht het besluit af"
- THEN the ticket's replies end with that text and the time it was sent
- AND the ticket's status is in_progress

#### Scenario: A resident replies to someone else's question

- GIVEN a ticket asked by another resident
- WHEN a resident sends a reply to it
- THEN the answer is 404 and the ticket is unchanged

### Requirement: The resident hears that there is an answer (REQ-QCD-005)

Implements hydra `woo-citizen-journey` "Every answer, decision and alert MUST
reach the resident through portaliq's notice path" (sender side).

The contribution SHALL declare the change rule `pipelinq.question.answered` on
`myQuestions` for the field `customerMessage`, so portaliq writes the inbox
notice and dispatches it by email and Berichtenbox according to the resident's
preferences. Saving an answer SHALL NOT depend on portaliq being installed.

#### Scenario: The rule is declared for both audiences

- GIVEN pipelinq with opencatalogi installed
- WHEN portaliq reads the contribution for citizen and for client
- THEN both declare rule pipelinq.question.answered on myQuestions, field customerMessage

#### Scenario: portaliq is not installed

- GIVEN pipelinq without portaliq
- WHEN an employee saves an answer
- THEN the answer is saved and nothing fails

### Requirement: The employee sees what the resident asked about (REQ-QCD-006)

Implements hydra `woo-citizen-journey` scenario "An employee sees what the
resident asked about".

TicketDetail SHALL show a ticket's `subjectReference`: the dossier title and
each item's title as a link to the public publication. It SHALL show nothing
for a ticket without one.

#### Scenario: An employee opens a question about a dossier

- GIVEN a question asked about a dossier with three items
- WHEN a KCC employee opens the ticket
- THEN the ticket shows the dossier title and the three items with links

### Requirement: The employee answers from the ticket (REQ-QCD-007)

TicketDetail SHALL offer a request or complaint ticket an answer section: the
resident's replies oldest first, a text field for the message to the resident,
"Antwoord opslaan", and "Opslaan en wachten op een reactie", which also sets the
status to awaiting_customer. Saving SHALL write `customerMessage` on the
ticket.

#### Scenario: An employee answers a question

- GIVEN an open question from a resident
- WHEN the KCC employee writes the answer and chooses "Opslaan en wachten op een reactie"
- THEN the ticket's message to the resident holds the answer
- AND its status is awaiting_customer

### Requirement: The employee turns a question into a Woo request (REQ-QCD-008)

Implements hydra `woo-citizen-journey` "A Woo request MUST be created by one
dossiq path, from the portal and from pipelinq alike" (caller side).

TicketDetail SHALL offer "Omzetten naar Woo-verzoek" on a ticket with a
`subjectReference` that is not yet converted, only when dossiq's
`OCA\Dossiq\Woo\WooRequestIntake` is available. Converting SHALL call
`WooRequestIntake::start()` with the ticket's `portalSubject`, the dossier id,
title and description, `origin` `pipelinq` and the ticket id as
`originReference`, then set the ticket's status to `converted` and its
`caseReference` to the returned case id.

#### Scenario: An employee converts a question

- GIVEN a ticket asked from a dossier
- WHEN the KCC employee converts it into a Woo request
- THEN dossiq is asked to start a Woo request for the same resident and dossier, with origin pipelinq
- AND the ticket's status is converted and it references the returned case

#### Scenario: dossiq is not installed

- GIVEN pipelinq without dossiq
- WHEN a KCC employee opens a question about a dossier
- THEN no convert action is shown
- AND a direct call to convert answers 409 and changes nothing

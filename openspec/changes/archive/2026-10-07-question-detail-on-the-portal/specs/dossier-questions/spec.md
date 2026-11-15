# dossier-questions Specification (delta)

## Purpose

The resident reads their question in full on the portal and replies there.
pipelinq's part of hydra `woo-citizen-journey` J4.2 and J4.3.

## ADDED Requirements

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

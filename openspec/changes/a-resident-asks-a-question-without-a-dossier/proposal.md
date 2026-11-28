# Proposal: a-resident-asks-a-question-without-a-dossier

## Why

Seen in the Zuiddrecht go-live rehearsal: the site's contact page `/contact/vraag` said "U kunt nu geen vraag stellen". Its `contactForm` block binds to a pipelinq create action for a question, and pipelinq contributed none. The only question actions were `askAboutDossier`, an endpoint action attached to a dossier, and `replyToQuestion`. A resident had no way to ask a question that is not about a dossier.

## What changes

- pipelinq contributes `askQuestion`, a create action on `ticket` for the citizen and client audiences. It stamps `ticketType: request` and `channel: portal` server-side, so the question is listed under Mijn vragen (`myQuestions`), and it stamps the resident's own reference in `portalSubject`.
- The resident writes only the subject (`title`, "Onderwerp") and the question (`description`, "Uw vraag"); both are required.
- The action asks the portal for its `contact-confirmation` mail, naming the subject (`topicField: title`), never the question.
- It is offered whether or not opencatalogi is installed.

## Not changed

- `askAboutDossier` and `replyToQuestion`.

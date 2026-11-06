# Proposal: a-contact-question-is-required-and-dated

## Why

Rehearsal R3, 16 October:

1. The question form on `/contact/vraag` (the `askQuestion` create action) labelled "Uw vraag" as optional. The action's field configs say `required: true`, but portaliq honours that only for a field the schema mandates, and the ticket schema mandates neither the subject nor the question. #2520 made the question required on the dossier question form through `requiredFields`; this action had no such list.
2. "Gesteld" on Mijn vragen stayed empty for a question asked there. The dossier question endpoint stamps `occurredAt`; the create action, which portaliq writes, set nothing.

## What changes

- `askQuestion` declares `requiredFields: [title, description]`.
- `askQuestion` declares `createdAtField: occurredAt`, which portaliq stamps with the moment it writes the ticket (portaliq `a-create-stamps-the-moment-it-was-made`).

## Not changed

- Questions asked before this change keep an empty `occurredAt`.

## Rollback

Revert the commit.

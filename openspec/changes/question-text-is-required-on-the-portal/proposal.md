# Proposal: question-text-is-required-on-the-portal

## Why

Seen in the Zuiddrecht go-live rehearsal R2: the form "Stel een vraag over dit dossier" labelled its question field "Uw vraag (niet verplicht)". The question is the point of the form, and the receiver (`PortalQuestionController::ask`) refuses a question without text with 400.

The action declared `required: true` in the field's config, but portaliq honours that flag only for a field a schema mandates (the WMEBV data-minimisation guard), and an endpoint action has no schema. An endpoint action names its required fields in `requiredFields` (portaliq REQ-SMF-023). The reply form had the same gap for its message.

## What changes

- `askAboutDossier` declares `requiredFields: ['question']`.
- `replyToQuestion` declares `requiredFields: ['message']`.

## Not changed

- The subject (`title`) stays optional.
- The receivers' own checks.

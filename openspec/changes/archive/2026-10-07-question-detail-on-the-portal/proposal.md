---
kind: code
depends_on: [questions-about-a-citizen-dossier]
---

# Proposal: question-detail-on-the-portal

## Why

A resident who asked about their Woo dossier cannot read the answer on the
portal, and cannot reply to it (hydra woo-citizen-journey J4.2 and J4.3, found
by the Woo movies lane). The Vragen page lists the questions, but declares no
detail block, so no renderer shows a selected question. `replyToQuestion` is
declared, and no page places it: it has no `rowField`, so the portal can never
fill in which question the reply is for.

The ticket also keeps only the latest answer (`customerMessage`), without a
date, so even a detail could not say what was answered when.

## What changes

- The Vragen page gains a `detail` block for `myQuestions`.
- `myQuestions` names two provider methods. `questionTimeline` returns the
  question, every answer and every reply, each with its moment, and the
  conversion into a Woo request. `questionDossierItems` returns the dossier as
  it was when the resident asked, each document with its link, led by the Woo
  request when the question was converted.
- `replyToQuestion` attaches to `myQuestions` (`attachTo.collection`, portaliq
  `attach-to-own-collection`), with `rowField: ticket` and
  `rowWhen: status in [awaiting_customer]`. The portal shows the reply form on
  the question while the employee waits for the resident, and refuses it
  otherwise.
- The ticket gains `portalAnswers`: every answer with when it was sent. The
  employee's answer section appends to it when the answer text changes.
  Register 1.9.0, ticket schema 1.4.0.

## Depends on

- portaliq `attach-to-own-collection`: an action attached to its own app's
  collection, narrowed to one collection, with `rowWhen` in the listing.
  Without it the reply attaches to every ticket collection and the forward
  refuses it.

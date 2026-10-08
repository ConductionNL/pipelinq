---
kind: code
depends_on: []
---

# Proposal: questions-about-a-citizen-dossier

## Summary

A resident collects Woo publications in their own dossier on the portal. Let
them ask the municipality a question about that dossier, read the answer on
the portal and reply to it. The KCC employee sees what the resident saw when
they asked, answers from the ticket, and can turn the question into a Woo
request in dossiq.

This is pipelinq's part of the Woo citizen journey (hydra
`openspec/changes/woo-citizen-journey`, journey J4, contracts C4, C3 as sender
and C5 as caller).

## Motivation

Journey J4 in `journey-map.md` of the hydra change lists six steps. None runs
today:

- 4.1 "No citizen question action, no dossier link". pipelinq's portal
  contribution serves the audiences `client` and `customer` only
  (`lib/Portal/PortalContributionProvider.php:92`), and the `customer`
  audience has no ticket collection or create action at all.
- 4.2 "Ticket screens exist, the dossier panel does not".
- 4.3 "The field exists, the answer screen is specified only". An employee can
  only fill `customerMessage` through the generic Edit form. The answer
  section `CustomerReplySection` is specified in
  `messaging-saved-replies-and-resend` (task 3.3) and not built.
- 4.4 No notice reaches the resident when the answer is posted.
- 4.5 A resident replies only in pipelinq's own bespoke portal
  (`PortalRequestService::addReply()`), not through portaliq.
- 4.6 No conversion into a Woo request.

## Hydra requirements implemented

From `hydra/openspec/changes/woo-citizen-journey/specs/woo-citizen-journey/spec.md`:

- "A question about a dossier MUST carry a snapshot of the dossier, not access to it"
- "Every answer, decision and alert MUST reach the resident through portaliq's notice path" (sender side, rule key `pipelinq.question.answered`)
- "A Woo request MUST be created by one dossiq path, from the portal and from pipelinq alike" (caller side, scenario "An employee converts a question")

## What changes

- The `ticket` schema gains `subjectReference` (the dossier snapshot) and
  `portalSubject` (the portal subject reference of the resident who asked).
  Ticket schema 1.1.0 to 1.2.0, register 1.6.1 to 1.7.0.
- The portal contribution serves `citizen` as well, and offers `citizen` and
  `client` the endpoint actions `askAboutDossier` and `replyToQuestion`, the
  collection `myQuestions`, and the change rule `pipelinq.question.answered`.
- A new receiver verifies portaliq's `X-Portal-Subject` assertion and checks
  that the resident owns the dossier and the question.
- TicketDetail shows the dossier snapshot, a minimal answer section, and
  "Omzetten naar Woo-verzoek", which calls dossiq's
  `OCA\Dossiq\Woo\WooRequestIntake::start`.

## Out of scope

- Saved replies in the answer section. `messaging-saved-replies-and-resend`
  adds its picker to the `CustomerReplySection` built here.
- Placing the ask action on the dossier page. That page is opencatalogi's
  contribution and portaliq renders it (C7).
- The bespoke in-app portal stays as it is.

## Impact

- dossiq: called through `class_exists` and the container only. Without dossiq
  the convert action is hidden.
- opencatalogi: its `collection` object is read once, server side, when a
  question is asked. Without opencatalogi the ask action is not offered.
- portaliq: delivers the notice through its existing change-rule listener.
  Without portaliq nothing listens and the answer is still saved.

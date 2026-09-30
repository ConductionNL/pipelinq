# Design: questions-about-a-citizen-dossier

## Context (read at pipelinq development a6d658cf8)

- **The provider is a manifest.** `lib/Portal/PortalContributionProvider.php`
  returns pure data. A `type: create` action lets portaliq write the whitelisted
  fields straight into OpenRegister, with no hook where pipelinq could check
  who owns the dossier. The ownership check of C4 therefore needs an endpoint
  action (portaliq contract v2, A6): portaliq forwards the call server to
  server with a signed `X-Portal-Subject` assertion. pipelinq has no receiver
  for it yet. The fleet reference receiver is petstore's
  `PortalAssertionVerifier` and `PortalActionController`.
- **The notice path is a change rule.** portaliq's
  `PortalRecordChangeListener` reads the `notifications` of every contribution.
  For a rule `{ruleKey, collection, on: {field, operator: changed}, titleField}`
  it compares the field on every OpenRegister update of that collection's
  schema. When it changed, portaliq writes the `portalMessage` to the resident
  named in the record's scope field and dispatches the rule key, so the
  resident's preferences decide on email. The notice goes to the inbox and by
  email only: Berichtenbox needs the resident's BSN, which this journey does
  not store (Ruben, 30 Sep; hydra#730). The change rule is one of the two C3
  paths the settled contract allows; the other is a `portalMessage` the app
  writes itself with the new optional `ruleKey`. pipelinq uses the change
  rule, because the answer already lives on the ticket. The rule is only kept when its collection is
  scoped by the subject reference on the record, and its field is projected to
  the resident.
- **The ticket has no field for the portal subject.** `client` and `contact`
  point at pipelinq objects, and a Woo resident is neither. Scope needs a field
  holding the `subjectRef`.
- **The public publication URL.** opencatalogi answers an anonymous
  `GET /index.php/apps/opencatalogi/api/search/{id}` for a public publication.
  The dossier `collection` lives in opencatalogi's register `publication`
  (C1), schema `collection`.
- **Status and case reference exist.** The ticket status enum holds
  `converted`, and `caseReference` is the ADR-048 case reference used by the
  existing "Convert to case" handoff (`SemanticHandoffController`).

## Decisions

### D1. Two new ticket properties

- `subjectReference`: object `{type, id, title, items: [{title, url}]}`,
  `type` fixed to `opencatalogi.collection`. Written once, when the question is
  asked. Never updated from the dossier.
- `portalSubject`: string, the `subjectRef` of the resident who asked. The
  scope field of `myQuestions`, and the resident dossiq receives on
  conversion. Not a BSN: portaliq's subject reference is opaque.

### D2. Endpoint actions, one receiver

`POST /api/portal/questions` (`askAboutDossier`) and
`POST /api/portal/questions/reply` (`replyToQuestion`) on a new
`PortalQuestionController`. Both are `#[PublicPage]` and trust only the
verified assertion (`PortalAssertionVerifier`, a copy of the petstore
reference). The controller refuses an audience other than `citizen` or
`client`.

- `askAboutDossier` takes `collectionId` and `question` (and an optional
  `title`). It declares `attachTo: {app: opencatalogi, schema: collection}`
  and `rowField: collectionId`, the attachment the portaliq lane defined for
  C7 (hydra#727): portaliq shows the action on the resident's dossier page
  and forwards the dossier id. It reads the collection through OpenRegister without RBAC, then
  compares `owner` with the assertion's `sub`. A missing collection and a
  foreign one both answer 404. It creates a `ticket` with
  `ticketType: request`, `channel: portal`, `status: new`, `portalSubject`,
  `subjectReference` and `occurredAt`.
- The snapshot resolves each item's publication for its title and links it to
  the public URL. An item whose publication cannot be read keeps the note or
  the publication id as title, and no link.
- `replyToQuestion` takes `ticket` and `message`. It answers 404 unless the
  ticket's `portalSubject` equals `sub` and the ticket carries a
  `subjectReference`. It appends `{message, createdAt}` to `portalReplies` and
  moves `awaiting_customer` back to `in_progress`, as `addReply()` does.

### D3. The provider serves `citizen`

`getAudiences()` returns `client`, `customer` and `citizen`. `citizen` gets a
contribution with only the question surface. `client` keeps everything it has
and gains the same surface. The surface is:

- collection `myQuestions`: schema `ticket`, filter
  `{ticketType: request, channel: portal}`, `scopeField: portalSubject`
  (default subject scoping), fields `title`, `description`, `status`,
  `occurredAt`, `customerMessage`, `portalReplies`, `subjectReference`.
- actions `askAboutDossier` and `replyToQuestion`, endpoint actions.
  `askAboutDossier` is offered only when opencatalogi is installed
  (`class_exists` on its `Application` class, the duck-typing the contract
  asks for). `replyToQuestion` stays, so questions asked earlier can still be
  answered by the resident.
- notification rule `pipelinq.question.answered` on `myQuestions`, field
  `customerMessage`, title field `title`.

### D4. Converting to a Woo request

`WooRequestConversionService` resolves `OCA\Dossiq\Woo\WooRequestIntake` from
the container when the class exists, and calls `start()` with
`{subjectRef: portalSubject, collectionId: subjectReference.id, onderwerp:
title, omschrijving: description, periodeVan: null, periodeTot: null, origin:
pipelinq, originReference: ticket id}`. On success the ticket gets
`status: converted` and `caseReference: caseId`. Routes:
`GET /api/tickets/{id}/woo-request/availability` and
`POST /api/tickets/{id}/woo-request`, `#[NoAdminRequired]`, with the same
privileged-user check the case handoff uses, and the ticket read through
OpenRegister RBAC. A ticket without `subjectReference`, or already converted,
answers 409.

### D5. TicketDetail

Three `bodyWidgets`, each rendering nothing when it does not apply:

- `DossierSnapshotSection`: the dossier title and its items as links, with a
  line saying this is what the resident saw when asking.
- `CustomerReplySection`: the minimal answer control of
  `messaging-saved-replies-and-resend` D5 without the saved-reply picker. The
  resident's replies oldest first, a text area bound to `customerMessage`, and
  two buttons: "Antwoord opslaan", and "Opslaan en wachten op een reactie",
  which also sets `awaiting_customer`. It writes through the object store, so
  the change rule fires on save. It renders for `request` and `complaint`.
- `WooConversionSection`: "Omzetten naar Woo-verzoek", shown only when the
  availability endpoint says dossiq answers and the ticket has a snapshot.

## Risks

- **opencatalogi's collection shape.** Built against C1 with a fixture. If the
  opencatalogi lane ships other slugs, only two constants change.
- **The public link may move to a portal page.** The URL is built in one
  method, so a later site detail page is a one-line change.
- **dossiq's service signature.** Built against C5. Until dossiq lands the
  class, the convert action is hidden.

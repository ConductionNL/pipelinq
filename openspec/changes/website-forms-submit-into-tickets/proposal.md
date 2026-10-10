---
kind: code
depends_on: []
---

# Proposal: website-forms-submit-into-tickets

pipelinq's half of decision 179 (Ruben, 10 October 2026): "We dont intake to an intake, we intake into a case, or ticket or something else." Cross-app change: `hydra/openspec/changes/form-submits-into-its-destination-object`, architecture in hydra ADR-117. Needs `openregister/form-destination-validator`; the landing-page part needs `portaliq/submit-creates-the-case-directly`.

## Why

Three website paths stop short of the object a person works on.

- `POST /api/enquiry` writes an `enquiry`, "kept separate from lead on purpose". A sales user converts it, and an async flow, shipped disabled, upserts a client, a contact and a lead. The enquiry is a holding object with a manual exit.
- A landing-page submit is stored in portaliq's `landingPageSubmission`, then relayed to pipelinq by event. The form fields are hard-coded and never checked against the lead schema.
- `public-intake-forms` contradicts itself. Lines 24 to 499 describe an in-app builder with a `formSubmission` log and a retry queue. Lines 587 to 651 retire that builder for Nextcloud Forms responses that are linked to a lead but never create one. The spec says nothing of it is built.

The portal request path already does the right thing: it writes a `ticket` directly.

## What changes

1. **Website enquiries become tickets.** The website form submits into `ticket` with `ticketType: request`, channel `website` and `source` from the allowlist (question Q4, recommended option). Spam and closed are ticket states. Turning a ticket into a lead stays a person's decision, as a handoff between two real objects.
2. **Landing pages submit into a lead.** The landing-page form's destination is `lead`. Contact matching and the `submit` touchpoint move into pipelinq's lead-create listener, keyed on the nonce as today. `LandingPageProvisioningService` declares the form with a destination, checked by OpenRegister's validator.
3. **One answer for public forms.** `public-intake-forms` keeps one model: forms are fleet forms (buildiq-authored, portaliq-hosted) with a pipelinq destination. The in-app builder, the `formSubmission` log and the retry queue are removed from the spec. Nextcloud Forms stays a source only through a binding checked against the destination when made (Q6, recommended option).
4. **References.** The portal request and website ticket answers carry the ticket's human-readable reference and `receivedAt`, not only an id.
5. **Drain, then remove `enquiry`.** `occ pipelinq:enquiry:drain` turns every open enquiry into a ticket and reports. The `enquiry` schema, the "Enquiry to lead" flow and `EnquiryController` go when the drain reports zero.

## Rollback

`enquiry` stays until its drain reports zero. The landing-page listener stays until portaliq stops relaying.

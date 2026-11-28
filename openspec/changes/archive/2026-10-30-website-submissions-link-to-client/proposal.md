---
kind: code
screens: [PqAanvraagOmzetten]
---

## Why

The PqAanvraagOmzetten board drew a website-enquiries inbox with a convert step. Decision 179 retired that in-between
intake layer: a website form submits straight into the object it names, usually a ticket. Ruben decided (23 Oct) that
the board becomes a lens over what came in through a form, plus a step that links what the visitor typed to a client
and a contact person. A form ticket carries the reporter's name, e-mail, phone and organisation as typed
(`reporterName`, `reporterEmail`, `reporterPhone`, `reporterOrganisation`) and no client, so nobody can see it on the
client's page until someone links it.

## What changes

- A page **Website submissions** (`/website-submissions`): the tickets that came in through a form (`source` is set),
  newest first, with lenses Not linked yet (default), Linked, Closed and All, and the columns the board draws:
  received, title, from, organisation, form, page, status, handled by.
- **Link to a client** on the ticket page while the ticket has no client: a dialog that shows the submission as sent and
  links it in one step to a client (a new one named after the organisation the visitor typed, or an existing one, with a
  match on that name suggested) and a contact person (new from the typed name and e-mail, or existing, with a match on the
  e-mail suggested), and can create a lead in the default pipeline for it.
- Every write goes through the object API and the contact-first create the client and contact forms already use, as the
  signed-in colleague, so OpenRegister's rights decide.

Leads that came in through a form already get their contact and client from `SubmittedLeadIntake`, so they need no step.

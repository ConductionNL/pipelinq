# Design: contact-moments-keep-draft

## Context (read at pipelinq development cfe0a0a51)

- **The form.** `src/components/ContactmomentQuickLog.vue` (402 lines) holds
  `form: {title, channel, outcome, client, contact, parentTicket, description,
  duration, notes}` in component data (:197-210), takes `clientId`,
  `requestId` and `inline` props (:178-192), and `onSave()` (:308) writes a
  `ticket` through `objectStore.saveObject('ticket', data)` (:335). It is mounted
  on ClientDetail and ContactDetail (`src/manifest.json:2144`).
- **Telephony.** `CtiService::createPendingContactmoment()` (:252) already writes
  a pending ticket when a call rings, so telephony moments survive a closed tab.
- **Retention.** OpenRegister enforces `x-openregister-archival` blocks on
  pipelinq schemas (task and ticket use it today, `pipelinq_register.json:1516`,
  `register.d/99-unify-ticket-supertype.json:27`).

## Decisions

### D1. The draft is the ticket itself, in status draft

Reworked on 12 October 2026 (decisions 180, 201 and 216, Q-pipelinq-180-1 and
Q-pipelinq-180-2). The first design kept a separate `contactMomentDraft`
schema. Ruben decided that a draft is the destination object in status
`draft`, and that OpenRegister is extended so an owner can finish their own
draft. So:

- The draft is a `ticket` with `ticketType: interaction` and
  `@self.status: draft`. It may miss the subject; every value it does hold
  must fit the ticket schema. There is no draft schema, no `form` envelope
  and no `author` field: OpenRegister's owner is the author.
- OpenRegister keeps a draft to its owner. The `ticket` schema sets
  `draftsListed: false`, so the agent's own drafts stay out of every ticket
  list, count and facet too. The quick log asks for them with `_drafts=true`.
- The `ticket` schema sets `draftLifetimeDays: 7`, explicitly: OpenRegister's default is 30 days (decision 228). OpenRegister's one draft
  cleanup removes a draft nobody changed for seven days. pipelinq's own daily
  job (decision 145) is not built and not needed.
- `ticket` carries an `x-openregister-archival` block, which refuses every
  user delete. OpenRegister lets a stored draft through that gate and removes
  it for good, so Discard and the cleanup both work.
- The `newTicket` notification rule fires when the draft is saved as a
  contact moment, not when the first letters are autosaved.

Needs OpenRegister with the change `draft-owner-activation-and-lifetime`.

### D2. Autosave on quiet and on leaving

The quick log saves the draft two seconds after the last keystroke, and on
`visibilitychange` to hidden (with `fetch keepalive`, which a closing tab still
sends). One draft per author, client and request: a new save overwrites it. An
empty form saves nothing and deletes an existing draft.

### D3. Restore is offered, never forced

On mount, the quick log asks OpenRegister for the agent's draft contact
moments and picks the one for its `clientId` and `requestId`. If one exists it
shows "You have an unsaved contact moment from {time}" with Restore draft and
Discard. Discard deletes the draft ticket.

Save does not create a second ticket. It posts the form to OpenRegister's
owner action, `POST /api/objects/pipelinq/ticket/{id}/activate`, which lays it
over the stored draft, checks it against the whole ticket schema and moves it
out of draft. A refusal (422) shows what OpenRegister found and keeps the form
and the draft. A draft that is gone (404 or 409) is saved as a new contact
moment. A save before any draft was written creates the ticket the ordinary
way.

### D4. Session expiry keeps the text on screen

When the save or an autosave answers 401, the form keeps its data, shows "Your
session has ended. Log in again in a new tab, then press Save here", and retries
on the next Save. The server draft from before the expiry is still there if the
tab closes.

## Risks

- `keepalive` requests are limited in size by browsers (64 KB); a contact moment
  is far below that. If one fails, the last quiet-time autosave stands.
- Autosave writes add audit trail rows on the draft ticket. Drafts are
  short-lived: OpenRegister removes an abandoned one after seven days.
- A flow or webhook on "ticket created" still fires on a draft's first
  autosave. OpenRegister's notification rules wait for the save; its other
  create-time listeners do not yet (named in OpenRegister's change).

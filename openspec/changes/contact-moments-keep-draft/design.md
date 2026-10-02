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

### D1. A separate draft schema, not a draft ticket

`contactMomentDraft` holds `author` (uid), `client`, `request`, `form` (the
quick log fields as an object) and `updatedAt`. It is not a `ticket` status, so
no ticket list, Queue, SLA or report can ever count a draft. Its authorization
block allows read, update and delete to the author only. Its
`x-openregister-archival` block destroys it seven days after `updatedAt`.

### D2. Autosave on quiet and on leaving

The quick log saves the draft two seconds after the last keystroke, and on
`visibilitychange` to hidden (with `fetch keepalive`, which a closing tab still
sends). One draft per author, client and request: a new save overwrites it. An
empty form saves nothing and deletes an existing draft.

### D3. Restore is offered, never forced

On mount, the quick log looks up the author's draft for its `clientId` and
`requestId`. If one exists it shows "You have an unsaved contact moment from
{time}" with Restore draft and Discard. A successful `onSave()` deletes the
draft.

### D4. Session expiry keeps the text on screen

When the save or an autosave answers 401, the form keeps its data, shows "Your
session has ended. Log in again in a new tab, then press Save here", and retries
on the next Save. The server draft from before the expiry is still there if the
tab closes.

## Risks

- `keepalive` requests are limited in size by browsers (64 KB); a contact moment
  is far below that. If one fails, the last quiet-time autosave stands.
- Autosave writes add audit trail rows on the draft object. Drafts are
  short-lived and destroyed by the archival term, which removes them with their
  history.

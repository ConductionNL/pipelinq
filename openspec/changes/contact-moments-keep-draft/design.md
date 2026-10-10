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
block is `scope: private`, so read, update and delete are open to the author
(the object's owner) and to administrators only.

**Amended while building (10 Oct).** No `x-openregister-archival` block.
OpenRegister makes every schema that declares one refuse user deletes (403
`SCHEMA_ARCHIVAL_IMMUTABLE`), which would make D3's delete on save, and
Discard, impossible. The quick log applies the seven days instead: it never
offers a draft whose `updatedAt` is older than seven days, and removes the
author's expired drafts each time it opens. A server-side removal for drafts
nobody opens again is Q-pipelinq-3.

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
on the next Save. A 412 counts too: after the agent logs in again in another
tab, this tab's request token belongs to the old session. The next Save first
fetches a fresh token from `/csrftoken`, then saves. The server draft from before the expiry is still there if the
tab closes.

## Risks

- `keepalive` requests are limited in size by browsers (64 KB); a contact moment
  is far below that. If one fails, the last quiet-time autosave stands.
- Autosave writes add audit trail rows on the draft object. Drafts are
  short-lived and destroyed by the archival term, which removes them with their
  history.

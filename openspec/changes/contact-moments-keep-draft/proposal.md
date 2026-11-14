---
kind: code
depends_on: []
# Needs OpenRegister's change draft-owner-activation-and-lifetime (decision 216).
---

# Proposal: contact-moments-keep-draft

## Summary

A contact moment you are typing is kept while you type. Close the tab, lose the
connection or let the session expire, and the next time you open the quick log
for that client your text is offered back. Today a telephony call keeps its
pending contact moment on the server, but a manually typed one lives only in the
page and is gone when the page is.

## Motivation

One row of the pipelinq capability matrix (`openspec/parity/capabilities.json`,
compared 2026-09-24), decided `build` by the OpenSpec pass of 2026-09-27.

**`cm-keep-draft`**, "Keep a half finished contact moment when the browser closes
or the session expires". Rated partial, built.state built. Matrix evidence: "a
telephony call creates a pending contact moment server side as soon as it rings
(lib/Service/CtiService.php:239 'Create a pending contactmoment ticket'), so that
record survives a closed browser; the manual form
src/components/ContactmomentQuickLog.vue (mounted at src/manifest.json:2144)
keeps its input only in component data, with no local or server draft". Note: "a
half typed manual contact moment is lost when the tab closes or the session
expires". Demand: feature request,
https://github.com/Klantinteractie-Servicesysteem/KISS-frontend/issues/1558. One
competitor rates it yes:

- odoo-crm: source read, `addons/web/static/src/views/form/form_controller.js:514-518`
  `beforeUnload` calls `urgentSave` so a half filled record is saved when the tab
  closes, and `addons/mail/static/src/core/common/composer.js:909`
  `saveContentToLocalStorage` keeps a half written message.

The missing half is the manual form.

## What changes

- While an agent types in the quick log, the form is saved as a draft on the
  server every few seconds of quiet and when the tab is closed.
- A draft belongs to its author only and never shows in lists or reports. It
  is the contact moment itself, a ticket in OpenRegister's status `draft`
  (decisions 180 and 216): saving makes it definitive, and OpenRegister
  removes it after seven days without a change.
- Opening the quick log for a client or request that has a draft offers Restore
  draft or Discard.
- When the session has expired, a save attempt keeps the text on screen and
  says how to log in again without losing it.

## Out of scope

- Drafts in the browser's local storage. On a shared KCC workstation that would
  leave a caller's details on the machine for the next user; the server draft is
  owner-only and expires.
- Drafts for the other ticket forms (request, complaint). The same mechanism can
  be reused there later.

## Impact

- New schema `contactMomentDraft` with owner-only access and a seven day
  archival term.
- `src/components/ContactmomentQuickLog.vue` (autosave, restore, session expiry).

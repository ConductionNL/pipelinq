---
kind: code
depends_on: []
---

# Proposal: platform-record-lock

## Summary

Lock a client, a lead or a ticket once it is done, so nobody changes it by
accident. Unlock it when a real change is needed, and see who locked it and
when. OpenRegister already has this state: a frozen object stays in every
list and refuses every edit. pipelinq never offers it. This change puts Lock
and Unlock on the three detail pages and asks OpenRegister for the one thing
its freeze does not do yet: refuse a delete.

## Motivation

One row of the pipelinq capability matrix (`openspec/parity/capabilities.json`),
decided `build` by the OpenSpec pass of 2026-09-27.

**`plat-record-lock`**, "Lock a finished record so nobody can change or delete
it by accident". Rated partial, built.state built. Matrix evidence: "finished
records freeze per type: a contract in a terminal state cannot transition
(lib/Service/ContractService.php:152-160), a confirmed POS transaction is
guarded (lib/Lifecycle/PosTransactionConfirmGuard.php), and tickets cannot be
hard deleted by hand because their schema declares archival [...]. OpenRegister's
lock (routes objects#lock) is a temporary edit lock, and no pipelinq page offers
a lock action on a client, lead or ticket". Note: "no general 'lock this record'
for users".

The decision reason: partial and built, and the missing half is a lock a user
puts on a client, lead or ticket. Demand: changelog,
https://github.com/espocrm/espocrm/issues/3592, with one competitor rated yes.

Competitor cells, quoted from the matrix:

- espocrm (yes): "New in 10.0.0: application/Espo/Modules/Crm/Resources/metadata/scopes/Account.json:17
  \"lockable\": true and entityDefs/Account.json:163 \"isLocked\", with
  client/src/handlers/record/lock-action.js giving Lock and Unlock on the detail
  view and mass actions"; https://docs.espocrm.com/general/record-locking/
  "Locked records cannot be edited (except for designated fields) or removed".
- pipedrive (partial): https://support.pipedrive.com/en/article/read-only-fields
  makes custom field data "visible to users without them being able to edit
  it", and delete rights are set per permission set
  (https://support.pipedrive.com/en/article/how-can-i-delete-items-in-pipedrive);
  locking one finished record is not described.
- odoo-crm (partial): "addons/sale/models/sale_order.py:78 locked and :1320
  action_lock lock a confirmed sales order [...]; leads, contacts and other
  records have no lock, only archive."
- kiss (partial): "a saved contact moment or contactverzoek cannot be edited or
  deleted from any KISS screen [...]; this is by design, not a lock action".
- hubspot-crm (unknown): restricts property edit rights
  (https://knowledge.hubspot.com/properties/restrict-view-edit-access-for-properties)
  but no article describes locking a whole record.

## What changes

- The client, lead and ticket schemas switch on OpenRegister's object state
  verbs (`x-openregister-archive: {"enabled": true}`), which is the switch
  OpenRegister reads before it lets anyone freeze an object.
- ClientDetail, LeadDetail and TicketDetail get a Lock action and an Unlock
  action in the page's Actions menu. Only one of the two shows at a time.
- On a lead and a ticket, Lock shows only once the record is finished (a final
  lifecycle state). On a client it shows always, because a client has no
  finished state.
- A locked record shows a banner naming who locked it and when.
- An edit to a locked record is refused with OpenRegister's message, which
  names who locked it.
- OpenRegister gains one rule, specified there: a frozen object refuses
  deletion. Until that lands, a locked record in pipelinq cannot be changed
  but can still be deleted.

## Out of scope

- Fields that stay editable on a locked record (EspoCRM's "designated
  fields"). OpenRegister's freeze refuses every data write; a field list is an
  OpenRegister decision.
- Locking many records at once from a list. The same verb can be a bulk
  action later.
- Locking contracts and POS transactions. They already freeze by their own
  lifecycle.
- Freezing a record automatically on entering a final state. OpenRegister has
  this open as its task C29.2 (`freezesObject` on a lifecycle state); this
  change keeps the lock a person's act, which is what the row asks.

## Impact

- `lib/Settings/pipelinq_register.json` (client, lead) and
  `lib/Settings/register.d/99-unify-ticket-supertype.json` (ticket): the
  archive switch, and a non-materialised `isFinished` calculation on lead and
  ticket.
- `src/manifest.json` pages ClientDetail, LeadDetail and TicketDetail:
  `headerActions` and a banner widget.
- `src/registry.js`: one handler, `unlockRecord`.
- No new PHP. The freeze, the audit entries and the edit refusal are
  OpenRegister's (ADR-022).
- Cross-repo: OpenRegister must refuse deletion of a frozen object. Named in
  design.md D5 and in the hand-back of the OpenSpec pass.

# A finished ticket can be locked

## Why
Board PqTicket (design-system #821) draws "the same ticket, finished and locked": Vergrendelen sits in the menu More once a ticket is finished, and a locked ticket shows Vergrendeld beside its status, Bewerken disabled ("Vergrendeld: eerst ontgrendelen") and "Vergrendeld door Pieter Jansen op 12 oktober 2026 om 16:40. Niemand kan dit ticket wijzigen of verwijderen." with Ontgrendelen. pipelinq had no lock.

## What changes
Configuration only. The lock is OpenRegister's: `POST /api/objects/pipelinq/ticket/{id}/lock` without a duration holds the record until it is unlocked and refuses other users' writes. The page is nextcloud-vue's (record-lock-held: the pill, the disabled Edit, the card, a held lock not released on leaving the page). pipelinq says when a ticket may be locked (a finished status: resolved, completed, rejected, converted, closed) and words the card (`lockNotice`).

## Depends on
- nextcloud-vue record-lock-held (24u1). Before that release a held lock reads as an edit lock: no pill, Edit stays open, and leaving the page releases the holder's lock.
- openregister: the lock endpoint checks only read access; a lock without an expiry should need update access (openregister PR, see the lane report).

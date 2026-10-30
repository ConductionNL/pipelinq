# Record lock

Lock a client, a finished lead or a finished ticket so nobody changes it by accident. Unlock it when a real change is needed. The page shows who locked the record and when.

## Specs

- `openspec/changes/platform-record-lock/specs/record-locking/spec.md`

## Lock a record

1. Open a client, a lead or a ticket.
2. Open the Actions menu and choose **Lock**.
3. Confirm.

A banner on the page now names who locked the record and on which date. The Actions menu offers **Unlock** instead of **Lock**.

You can lock any client. You can lock a lead once it is won or lost. You can lock a ticket once it is completed, converted, resolved, rejected or closed. An open lead or ticket shows no **Lock**, because pipelinq still writes to it, for example to keep its service deadline up to date.

## What a lock stops

- Every edit. Saving a locked record shows a message that names who locked it. Nothing changes.
- Background updates. The service deadline sweep skips a locked ticket.

A lock does not stop deletion yet. OpenRegister will refuse to delete a locked record once its change `archival-frozen-refuses-delete-and-dates-follow` lands. Until then, a locked record can still be deleted.

A lock does not stop retention either. When a record's retention term ends, it is still destroyed.

## Unlock a record

Anyone who may edit the record can unlock it. Open the Actions menu and choose **Unlock**. The banner disappears and the record can be edited again.

The record's history lists every lock and unlock, with the user who did it.

## How it works

The lock is OpenRegister's freeze. pipelinq adds no lock field and no code of its own for it.

| Method | URL | Purpose |
|--------|-----|---------|
| POST | `/apps/openregister/api/objects/pipelinq/{schema}/{id}/freeze` | Lock a client, lead or ticket |
| DELETE | `/apps/openregister/api/objects/pipelinq/{schema}/{id}/freeze` | Unlock it |

The client, lead and ticket schemas switch the freeze on with `x-openregister-archive` in their configuration. The same switch lets someone with edit rights archive these records through the OpenRegister API. pipelinq offers no Archive action.

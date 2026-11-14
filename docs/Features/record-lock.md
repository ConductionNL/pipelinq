# Record lock

Lock a client, a lead or a ticket once it is done, so nobody changes it by accident. Unlock it when a real change is needed. The lock is OpenRegister's freeze: pipelinq offers it, OpenRegister keeps it.

## Specs

- `openspec/changes/platform-record-lock/specs/record-locking/spec.md`

## When to lock

- **A client**: any time. A client has no finished state, so **Lock** is always in the Actions menu.
- **A lead**: once it is won or lost.
- **A ticket**: once it is completed, converted, resolved, rejected or closed.

An open lead or ticket cannot be locked. The SLA engine and other background work still write to an open ticket, and a lock would refuse those writes.

## Lock a record

1. Open the client, lead or ticket.
2. Open the Actions menu and choose **Lock**.
3. Confirm.

A banner under the title now says who locked the record and when. The Actions menu offers **Unlock** instead of **Lock**.

## What a lock does

- Every change to the record is refused. The refusal names who locked it.
- The record stays in every list and every search.
- The audit trail records who locked the record and who unlocked it.

A lock does not stop retention. A locked record still expires when its retention term ends.

A lock does not yet stop a delete. That rule belongs to OpenRegister; until it ships there, a locked record cannot be changed but can still be deleted.

## Unlock a record

Anyone who may edit the record can unlock it. Open the Actions menu and choose **Unlock**. The banner goes away and the record can be changed again.

## For administrators

The client, lead and ticket schemas switch on OpenRegister's object state verbs (`x-openregister-archive`). The same switch also lets a caller with edit rights archive one of these records through the OpenRegister API. pipelinq offers no Archive action.

## API

| Method | URL | Purpose |
|--------|-----|---------|
| POST | `/apps/openregister/api/objects/pipelinq/{schema}/{id}/freeze` | Lock the record |
| DELETE | `/apps/openregister/api/objects/pipelinq/{schema}/{id}/freeze` | Unlock the record |

`{schema}` is `client`, `lead` or `ticket`.

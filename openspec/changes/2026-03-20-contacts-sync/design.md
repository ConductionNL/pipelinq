# Design: contacts-sync unlink and re-sync

## Unlink
Clear `contactsUid` from the Pipelinq object by saving it with `contactsUid: null`. The Nextcloud contact is NOT deleted.

## Re-sync
Call the existing `/api/contacts-sync/write-back` endpoint to push current Pipelinq data to Nextcloud Contacts. For unlinked entities, this creates a new vCard and stores the new UID.

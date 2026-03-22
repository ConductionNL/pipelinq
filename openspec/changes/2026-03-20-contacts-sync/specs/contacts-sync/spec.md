# Delta Spec: contacts-sync unlink and re-sync

## Newly Implemented

- **Manual Sync Trigger**: Re-sync button added to ClientDetail and ContactDetail. Calls `/api/contacts-sync/write-back` to push current data to Nextcloud Contacts.
- **Contact Deletion Handling - Unlink**: Unlink button on both detail views clears `contactsUid` without deleting the Nextcloud contact.
- **Sync to Contacts for unlinked**: "Sync to Contacts" button shown when entity has no `contactsUid`, creating a new vCard.

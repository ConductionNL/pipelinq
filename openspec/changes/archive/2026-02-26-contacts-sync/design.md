# Design: contacts-sync

## Architecture Overview

Three layers of changes:

1. **PHP Service** (`ContactSyncService`): Core sync logic using Nextcloud's `\OCP\Contacts\IManager`. Handles vCard creation/update on save, and search/import from Nextcloud addressbooks.

2. **PHP Controller** (`ContactSyncController`): REST API endpoints for the frontend — search Nextcloud contacts, trigger import.

3. **Schema update**: Add `contactsUid` string field to `client` and `contact` schemas in `pipelinq_register.json`. This stores the Nextcloud addressbook contact ID for linking.

4. **Frontend**: Import dialog on ClientList/ContactList, sync badge on detail views.

## API Design

### New Endpoints

```
GET  /api/contacts-sync/search?q={query}
  → Search Nextcloud addressbooks via IManager
  → Returns: [{uid, name, email, phone, org, addressBookKey, alreadyLinked}]

POST /api/contacts-sync/import
  → Body: {uid, addressBookKey, type: "client"|"contact", clientId?: string}
  → Creates a Pipelinq client or contact from the Nextcloud contact
  → Returns: the created object
```

### Write-Back (Internal)

No API endpoint needed. `ContactSyncService::syncToContacts()` is called from the frontend's existing save flow by adding a new `POST /api/contacts-sync/write-back` endpoint that the frontend calls after a successful objectStore save.

```
POST /api/contacts-sync/write-back
  → Body: {objectType: "client"|"contact", objectId: string}
  → Reads the Pipelinq object, syncs to Nextcloud Contacts
  → Returns: {contactsUid: string}
```

## vCard Field Mapping

| Pipelinq Field | vCard Property | Direction |
|---|---|---|
| name | FN | Both |
| email | EMAIL | Both |
| phone | TEL | Both |
| role (contact) | ROLE | Both |
| client.name (via lookup) | ORG | Write-back only |
| website | URL | Both |
| address | ADR | Both |
| notes | NOTE | Write-back only |

## File Structure

```
lib/
  Service/ContactSyncService.php    ← NEW: IManager integration
  Controller/ContactSyncController.php ← NEW: API endpoints
  Settings/pipelinq_register.json   ← UPDATE: add contactsUid field
appinfo/
  routes.php                        ← UPDATE: add 3 new routes
src/
  components/ContactImportDialog.vue ← NEW: search & import UI
  views/clients/ClientDetail.vue    ← UPDATE: add sync badge + import button
  views/contacts/ContactDetail.vue  ← UPDATE: add sync badge
```

## Trade-offs

**Addressbook strategy**: Using the user's default personal addressbook rather than creating a separate "Pipelinq CRM" addressbook. Simpler implementation and contacts appear in the user's main Contacts view. Downside: harder to distinguish Pipelinq contacts from personal ones.

**Write-back trigger**: Frontend calls write-back endpoint after save rather than PHP event listeners. Simpler, no event system dependency. Downside: sync only happens from Pipelinq UI, not from direct API calls.

**Import granularity**: Import creates clients by default (person type). User can also import as a contact linked to an existing client. Organization detection uses heuristic (ORG present and matches FN).

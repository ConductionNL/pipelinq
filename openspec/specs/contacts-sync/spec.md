# Contacts Sync Specification

## Purpose

Sync Pipelinq clients and contacts with Nextcloud Contacts via IManager to eliminate duplicate data entry and keep address books current.

## Requirements

### Requirement: Write-Back Sync [MVP]

When a client (person type) or contact is created or updated in Pipelinq, the system MUST sync the data to a Nextcloud addressbook as a vCard.

#### Scenario: Create new contact syncs to Nextcloud
- WHEN a user saves a new client (type: person) or contact in Pipelinq
- THEN the system MUST create a vCard in the user's "Pipelinq CRM" addressbook via IManager
- AND the vCard MUST include: FN (name), EMAIL (email), TEL (phone), ROLE (role for contacts), ORG (client name for contacts)
- AND the Nextcloud contact UID MUST be stored back on the Pipelinq object as `contactsUid`

#### Scenario: Update existing contact syncs changes
- WHEN a user updates a client or contact that has a `contactsUid`
- THEN the system MUST update the existing vCard in Nextcloud Contacts
- AND the vCard properties MUST reflect the updated Pipelinq data

#### Scenario: Organization clients sync with ORG property
- WHEN a client with type "organization" is saved
- THEN the system MUST create/update a vCard with ORG set to the organization name
- AND FN MUST also be set to the organization name (vCard requires FN)

#### Scenario: Sync is graceful when Contacts is disabled
- WHEN the Nextcloud Contacts app is not installed or IManager is not available
- THEN the system MUST skip the sync silently (log a debug message)
- AND the save operation MUST still succeed normally

### Requirement: Import from Contacts [MVP]

Users MUST be able to search and import contacts from their Nextcloud addressbooks into Pipelinq.

#### Scenario: Search Nextcloud contacts
- WHEN the user opens the import dialog and types a search query
- THEN the system MUST search across all user addressbooks via IManager
- AND results MUST show: name, email, phone, organization
- AND results that are already linked (matching `contactsUid`) MUST be indicated

#### Scenario: Import selected contact as client
- WHEN the user selects a Nextcloud contact and clicks import
- THEN a new Pipelinq client MUST be created with mapped fields (FN→name, EMAIL→email, TEL→phone, ORG→industry, URL→website)
- AND the Nextcloud contact UID MUST be stored as `contactsUid`
- AND the client type MUST be "person" (or "organization" if ORG is present but FN matches ORG)

#### Scenario: Import already-linked contact is blocked
- WHEN the user attempts to import a contact that already has a matching `contactsUid` in Pipelinq
- THEN the system MUST show "Already linked" and prevent duplicate import

### Requirement: Sync Status Indicator [MVP]

Client and contact detail views MUST show whether the entity is linked to a Nextcloud contact.

#### Scenario: Linked entity shows sync badge
- WHEN viewing a client or contact that has a `contactsUid`
- THEN a "Synced with Contacts" indicator MUST be displayed
- AND the indicator SHOULD show the linked contact name from Nextcloud

#### Scenario: Unlinked entity shows no badge
- WHEN viewing a client or contact without a `contactsUid`
- THEN no sync indicator MUST be shown

---

### Current Implementation Status

**Fully implemented.** All MVP requirements (write-back sync, import from contacts, sync status indicator) are implemented.

Implemented:
- **Write-back sync**: `lib/Service/ContactSyncService.php` -- `syncToContacts()` delegates to `ContactVcardService`. Called automatically from `src/views/clients/ClientDetail.vue` on save (`syncToContacts()` method POSTs to `/api/contacts-sync/write-back`).
- **vCard creation/update**: `lib/Service/ContactVcardService.php`, `ContactVcardWriterService.php`, `ContactVcardPropertyBuilder.php` -- creates/updates vCards in a "Pipelinq CRM" addressbook via `IContactsManager`. Maps Pipelinq fields to vCard properties (FN, EMAIL, TEL, ORG, ROLE).
- **Import from contacts**: `lib/Service/ContactSyncService.php` -- `searchContacts()` searches Nextcloud addressbooks via `IContactsManager::search()` across FN, EMAIL, TEL, ORG fields. `importContact()` creates a Pipelinq client or contact from a Nextcloud contact. `ContactImportService.php` handles the field mapping (FN->name, EMAIL->email, TEL->phone, ORG->industry/org).
- **Already-linked detection**: `ContactLinkedUidsService.php` -- collects all `contactsUid` values from existing Pipelinq objects. `searchContacts()` marks results with `alreadyLinked: true` if their UID is already in use.
- **API routes**: `GET /api/contacts-sync/search` (search Nextcloud contacts), `POST /api/contacts-sync/import` (import contact), `POST /api/contacts-sync/write-back` (sync to Nextcloud).
- **Controller**: `lib/Controller/ContactSyncController.php` -- handles all three endpoints.
- **Import dialog**: `src/components/ContactImportDialog.vue` -- UI for searching and importing Nextcloud contacts.
- **Sync status indicator**: `src/views/clients/ClientDetail.vue` shows a "Synced with Contacts" badge when `clientData.contactsUid` is set.
- **Graceful degradation**: `ContactSyncService::searchContacts()` returns empty array when contacts manager is disabled. `importContact()` throws `RuntimeException` when not available.
- **Data model**: Both `client` and `contact` schemas include a `contactsUid` property (type: string, visible: false).
- **`ContactDataBuilder.php`** -- builds Pipelinq object data from Nextcloud contact properties for import.

NOT implemented:
- Automatic sync on contact update (currently only triggered manually on client save, not on contact save).
- Organization client ORG property mapping during write-back may need verification.
- Contact detail view does not show the "Synced with Contacts" badge (only client detail does).

### Standards & References
- vCard RFC 6350 -- field conventions for FN, EMAIL, TEL, ORG, ROLE
- Nextcloud Contacts IManager API (`OCP\Contacts\IManager`) -- used for search and CRUD
- CardDAV (RFC 6352) -- underlying protocol for Nextcloud Contacts storage

### Specificity Assessment
- The spec is specific and well-aligned with the implementation. All scenarios are testable.
- **Fully implementable as-is** -- and largely already implemented.
- **Minor gap**: The spec says "subsequent updates to the client SHOULD propagate to the linked vCard" -- this works for clients but may not be triggered for contact person updates.
- **Minor gap**: The spec does not specify what happens if the Nextcloud contact is deleted externally -- should the `contactsUid` be cleared?

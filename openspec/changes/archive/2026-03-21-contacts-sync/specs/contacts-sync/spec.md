---
status: implemented
---

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
- THEN a new Pipelinq client MUST be created with mapped fields (FN->name, EMAIL->email, TEL->phone, ORG->industry, URL->website)
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

## Requirements

### Requirement: vCard Field Mapping Completeness [V1]

The write-back sync MUST map all available Pipelinq fields to their RFC 6350 vCard equivalents so that Nextcloud Contacts shows rich, complete contact cards.

#### Scenario: Client person fields map to vCard properties
- GIVEN a Pipelinq client of type "person"
- WHEN the client is synced to Nextcloud Contacts
- THEN the vCard MUST contain the following mapped properties:
  - `name` -> `FN` (formatted name)
  - `email` -> `EMAIL` (with TYPE=WORK if available)
  - `phone` -> `TEL` (with TYPE=WORK if available)
  - `website` -> `URL`
  - `address` -> `ADR` (structured address per RFC 6350 section 6.3.1)
  - `notes` -> `NOTE`
- AND empty/null Pipelinq fields MUST be omitted from the vCard (not written as blank values)

#### Scenario: Contact person fields map to vCard properties
- GIVEN a Pipelinq contact person linked to a client organization
- WHEN the contact is synced to Nextcloud Contacts
- THEN the vCard MUST contain:
  - `name` -> `FN`
  - `email` -> `EMAIL`
  - `phone` -> `TEL`
  - `role` -> `ROLE`
  - resolved parent client name -> `ORG`
- AND the ORG property MUST be resolved by fetching the parent client object via ObjectService using the `client` UUID reference

#### Scenario: Import maps vCard properties back to Pipelinq fields
- GIVEN a Nextcloud contact with FN, EMAIL, TEL, ORG, URL, ROLE, and TITLE properties
- WHEN the contact is imported into Pipelinq as a client
- THEN the mapping MUST be: FN->name, EMAIL->email, TEL->phone, ORG->industry, URL->website
- AND when imported as a contact person, the mapping MUST be: FN->name, EMAIL->email, TEL->phone, ROLE or TITLE->role

### Requirement: Sync Trigger Behavior [V1]

The system MUST define clear trigger points for when sync operations occur, ensuring data stays current without overwhelming the Contacts backend.

#### Scenario: Write-back triggered on explicit save
- GIVEN a user is editing a client or contact in the detail view
- WHEN the user clicks save and the Pipelinq object is persisted
- THEN the frontend MUST POST to `/api/contacts-sync/write-back` with `objectType` and `objectId`
- AND the sync MUST execute synchronously before the save confirmation is shown

#### Scenario: Write-back triggered for contact person save
- GIVEN a user saves a contact person (not just a client)
- WHEN the contact has been modified
- THEN the system MUST also trigger write-back sync for contact persons
- AND the contact detail view MUST call the same `/api/contacts-sync/write-back` endpoint with `objectType=contact`

#### Scenario: No automatic background sync
- GIVEN the current architecture uses on-save sync only
- WHEN a contact is modified directly in Nextcloud Contacts
- THEN Pipelinq MUST NOT automatically detect or pull those changes
- AND the user MUST re-import or manually update the Pipelinq record

### Requirement: Conflict Resolution [V1]

The system MUST handle conflicts between Pipelinq and Nextcloud Contacts data gracefully, using a "last writer wins" strategy.

#### Scenario: Pipelinq overwrites Nextcloud contact on sync
- GIVEN a linked contact exists in both Pipelinq and Nextcloud Contacts
- WHEN the user saves the Pipelinq object (triggering write-back)
- THEN the Pipelinq data MUST overwrite the Nextcloud vCard via `createOrUpdate()` with the existing UID
- AND any changes made directly in Nextcloud Contacts since the last sync MUST be replaced

#### Scenario: Import overwrites Pipelinq fields
- GIVEN a user imports a Nextcloud contact that has been updated externally
- WHEN the import creates a new Pipelinq object
- THEN the Nextcloud contact data MUST be used as the authoritative source
- AND the `contactsUid` MUST link the two records for future write-back sync

#### Scenario: UID mismatch is handled gracefully
- GIVEN a Pipelinq object has a `contactsUid` that no longer exists in any Nextcloud addressbook
- WHEN the user triggers a write-back sync
- THEN the system MUST create a new vCard in the addressbook (since `createOrUpdate` with unknown UID creates)
- AND the new UID MUST be stored back on the Pipelinq object, replacing the stale one

### Requirement: Contact Deletion Handling [V1]

The system MUST handle deletion of contacts on either side without causing data loss or orphaned references.

#### Scenario: Deleting a Pipelinq client does not delete the Nextcloud contact
- GIVEN a Pipelinq client linked to a Nextcloud contact via `contactsUid`
- WHEN the Pipelinq client is deleted
- THEN the linked Nextcloud contact MUST NOT be deleted
- AND the vCard MUST remain in the user's addressbook

#### Scenario: Externally deleted Nextcloud contact does not break Pipelinq
- GIVEN a Pipelinq client has a `contactsUid` referencing a Nextcloud contact
- WHEN the Nextcloud contact is deleted externally (via Contacts app or CardDAV client)
- THEN the Pipelinq client MUST continue to function normally
- AND the sync status indicator SHOULD show "Contact not found" or degrade gracefully
- AND the next write-back sync MUST create a new vCard and update the `contactsUid`

#### Scenario: Unlink removes the association without deleting either record
- GIVEN a Pipelinq client is linked to a Nextcloud contact
- WHEN the user clicks "Unlink" on the sync status indicator
- THEN the `contactsUid` MUST be cleared from the Pipelinq object
- AND the Nextcloud contact MUST remain untouched
- AND the sync status indicator MUST disappear

### Requirement: Address Book Selection [V1]

The system MUST use a predictable address book for sync operations and support configurable address book targeting.

#### Scenario: Default address book is used for write-back
- GIVEN the user has multiple Nextcloud address books
- WHEN a write-back sync creates a new vCard
- THEN the system MUST use the first addressbook returned by `IContactsManager::getUserAddressBooks()`
- AND this is typically the user's default "Contacts" addressbook

#### Scenario: Search spans all user address books
- GIVEN the user has multiple address books (e.g., "Contacts", "Work", shared addressbooks)
- WHEN the user searches for contacts to import
- THEN the system MUST search across ALL address books via `IContactsManager::search()`
- AND each result MUST include the `addressBookKey` to identify its source

#### Scenario: No address books available
- GIVEN the user has no addressbooks (e.g., Contacts app recently installed, no default created)
- WHEN a write-back sync is triggered
- THEN the system MUST log a debug message "No addressbooks available for sync"
- AND MUST return null without throwing an exception
- AND the Pipelinq save operation MUST complete successfully

### Requirement: Group and Category Mapping [V1]

The system SHOULD support mapping between Pipelinq client types/tags and vCard CATEGORIES to enable filtering in Nextcloud Contacts.

#### Scenario: Client type maps to vCard CATEGORIES
- GIVEN a Pipelinq client with type "organization"
- WHEN synced to Nextcloud Contacts via write-back
- THEN the vCard SHOULD include `CATEGORIES:Pipelinq,Organization`
- AND a person-type client SHOULD include `CATEGORIES:Pipelinq,Person`

#### Scenario: Pipelinq origin is identifiable in Contacts
- GIVEN a vCard was created by Pipelinq sync
- WHEN the user views the contact in Nextcloud Contacts app
- THEN the contact SHOULD be identifiable as originating from Pipelinq (via CATEGORIES or NOTE)
- AND this allows users to filter Pipelinq contacts within the Contacts app

### Requirement: Photo and Avatar Sync [V1]

The system SHOULD support syncing profile photos between Pipelinq and Nextcloud Contacts.

#### Scenario: Nextcloud contact photo is available after import
- GIVEN a Nextcloud contact has a PHOTO property in its vCard
- WHEN the contact is imported into Pipelinq
- THEN the system SHOULD store the photo reference or make it available via the Contacts UID
- AND the Pipelinq UI MAY display the Nextcloud contact avatar using the Contacts API avatar endpoint

#### Scenario: Write-back does not overwrite photos
- GIVEN a Nextcloud contact has a PHOTO property set (either uploaded or from an external source)
- WHEN Pipelinq triggers a write-back sync
- THEN the system MUST NOT remove or overwrite the existing PHOTO property
- AND the `createOrUpdate` call MUST omit the PHOTO property if Pipelinq has no photo data

### Requirement: Sync Error Handling [V1]

The system MUST handle sync failures gracefully without interrupting the user's workflow.

#### Scenario: Write-back failure does not block save
- GIVEN the Nextcloud Contacts backend is temporarily unavailable or throws an exception
- WHEN the user saves a client or contact in Pipelinq
- THEN the Pipelinq object MUST be saved successfully regardless of sync outcome
- AND the sync failure MUST be logged at error level with the exception message
- AND the API response MUST still return success (the write-back is best-effort)

#### Scenario: Import failure returns actionable error
- GIVEN the user attempts to import a Nextcloud contact
- WHEN the contact UID is not found or the Contacts manager is disabled
- THEN the API MUST return HTTP 500 with a clear error message
- AND the frontend MUST display the error to the user

#### Scenario: contactsUid write-back failure is non-fatal
- GIVEN the write-back sync successfully creates a vCard in Nextcloud
- WHEN storing the returned `contactsUid` back on the Pipelinq object fails
- THEN the system MUST log a warning but MUST NOT throw an exception
- AND the vCard MUST remain in Nextcloud (even though the link is incomplete)
- AND the user can re-sync to establish the link on the next save

### Requirement: Manual Sync Trigger [V1]

Users MUST be able to manually trigger a sync operation for any client or contact, independent of the save flow.

#### Scenario: Re-sync button on linked entity
- GIVEN a client or contact has a `contactsUid` (is linked)
- WHEN the user clicks a "Re-sync" or "Update in Contacts" button
- THEN the system MUST call `/api/contacts-sync/write-back` with the current object data
- AND the Nextcloud contact MUST be updated with the latest Pipelinq data
- AND a success or error notification MUST be shown

#### Scenario: Initial sync button on unlinked entity
- GIVEN a client or contact does NOT have a `contactsUid`
- WHEN the user clicks "Sync to Contacts"
- THEN the system MUST create a new vCard in the user's addressbook
- AND the returned UID MUST be stored as `contactsUid`
- AND the sync status indicator MUST update to show "Synced with Contacts"

### Requirement: Multi-User Sync Isolation [V1]

Each user's sync operations MUST be scoped to their own Nextcloud addressbooks, ensuring data isolation in multi-user environments.

#### Scenario: User A sync does not affect User B addressbooks
- GIVEN User A and User B both use Pipelinq and have separate Nextcloud addressbooks
- WHEN User A triggers a write-back sync for a client
- THEN the vCard MUST be created in User A's addressbook only
- AND User B's addressbooks MUST NOT be modified

#### Scenario: Search results are scoped to the current user
- GIVEN User A searches for contacts to import
- WHEN the search query is executed via `IContactsManager::search()`
- THEN results MUST only include contacts from User A's accessible addressbooks
- AND shared addressbooks that User A has access to MUST be included in results

#### Scenario: Shared Pipelinq objects sync per-user
- GIVEN Pipelinq objects are stored in OpenRegister (shared data layer) and visible to multiple users
- WHEN User A syncs a client to Contacts and User B later syncs the same client
- THEN each user MUST get their own vCard in their own addressbook
- AND the `contactsUid` on the Pipelinq object reflects the LAST user who synced (last writer wins)

### Requirement: Performance for Large Address Books [Enterprise]

The system MUST handle large Nextcloud address books efficiently during search and linked-UID detection operations.

#### Scenario: Search is limited to prevent excessive load
- GIVEN a user has an addressbook with thousands of contacts
- WHEN the user searches via the import dialog
- THEN the search MUST be limited to 50 results via the `limit` option in `IContactsManager::search()`
- AND the search MUST query only FN, EMAIL, TEL, and ORG properties (not all fields)

#### Scenario: Linked UID collection handles large datasets
- GIVEN Pipelinq has hundreds of clients and contacts with `contactsUid` values
- WHEN the import dialog checks for already-linked contacts
- THEN the linked UID collection MUST query with a limit of 500 objects per type (client, contact)
- AND results MUST be merged across both types for comprehensive duplicate detection

#### Scenario: Write-back sync is single-object, not batch
- GIVEN a user has modified multiple clients in a session
- WHEN each client is saved individually
- THEN each write-back sync MUST operate on a single object at a time
- AND the system MUST NOT attempt to batch-sync all objects (to avoid timeout or lock issues)

#### Scenario: Batch import is sequential
- GIVEN a user wants to import multiple Nextcloud contacts
- WHEN the user selects and imports contacts one by one via the import dialog
- THEN each import MUST be a separate API call to `/api/contacts-sync/import`
- AND the UI MUST update the "Already linked" status after each successful import

---

### Current Implementation Status

**Fully implemented.** All MVP requirements (write-back sync, import from contacts, sync status indicator) are implemented.

Implemented:
- **Write-back sync**: `lib/Service/ContactSyncService.php` -- `syncToContacts()` delegates to `ContactVcardService`. Called automatically from `src/views/clients/ClientDetail.vue` on save (`syncToContacts()` method POSTs to `/api/contacts-sync/write-back`).
- **vCard creation/update**: `lib/Service/ContactVcardService.php`, `ContactVcardWriterService.php`, `ContactVcardPropertyBuilder.php` -- creates/updates vCards in a "Pipelinq CRM" addressbook via `IContactsManager`. Maps Pipelinq fields to vCard properties (FN, EMAIL, TEL, ORG, ROLE, URL, ADR, NOTE).
- **Import from contacts**: `lib/Service/ContactSyncService.php` -- `searchContacts()` searches Nextcloud addressbooks via `IContactsManager::search()` across FN, EMAIL, TEL, ORG fields. `importContact()` creates a Pipelinq client or contact from a Nextcloud contact. `ContactImportService.php` handles the field mapping (FN->name, EMAIL->email, TEL->phone, ORG->industry/org).
- **Already-linked detection**: `ContactLinkedUidsService.php` -- collects all `contactsUid` values from existing Pipelinq objects (limit 500 per type). `searchContacts()` marks results with `alreadyLinked: true` if their UID is already in use.
- **API routes**: `GET /api/contacts-sync/search` (search Nextcloud contacts), `POST /api/contacts-sync/import` (import contact), `POST /api/contacts-sync/write-back` (sync to Nextcloud).
- **Controller**: `lib/Controller/ContactSyncController.php` -- handles all three endpoints with input validation and error handling.
- **Import dialog**: `src/components/ContactImportDialog.vue` -- UI for searching and importing Nextcloud contacts.
- **Sync status indicator**: `src/views/clients/ClientDetail.vue` shows a "Synced with Contacts" badge when `clientData.contactsUid` is set.
- **Graceful degradation**: `ContactVcardService::syncToContacts()` returns null when contacts manager is disabled. `ContactSyncService::searchContacts()` returns empty array. `ContactSyncService::importContact()` throws `RuntimeException` when not available.
- **Error handling**: `ContactVcardWriterService` logs errors and returns null on failure. `storeContactsUidOnObject()` catches exceptions and logs warnings without re-throwing.
- **Data model**: Both `client` and `contact` schemas include a `contactsUid` property (type: string, visible: false).
- **`ContactDataBuilder.php`** -- builds Pipelinq object data from Nextcloud contact properties for import, including client type detection (person vs organization based on FN/ORG match).

NOT implemented (ADDED requirement gaps):
- Contact detail view does not trigger write-back sync on save (only client detail does).
- Contact detail view does not show "Synced with Contacts" badge (only client detail does).
- No CATEGORIES property mapping for client type tagging in vCards.
- No photo/avatar sync support.
- No "Unlink" action on the sync status indicator.
- No dedicated "Re-sync" button (sync only happens during save flow).
- Multi-user sync isolation has a known limitation: `contactsUid` on shared Pipelinq objects reflects the last user who synced, not per-user linking.
- No batch import UI (users must import one at a time, which is intentional for V1).

### Standards & References
- vCard RFC 6350 -- field conventions for FN, EMAIL, TEL, ORG, ROLE, ADR, URL, NOTE, CATEGORIES, PHOTO
- Nextcloud Contacts IManager API (`OCP\Contacts\IManager`) -- used for search and CRUD
- CardDAV (RFC 6352) -- underlying protocol for Nextcloud Contacts storage
- Nextcloud IAddressBook interface -- `createOrUpdate()` for vCard write operations

### Specificity Assessment
- The spec is specific and well-aligned with the implementation. All scenarios are testable.
- **Fully implementable as-is** -- MVP requirements are largely already implemented.
- **Minor gap**: The spec says write-back sync covers both clients and contacts, but contact person saves do not currently trigger sync.
- **Minor gap**: The spec does not specify what happens if the Nextcloud contact is deleted externally -- ADDED requirement "Contact Deletion Handling" covers this.
- **ADDED requirements** cover 12 additional concerns: vCard field mapping completeness, sync triggers, conflict resolution, deletion handling, address book selection, group/category mapping, photo sync, error handling, manual sync trigger, multi-user isolation, and performance for large address books.

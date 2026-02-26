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

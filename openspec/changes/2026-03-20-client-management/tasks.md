# Tasks: client-management enhancements

## 1. Client Summary Statistics

- [x] 1.1 Add summary statistics card to `ClientDetail.vue`
  - **spec_ref**: `specs/client-management/spec.md#Client Detail View`
  - **files**: `pipelinq/src/views/clients/ClientDetail.vue`
  - **acceptance_criteria**:
    - GIVEN a client with linked leads and requests
    - THEN a summary card MUST display: open leads count + value, won leads count + value, open requests count, total value
    - AND values MUST be formatted with EUR currency

## 2. Contact Sync Enhancements

- [x] 2.1 Add write-back sync on contact save in `ContactDetail.vue`
  - **spec_ref**: `specs/contacts-sync/spec.md#Sync Trigger Behavior`
  - **files**: `pipelinq/src/views/contacts/ContactDetail.vue`
  - **acceptance_criteria**:
    - GIVEN a contact person is saved
    - THEN the system MUST POST to `/api/contacts-sync/write-back` with `objectType=contact`
    - AND sync failure MUST NOT block the save operation

- [x] 2.2 Add sync status badge to `ContactDetail.vue`
  - **spec_ref**: `specs/contacts-sync/spec.md#Sync Status Indicator`
  - **files**: `pipelinq/src/views/contacts/ContactDetail.vue`
  - **acceptance_criteria**:
    - GIVEN a contact with `contactsUid` set
    - THEN a "Synced with Contacts" badge MUST be displayed
    - AND contacts without `contactsUid` MUST NOT show the badge

## 3. Dynamic @type Mapping

- [x] 3.1 Set `@type` based on client type in `ClientForm.vue`
  - **spec_ref**: `specs/client-management/spec.md#Client Creation`
  - **files**: `pipelinq/src/views/clients/ClientForm.vue`
  - **acceptance_criteria**:
    - GIVEN a user creates a person client
    - THEN `@type` MUST be set to `schema:Person`
    - GIVEN a user creates an organization client
    - THEN `@type` MUST be set to `schema:Organization`

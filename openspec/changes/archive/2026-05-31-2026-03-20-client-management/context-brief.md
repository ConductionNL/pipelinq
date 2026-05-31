# Proposal: client-management enhancements

## Problem

The client management spec identifies several MVP gaps that remain unimplemented:
1. No summary statistics panel on client detail view (open leads count/value, won leads count/value, open requests count)
2. Contact person detail view does not trigger write-back sync to Nextcloud Contacts on save
3. Contact person detail view does not show "Synced with Contacts" badge
4. Client `@type` is not dynamically set based on `type` field (always defaults to `schema:Person`)

## Proposed Change

Implement the missing MVP features:
- Add a summary statistics card to `ClientDetail.vue` showing aggregated lead/request counts and values
- Add write-back sync to `ContactDetail.vue` on save (same pattern as ClientDetail)
- Add sync status badge to `ContactDetail.vue`
- Set `@type` dynamically in `ClientForm.vue` based on selected type

### Out of Scope
- Duplicate detection (V1)
- Import/export CSV/vCard (V1)
- KVK integration in client form (V1)
- BSN handling (Enterprise)
- Client hierarchy (V1)
- Client segmentation/tagging (V1)
- Health scoring (Enterprise)
- GDPR data subject rights (V1)

## Impact

- **Files modified**: 2-3 Vue files
- **New files**: 0
- **Risk**: Low -- enhancing existing frontend components with additional sections



## Design

# Design: client-management enhancements

## Architecture Overview

All changes are frontend-only. Data operations use the existing generic object store (`useObjectStore`) which calls OpenRegister's API. Contact sync uses the existing `/api/contacts-sync/write-back` endpoint.

## Key Design Decisions

### 1. Summary Statistics Card

**Decision**: Add a `CnDetailCard` with computed summary stats calculated from the already-fetched `leads` and `requests` arrays in `ClientDetail.vue`.

**Rationale**: The detail view already fetches linked leads and requests. Computing counts/sums from these arrays avoids additional API calls. The stats card appears between Client Information and Contacts sections.

**Fields displayed**:
- Open leads: count and total value
- Won leads: count and total value
- Open requests: count
- Total value: open + won leads
- Client since: creation date from object metadata

### 2. Contact Detail Sync

**Decision**: Replicate the `syncToContacts()` pattern from `ClientDetail.vue` into `ContactDetail.vue`.

**Rationale**: The backend `/api/contacts-sync/write-back` endpoint already supports both `objectType=client` and `objectType=contact`. Only the frontend trigger is missing.

### 3. Dynamic @type

**Decision**: Set `@type` in the form data based on the selected `type` field: `person` -> `schema:Person`, `organization` -> `schema:Organization`.

**Rationale**: OpenRegister uses `@type` for Schema.org compliance. The register schema defaults `@type` to `schema:Person` but this should vary by client type.



## Tasks

# Tasks: client-management enhancements

## 1. Client Summary Statistics

- [ ] 1.1 Add summary statistics card to `ClientDetail.vue`
  - **spec_ref**: `specs/client-management/spec.md#Client Detail View`
  - **files**: `pipelinq/src/views/clients/ClientDetail.vue`
  - **acceptance_criteria**:
    - GIVEN a client with linked leads and requests
    - THEN a summary card MUST display: open leads count + value, won leads count + value, open requests count, total value
    - AND values MUST be formatted with EUR currency

## 2. Contact Sync Enhancements

- [ ] 2.1 Add write-back sync on contact save in `ContactDetail.vue`
  - **spec_ref**: `specs/contacts-sync/spec.md#Sync Trigger Behavior`
  - **files**: `pipelinq/src/views/contacts/ContactDetail.vue`
  - **acceptance_criteria**:
    - GIVEN a contact person is saved
    - THEN the system MUST POST to `/api/contacts-sync/write-back` with `objectType=contact`
    - AND sync failure MUST NOT block the save operation

- [ ] 2.2 Add sync status badge to `ContactDetail.vue`
  - **spec_ref**: `specs/contacts-sync/spec.md#Sync Status Indicator`
  - **files**: `pipelinq/src/views/contacts/ContactDetail.vue`
  - **acceptance_criteria**:
    - GIVEN a contact with `contactsUid` set
    - THEN a "Synced with Contacts" badge MUST be displayed
    - AND contacts without `contactsUid` MUST NOT show the badge

## 3. Dynamic @type Mapping

- [ ] 3.1 Set `@type` based on client type in `ClientForm.vue`
  - **spec_ref**: `specs/client-management/spec.md#Client Creation`
  - **files**: `pipelinq/src/views/clients/ClientForm.vue`
  - **acceptance_criteria**:
    - GIVEN a user creates a person client
    - THEN `@type` MUST be set to `schema:Person`
    - GIVEN a user creates an organization client
    - THEN `@type` MUST be set to `schema:Organization`
# Tasks: client-management enhancements

## 0. Deduplication Check

- [x] 0.1 Verify no existing component or utility duplicates the summary stats computation
  - Search `pipelinq/src/` for any existing stats aggregation helpers or computed properties
    that sum lead values or count open requests
  - Search `openregister/lib/Service/` for `ObjectService::aggregateObjects()` or similar
    server-side aggregation methods that could replace client-side computation
  - **Finding**: All aggregation is done client-side from already-fetched arrays; no overlap
    with platform services. Client-side computation is the established pattern in `ClientDetail.vue`.

- [x] 0.2 Verify no existing sync trigger or badge helper in `ContactDetail.vue`
  - Confirm `ContactDetail.vue` has no `syncToContacts()` method or `contactsUid` badge logic
  - Confirm `ClientDetail.vue` has the reference implementation to copy from
  - **Finding**: `ClientDetail.vue` has the full pattern; `ContactDetail.vue` is missing both.

## 1. Client Summary Statistics

- [x] 1.1 Add summary statistics card to `ClientDetail.vue`
  - **spec_ref**: `REQ-CLT-001` / `openspec/specs/client-management/spec.md#Client Detail View`
  - **files**: `pipelinq/src/views/clients/ClientDetail.vue`
  - **tier**: MVP
  - Add `summaryStats` computed property aggregating from the existing `leads` and `requests`
    arrays:
    - `openLeadsCount`: leads where `status !== 'won' && status !== 'lost'`
    - `openLeadsValue`: sum of `lead.value` for open leads
    - `wonLeadsCount`: leads where `status === 'won'`
    - `wonLeadsValue`: sum of `lead.value` for won leads
    - `openRequestsCount`: requests where `status !== 'closed'`
    - `totalValue`: `openLeadsValue + wonLeadsValue`
    - `clientSince`: `clientObject.createdAt`
  - Render a `CnDetailCard` (from `@conduction/nextcloud-vue`) containing a `CnDetailGrid`
    with label-value rows for each stat
  - Format currency values with `Intl.NumberFormat` and wrap display strings in `t(appName, ...)`
  - **acceptance_criteria**:
    - GIVEN a client with linked leads and requests
    - THEN a summary card MUST display: open leads count + value, won leads count + value,
      open requests count, total value, client since date
    - AND values MUST be formatted with EUR currency
    - AND zero values MUST display as 0 / EUR 0 (not hidden)

## 2. Contact Sync Enhancements

- [x] 2.1 Add write-back sync on contact save in `ContactDetail.vue`
  - **spec_ref**: `REQ-SYN-001` / `openspec/specs/contacts-sync/spec.md#Sync Trigger Behavior`
  - **files**: `pipelinq/src/views/contacts/ContactDetail.vue`
  - **tier**: MVP
  - Copy the `syncToContacts()` method from `ClientDetail.vue` verbatim, changing
    `objectType` to `'contact'` and referencing `this.contactData` for the object UUID
  - Call `syncToContacts()` inside the `saveObject()` success handler (same position as in
    `ClientDetail.vue`)
  - Wrap the sync call in `try/catch`; log errors with `console.error` but do NOT re-throw
  - **acceptance_criteria**:
    - GIVEN a contact person is saved
    - THEN the system MUST POST to `/api/contacts-sync/write-back` with `objectType=contact`
    - AND sync failure MUST NOT block the save operation or success toast

- [x] 2.2 Add sync status badge to `ContactDetail.vue`
  - **spec_ref**: `REQ-SYN-002` / `openspec/specs/contacts-sync/spec.md#Sync Status Indicator`
  - **files**: `pipelinq/src/views/contacts/ContactDetail.vue`
  - **tier**: MVP
  - Render the same `CnStatusBadge` (or badge markup) used in `ClientDetail.vue`, conditioned
    on `contactData.contactsUid` being truthy
  - Place the badge in the detail header or info section, consistent with `ClientDetail.vue`
  - Import and register `CnStatusBadge` from `@conduction/nextcloud-vue` in `components: {}`
    if not already present
  - **acceptance_criteria**:
    - GIVEN a contact with `contactsUid` set
    - THEN a "Synced with Contacts" badge MUST be displayed
    - AND contacts without `contactsUid` MUST NOT show the badge
    - AND the badge MUST use accessible text (not only an icon)

## 3. Dynamic @type Mapping

- [x] 3.1 Set `@type` based on client type in `ClientForm.vue`
  - **spec_ref**: `REQ-CLT-002` / `openspec/specs/client-management/spec.md#Client Creation`
  - **files**: `pipelinq/src/views/clients/ClientForm.vue`
  - **tier**: MVP
  - Add a `TYPE_MAPPING` constant:
    ```js
    const TYPE_MAPPING = { person: 'schema:Person', organization: 'schema:Organization' }
    ```
  - In the form data initializer (or a `watch` on `form.type`), derive `@type` as
    `TYPE_MAPPING[this.form.type] ?? 'schema:Person'`
  - Ensure the mapping runs both on initial load (edit mode pre-population) and on type change
  - **acceptance_criteria**:
    - GIVEN a user creates a person client
    - THEN `@type` MUST be set to `schema:Person`
    - GIVEN a user creates an organization client
    - THEN `@type` MUST be set to `schema:Organization`
    - GIVEN an existing person client is edited and type changed to organization
    - THEN the saved object MUST have `@type: schema:Organization`

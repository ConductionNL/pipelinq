# Specs: client-management enhancements

**Feature tier**: MVP
**Spec refs**: `openspec/specs/client-management/spec.md`, `openspec/specs/contacts-sync/spec.md`
**Standards**: Schema.org (`schema:Person`, `schema:Organization`), vCard RFC 6350

---

## REQ-CLT-001: Client Summary Statistics Card

The client detail view MUST display a summary statistics card showing aggregated counts and
values derived from the client's linked leads and requests. Values MUST be computed client-side
from already-fetched collections without additional API calls.

**Feature tier**: MVP
**Spec ref**: `openspec/specs/client-management/spec.md#Client Detail View`
**Files**: `pipelinq/src/views/clients/ClientDetail.vue`

### Scenario REQ-CLT-001-01: Stats card visible with linked data

- GIVEN a client "Acme Corporation" with 2 open leads (values EUR 10,000 and EUR 15,000),
  3 won leads (values EUR 12,000, EUR 18,000, EUR 12,000), and 1 open request
- WHEN a user navigates to the client detail view
- THEN a summary statistics card MUST be displayed between the Client Information card and
  the Contacts section
- AND the card MUST show open leads count: 2
- AND the card MUST show open leads value: EUR 25.000
- AND the card MUST show won leads count: 3
- AND the card MUST show won leads value: EUR 42.000
- AND the card MUST show open requests count: 1
- AND the card MUST show total pipeline value: EUR 67.000

### Scenario REQ-CLT-001-02: Stats card shows zeros for new client

- GIVEN a client "Nieuwe B.V." with no linked leads or requests
- WHEN a user views the client detail
- THEN the summary statistics card MUST still be displayed
- AND all count and value fields MUST show 0 or EUR 0

### Scenario REQ-CLT-001-03: Currency formatted for Dutch locale

- GIVEN a client with an open lead of value 1500
- WHEN the summary statistics card renders the open leads value
- THEN the value MUST be formatted as a EUR currency string (e.g., "EUR 1.500" or "€ 1.500")
- AND NO hardcoded currency string MUST appear outside the translation system

### Scenario REQ-CLT-001-04: Client since date shown

- GIVEN a client created on 2025-01-15
- WHEN the summary statistics card is displayed
- THEN a "Client since" field MUST show the creation date from the object's `createdAt`
  metadata field provided by OpenRegister

---

## REQ-CLT-002: Dynamic @type Based on Client Type

When creating or updating a client, the `@type` JSON-LD property MUST be set dynamically
based on the selected `type` field, mapping `person` to `schema:Person` and `organization`
to `schema:Organization`.

**Feature tier**: MVP
**Spec ref**: `openspec/specs/client-management/spec.md#Client Creation`
**Files**: `pipelinq/src/views/clients/ClientForm.vue`
**Standards**: Schema.org JSON-LD (`@type`), ADR-001 (international-first)

### Scenario REQ-CLT-002-01: Person client stored with schema:Person type

- GIVEN a user opens the client creation form
- WHEN the user selects type "person" and saves the client
- THEN the OpenRegister object MUST be stored with `@type` set to `schema:Person`
- AND no `@type` value of `schema:Organization` MUST appear on the object

### Scenario REQ-CLT-002-02: Organization client stored with schema:Organization type

- GIVEN a user opens the client creation form
- WHEN the user selects type "organization" and saves the client
- THEN the OpenRegister object MUST be stored with `@type` set to `schema:Organization`
- AND no `@type` value of `schema:Person` MUST appear on the object

### Scenario REQ-CLT-002-03: Type change updates @type on edit

- GIVEN an existing person client "Jan de Vries Consultancy" stored with `@type: schema:Person`
- WHEN the user opens the edit form, changes the type to "organization", and saves
- THEN the updated object MUST have `@type` set to `schema:Organization`
- AND existing properties (name, email, phone) MUST be preserved unchanged

### Scenario REQ-CLT-002-04: @type defaults to schema:Person if type not yet selected

- GIVEN a user opens the client creation form before selecting a type
- WHEN the form data is initialized
- THEN `@type` MUST default to `schema:Person`
- AND it MUST update reactively as soon as the user selects a type

---

## REQ-SYN-001: Contact Person Write-Back Sync on Save

When a contact person is saved in `ContactDetail.vue`, the system MUST trigger write-back
sync to Nextcloud Contacts by POSTing to the existing `/api/contacts-sync/write-back` endpoint
with `objectType=contact`. Sync failure MUST NOT block the save operation.

**Feature tier**: MVP
**Spec ref**: `openspec/specs/contacts-sync/spec.md#Sync Trigger Behavior`
**Files**: `pipelinq/src/views/contacts/ContactDetail.vue`
**Standards**: vCard RFC 6350, Nextcloud `OCP\Contacts\IManager`

### Scenario REQ-SYN-001-01: Write-back triggered after successful contact save

- GIVEN a contact person "Petra Jansen" with email "p.jansen@acme.nl" and `contactsUid` set
- WHEN the user updates Petra's role to "Sales Director" and clicks Save
- THEN the system MUST first persist the contact via `saveObject()`
- AND on save success, the system MUST POST to `/api/contacts-sync/write-back` with body
  `{ objectType: 'contact', objectId: '<uuid>' }`
- AND the Nextcloud vCard for Petra MUST reflect the updated role

### Scenario REQ-SYN-001-02: Sync failure does not block save confirmation

- GIVEN the `/api/contacts-sync/write-back` endpoint returns HTTP 500
- WHEN the user saves a contact person
- THEN the contact MUST still be saved successfully in OpenRegister
- AND the save confirmation (success toast or navigation) MUST still occur
- AND the sync error MUST be logged but MUST NOT surface as a blocking modal to the user

### Scenario REQ-SYN-001-03: Write-back not triggered when objectType is client

- GIVEN the user saves a contact person in `ContactDetail.vue`
- WHEN the sync call is made
- THEN the `objectType` parameter MUST be `'contact'` (not `'client'`)
- AND the endpoint MUST receive the contact's UUID, not any client UUID

### Scenario REQ-SYN-001-04: No write-back when contact has no contactsUid

- GIVEN a contact person with no `contactsUid` (not yet linked to Nextcloud Contacts)
- WHEN the user saves the contact
- THEN the system SHOULD still POST to `/api/contacts-sync/write-back` to create the initial
  vCard link (consistent with `ClientDetail.vue` behaviour)
- AND if the endpoint returns a `contactsUid`, it MUST be stored on the object

---

## REQ-SYN-002: Contact Person Sync Status Badge

The contact person detail view MUST display a "Synced with Contacts" badge when the contact
has a `contactsUid` value set. Contacts without `contactsUid` MUST NOT show the badge.

**Feature tier**: MVP
**Spec ref**: `openspec/specs/contacts-sync/spec.md#Sync Status Indicator`
**Files**: `pipelinq/src/views/contacts/ContactDetail.vue`
**Standards**: vCard RFC 6350, WCAG AA (badge must not use colour as sole indicator)

### Scenario REQ-SYN-002-01: Badge shown when contactsUid is present

- GIVEN a contact person "Mark de Groot" with `contactsUid: "abc-123-def"`
- WHEN the user opens the contact detail view
- THEN a "Synced with Contacts" badge MUST be visible in the detail view header or info area
- AND the badge MUST be visually identical to the badge used in `ClientDetail.vue`

### Scenario REQ-SYN-002-02: Badge absent when contactsUid is not set

- GIVEN a contact person "Sanne Bakker" with no `contactsUid` (value is null or empty string)
- WHEN the user opens the contact detail view
- THEN NO sync badge MUST be rendered in the DOM

### Scenario REQ-SYN-002-03: Badge appears after first successful sync

- GIVEN a contact person "Sanne Bakker" with no `contactsUid`
- WHEN the user saves the contact and the write-back sync returns a `contactsUid`
- THEN the `contactsUid` MUST be stored on the contact object in the store
- AND the badge MUST appear reactively without requiring a page reload

### Scenario REQ-SYN-002-04: Badge meets WCAG AA contrast requirements

- GIVEN the "Synced with Contacts" badge is rendered
- WHEN a screen reader or accessibility tool inspects the badge
- THEN the badge MUST include accessible text (not only an icon)
- AND the badge MUST be keyboard-focusable if it is interactive
- AND the badge MUST use Nextcloud CSS variables for colours (no hardcoded hex values)

---
status: implemented
---

# Client Management Specification

## Purpose

Client management is the core capability of Pipelinq. A client represents a person or organization that the team has a relationship with. Contact persons are individuals linked to organization clients, qualified by role. This specification covers client and contact person CRUD, list views with search/sort/filter, the client detail view (info panel, summary stats, contact persons, leads, requests, activity timeline), validation rules, Nextcloud Contacts sync, and future capabilities such as duplicate detection and import/export.

**Standards**: Schema.org (`Person`, `Organization`, `ContactPoint`), vCard (RFC 6350), VNG Klantinteracties (`Partij`, `Betrokkene`, `DigitaalAdres`)
**Feature tier**: MVP (core), V1 (extended), Enterprise (advanced)

## Data Model

See [ARCHITECTURE.md](../../docs/ARCHITECTURE.md) for full entity definitions of Client and Contact Person, including property tables, Schema.org mappings, vCard alignment, and VNG mapping layer.

## Requirements

---

### Requirement: Client Creation

The system MUST support creating client records of type `person` or `organization`. Each client MUST have a `name` and a `type`. The system MUST store clients as OpenRegister objects in the `pipelinq` register using the `client` schema.

**Feature tier**: MVP

#### Scenario: Create a person client with minimal fields

- GIVEN a user with CRM access
- WHEN they submit a new client form with name "Jan de Vries" and type "person"
- THEN the system MUST create an OpenRegister object in the `pipelinq` register with the `client` schema
- AND the object MUST have `@type` set to `schema:Person`
- AND the object MUST have `name` set to "Jan de Vries"
- AND the client MUST appear in the client list immediately

#### Scenario: Create an organization client with full fields

- GIVEN a user with CRM access
- WHEN they submit a new client form with name "Gemeente Utrecht", type "organization", email "info@utrecht.nl", telephone "+31 30 286 0000", website "https://www.utrecht.nl", taxID "12345678", and address "Stadsplateau 1, 3521 AZ Utrecht"
- THEN the system MUST create an OpenRegister object with `@type` set to `schema:Organization`
- AND all provided fields MUST be stored on the object
- AND the `taxID` field MUST accept KVK-format numbers

#### Scenario: Create a client with only required fields

- GIVEN a user with CRM access
- WHEN they submit a new client form with name "Acme B.V." and type "organization" and leave all optional fields empty
- THEN the system MUST create the client successfully
- AND optional fields (email, telephone, address, taxID, website, notes) MUST be stored as empty/null

#### Scenario: Fail to create a client without required name

- GIVEN a user with CRM access
- WHEN they submit a new client form with type "person" but no name
- THEN the system MUST reject the request with a validation error
- AND the error message MUST indicate that name is required
- AND no OpenRegister object MUST be created

#### Scenario: Fail to create a client without required type

- GIVEN a user with CRM access
- WHEN they submit a new client form with name "Jan de Vries" but no type
- THEN the system MUST reject the request with a validation error
- AND the error message MUST indicate that type is required

#### Scenario: Fail to create a client with invalid type

- GIVEN a user with CRM access
- WHEN they submit a new client form with name "Jan de Vries" and type "government"
- THEN the system MUST reject the request with a validation error
- AND the error message MUST indicate that type must be "person" or "organization"

---

### Requirement: Client Update

The system MUST support updating all properties of an existing client. Updates MUST be recorded in the audit trail.

**Feature tier**: MVP

#### Scenario: Update a client email

- GIVEN an existing client "Jan de Vries" with no email
- WHEN the user updates the email to "jan@devries.nl"
- THEN the system MUST update the OpenRegister object via PUT
- AND the audit trail MUST record the change with the user identity and timestamp
- AND the client detail view MUST reflect the updated email

#### Scenario: Update a client type from person to organization

- GIVEN an existing person client "Jan de Vries Consultancy"
- WHEN the user changes the type to "organization"
- THEN the system MUST update the `@type` to `schema:Organization`
- AND existing properties (name, email, telephone) MUST be preserved

#### Scenario: Edit form pre-populates existing values

- GIVEN an existing client "Gemeente Utrecht" is opened for editing
- WHEN the edit form loads
- THEN all existing field values MUST be pre-populated in the form
- AND the user can modify any field and save

#### Scenario: Clear an optional field

- GIVEN an existing client "Gemeente Utrecht" with email "info@utrecht.nl"
- WHEN the user clears the email field and saves
- THEN the system MUST update the object with email set to empty/null
- AND the change MUST be recorded in the audit trail

---

### Requirement: Client Deletion

The system MUST support deleting client records. Deletion of clients with active relationships MUST be handled safely.

**Feature tier**: MVP

#### Scenario: Delete a client with no linked entities

- GIVEN an existing client "Test B.V." with no linked leads, requests, or contact persons
- WHEN the user deletes the client
- THEN the system MUST remove the OpenRegister object
- AND the client MUST no longer appear in the client list

#### Scenario: Delete a client with linked contact persons

- GIVEN an existing client "Acme B.V." with two linked contact persons
- WHEN the user deletes the client
- THEN the system MUST warn the user that linked contact persons exist
- AND if the user confirms deletion, the system MUST remove the client object
- AND the linked contact persons SHOULD be flagged as orphaned or deleted

#### Scenario: Attempt to delete a client with active leads

- GIVEN an existing client "Gemeente Utrecht" with 2 open leads
- WHEN the user attempts to delete the client
- THEN the system MUST display a warning that the client has active leads
- AND the system SHOULD require explicit confirmation before proceeding
- AND if the user confirms, the leads MUST retain their data but the client reference SHOULD be cleared

#### Scenario: Attempt to delete a client with active requests

- GIVEN an existing client "Provincie Noord-Holland" with 1 open request
- WHEN the user attempts to delete the client
- THEN the system MUST display a warning that the client has active requests
- AND the system SHOULD require explicit confirmation before proceeding

---

### Requirement: Client Validation

The system MUST validate client data according to schema rules and field format constraints.

**Feature tier**: MVP

#### Scenario: Validate email format

- GIVEN a user creating or updating a client
- WHEN they enter "not-an-email" in the email field
- THEN the system MUST reject the input with a validation error
- AND the error message MUST indicate invalid email format
- AND valid formats such as "info@gemeente-utrecht.nl" MUST be accepted

#### Scenario: Validate telephone format

- GIVEN a user creating or updating a client
- WHEN they enter a telephone number
- THEN the system SHOULD accept international formats: "+31 30 286 0000", "+31302860000", "030-286 0000"
- AND the system SHOULD reject clearly invalid input such as "abc" or "12"

#### Scenario: Validate website URL format

- GIVEN a user creating or updating a client
- WHEN they enter "not a url" in the website field
- THEN the system MUST reject the input with a validation error
- AND valid formats such as "https://www.utrecht.nl" and "http://acme.nl" MUST be accepted

#### Scenario: Validate name maximum length

- GIVEN a user creating a client
- WHEN they enter a name exceeding 255 characters
- THEN the system MUST reject the input with a validation error
- AND the error message MUST indicate the maximum length

#### Scenario: Inline validation errors

- GIVEN a user creating or editing a client
- WHEN they enter invalid data in any field
- THEN validation errors MUST appear inline next to the relevant field
- AND the save button MUST be disabled while required fields are empty or validation errors exist

---

### Requirement: Client List View

The system MUST provide a list view of all clients with search, sort, filter, and pagination capabilities.

**Feature tier**: MVP

#### Scenario: Display client list with default settings

- GIVEN 35 clients exist in the system
- WHEN the user navigates to the client list
- THEN the system MUST display the first page of clients (default 20 per page)
- AND each row MUST show at minimum: name, type, email, and telephone
- AND the total count (35) MUST be displayed

#### Scenario: Search clients by name

- GIVEN clients "Jan de Vries", "Gemeente Utrecht", "Gemeente Amsterdam", "Acme B.V."
- WHEN the user searches for "Gemeente"
- THEN the results MUST include "Gemeente Utrecht" and "Gemeente Amsterdam"
- AND the results MUST NOT include "Jan de Vries" or "Acme B.V."

#### Scenario: Search clients by email

- GIVEN a client "Acme B.V." with email "info@acme.nl"
- WHEN the user searches for "acme.nl"
- THEN the results MUST include "Acme B.V."

#### Scenario: Filter clients by type

- GIVEN 10 person clients and 5 organization clients
- WHEN the user filters by type "organization"
- THEN only the 5 organization clients MUST be shown
- AND the filter MUST be clearable to show all clients again

#### Scenario: Sort clients by name

- GIVEN clients "Gemeente Utrecht", "Acme B.V.", "Jan de Vries"
- WHEN the user sorts by name ascending
- THEN the order MUST be: "Acme B.V.", "Gemeente Utrecht", "Jan de Vries"

#### Scenario: Paginate client list

- GIVEN 45 clients and a page size of 20
- WHEN the user views page 1
- THEN 20 clients MUST be displayed
- AND navigation controls MUST indicate 3 pages total
- AND clicking page 2 MUST display clients 21-40
- AND clicking page 3 MUST display clients 41-45

#### Scenario: Empty client list

- GIVEN no clients exist in the system
- WHEN the user navigates to the client list
- THEN the system MUST display an empty state message
- AND the message SHOULD include a call-to-action to create the first client

---

### Requirement: Client Detail View

The system MUST provide a detail view for each client showing all properties, summary statistics, linked entities (contact persons, leads, requests), and an activity timeline.

**Feature tier**: MVP

#### Scenario: View organization client detail

- GIVEN an organization client "Acme Corporation" with email "info@acme.nl", telephone "+31 20 555 0123", website "www.acme.nl", taxID "12345678", and address "Keizersgracht 100, 1015 AA Amsterdam"
- WHEN the user navigates to the client detail view
- THEN the system MUST display all client properties in an info panel
- AND the type MUST be displayed as "Organization"

#### Scenario: View person client detail

- GIVEN a person client "Jan de Vries" with email "jan@devries.nl"
- WHEN the user navigates to the client detail view
- THEN the system MUST display all client properties
- AND the type MUST be displayed as "Person"
- AND the taxID field SHOULD NOT be prominently displayed for person clients

#### Scenario: Display summary statistics

- GIVEN a client "Acme Corporation" with 2 open leads (total value EUR 25,000), 3 won leads (total value EUR 42,000), and 1 open request
- WHEN the user views the client detail
- THEN the system MUST display a summary panel showing:
  - Open leads count and total value
  - Won leads count and total value
  - Open requests count
  - Total value (open + won)
  - Last activity date
  - Client since date (creation date)

#### Scenario: Display linked contact persons

- GIVEN a client "Acme Corporation" with contact persons "Petra Jansen (Sales Manager)" and "Mark de Groot (CTO)"
- WHEN the user views the client detail
- THEN the system MUST display a contact persons section listing both contacts
- AND each contact MUST show name, role, and email
- AND a button to add a new contact person MUST be visible

#### Scenario: Display linked leads

- GIVEN a client "Acme Corporation" with leads "Acme Corp deal (Qualified, EUR 5,000)" and "Acme expansion (New, EUR 20,000)"
- WHEN the user views the client detail
- THEN the system MUST display a leads section listing both leads
- AND each lead MUST show title, stage, and value
- AND clicking a lead MUST navigate to the lead detail view

#### Scenario: Display linked requests

- GIVEN a client "Acme Corporation" with request "IT Support #42 (In Progress)"
- WHEN the user views the client detail
- THEN the system MUST display a requests section listing the request
- AND each request MUST show title and status
- AND clicking a request MUST navigate to the request detail view

#### Scenario: Activity timeline

- GIVEN a client "Acme Corporation" with the following history:
  - Jan 15: Client created
  - Feb 10: Lead "Acme Corp deal" created
  - Feb 18: Note added by Jan de Vries
  - Feb 20: Request #42 created
  - Feb 22: Lead moved to Qualified
- WHEN the user views the client detail
- THEN the system MUST display an activity timeline ordered newest first
- AND each entry MUST show the date, action description, and actor
- AND the timeline MUST support loading more entries

---

### Requirement: Contact Person Creation

The system MUST support creating contact persons linked to client organizations. A contact person MUST have a name and a client reference.

**Feature tier**: MVP

#### Scenario: Create a contact person for an organization

- GIVEN an organization client "Gemeente Utrecht"
- WHEN the user adds a contact person with name "Jan Jansen", role "Projectleider", email "j.jansen@utrecht.nl", and jobTitle "Senior Project Manager"
- THEN the system MUST create an OpenRegister object with the `contact` schema
- AND the object MUST have `schema:worksFor` referencing the client UUID
- AND the contact person MUST appear on the client detail view under "Contact Persons"

#### Scenario: Create a contact person with minimal fields

- GIVEN an organization client "Acme B.V."
- WHEN the user adds a contact person with only name "Petra Jansen" and the client reference
- THEN the system MUST create the contact person successfully
- AND optional fields (email, telephone, role, jobTitle) MUST be stored as empty/null

#### Scenario: Fail to create a contact person without a client link

- GIVEN a new contact person form
- WHEN the user tries to save with name "Jan Jansen" but without selecting a client
- THEN the system MUST show a validation error indicating that a client is required
- AND the contact person MUST NOT be created

#### Scenario: Fail to create a contact person without a name

- GIVEN a new contact person form with client "Gemeente Utrecht" selected
- WHEN the user tries to save without entering a name
- THEN the system MUST show a validation error indicating that name is required
- AND the contact person MUST NOT be created

---

### Requirement: Contact Person Update and Deletion

The system MUST support updating and deleting contact persons. Changes MUST be recorded in the audit trail.

**Feature tier**: MVP

#### Scenario: Update a contact person role

- GIVEN a contact person "Jan Jansen" with role "Projectleider" linked to "Gemeente Utrecht"
- WHEN the user changes the role to "Programmamanager"
- THEN the system MUST update the OpenRegister object
- AND the audit trail MUST record the change

#### Scenario: Delete a contact person

- GIVEN a contact person "Petra Jansen" linked to "Acme B.V."
- WHEN the user deletes the contact person
- THEN the system MUST remove the OpenRegister object
- AND the contact person MUST no longer appear on the client detail view
- AND leads or requests that reference this contact SHOULD have their contact reference cleared

#### Scenario: Reassign a contact person to a different client

- GIVEN a contact person "Mark de Groot" linked to "Acme B.V."
- WHEN the user changes the client reference to "TechCorp B.V."
- THEN the system MUST update the `schema:worksFor` reference
- AND the contact MUST disappear from "Acme B.V." detail and appear on "TechCorp B.V." detail

---

### Requirement: Contact Person List and Search

The system MUST provide a way to list and search contact persons across all clients.

**Feature tier**: MVP

#### Scenario: List all contact persons

- GIVEN 15 contact persons across multiple clients
- WHEN the user navigates to the contact person list
- THEN the system MUST display all contact persons with name, role, organization (client name), and email
- AND the list MUST support pagination

#### Scenario: Search contact persons by name

- GIVEN contact persons "Jan Jansen", "Petra Jansen", "Mark de Groot"
- WHEN the user searches for "Jansen"
- THEN the results MUST include "Jan Jansen" and "Petra Jansen"
- AND the results MUST NOT include "Mark de Groot"

#### Scenario: Navigate from contact person to client

- GIVEN a contact person "Petra Jansen" linked to "Acme B.V."
- WHEN the user clicks on the organization name in the contact person list
- THEN the system MUST navigate to the "Acme B.V." client detail view

---

### Requirement: Client-to-Lead Relationship

The system MUST support linking leads to clients. A lead's `client` property references a client UUID.

**Feature tier**: MVP

#### Scenario: Create a lead linked to a client

- GIVEN a client "Gemeente Utrecht"
- WHEN the user creates a lead with title "Digital Transformation Project" and selects "Gemeente Utrecht" as the client
- THEN the lead MUST store a reference to the client UUID
- AND the lead MUST appear on the "Gemeente Utrecht" client detail under "Leads"

#### Scenario: View all leads for a client

- GIVEN a client "Acme B.V." with 3 linked leads
- WHEN the user views the client detail
- THEN the system MUST display all 3 leads in the leads section
- AND each lead MUST show title, current stage, and value

---

### Requirement: Client-to-Request Relationship

The system MUST support linking requests to clients. A request's `client` property references a client UUID.

**Feature tier**: MVP

#### Scenario: Create a request linked to a client

- GIVEN a client "Provincie Noord-Holland"
- WHEN the user creates a request with title "ICT Ondersteuning" and selects "Provincie Noord-Holland" as the client
- THEN the request MUST store a reference to the client UUID
- AND the request MUST appear on the client detail under "Requests"

#### Scenario: View all requests for a client

- GIVEN a client "Gemeente Utrecht" with 2 linked requests
- WHEN the user views the client detail
- THEN the system MUST display both requests in the requests section
- AND each request MUST show title and current status

---

### Requirement: Nextcloud Contacts Sync

The system MUST sync client data with Nextcloud's built-in Contacts app via `OCP\Contacts\IManager`. This avoids duplicate contact entry and leverages Nextcloud's native CardDAV infrastructure.

**Feature tier**: MVP

#### Scenario: Search existing Nextcloud contacts when creating a client

- GIVEN Nextcloud contacts "Jan de Vries (jan@devries.nl)" and "Maria Garcia" exist in the user's address book
- WHEN the user creates a new client and types "Jan" in the name field
- THEN the system SHOULD query `IManager::search('Jan', ['FN', 'EMAIL'], ['limit' => 10])`
- AND suggest "Jan de Vries" as a potential match
- AND the user SHOULD be able to import contact data from the match

#### Scenario: Create Nextcloud contact from client

- GIVEN a Pipelinq client "Gemeente Utrecht" with email "info@utrecht.nl" and telephone "+31 30 286 0000" and no linked Nextcloud contact
- WHEN the user chooses to sync the client to Nextcloud Contacts
- THEN the system SHOULD create a vCard contact via `IManager::createOrUpdate()` with FN, EMAIL, and TEL properties
- AND the system MUST store the vCard UID as a reference on the client object
- AND subsequent updates to the client SHOULD propagate to the linked vCard

#### Scenario: Link existing Nextcloud contact to client

- GIVEN a Nextcloud contact "Jan de Vries" with UID "abc-123-def"
- AND a Pipelinq client "Jan de Vries" exists but has no linked contact
- WHEN the user links the Nextcloud contact to the client
- THEN the system MUST store the vCard UID "abc-123-def" on the client object
- AND the client detail SHOULD show that a Nextcloud Contact link exists

---

### Requirement: Duplicate Detection

The system MUST detect potential duplicate clients based on name and email matching.

**Feature tier**: V1

#### Scenario: Detect duplicate by exact name match

- GIVEN an existing client "Gemeente Utrecht"
- WHEN the user creates a new client with name "Gemeente Utrecht"
- THEN the system SHOULD display a warning indicating a potential duplicate exists
- AND the warning SHOULD show the existing client details
- AND the user SHOULD be able to proceed with creation or navigate to the existing client

#### Scenario: Detect duplicate by email match

- GIVEN an existing client "Acme B.V." with email "info@acme.nl"
- WHEN the user creates a new client with a different name but the same email "info@acme.nl"
- THEN the system SHOULD display a warning indicating a potential duplicate based on email
- AND the user SHOULD be able to proceed or navigate to the existing client

#### Scenario: Fuzzy name matching

- GIVEN an existing client "Gemeente Utrecht"
- WHEN the user creates a client named "Gem. Utrecht" or "gemeente utrecht"
- THEN the system SHOULD detect the similarity and warn about a potential duplicate
- AND the confidence level of the match SHOULD be indicated

---

### Requirement: Client Import

The system MUST support importing clients from CSV and vCard files.

**Feature tier**: V1

#### Scenario: Import clients from CSV

- GIVEN a CSV file with columns: name, type, email, telephone
- AND the file contains 50 rows of client data
- WHEN the user uploads the CSV and confirms the column mapping
- THEN the system MUST create 50 client objects in OpenRegister
- AND the system MUST report how many were created, skipped (duplicates), or failed (validation errors)

#### Scenario: Import clients from vCard

- GIVEN a vCard (.vcf) file containing 10 contacts
- WHEN the user uploads the vCard file
- THEN the system MUST parse the vCard properties (FN, EMAIL, TEL, ADR, ORG)
- AND create client objects for each contact
- AND map the vCard KIND property to client type (individual -> person, org -> organization)

#### Scenario: Import with validation errors

- GIVEN a CSV file where 3 out of 20 rows have missing name fields
- WHEN the user imports the file
- THEN the system MUST create the 17 valid clients
- AND report 3 failures with row numbers and error descriptions
- AND the user MUST be able to download an error report

---

### Requirement: Client Export

The system MUST support exporting clients to CSV and vCard formats.

**Feature tier**: V1

#### Scenario: Export all clients as CSV

- GIVEN 30 clients exist in the system
- WHEN the user clicks "Export CSV"
- THEN the system MUST generate a CSV file containing all client fields
- AND the file MUST include a header row
- AND the file MUST be downloadable

#### Scenario: Export filtered clients as CSV

- GIVEN the user has filtered the client list to show only organizations
- WHEN the user clicks "Export CSV"
- THEN the system MUST export only the filtered results
- AND the export MUST respect the current filter and search criteria

#### Scenario: Export client as vCard

- GIVEN a client "Gemeente Utrecht" with all fields populated
- WHEN the user exports the client as vCard
- THEN the system MUST generate a valid RFC 6350 vCard
- AND the vCard MUST include FN, EMAIL, TEL, ADR, and URL properties

---

## Requirements

---

### Requirement: KVK Integration for Dutch Businesses

The system MUST support looking up Dutch organizations via the KVK (Kamer van Koophandel) Handelsregister API to auto-populate client data and ensure accurate business registration details. The KVK API integration already exists in `KvkApiClient` and `KvkResultMapper` for prospect discovery; this requirement extends it to client creation and enrichment.

**Feature tier**: V1

#### Scenario: Auto-complete organization from KVK number

- GIVEN a user creating a new organization client
- WHEN they enter KVK number "12345678" in the KVK field
- THEN the system MUST query the KVK Handelsregister API (`api.kvk.nl/api/v1/zoeken`)
- AND if the company is found, the system MUST auto-populate: name (eersteHandelsnaam), address (bezoekadres), and legal form (rechtsvorm)
- AND the user MUST be able to review and modify the auto-populated data before saving
- AND the KVK number MUST be stored on the client object in a `kvkNumber` field

#### Scenario: Search KVK by company name

- GIVEN a user creating a new organization client
- WHEN they enter "Conduction" in the company name field and click a "Search KVK" action
- THEN the system MUST search the KVK API by company name
- AND display matching results with KVK number, trade name, address, and legal form
- AND the user MUST be able to select a result to auto-populate the client fields

#### Scenario: Validate KVK number format

- GIVEN a user entering a KVK number on a client
- WHEN they enter "1234" (fewer than 8 digits) or "ABCDEFGH" (non-numeric)
- THEN the system MUST reject the input with a validation error
- AND the error message MUST indicate that a KVK number must be exactly 8 digits

#### Scenario: Store KVK metadata on client

- GIVEN a client "Acme B.V." created from a KVK lookup with KVK number "12345678", SBI code "6201", legal form "Besloten Vennootschap", and registration date "2015-03-01"
- WHEN the client is saved
- THEN the system MUST store the KVK number, SBI code(s), legal form, and registration date as structured fields on the client object
- AND these fields MUST be visible in the client detail view under a "Chamber of Commerce" section
- AND the system SHOULD display a "Verified via KVK" badge when the KVK number is present

#### Scenario: Detect duplicate client by KVK number

- GIVEN an existing client "Acme B.V." with KVK number "12345678"
- WHEN a user attempts to create a new client with the same KVK number "12345678"
- THEN the system MUST display a warning that a client with this KVK number already exists
- AND the warning MUST link to the existing client
- AND the system MUST NOT allow two clients with the same KVK number unless the user explicitly overrides

#### Scenario: KVK API unavailable

- GIVEN the KVK API key is not configured or the API returns an error
- WHEN the user attempts to search KVK
- THEN the system MUST display a user-friendly error message
- AND the user MUST still be able to create the client manually without KVK data
- AND no partial or corrupted data MUST be stored

---

### Requirement: BSN Handling Compliance

The system MUST handle BSN (Burgerservicenummer) data in compliance with Dutch privacy law (Wet algemene bepalingen burgerservicenummer). BSN is a sensitive personal identifier that may only be processed when there is a legal basis, and MUST be stored and transmitted with appropriate safeguards.

**Feature tier**: Enterprise

#### Scenario: BSN field restricted to authorized users

- GIVEN a person client "Jan de Vries" with a BSN stored
- WHEN a user without the "bsn_access" permission views the client detail
- THEN the BSN field MUST NOT be visible at all (not masked, fully hidden)
- AND when a user with "bsn_access" permission views the client detail
- THEN the BSN MUST be displayed masked as "****-**-123" (last 3 digits visible)
- AND an explicit "Show BSN" action MUST be required to reveal the full number

#### Scenario: BSN validation (elfproef)

- GIVEN a user entering a BSN on a person client
- WHEN they enter "123456789"
- THEN the system MUST validate using the 11-proof (elfproef) algorithm: (9*d1 + 8*d2 + 7*d3 + 6*d4 + 5*d5 + 4*d6 + 3*d7 + 2*d8 - 1*d9) mod 11 == 0
- AND the result MUST be non-zero before modulo (to exclude "000000000")
- AND if validation fails, the system MUST reject with "Invalid BSN"

#### Scenario: BSN access logging

- GIVEN a user with "bsn_access" permission views or reveals a BSN
- WHEN the BSN is accessed
- THEN the system MUST log the access in the audit trail with: user identity, timestamp, client ID, and access type (view/reveal/modify)
- AND the BSN access log MUST be available for compliance auditing

#### Scenario: BSN not stored for organization clients

- GIVEN a user editing an organization client "Gemeente Utrecht"
- WHEN the client form is displayed
- THEN the BSN field MUST NOT be visible or available
- AND the BSN field MUST only be available on person-type clients

#### Scenario: BSN excluded from standard exports

- GIVEN 20 person clients, 5 of which have BSN stored
- WHEN the user exports clients as CSV
- THEN the BSN column MUST NOT be included in the export by default
- AND a separate "Export with BSN" option MUST be available only to users with "bsn_access" permission
- AND any export containing BSN data MUST be logged in the audit trail

---

### Requirement: Client Deduplication and Merge

The system MUST support merging duplicate client records into a single consolidated record, transferring all linked entities (contact persons, leads, requests) and preserving the full audit history.

**Feature tier**: V1

#### Scenario: Identify merge candidates

- GIVEN existing clients "Gemeente Utrecht" (ID: abc-111, with 3 leads) and "Gem. Utrecht" (ID: abc-222, with 1 lead and 2 contacts)
- WHEN the user selects both clients in the list view and clicks "Merge"
- THEN the system MUST display a merge preview showing:
  - Both client records side-by-side with all fields
  - A field-by-field selector allowing the user to choose which value to keep for each property
  - A summary of linked entities that will be transferred: 4 leads, 2 contacts
  - The target (surviving) client record

#### Scenario: Execute client merge

- GIVEN the user has configured a merge of "Gem. Utrecht" into "Gemeente Utrecht"
- WHEN the user confirms the merge
- THEN the system MUST update the surviving client "Gemeente Utrecht" with the selected field values
- AND the system MUST re-link all contact persons from "Gem. Utrecht" to "Gemeente Utrecht" (update `client` UUID reference)
- AND the system MUST re-link all leads from "Gem. Utrecht" to "Gemeente Utrecht"
- AND the system MUST re-link all requests from "Gem. Utrecht" to "Gemeente Utrecht"
- AND the system MUST delete the source client "Gem. Utrecht"
- AND the audit trail MUST record the merge operation with both client IDs and the user who performed it

#### Scenario: Merge preserves Nextcloud contact link

- GIVEN client A has a `contactsUid` linking to a Nextcloud vCard and client B does not
- WHEN client B is merged into client A
- THEN the surviving client A MUST retain its `contactsUid` link
- AND if both clients had `contactsUid` links, the surviving client MUST keep its own link and the system SHOULD log that the duplicate link was discarded

#### Scenario: Merge from duplicate detection

- GIVEN the duplicate detection system has flagged "Acme B.V." and "ACME BV" as potential duplicates
- WHEN the user clicks "Merge" on the duplicate warning
- THEN the system MUST open the merge preview with both clients pre-selected
- AND the user MUST be able to proceed through the standard merge flow

#### Scenario: Cancel a merge

- GIVEN the user has opened the merge preview for two clients
- WHEN the user clicks "Cancel"
- THEN no changes MUST be made to either client
- AND no linked entities MUST be modified

---

### Requirement: Client Hierarchy (Parent/Child Organizations)

The system MUST support hierarchical organization structures where a client organization can have a parent organization and child organizations. This enables representing corporate groups, holding companies, and franchise networks.

**Feature tier**: V1

#### Scenario: Set a parent organization

- GIVEN an organization client "Acme Netherlands B.V."
- AND an organization client "Acme Corporation" (the global holding company)
- WHEN the user sets the parent of "Acme Netherlands B.V." to "Acme Corporation"
- THEN the system MUST store a `parentOrganization` UUID reference on "Acme Netherlands B.V."
- AND the detail view of "Acme Netherlands B.V." MUST display "Acme Corporation" as the parent with a clickable link
- AND the detail view of "Acme Corporation" MUST display "Acme Netherlands B.V." in a "Subsidiaries" section

#### Scenario: View organization hierarchy tree

- GIVEN "Acme Corporation" has children "Acme Netherlands B.V." and "Acme Germany GmbH"
- AND "Acme Netherlands B.V." has child "Acme Utrecht Office"
- WHEN the user views the "Acme Corporation" detail page
- THEN the system MUST display the full hierarchy as a tree:
  - Acme Corporation
    - Acme Netherlands B.V.
      - Acme Utrecht Office
    - Acme Germany GmbH
- AND each node in the tree MUST be clickable to navigate to that client's detail view

#### Scenario: Aggregate hierarchy statistics

- GIVEN a parent organization "Acme Corporation" with 2 subsidiaries
- AND the subsidiaries collectively have 5 open leads (total EUR 120,000) and 3 requests
- WHEN the user views "Acme Corporation" detail
- THEN the summary statistics panel SHOULD display both:
  - Direct statistics (leads/requests linked directly to "Acme Corporation")
  - Consolidated statistics (including all subsidiaries' leads/requests)
- AND the consolidated values MUST be clearly labeled as "Including subsidiaries"

#### Scenario: Prevent circular parent references

- GIVEN "Acme Netherlands B.V." has parent "Acme Corporation"
- WHEN the user tries to set the parent of "Acme Corporation" to "Acme Netherlands B.V."
- THEN the system MUST reject the change with a validation error
- AND the error message MUST indicate that circular parent references are not allowed

#### Scenario: Person client cannot have parent organization

- GIVEN a person client "Jan de Vries"
- WHEN the user attempts to set a parent organization on the person client
- THEN the `parentOrganization` field MUST NOT be available on person-type clients
- AND only organization-type clients MUST support parent/child relationships

---

### Requirement: Client Segmentation and Tagging

The system MUST support tagging and categorizing clients for segmentation purposes. Tags enable grouping clients for targeted actions, filtering, and reporting.

**Feature tier**: V1

#### Scenario: Add tags to a client

- GIVEN a client "Gemeente Utrecht"
- WHEN the user adds tags "overheid", "provincie-utrecht", and "bestaande-klant"
- THEN the system MUST store the tags as an array property on the client object
- AND the tags MUST be displayed on the client detail view as visual chips/badges
- AND the tags MUST be visible in the client list view

#### Scenario: Filter clients by tag

- GIVEN 20 clients, 8 of which have the tag "overheid"
- WHEN the user filters the client list by tag "overheid"
- THEN only the 8 tagged clients MUST be displayed
- AND the filter MUST support multiple tag selection (AND logic: clients must have all selected tags)

#### Scenario: Manage tag vocabulary

- GIVEN a user with admin access
- WHEN they navigate to CRM settings
- THEN the system SHOULD display a tag management section
- AND the user SHOULD be able to add new tags, rename existing tags, and delete unused tags
- AND deleting a tag MUST remove it from all clients that have it

#### Scenario: Tag auto-complete on client form

- GIVEN existing tags "overheid", "onderwijs", "zorg", "bedrijfsleven"
- WHEN the user adds a tag to a client and types "over"
- THEN the system MUST suggest "overheid" as an auto-complete option
- AND the user MUST be able to select from existing tags or create a new one

#### Scenario: Industry classification

- GIVEN the client schema already has an `industry` field (facetable)
- WHEN the user creates or updates a client
- THEN the system MUST provide a curated list of industry values based on SBI sector codes
- AND the industry field MUST be selectable from a dropdown with common Dutch industry sectors: "ICT", "Overheid", "Zorg", "Onderwijs", "Bouw", "Financiele dienstverlening", "Detailhandel", "Transport en logistiek"
- AND custom industry values SHOULD be allowed

---

### Requirement: Client Health Scoring

The system SHOULD calculate and display a client health score based on relationship activity metrics. The health score helps sales teams prioritize follow-ups and identify at-risk client relationships.

**Feature tier**: Enterprise

#### Scenario: Calculate health score based on activity

- GIVEN a client "Acme B.V." with the following activity in the last 90 days:
  - 3 leads in active pipeline stages
  - 2 requests opened and resolved
  - 5 audit trail entries (field updates, notes)
  - Last interaction: 5 days ago
- WHEN the system calculates the health score
- THEN the score MUST be a value from 0-100
- AND the score MUST factor in: recency of last interaction (40% weight), number of active leads (25% weight), request resolution rate (20% weight), and overall activity frequency (15% weight)
- AND the score MUST be displayed on the client detail view as a color-coded indicator (green >= 70, yellow 40-69, red < 40)

#### Scenario: Health score displayed in client list

- GIVEN 20 clients with calculated health scores
- WHEN the user views the client list
- THEN the system SHOULD display a health score indicator (color dot or bar) for each client
- AND the user MUST be able to sort clients by health score (ascending/descending)
- AND the user MUST be able to filter clients by health range (e.g., "At Risk" = score < 40)

#### Scenario: Health score recalculation

- GIVEN a client "Acme B.V." with health score 75 (green)
- AND no interaction has occurred for 60 days
- WHEN the health score is recalculated (daily cron or on-demand)
- THEN the score MUST decrease to reflect the inactivity period
- AND the new score MUST be below 40 (red) after 60+ days of inactivity
- AND the score change MUST be logged in the audit trail

#### Scenario: Health score ignored for new clients

- GIVEN a client "NewCo B.V." created 3 days ago with 1 lead created
- WHEN the system calculates the health score
- THEN the system SHOULD display "New" instead of a numeric score for clients less than 30 days old
- AND the system MUST NOT penalize new clients for lack of historical activity

---

### Requirement: Client Lifecycle Analytics

The system SHOULD provide analytics about the client lifecycle, including acquisition rates, retention metrics, and revenue trends per client.

**Feature tier**: Enterprise

#### Scenario: Client acquisition over time

- GIVEN 50 clients created between January and June
- WHEN the user views the client lifecycle analytics dashboard
- THEN the system MUST display a chart showing new clients per month
- AND the chart MUST support drill-down to see which clients were created in each period

#### Scenario: Client revenue contribution

- GIVEN a client "Acme B.V." with leads: 3 won (EUR 42,000), 2 open (EUR 25,000), 1 lost (EUR 10,000)
- WHEN the user views the client's revenue analytics on the detail page
- THEN the system MUST display:
  - Total won revenue: EUR 42,000
  - Pipeline value (open leads): EUR 25,000
  - Lost revenue: EUR 10,000
  - Win rate: 60% (3 won out of 5 closed: 3 won + 1 lost, noting 2 still open)
- AND the system SHOULD show a revenue trend chart over time (monthly won revenue)

#### Scenario: Client retention status

- GIVEN clients with the following patterns:
  - "Active Client A": has leads or requests in the last 90 days
  - "Dormant Client B": last activity was 180 days ago, no open leads or requests
  - "Churned Client C": last activity was 365+ days ago
- WHEN the user views the client analytics dashboard
- THEN the system MUST categorize clients as: Active (activity in last 90 days), Dormant (90-365 days), Churned (365+ days)
- AND display counts and percentages for each category
- AND the user MUST be able to filter the client list by lifecycle status

---

### Requirement: GDPR Data Subject Rights

The system MUST support GDPR (AVG) data subject rights for person-type clients in compliance with EU General Data Protection Regulation. Organization clients are not data subjects under GDPR.

**Feature tier**: V1

#### Scenario: Right to access (inzageverzoek)

- GIVEN a person client "Jan de Vries" with all CRM data (personal details, linked leads, requests, activity timeline, notes)
- WHEN an authorized user processes a data access request for "Jan de Vries"
- THEN the system MUST generate a complete data export containing:
  - All client properties (name, email, phone, address, notes)
  - All linked contact person records
  - All linked lead records (title, value, stage, dates)
  - All linked request records (title, status, dates)
  - Audit trail entries for this client
- AND the export MUST be in a machine-readable format (JSON or CSV)
- AND the export MUST be downloadable as a single file

#### Scenario: Right to erasure (verwijderverzoek)

- GIVEN a person client "Jan de Vries" with 2 linked leads and 1 request
- WHEN an authorized user processes a data erasure request
- THEN the system MUST display a preview of all data that will be erased
- AND upon confirmation, the system MUST:
  - Delete the client object
  - Delete all linked contact person records
  - Anonymize references in linked leads (replace client name with "Geanonimiseerd")
  - Anonymize references in linked requests
  - Remove the linked Nextcloud contact if `contactsUid` is set
- AND the system MUST log the erasure action with a reference number and timestamp
- AND the erasure MUST NOT be reversible

#### Scenario: Right to rectification (rectificatieverzoek)

- GIVEN a person client "Jan de Vries" with email "wrong@email.nl"
- WHEN an authorized user processes a rectification request to update the email to "jan@correct.nl"
- THEN the system MUST update the email field
- AND the audit trail MUST record the rectification with the reason "GDPR rectification request"
- AND if a Nextcloud contact is linked, the correction MUST propagate to the vCard

#### Scenario: Data processing register entry

- GIVEN the system processes personal data for person-type clients
- WHEN an administrator views the GDPR compliance settings
- THEN the system MUST provide a data processing register documenting:
  - Categories of personal data processed (name, email, phone, address, BSN if applicable)
  - Purpose of processing (client relationship management)
  - Legal basis (legitimate interest or contract)
  - Retention period policy
  - Technical and organizational security measures

---

### Requirement: Multi-Tenancy Client Isolation

The system MUST ensure that client data is isolated per Nextcloud instance and, within shared Nextcloud instances, per authorized group or user scope. This prevents unauthorized cross-tenant data access.

**Feature tier**: Enterprise

#### Scenario: Client data scoped to OpenRegister instance

- GIVEN two Nextcloud users "sales_user_a" and "sales_user_b" both using Pipelinq
- WHEN "sales_user_a" creates a client "Acme B.V."
- THEN the client MUST be stored in the shared Pipelinq register
- AND "sales_user_b" MUST also be able to see and access "Acme B.V."
- AND the client data MUST NOT be accessible from other Nextcloud instances

#### Scenario: Team-based client visibility

- GIVEN team "Sales Amsterdam" and team "Sales Rotterdam" are configured
- AND client "Acme Amsterdam B.V." is assigned to team "Sales Amsterdam"
- WHEN a user from team "Sales Rotterdam" views the client list
- THEN the system SHOULD filter based on team assignment according to Nextcloud group permissions
- AND administrators MUST always see all clients regardless of team assignment

#### Scenario: API access respects authentication scope

- GIVEN a client "Acme B.V." exists in the Pipelinq register
- WHEN an unauthenticated API request attempts to fetch the client
- THEN the request MUST be rejected with HTTP 401
- AND when an authenticated user from a different app attempts to access the client via OpenRegister API
- THEN the access MUST be governed by OpenRegister's permission model

---

### Requirement: Client Import from CSV with Column Mapping

The system MUST provide a guided CSV import workflow with column mapping, preview, validation feedback, and duplicate handling.

**Feature tier**: V1

#### Scenario: Upload and preview CSV

- GIVEN a user with CRM access
- WHEN they upload a CSV file "clients_export_2025.csv" with 100 rows
- THEN the system MUST display a preview showing the first 5 rows of the file
- AND the system MUST detect the delimiter (comma, semicolon, or tab) automatically
- AND the system MUST detect the character encoding (UTF-8, ISO-8859-1)

#### Scenario: Map CSV columns to client fields

- GIVEN a CSV with headers: "Bedrijfsnaam", "E-mail", "Telefoon", "Postcode", "Type"
- WHEN the mapping step is displayed
- THEN the system MUST auto-suggest mappings based on header names: "Bedrijfsnaam" -> name, "E-mail" -> email, "Telefoon" -> phone, "Postcode" -> address, "Type" -> type
- AND the user MUST be able to override any mapping
- AND the user MUST be able to skip columns that should not be imported
- AND required fields (name, type) MUST be mapped before import can proceed

#### Scenario: Handle duplicate detection during import

- GIVEN a CSV with 100 rows and 5 rows match existing clients by name or email
- WHEN the user runs the import
- THEN the system MUST identify the 5 duplicates and present options: "Skip", "Update existing", or "Create anyway"
- AND the user MUST be able to choose a default action for all duplicates or handle each individually
- AND the import summary MUST show: 95 created, 5 duplicates (with chosen action)

#### Scenario: Import progress and error handling

- GIVEN a CSV with 500 rows, 12 of which have validation errors
- WHEN the import is running
- THEN the system MUST display a progress indicator showing rows processed
- AND upon completion, the system MUST display: 488 created, 12 failed
- AND each failure MUST show the row number, the offending value, and the validation error
- AND the user MUST be able to download a CSV of failed rows for correction and re-import

---

### Requirement: Client Export with Format Options

The system MUST support exporting clients in multiple formats with configurable field selection.

**Feature tier**: V1

#### Scenario: Export with field selection

- GIVEN 50 clients in the system
- WHEN the user clicks "Export" and selects fields: name, type, email, phone, industry
- THEN the exported file MUST contain only the selected fields
- AND fields not selected (address, website, notes, contactsUid) MUST be excluded

#### Scenario: Export as Excel (XLSX)

- GIVEN 50 clients in the system
- WHEN the user chooses "Export as Excel"
- THEN the system MUST generate a valid XLSX file
- AND the file MUST include a header row with field names
- AND the file MUST be downloadable with a filename pattern: "pipelinq-clients-YYYY-MM-DD.xlsx"

#### Scenario: Bulk export as vCard

- GIVEN the user has filtered the client list to 15 person clients
- WHEN the user clicks "Export as vCard"
- THEN the system MUST generate a single .vcf file containing all 15 contacts
- AND each vCard entry MUST comply with RFC 6350
- AND the file MUST include: FN, EMAIL, TEL, ADR, ORG (if organization type), and URL (if website is set)

---

### Requirement: Client Timeline (All Interactions)

The system MUST provide a unified interaction timeline on the client detail view that aggregates all CRM activity types into a single chronological feed.

**Feature tier**: V1

#### Scenario: Timeline aggregates all entity types

- GIVEN a client "Acme B.V." with the following history:
  - Mar 1: Client created by user "admin"
  - Mar 5: Contact person "Jan Jansen" added
  - Mar 10: Lead "Website Redesign" created (EUR 15,000)
  - Mar 12: Lead "Website Redesign" moved to stage "Qualified"
  - Mar 15: Request "Support Ticket #101" created
  - Mar 18: Note "Followed up by phone" added by user "sales1"
  - Mar 20: Request "Support Ticket #101" resolved
  - Mar 25: Lead "Website Redesign" won
- WHEN the user views the client detail timeline
- THEN the system MUST display all 8 events in reverse chronological order
- AND each event MUST show: date, event type icon, description, and actor (user who performed the action)
- AND events MUST be visually distinguished by type (create, update, stage change, note, resolution)

#### Scenario: Timeline supports filtering by event type

- GIVEN a client with 50 timeline events of various types
- WHEN the user filters the timeline by "Leads only"
- THEN only lead-related events MUST be displayed
- AND filter options MUST include: All, Leads, Requests, Contacts, Notes, Field changes

#### Scenario: Timeline pagination

- GIVEN a client with 200 timeline events
- WHEN the user views the timeline
- THEN the system MUST display the most recent 20 events initially
- AND a "Load more" button MUST load the next 20 events
- AND the system MUST indicate the total number of events

#### Scenario: Timeline shows linked entity details

- GIVEN a timeline event "Lead 'Website Redesign' moved to Qualified"
- WHEN the user clicks on the lead name in the timeline
- THEN the system MUST navigate to the lead detail view
- AND the same click-through behavior MUST apply to requests, contacts, and other referenced entities

---

### Requirement: Client Custom Fields

The system MUST support adding custom fields to the client schema to accommodate organization-specific data requirements without code changes. Custom fields are stored as additional properties on the OpenRegister client object.

**Feature tier**: V1

#### Scenario: Admin creates a custom text field

- GIVEN an administrator in the Pipelinq settings
- WHEN they add a custom field with label "Contract Number", type "text", and maxLength 50
- THEN the system MUST add the field to the client schema in OpenRegister
- AND the field MUST appear in the client create/edit form
- AND the field MUST appear in the client detail view
- AND the field MUST be included in CSV exports

#### Scenario: Admin creates a custom dropdown field

- GIVEN an administrator in the Pipelinq settings
- WHEN they add a custom field with label "Account Tier", type "enum", and options ["Bronze", "Silver", "Gold", "Platinum"]
- THEN the system MUST render the field as a dropdown in the client form
- AND the field MUST be filterable in the client list view (facetable)
- AND the field MUST be sortable

#### Scenario: Admin creates a custom date field

- GIVEN an administrator in the Pipelinq settings
- WHEN they add a custom field with label "Contract Expiry", type "date"
- THEN the system MUST render the field with a date picker in the client form
- AND the system SHOULD support creating notifications/reminders based on this date (e.g., 30 days before expiry)

#### Scenario: Custom field visible in imports

- GIVEN a custom field "Contract Number" exists on the client schema
- WHEN the user imports clients from CSV
- THEN the column mapping step MUST include "Contract Number" as an available target field
- AND imported values MUST be validated against the custom field's constraints

#### Scenario: Delete a custom field

- GIVEN a custom field "Legacy ID" exists on 30 clients
- WHEN the administrator deletes the custom field
- THEN the system MUST warn that data in this field will be lost for 30 clients
- AND upon confirmation, the field definition MUST be removed from the schema
- AND existing data in that field MUST be deleted from all client objects

---

### Requirement: Client Prospect Conversion

The system MUST support converting KVK prospect discovery results directly into client records, linking the prospect origin data (KVK number, SBI codes, legal form) to the newly created client. The `ProspectDiscoveryService` and `ProspectController` already support this flow partially; this requirement formalizes the full conversion path.

**Feature tier**: V1

#### Scenario: Convert prospect to client

- GIVEN the prospect discovery results include "TechStart B.V." with KVK number "87654321", SBI code "6201", address "Oudegracht 50, 3511 AR Utrecht", and legal form "Besloten Vennootschap"
- WHEN the user clicks "Create Client" on the prospect card
- THEN the system MUST create an organization client with:
  - name: "TechStart B.V."
  - type: "organization"
  - kvkNumber: "87654321"
  - industry: mapped from SBI code "6201" ("IT-dienstverlening")
  - address: "Oudegracht 50, 3511 AR Utrecht"
  - notes: "KVK: 87654321 | Legal form: Besloten Vennootschap | Source: KVK prospect discovery"
- AND the system MUST navigate to the new client's detail view
- AND the prospect MUST be removed from the discovery results

#### Scenario: Convert prospect to client with lead

- GIVEN a prospect "TechStart B.V." from the discovery results
- WHEN the user clicks "Create Client + Lead"
- THEN the system MUST create both a client and a linked lead
- AND the lead MUST reference the new client via its `client` UUID
- AND the lead MUST have source set to "prospect_discovery"

#### Scenario: Prospect already exists as client

- GIVEN a prospect "Acme B.V." with KVK number "12345678"
- AND an existing client "Acme B.V." with the same KVK number
- WHEN the prospect discovery results are displayed
- THEN "Acme B.V." MUST be flagged as "Already a client"
- AND the "Create Client" button MUST be replaced with "View Client"
- AND the system MUST link to the existing client detail view

---

### Current Implementation Status

**Substantially implemented.** Core CRUD, list view, detail view, contact persons, and Nextcloud Contacts sync are all in place. V1 features (duplicate detection, import/export) are NOT implemented. KVK API integration exists for prospect discovery but is not integrated into the client creation form.

Implemented:
- **Data model**: `lib/Settings/pipelinq_register.json` defines the `client` schema with properties: `name` (required), `type` (required, enum: person/organization), `email`, `phone`, `address`, `website`, `industry`, `notes`, `contactsUid`. Also defines the `contact` schema with: `name` (required), `email`, `phone`, `role`, `client` (UUID reference), `contactsUid`.
- **Client CRUD**: `src/store/modules/object.js` provides generic `saveObject()`, `deleteObject()`, `fetchObject()`, `fetchCollection()` via OpenRegister API. No dedicated client controller -- all CRUD goes through OpenRegister's object API directly.
- **Client List View**: `src/views/clients/ClientList.vue` -- displays client list with search, sort, and filter using OpenRegister's built-in query capabilities. Uses the `CnIndexPage` component.
- **Client Detail View**: `src/views/clients/ClientDetail.vue` -- displays client info (type, email, phone, website, address, notes), linked contacts table, linked leads table, linked requests table. Shows a "Synced with Contacts" badge when `contactsUid` is set. Uses `CnDetailPage` with sidebar (audit log). Has edit/delete actions with delete confirmation dialog that warns about linked entities.
- **Client Form**: `src/views/clients/ClientForm.vue` -- create/edit form for clients with inline validation (name required, type required, email/phone/website format validation via regex).
- **Client Create Dialog**: `src/views/clients/ClientCreateDialog.vue` -- quick-create dialog used from the dashboard.
- **Contact Person CRUD**: `src/views/contacts/ContactDetail.vue`, `ContactForm.vue`, `ContactList.vue` -- full CRUD for contact persons with client linking.
- **Client-to-Lead relationship**: LeadDetail shows linked client, ClientDetail shows linked leads (fetched via `client` filter on lead collection).
- **Client-to-Request relationship**: Same pattern as leads -- RequestDetail links to client, ClientDetail shows requests.
- **Nextcloud Contacts sync**: `ContactSyncService`, `ContactVcardService`, `ContactImportService`, `ContactVcardPropertyBuilder`, `ContactVcardWriterService`, `ContactLinkedUidsService`, `ContactDataBuilder` handle bidirectional sync, search, and import from Nextcloud address books. UI component: `ContactImportDialog.vue`.
- **KVK API integration (prospect discovery only)**: `KvkApiClient` queries `api.kvk.nl/api/v1/zoeken`, `KvkResultMapper` maps results to prospect format, `ProspectDiscoveryService` orchestrates search/scoring/caching, `ProspectController` exposes API endpoints. Not yet integrated into client creation form.
- **Routing**: `/clients` (list), `/clients/:id` (detail), `/clients/new` (create), `/contacts` (list), `/contacts/:id` (detail).

NOT implemented:
- **Summary statistics** on client detail (open leads count/value, won leads count/value, open requests count, total value, last activity, client since) -- the detail view shows raw lists but no aggregated stats panel.
- **Activity timeline** on client detail -- the sidebar provides OpenRegister audit log but no unified CRM timeline.
- **Validation rules**: Email format, telephone format, website URL, name max length validation -- these ARE implemented in `ClientForm.vue` with regex validation (EMAIL_REGEX, PHONE_REGEX, URL_REGEX) and maxlength. Inline errors and disabled save button are present.
- **Duplicate detection** (V1) -- not implemented.
- **Client import from CSV/vCard** (V1) -- not implemented (Nextcloud Contact import exists but not CSV/vCard file upload).
- **Client export to CSV/vCard** (V1) -- not implemented.
- **`@type` mapping** -- the spec requires `@type` set to `schema:Person` or `schema:Organization` based on client type. The schema defaults to `schema:Person` but dynamic switching based on `type` field is not implemented.
- **Audit trail for field changes** -- relies on OpenRegister's built-in audit log, not a CRM-level audit trail.
- **KVK integration in client form** (ADDED) -- KVK API exists for prospect discovery but is not available in the client create/edit form.
- **BSN handling** (ADDED, Enterprise) -- not implemented, no BSN field on schema.
- **Client deduplication/merge** (ADDED) -- not implemented.
- **Client hierarchy** (ADDED) -- no `parentOrganization` field on schema.
- **Client segmentation/tagging** (ADDED) -- no tags field on schema; `industry` field exists but no tag system.
- **Client health scoring** (ADDED, Enterprise) -- not implemented.
- **Client lifecycle analytics** (ADDED, Enterprise) -- not implemented.
- **GDPR data subject rights** (ADDED) -- not implemented.
- **Multi-tenancy client isolation** (ADDED, Enterprise) -- relies on OpenRegister's existing permission model; no team-based scoping.
- **CSV column mapping import** (ADDED) -- not implemented.
- **Export with format options** (ADDED) -- not implemented.
- **Client timeline** (ADDED) -- not implemented beyond OpenRegister audit sidebar.
- **Custom fields** (ADDED) -- not implemented; requires OpenRegister schema extension support.
- **Prospect conversion** (ADDED) -- partially implemented in `ProspectDiscoveryService.createLeadFromProspect()` but only returns data, does not create objects.

### Standards & References
- Schema.org `Person`, `Organization`, `ContactPoint` -- mapped in the register JSON schema
- vCard RFC 6350 -- field naming conventions followed (FN, EMAIL, TEL)
- VNG Klantinteracties `Partij`, `Betrokkene`, `DigitaalAdres` -- mentioned in spec but no explicit mapping layer implemented
- WCAG AA -- Nextcloud Vue components provide baseline accessibility
- OpenRegister object API for all CRUD operations
- KVK Handelsregister API (`api.kvk.nl/api/v1`) -- used by `KvkApiClient` for prospect discovery
- BSN elfproef (11-proof) validation -- Dutch Wet ABB (Algemene bepalingen burgerservicenummer)
- GDPR / AVG -- EU General Data Protection Regulation for data subject rights
- SBI codes (Standaard Bedrijfsindeling) -- Dutch industry classification standard used by KVK

### Specificity Assessment
- The spec is comprehensive and well-structured for MVP implementation. Scenarios are detailed and testable.
- **Implementable as-is** for all MVP requirements. V1 features need additional design work.
- **Gap**: The spec references `taxID` field but the actual schema uses `industry` instead -- the data model does not include `taxID` or `KVK number`. The ADDED KVK Integration requirement addresses this gap.
- **Gap**: The spec mentions `schema:worksFor` for contact-to-client linking but the actual schema uses a simple `client` UUID reference field.
- **Gap**: No `parentOrganization` field exists on the schema for hierarchy support. The ADDED Client Hierarchy requirement defines this.
- **Gap**: No `tags` array field exists on the schema. The ADDED Client Segmentation requirement defines this.
- **Addressed**: Client-side validation IS implemented in `ClientForm.vue` with regex validation, inline errors, and disabled save button -- corrected from original assessment.
- **Open question**: Should validation happen at the OpenRegister schema level (JSON Schema validation) or in the Pipelinq application layer? Currently both: JSON Schema defines formats, `ClientForm.vue` implements client-side regex validation.
- **Open question**: How should the contact person list (cross-client) be navigated? The current implementation has a dedicated `/contacts` route, but the spec does not discuss whether this is a top-level navigation item. Implementation shows it IS a top-level nav item via `MainMenu.vue`.
- **Open question**: Should BSN handling be a separate spec given its legal complexity? Currently included here as an Enterprise-tier requirement but may warrant its own spec with deeper compliance scenarios.

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

The system SHOULD sync client data with Nextcloud's built-in Contacts app via `OCP\Contacts\IManager`. This avoids duplicate contact entry and leverages Nextcloud's native CardDAV infrastructure.

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

The system SHOULD detect potential duplicate clients based on name and email matching.

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

The system SHOULD support importing clients from CSV and vCard files.

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

The system SHOULD support exporting clients to CSV and vCard formats.

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

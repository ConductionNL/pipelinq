# Client & Contact CRUD — Delta Spec

## Purpose
Enhance the existing client list/detail views and add contact person CRUD views with proper validation, filtering, and UX for MVP-tier client management.

**Main spec ref**: [client-management/spec.md](../../../../specs/client-management/spec.md)
**Feature tier**: MVP

---

## Requirements

### REQ-CM-001: Client Form with Validation

The system MUST provide a client creation/edit form with field validation that prevents invalid data from being submitted.

#### Scenario: Create client with all fields validated

- GIVEN the user opens the new client form
- WHEN they fill in name, type, and optional fields
- THEN the form MUST validate:
  - `name` is required and max 255 characters
  - `type` is required and must be "person" or "organization"
  - `email` must be valid email format if provided
  - `phone` accepts international formats if provided
  - `website` must be valid URL if provided
- AND validation errors MUST appear inline next to the relevant field
- AND the save button MUST be disabled while required fields are empty

#### Scenario: Edit existing client preserves data

- GIVEN an existing client "Gemeente Utrecht" is opened for editing
- WHEN the form loads
- THEN all existing field values MUST be pre-populated
- AND the user can modify any field and save

#### Scenario: Validation prevents bad save

- GIVEN a user enters "not-an-email" in the email field
- WHEN they click save
- THEN the form MUST NOT submit
- AND an inline error MUST appear: "Invalid email format"

---

### REQ-CM-002: Enhanced Client List

The client list MUST support type filtering, search by name/email, sorting, pagination controls, and an empty state.

#### Scenario: Filter by client type

- GIVEN 10 person clients and 5 organization clients
- WHEN the user selects "Organization" from the type filter
- THEN only the 5 organization clients MUST be shown
- AND the filter MUST be clearable

#### Scenario: Search by name or email

- GIVEN clients "Gemeente Utrecht" (info@utrecht.nl) and "Acme B.V." (info@acme.nl)
- WHEN the user types "acme" in the search box
- THEN "Acme B.V." MUST appear in results
- AND search MUST be debounced (300ms)

#### Scenario: Sort by name

- GIVEN clients in any order
- WHEN the user clicks the name column header
- THEN clients MUST sort alphabetically ascending
- AND clicking again MUST reverse to descending

#### Scenario: Empty state with CTA

- GIVEN no clients exist
- WHEN the user views the client list
- THEN an empty state message MUST display
- AND a "Create your first client" button MUST be visible

#### Scenario: Pagination controls

- GIVEN 45 clients with page size 20
- THEN page navigation MUST show current page, total pages, and total count
- AND prev/next buttons MUST be functional

---

### REQ-CM-003: Client Detail with Linked Entities

The client detail view MUST display the client info panel and linked entities (contacts, leads, requests) with navigation.

#### Scenario: Display linked contact persons

- GIVEN client "Acme B.V." with 2 contact persons
- WHEN the user views the client detail
- THEN the contacts section MUST list both contacts with name, role, and email
- AND an "Add contact" button MUST be visible
- AND clicking a contact MUST navigate to the contact detail

#### Scenario: Display linked leads

- GIVEN client "Acme B.V." with 2 leads
- WHEN the user views the client detail
- THEN the leads section MUST list both leads with title, stage, and value
- AND clicking a lead MUST navigate to the lead detail

#### Scenario: Display linked requests

- GIVEN client "Acme B.V." with 1 request
- WHEN the user views the client detail
- THEN the requests section MUST list the request with title and status

#### Scenario: Delete with warnings

- GIVEN client "Acme B.V." with linked contacts, leads, or requests
- WHEN the user clicks delete
- THEN a warning dialog MUST show the count of linked entities
- AND the user MUST confirm before deletion proceeds

---

### REQ-CM-004: Contact Person CRUD

The system MUST support creating, reading, updating, and deleting contact persons linked to clients.

#### Scenario: Create contact for a client

- GIVEN the user is on "Acme B.V." client detail
- WHEN they click "Add contact" and fill in name "Petra Jansen", role "Sales Manager", email "p.jansen@acme.nl"
- THEN a contact MUST be created with the client reference
- AND the contact MUST appear on the client detail

#### Scenario: Contact form validates required fields

- GIVEN the new contact form
- WHEN the user submits without a name
- THEN a validation error MUST appear: "Name is required"
- AND the contact MUST NOT be created

#### Scenario: Edit contact person

- GIVEN an existing contact "Petra Jansen"
- WHEN the user changes the role to "VP Sales"
- THEN the contact MUST be updated

#### Scenario: Delete contact person

- GIVEN a contact "Petra Jansen"
- WHEN the user deletes the contact
- THEN the contact MUST be removed from the client detail

---

### REQ-CM-005: Contact Person List

The system MUST provide a standalone list view for all contact persons with search and client navigation.

#### Scenario: List all contacts with client names

- GIVEN 15 contacts across multiple clients
- WHEN the user navigates to the contact list
- THEN all contacts MUST display with name, role, client name, and email
- AND pagination MUST be supported

#### Scenario: Search contacts

- GIVEN contacts "Jan Jansen" and "Petra Jansen" and "Mark de Groot"
- WHEN the user searches "Jansen"
- THEN only "Jan Jansen" and "Petra Jansen" MUST appear

#### Scenario: Navigate to client from contact list

- GIVEN contact "Petra Jansen" linked to "Acme B.V."
- WHEN the user clicks the client name
- THEN the app MUST navigate to the "Acme B.V." client detail

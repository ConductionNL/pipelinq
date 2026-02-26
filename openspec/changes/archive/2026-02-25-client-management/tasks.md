# Tasks: client-management

## 1. Client Form

- [x] 1.1 Create `src/views/clients/ClientForm.vue` with field validation
  - **spec_ref**: `specs/client-crud/spec.md#REQ-CM-001`
  - **files**: `pipelinq/src/views/clients/ClientForm.vue`
  - **acceptance_criteria**:
    - GIVEN the form is opened for create or edit
    - THEN it MUST validate name (required, max 255), type (required, enum), email (format), phone (format), website (URL)
    - AND validation errors MUST appear inline next to the field
    - AND save button MUST be disabled while required fields are empty
    - AND in edit mode, existing values MUST be pre-populated

## 2. Enhanced Client List

- [x] 2.1 Enhance `ClientList.vue` with type filter, sort, empty state
  - **spec_ref**: `specs/client-crud/spec.md#REQ-CM-002`
  - **files**: `pipelinq/src/views/clients/ClientList.vue`
  - **acceptance_criteria**:
    - GIVEN the client list view
    - THEN a type filter dropdown MUST allow filtering by "person" or "organization"
    - AND clicking the name column MUST toggle sort direction
    - AND search MUST be debounced (300ms)
    - AND when no clients exist, an empty state with CTA MUST display
    - AND pagination MUST show current page, total pages, and total count

## 3. Enhanced Client Detail

- [x] 3.1 Enhance `ClientDetail.vue` with linked entities and delete warning
  - **spec_ref**: `specs/client-crud/spec.md#REQ-CM-003`
  - **files**: `pipelinq/src/views/clients/ClientDetail.vue`
  - **acceptance_criteria**:
    - GIVEN a client with linked contacts, leads, and requests
    - THEN each section MUST list the linked entities with key fields
    - AND clicking a linked entity MUST navigate to its detail view
    - AND an "Add contact" button MUST be visible in the contacts section
    - AND delete MUST show a warning dialog with linked entity counts before proceeding

- [x] 3.2 Integrate ClientForm into create/edit flow
  - **spec_ref**: `specs/client-crud/spec.md#REQ-CM-001`
  - **files**: `pipelinq/src/views/clients/ClientDetail.vue`
  - **acceptance_criteria**:
    - GIVEN the user clicks "Edit" or navigates to create
    - THEN the ClientForm component MUST be used
    - AND on save, it MUST call objectStore.saveObject and navigate back to detail

## 4. Contact Person Views

- [x] 4.1 Create `src/views/contacts/ContactList.vue`
  - **spec_ref**: `specs/client-crud/spec.md#REQ-CM-005`
  - **files**: `pipelinq/src/views/contacts/ContactList.vue`
  - **acceptance_criteria**:
    - GIVEN the contact list view
    - THEN all contacts MUST display with name, role, client name, and email
    - AND search MUST filter contacts by name
    - AND clicking the client name MUST navigate to the client detail
    - AND pagination MUST be supported

- [x] 4.2 Create `src/views/contacts/ContactDetail.vue`
  - **spec_ref**: `specs/client-crud/spec.md#REQ-CM-004`
  - **files**: `pipelinq/src/views/contacts/ContactDetail.vue`
  - **acceptance_criteria**:
    - GIVEN a contact person
    - THEN all contact fields MUST be displayed
    - AND the linked client name MUST be shown and clickable
    - AND edit and delete buttons MUST be available

- [x] 4.3 Create `src/views/contacts/ContactForm.vue`
  - **spec_ref**: `specs/client-crud/spec.md#REQ-CM-004`
  - **files**: `pipelinq/src/views/contacts/ContactForm.vue`
  - **acceptance_criteria**:
    - GIVEN the contact form
    - THEN name MUST be required with inline validation
    - AND client selection MUST be required (dropdown or search)
    - AND optional fields (email, phone, role) MUST be available
    - AND in edit mode, existing values MUST be pre-populated

## 5. Navigation & Routing

- [x] 5.1 Add contact routes to App.vue and MainMenu
  - **spec_ref**: `specs/client-crud/spec.md#REQ-CM-005`
  - **files**: `pipelinq/src/App.vue`, `pipelinq/src/navigation/MainMenu.vue`
  - **acceptance_criteria**:
    - GIVEN the hash routes `#/contacts` and `#/contacts/{id}`
    - THEN App.vue MUST render ContactList and ContactDetail respectively
    - AND MainMenu MUST include a "Contacts" navigation item
    - AND navigating between client ↔ contact views MUST work correctly

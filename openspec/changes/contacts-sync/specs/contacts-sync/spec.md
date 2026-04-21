<!-- ⚠️ EXTENSION NOTICE (auto-inserted by fix_extension_artifacts.py)
     Parent capability: client-management (Client Management)
     This spec extends the existing `client-management` capability. Do NOT define new entities or build new CRUD — reuse what `client-management` already provides. Your job is to add configuration, seed data, or workflow templates on top of that capability.
-->

# Contacts Sync — Link Contacts to Organizations (Specification)

## Purpose

Define requirements for linking contact persons to client organizations in Pipelinq via dedicated UI actions, without requiring full record edit mode. The `contact.client` field already exists in the schema; this spec governs the UI behavior for managing that relationship from both sides.

## Entities Affected

- **contact** — `contact.client` (UUID reference to parent client, optional)
- **client** — read-only in this context (organization side of the relationship)

---

## Requirements

### REQ-CSO-001: Link Existing Contact to Organization

**Priority**: MVP
**Entities**: contact, client

From a client organization's detail view, users MUST be able to link any existing unlinked contact person to that organization.

#### Scenario: Open link dialog from organization detail

```
GIVEN the user is viewing a client organization's detail page
WHEN the user clicks "Link contact" in the contacts panel header
THEN a search dialog MUST open
AND the dialog MUST include a search input for finding contacts by name
AND results MUST exclude contacts already linked to this client
```

#### Scenario: Link contact via dialog

```
GIVEN the link dialog is open and the user selects a contact from search results
WHEN the user confirms the selection
THEN contact.client MUST be set to the current client UUID via objectStore.saveObject()
AND the contacts panel MUST refresh and include the newly linked contact
AND the dialog MUST close
```

#### Scenario: Cancel link dialog

```
GIVEN the link dialog is open
WHEN the user cancels
THEN no save operation MUST occur
AND the contacts panel MUST remain unchanged
```

---

### REQ-CSO-002: Create New Contact Pre-Linked to Organization

**Priority**: MVP
**Entities**: contact, client

From a client organization's detail view, creating a new contact MUST pre-populate the `client` field so the user does not need to re-select the organization.

#### Scenario: New contact form pre-populates organization

```
GIVEN the user is viewing a client organization's detail page
WHEN the user clicks "Add contact"
THEN the contact creation view MUST open
AND the client field MUST be pre-populated with the current client's UUID
AND the user MUST NOT be required to manually select the organization
```

#### Scenario: Pre-populated field is visible in form

```
GIVEN the contact form opens with a pre-selected client
WHEN the form renders
THEN the organization name MUST be displayed in the client field
AND the field MUST remain editable (user can change or clear it)
```

---

### REQ-CSO-003: Organization Card on Contact Detail View

**Priority**: MVP
**Entities**: contact, client

Contact detail views MUST display the linked organization as a dedicated card section, separate from the contact information fields, with inline actions for linking and unlinking.

#### Scenario: Linked contact shows organization name and actions

```
GIVEN the user is viewing a contact that has a client UUID in contact.client
WHEN the detail view loads
THEN a dedicated "Organization" CnDetailCard MUST be rendered
AND the card MUST display the linked organization name as a clickable router-link to ClientDetail
AND the card header MUST include an "Unlink" action in the #header-actions slot
```

#### Scenario: Unlinked contact shows empty state and link action

```
GIVEN the user is viewing a contact where contact.client is null or empty
WHEN the detail view loads
THEN the "Organization" CnDetailCard MUST be rendered
AND the card MUST display an empty state message
AND the card header MUST include a "Link to organization" action in the #header-actions slot
```

#### Scenario: Clicking organization name navigates to client detail

```
GIVEN a linked organization name is displayed in the Organization card
WHEN the user clicks the organization name
THEN the router MUST navigate to the ClientDetail view for that client UUID
```

---

### REQ-CSO-004: Unlink Contact from Organization

**Priority**: MVP
**Entities**: contact

Users MUST be able to remove a contact's organization link from the contact detail view without entering the full edit form.

#### Scenario: Confirm before unlinking

```
GIVEN the user clicks "Unlink" in the Organization card header
WHEN the click is registered
THEN a confirmation dialog MUST appear (NcDialog — NEVER window.confirm())
AND the dialog MUST describe what will happen (the contact will no longer be linked to the organization)
```

#### Scenario: Confirm unlink clears client reference

```
GIVEN the confirmation dialog is open
WHEN the user confirms
THEN contact.client MUST be set to null via objectStore.saveObject()
AND the Organization card MUST update to show empty state
AND all other contact fields (name, email, phone, role, contactsUid) MUST remain unchanged
```

#### Scenario: Cancel unlink preserves link

```
GIVEN the confirmation dialog is open
WHEN the user cancels
THEN no save operation MUST occur
AND the Organization card MUST continue showing the linked organization
```

---

### REQ-CSO-005: Link Contact to Organization from Contact Detail

**Priority**: MVP
**Entities**: contact, client

From the contact detail view, users MUST be able to link an unlinked contact to a client organization without entering full edit mode.

#### Scenario: Open link dialog from contact detail

```
GIVEN the user is viewing a contact without a linked organization
WHEN the user clicks "Link to organization" in the Organization card header
THEN a search dialog MUST open
AND the dialog MUST include a search input for finding clients by name
AND both organization-type and person-type clients MUST be searchable
```

#### Scenario: Link contact to organization via dialog

```
GIVEN the link dialog is open and the user selects a client
WHEN the user confirms
THEN contact.client MUST be set to the selected client UUID via objectStore.saveObject()
AND the Organization card MUST update to show the linked organization name
AND the dialog MUST close
```

---

### REQ-CSO-006: Header Actions Slot Compliance (ADR-018)

**Priority**: Technical
**Entities**: client

The contacts panel action buttons in ClientDetail.vue MUST use the `#header-actions` slot per ADR-018.

#### Scenario: Contacts panel uses header-actions slot

```
GIVEN the contacts panel renders in ClientDetail.vue
WHEN action buttons (Add contact, Link contact) are displayed
THEN they MUST be placed inside <template #header-actions>
AND MUST appear inline with the panel title in the card header row
AND MUST NOT use the deprecated <template #actions> slot
```

---

## Non-Requirements (Explicit Exclusions)

- Real-time sync with Nextcloud Contacts (IManager) — handled by archived contacts-sync change
- Many-to-many contact-organization linking — `contact.client` is a single reference by schema design
- Bulk link/unlink operations — V1
- AI-assisted relationship inference — Enterprise
- Contact deduplication across organizations — V1

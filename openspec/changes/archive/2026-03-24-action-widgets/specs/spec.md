# Action Widgets — Requirements Specification

## Data Model

### Entities Involved

| Widget | Primary Entity | Related Entities |
|--------|---------------|------------------|
| StartRequestWidget | `request` (title, client, category, priority, channel, status) | `client` (autocomplete lookup) |
| CreateLeadWidget | `lead` (title, client, pipeline, value, source, stage, status) | `client` (autocomplete lookup), `pipeline` (dropdown) |
| FindClientWidget | `client` (name, type, email, phone, city) | `request` (create action), `lead` (create action) |

### API Endpoints (OpenRegister)

| Operation | Method | URL |
|-----------|--------|-----|
| List clients | GET | `/apps/openregister/api/objects/{register}/{clientSchema}` |
| Create client | POST | `/apps/openregister/api/objects/{register}/{clientSchema}` |
| List pipelines | GET | `/apps/openregister/api/objects/{register}/{pipelineSchema}` |
| Create request | POST | `/apps/openregister/api/objects/{register}/{requestSchema}` |
| Create lead | POST | `/apps/openregister/api/objects/{register}/{leadSchema}` |
| List requests | GET | `/apps/openregister/api/objects/{register}/{requestSchema}` |

Register and schema IDs are resolved at runtime via `initializeStores()` -> `objectStore.objectTypeRegistry`.

---

## Requirements

### Shared Infrastructure

| ID | Requirement | Priority |
|----|------------|----------|
| REQ-AW-001 | A shared `ClientAutocomplete` component SHALL search clients by name as the user types and emit the selected client object | MUST |
| REQ-AW-002 | All visible text SHALL use `t('pipelinq', '...')` for i18n support (Dutch + English) | MUST |
| REQ-AW-003 | All styling SHALL use CSS variables only (NL Design System compatible, no hardcoded colors) | MUST |
| REQ-AW-004 | All form inputs SHALL use `@nextcloud/vue` components (NcTextField, NcButton, NcSelect, etc.) | MUST |
| REQ-AW-005 | Each widget SHALL have a PHP class (IWidget), a Vue component, and a webpack entry point | MUST |

### Start Request Widget

| ID | Requirement | Priority |
|----|------------|----------|
| REQ-AW-010 | The widget SHALL display an inline form with: title (required), client autocomplete, category, priority dropdown, channel dropdown | MUST |
| REQ-AW-011 | Title field SHALL be required; form submission SHALL be blocked if empty | MUST |
| REQ-AW-012 | Channel dropdown SHALL show defaults: phone, email, walk-in, web | MUST |
| REQ-AW-013 | Priority dropdown SHALL show: low, normal, high, urgent (default: normal) | MUST |
| REQ-AW-014 | On submit, a new request SHALL be created via POST to OpenRegister with status "new" | MUST |
| REQ-AW-015 | On success, the widget SHALL show a confirmation message with a link to the created request | MUST |
| REQ-AW-016 | The widget SHALL display a list of the 3 most recent requests below the form | SHOULD |
| REQ-AW-017 | The PHP widget SHALL have order 14 and ID `pipelinq_start_request_widget` | MUST |

### Create Lead Widget

| ID | Requirement | Priority |
|----|------------|----------|
| REQ-AW-020 | The widget SHALL display an inline form with: title (required), client autocomplete, pipeline dropdown, value, source dropdown | MUST |
| REQ-AW-021 | Title field SHALL be required; form submission SHALL be blocked if empty | MUST |
| REQ-AW-022 | Pipeline dropdown SHALL list available pipelines and pre-select the first one | MUST |
| REQ-AW-023 | Source dropdown SHALL show options: website, referral, cold-call, advertisement, event, other | MUST |
| REQ-AW-024 | On submit, a lead SHALL be created with status "open" and the first stage of the selected pipeline | MUST |
| REQ-AW-025 | On success, the widget SHALL show a confirmation message with a link to the created lead | MUST |
| REQ-AW-026 | Quick-add mode: entering a title and pressing Enter SHALL create the lead with minimal fields and reset the form | SHOULD |
| REQ-AW-027 | The PHP widget SHALL have order 15 and ID `pipelinq_create_lead_widget` | MUST |

### Find Client Widget

| ID | Requirement | Priority |
|----|------------|----------|
| REQ-AW-030 | The widget SHALL display a search input with live filtering of clients | MUST |
| REQ-AW-031 | Each client result SHALL show name, contact info (email/phone), and type icon (person/organization) | MUST |
| REQ-AW-032 | Each client result SHALL have action buttons: view, create request, create lead, copy email | MUST |
| REQ-AW-033 | A "New client" button SHALL open an inline mini-form (name, type, email) for quick creation | MUST |
| REQ-AW-034 | Clicking a client name SHALL navigate to `/index.php/apps/pipelinq/clients/{id}` | MUST |
| REQ-AW-035 | The widget SHALL replace the existing `ClientSearchWidget` registration in Application.php | MUST |
| REQ-AW-036 | The PHP widget SHALL have order 13 and ID `pipelinq_find_client_widget` | MUST |

---

## Acceptance Scenarios

### Start Request Widget

**SC-AW-010: Basic request creation**
- GIVEN a user with the Start Request widget on their dashboard
- WHEN they enter a title "Fix printer" and click submit
- THEN a new request is created in OpenRegister with title "Fix printer", status "new", priority "normal"
- AND the widget shows "Request created" with a link to the new request

**SC-AW-011: Request with client autocomplete**
- GIVEN the Start Request widget
- WHEN the user types "Acme" in the client field
- THEN matching clients appear as autocomplete suggestions
- AND selecting "Acme Corp" associates the client UUID with the request

**SC-AW-012: Default channel options**
- GIVEN a fresh installation
- WHEN the Start Request widget loads
- THEN the channel dropdown shows: phone, email, walk-in, web

**SC-AW-013: Title required validation**
- GIVEN the Start Request widget with an empty title field
- WHEN the user clicks submit
- THEN the form is not submitted and the title field shows a validation indicator

### Create Lead Widget

**SC-AW-020: Basic lead creation**
- GIVEN a user with the Create Lead widget
- WHEN they enter title "New deal" and click submit
- THEN a lead is created with title "New deal", status "open", in the first stage of the default pipeline

**SC-AW-021: Pipeline selection**
- GIVEN the Create Lead widget with multiple pipelines available
- WHEN the user selects "Enterprise Pipeline" and submits
- THEN the lead is created in the first stage of "Enterprise Pipeline"

**SC-AW-022: Quick-add mode**
- GIVEN the Create Lead widget
- WHEN the user enters "Quick opportunity" in the title field and presses Enter
- THEN the lead is created with minimal fields and the form resets for the next entry

**SC-AW-023: Value and source**
- GIVEN the Create Lead widget
- WHEN the user enters title "Big deal", value 50000, source "referral" and submits
- THEN the lead is created with value 50000 and source "referral"

### Find Client Widget

**SC-AW-030: Client search**
- GIVEN a user with the Find Client widget
- WHEN they type "Jan" in the search field
- THEN clients matching "Jan" are shown with name, contact info, and type icon

**SC-AW-031: Action buttons**
- GIVEN search results showing "Acme Corp"
- WHEN the user clicks "Create request" on Acme Corp
- THEN a request creation dialog/form opens pre-filled with Acme Corp as client

**SC-AW-032: New client creation**
- GIVEN the Find Client widget
- WHEN the user clicks "New client" and fills in name "New Corp", type "organization", email "info@newcorp.nl"
- THEN a new client is created in OpenRegister and appears in the results

**SC-AW-033: Client navigation**
- GIVEN search results in the Find Client widget
- WHEN the user clicks on "Acme Corp" name
- THEN the browser navigates to `/index.php/apps/pipelinq/clients/{acme-uuid}`

**SC-AW-034: Copy email**
- GIVEN search results showing a client with email "info@acme.nl"
- WHEN the user clicks the copy email button
- THEN "info@acme.nl" is copied to the clipboard

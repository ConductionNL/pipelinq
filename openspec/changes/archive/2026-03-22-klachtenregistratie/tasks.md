# Tasks: klachtenregistratie

## 1. Complaint Schema & Store Registration (MVP)

- [x] 1.1 Add `complaint` schema to `lib/Settings/pipelinq_register.json`
  - **spec_ref**: `specs/klachtenregistratie/spec.md#REQ-KL-001`
  - **files**: `pipelinq/lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - GIVEN the register config is loaded
    - THEN the `complaint` schema MUST include: title, description, category (enum, facetable), priority (enum, facetable), status (enum, facetable), channel (enum, facetable), client (uuid), contact (uuid), assignedTo (string), slaDeadline (date-time), resolvedAt (date-time), resolution (string)
    - AND `title` and `category` MUST be required
    - AND status MUST default to "new"
    - AND priority MUST default to "normal"

- [x] 1.2 Register `complaint` object type in store initialization
  - **spec_ref**: `specs/klachtenregistratie/spec.md#REQ-KL-001`
  - **files**: `pipelinq/src/store/store.js`, `pipelinq/lib/Service/SettingsService.php`
  - **acceptance_criteria**:
    - GIVEN the app settings include a `complaint_schema` config key
    - WHEN `initializeStores()` runs
    - THEN `objectStore.registerObjectType('complaint', config.complaint_schema, config.register)` MUST be called
    - AND CRUD operations via `objectStore.saveObject('complaint', data)` MUST work

## 2. Complaint Status Service (MVP)

- [x] 2.1 Create `src/services/complaintStatus.js`
  - **spec_ref**: `specs/klachtenregistratie/spec.md#REQ-KL-004`
  - **files**: `pipelinq/src/services/complaintStatus.js`
  - **acceptance_criteria**:
    - GIVEN the status service is imported
    - THEN `getAllowedTransitions('new')` MUST return `['in_progress']`
    - AND `getAllowedTransitions('in_progress')` MUST return `['resolved', 'rejected']`
    - AND `getAllowedTransitions('resolved')` MUST return `[]`
    - AND `getAllowedTransitions('rejected')` MUST return `[]`
    - AND status labels, colors, priority labels, and priority colors MUST be defined
    - AND category labels MUST be defined (service, product, communication, billing, other)

## 3. Complaint List View (MVP)

- [x] 3.1 Create `src/views/complaints/ComplaintList.vue`
  - **spec_ref**: `specs/klachtenregistratie/spec.md#REQ-KL-003`
  - **files**: `pipelinq/src/views/complaints/ComplaintList.vue`
  - **acceptance_criteria**:
    - GIVEN the complaint list view using CnIndexPage
    - THEN complaints MUST be listed with title, status badge, category, priority, SLA deadline
    - AND status filter, category filter, and search MUST work
    - AND overdue complaints MUST show a red visual indicator
    - AND empty state with "Register first complaint" CTA MUST display when no complaints exist
    - AND pagination MUST show current page, total pages, total count
    - AND clicking a row MUST navigate to complaint detail

## 4. Complaint Form (MVP)

- [x] 4.1 Create `src/views/complaints/ComplaintForm.vue`
  - **spec_ref**: `specs/klachtenregistratie/spec.md#REQ-KL-002`
  - **files**: `pipelinq/src/views/complaints/ComplaintForm.vue`
  - **acceptance_criteria**:
    - GIVEN the complaint form in create or edit mode
    - THEN title (required, max 255), category (required, enum select), description (required, textarea) MUST be validated
    - AND priority MUST default to "normal" with enum select
    - AND channel MUST be optional enum select
    - AND client selector MUST search existing clients and set UUID reference
    - AND contact selector MUST filter contacts by selected client
    - AND validation errors MUST appear inline
    - AND save button MUST be disabled while required fields are empty
    - AND in edit mode, existing values MUST be pre-populated

- [x] 4.2 Create `src/views/complaints/ComplaintCreateDialog.vue`
  - **spec_ref**: `specs/klachtenregistratie/spec.md#REQ-KL-002`
  - **files**: `pipelinq/src/views/complaints/ComplaintCreateDialog.vue`
  - **acceptance_criteria**:
    - GIVEN the create dialog is opened (from client detail or nav)
    - THEN ComplaintForm MUST be rendered in a modal dialog
    - AND on save, the dialog MUST close and navigate to the new complaint detail

## 5. Complaint Detail View (MVP)

- [x] 5.1 Create `src/views/complaints/ComplaintDetail.vue`
  - **spec_ref**: `specs/klachtenregistratie/spec.md#REQ-KL-004, #REQ-KL-005`
  - **files**: `pipelinq/src/views/complaints/ComplaintDetail.vue`
  - **acceptance_criteria**:
    - GIVEN a complaint detail view
    - THEN all fields MUST display: title, description, category, priority, status, channel, SLA deadline, assigned agent
    - AND linked client name MUST be clickable (navigates to client detail)
    - AND linked contact name MUST be clickable if set
    - AND SLA deadline MUST show with color indicator: green (met/on-track), orange (approaching < 4h), red (overdue)
    - AND status transition buttons MUST show valid next states
    - AND "Afhandelen"/"Afwijzen" transitions MUST require resolution text via dialog
    - AND `resolvedAt` MUST be set when status moves to resolved/rejected
    - AND audit trail / status history MUST render as a chronological timeline

## 6. Navigation & Routing (MVP)

- [x] 6.1 Add complaint routes and navigation
  - **spec_ref**: `specs/klachtenregistratie/spec.md#REQ-KL-003, #REQ-KL-004`
  - **files**: `pipelinq/src/router/index.js`, `pipelinq/src/navigation/MainMenu.vue`
  - **acceptance_criteria**:
    - GIVEN routes `/complaints` and `/complaints/:id`
    - THEN ComplaintList and ComplaintDetail MUST render respectively
    - AND MainMenu MUST include a "Complaints" item with AlertCircleOutline icon after "Requests"

## 7. Dashboard Widget (V1)

- [x] 7.1 Create `src/views/widgets/ComplaintsOverviewWidget.vue`
  - **spec_ref**: `specs/klachtenregistratie/spec.md#REQ-KL-006`
  - **files**: `pipelinq/src/views/widgets/ComplaintsOverviewWidget.vue`, `pipelinq/src/views/Dashboard.vue`
  - **acceptance_criteria**:
    - GIVEN the dashboard is loaded
    - THEN the complaints widget MUST show total open complaints count
    - AND overdue complaints count with warning styling
    - AND breakdown by status (new / in_progress)
    - AND clicking the widget MUST navigate to the complaint list

## 8. Client Detail Integration (V1)

- [x] 8.1 Add complaints section to `ClientDetail.vue`
  - **spec_ref**: `specs/klachtenregistratie/spec.md#REQ-KL-007`
  - **files**: `pipelinq/src/views/clients/ClientDetail.vue`
  - **acceptance_criteria**:
    - GIVEN a client with linked complaints
    - THEN a "Complaints" section MUST show all complaints with title, status, date
    - AND clicking a complaint MUST navigate to complaint detail
    - AND an "Add complaint" button MUST be visible (opens create dialog with client pre-filled)

## 9. SLA Configuration (V1)

- [x] 9.1 Add SLA settings to admin configuration
  - **spec_ref**: `specs/klachtenregistratie/spec.md#REQ-KL-008`
  - **files**: `pipelinq/lib/Service/SettingsService.php`, `pipelinq/src/views/settings/UserSettings.vue`
  - **acceptance_criteria**:
    - GIVEN the admin settings page
    - THEN SLA hours per complaint category MUST be configurable
    - AND when a complaint is created with a category that has SLA configured
    - THEN `slaDeadline` MUST be set to creation time + configured hours
    - AND categories without SLA config MUST NOT have a deadline set

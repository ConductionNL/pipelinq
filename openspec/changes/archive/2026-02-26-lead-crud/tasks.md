# Tasks: lead-crud

## 1. Lead Form Component

- [x] 1.1 Create LeadForm.vue with validation and pipeline assignment
  - **spec_ref**: `specs/lead-crud/spec.md#REQ-LC-001, #REQ-LC-002, #REQ-LC-006`
  - **files**: `pipelinq/src/views/leads/LeadForm.vue`
  - **acceptance_criteria**:
    - GIVEN the lead form
    - THEN title (required), description, value, probability, source, priority, expectedCloseDate, client, pipeline, and stage fields MUST be present
    - AND validation MUST enforce: title required, value >= 0, probability 0-100
    - AND pipeline dropdown MUST filter to lead-compatible pipelines
    - AND stage dropdown MUST show stages from selected pipeline only
    - AND changing pipeline MUST reset stage selection
    - AND on create, default pipeline MUST be auto-populated with first non-closed stage

## 2. Lead List View

- [x] 2.1 Create LeadList.vue with search, filters, sort, and pagination
  - **spec_ref**: `specs/lead-crud/spec.md#REQ-LC-003`
  - **files**: `pipelinq/src/views/leads/LeadList.vue`
  - **acceptance_criteria**:
    - GIVEN the leads section
    - THEN a table MUST display columns: Title, Value, Stage, Priority, Source, Expected Close
    - AND search MUST filter by title with 300ms debounce
    - AND stage and source filter dropdowns MUST be available
    - AND column headers MUST support click-to-sort (value, priority, expectedCloseDate)
    - AND pagination MUST show 20 items per page
    - AND empty state MUST display with "Create first lead" button
    - AND rows MUST be clickable to navigate to lead detail

## 3. Lead Detail View

- [x] 3.1 Create LeadDetail.vue with info display, pipeline progress, and actions
  - **spec_ref**: `specs/lead-crud/spec.md#REQ-LC-004`
  - **files**: `pipelinq/src/views/leads/LeadDetail.vue`
  - **acceptance_criteria**:
    - GIVEN a lead detail view
    - THEN all lead properties MUST be displayed in a structured info grid
    - AND value MUST be formatted as "EUR X,XXX"
    - AND probability MUST display as "X%"
    - AND a pipeline progress indicator MUST show all stages with completed/current/future state
    - AND client name MUST be a clickable link to client detail
    - AND contact name and role MUST be displayed
    - AND Edit and Delete action buttons MUST be present
    - AND Delete MUST show a confirmation dialog

## 4. App Integration

- [x] 4.1 Add lead routing and navigation to App.vue and MainMenu.vue
  - **spec_ref**: `specs/lead-crud/spec.md#REQ-LC-005`
  - **files**: `pipelinq/src/App.vue`, `pipelinq/src/navigation/MainMenu.vue`
  - **acceptance_criteria**:
    - GIVEN the main navigation
    - THEN a "Leads" menu item MUST appear between Contacts and Requests
    - AND `#/leads` MUST render LeadList
    - AND `#/leads/{id}` MUST render LeadDetail with the lead ID
    - AND the Leads menu item MUST highlight when on leads or lead-detail routes

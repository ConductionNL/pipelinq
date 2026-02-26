# Design: lead-crud

## Architecture Decisions

### 1. Follow existing client view pattern
Lead views will mirror the ClientList/ClientDetail pattern for consistency:
- List: NcTextField search + NcSelect filters + sortable table + pagination
- Detail: Info grid + action buttons + related entity sections
- Form: Inline editing within detail view (toggle `editing` flag)

### 2. No backend changes needed
The `lead` schema is already defined in `pipelinq_register.json` and the `lead` object type is already registered in `store.js`. All CRUD operations go through the generic `objectStore` (OpenRegister API). No new PHP controllers or services required.

### 3. Pipeline/stage selection via cascading dropdowns
The lead form will have a pipeline dropdown (filtered to pipelines with `entityType: 'lead'` or `'both'`) and a dependent stage dropdown (populated from the selected pipeline's `stages` array). Changing the pipeline resets the stage selection.

### 4. Auto-assign default pipeline on create
When creating a new lead, if no pipeline is selected, the form will auto-populate the default pipeline (first pipeline where `isDefault: true` and `entityType` includes `'lead'`) and its first non-closed stage.

### 5. Hash-based routing extension
Add `leads` and `lead-detail` routes to `App.vue`'s `_handleHashRoute()` and `currentView` computed, following the same pattern as clients/requests/contacts.

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `src/views/leads/LeadList.vue` | CREATE | Lead list view with search, filters, sort, pagination |
| `src/views/leads/LeadDetail.vue` | CREATE | Lead detail view with info grid, pipeline progress, client links, edit/delete |
| `src/views/leads/LeadForm.vue` | CREATE | Lead create/edit form with validation and pipeline/stage assignment |
| `src/App.vue` | MODIFY | Add LeadList/LeadDetail imports, routes, and props |
| `src/navigation/MainMenu.vue` | MODIFY | Add "Leads" menu item with icon |

## Component Design

### LeadList.vue
- **Search**: `NcTextField` with 300ms debounce, queries `_search` param
- **Filters**: Stage dropdown (from pipeline stages), source dropdown (enum values)
- **Sort**: Clickable column headers (value, priority, expectedCloseDate), cycles null → asc → desc → null
- **Table columns**: Title, Value (EUR formatted), Stage (name), Priority (badge for non-normal), Source, Expected Close
- **Pagination**: 20 per page, uses `objectStore.fetchCollection('lead', params)`
- **Empty state**: NcEmptyContent with "Create first lead" action

### LeadDetail.vue
- **View mode**: Info grid (2-column) with Title, Description, Value, Probability, Source, Priority, Category, Expected Close Date
- **Pipeline progress**: Vertical stage list showing completed/current/future stages with filled/highlighted/empty indicators
- **Client link**: Clickable client name navigating to client-detail, "No client linked" when empty
- **Contact display**: Contact name + role (read-only)
- **Actions**: Edit (opens form), Delete (NcDialog confirmation)
- **On mount**: Fetch lead, then fetch related client/contact if referenced

### LeadForm.vue
- **Fields**: title (required), description, value (number), probability (0-100), source (NcSelect enum), priority (NcSelect), expectedCloseDate (date input), client (NcSelect from client collection), pipeline (NcSelect), stage (NcSelect dependent on pipeline)
- **Validation**: title required, value >= 0, probability 0-100
- **Pipeline logic**: Auto-populate default pipeline on create, filter stages by selected pipeline, reset stage on pipeline change
- **Emits**: `save(data)`, `cancel`

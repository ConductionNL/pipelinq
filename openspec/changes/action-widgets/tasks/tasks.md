# Action Widgets — Tasks

## Shared Infrastructure

- [ ] **T-AW-001**: Create `ClientAutocomplete.vue` shared component
  - Files: `src/components/widgets/ClientAutocomplete.vue`
  - Criteria: Types ahead, searches clients via OpenRegister API, emits selected client object

- [ ] **T-AW-002**: Update webpack.config.js with new entry points
  - Files: `webpack.config.js`
  - Criteria: 3 new entries (startRequestWidget, createLeadWidget, findClientWidget), clientSearchWidget entry removed

- [ ] **T-AW-003**: Update Application.php widget registrations
  - Files: `lib/AppInfo/Application.php`
  - Criteria: ClientSearchWidget removed, 3 new widgets registered (StartRequest, CreateLead, FindClient)

## Start Request Widget

- [ ] **T-AW-010**: Create StartRequestWidget.php
  - Files: `lib/Dashboard/StartRequestWidget.php`
  - Criteria: IWidget, ID `pipelinq_start_request_widget`, order 14, loads startRequestWidget script

- [ ] **T-AW-011**: Create StartRequestWidget.vue
  - Files: `src/views/widgets/StartRequestWidget.vue`
  - Criteria: Form with title (required), client autocomplete, category, priority, channel; creates request via API; shows success + link

- [ ] **T-AW-012**: Create startRequestWidget.js entry point
  - Files: `src/startRequestWidget.js`
  - Criteria: Registers `pipelinq_start_request_widget` with OCA.Dashboard, follows existing pattern

## Create Lead Widget

- [ ] **T-AW-020**: Create CreateLeadWidget.php
  - Files: `lib/Dashboard/CreateLeadWidget.php`
  - Criteria: IWidget, ID `pipelinq_create_lead_widget`, order 15, loads createLeadWidget script

- [ ] **T-AW-021**: Create CreateLeadWidget.vue
  - Files: `src/views/widgets/CreateLeadWidget.vue`
  - Criteria: Form with title (required), client autocomplete, pipeline dropdown, value, source; creates lead with first stage; quick-add mode with Enter key

- [ ] **T-AW-022**: Create createLeadWidget.js entry point
  - Files: `src/createLeadWidget.js`
  - Criteria: Registers `pipelinq_create_lead_widget` with OCA.Dashboard, follows existing pattern

## Find Client Widget

- [ ] **T-AW-030**: Create FindClientWidget.php
  - Files: `lib/Dashboard/FindClientWidget.php`
  - Criteria: IWidget, ID `pipelinq_find_client_widget`, order 13, loads findClientWidget script

- [ ] **T-AW-031**: Create FindClientWidget.vue
  - Files: `src/views/widgets/FindClientWidget.vue`
  - Criteria: Search with live filtering, action buttons per client (view, create request, create lead, copy email), new client mini-form, type icons

- [ ] **T-AW-032**: Create findClientWidget.js entry point
  - Files: `src/findClientWidget.js`
  - Criteria: Registers `pipelinq_find_client_widget` with OCA.Dashboard, follows existing pattern

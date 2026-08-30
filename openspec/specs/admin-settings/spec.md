---
status: done
retrofit_extensions:
  - REQ-AS-110
---

# Admin Settings Specification

## Purpose

The admin settings page provides a Nextcloud admin panel for configuring Pipelinq. Administrators can manage pipelines and their stages, set a default pipeline, configure lead source and request channel values, manage product categories, and configure prospect discovery (ICP) settings. Only Nextcloud admin users can access the admin settings page; regular users access per-user notification preferences via an in-app settings dialog. The design follows the wireframe in DESIGN-REFERENCES.md section 3.7.

**Feature tier**: MVP (admin page, version info, register mapping, pipeline CRUD, stage CRUD, default pipeline, re-import), V1 (lead source config, request channel config, product categories, prospect discovery ICP)

---
## Requirements
### Requirement: Nextcloud Admin Panel Registration [MVP]

The system MUST register a settings page in the Nextcloud admin panel under "Administration". Only users with Nextcloud admin privileges MUST be able to access this page. The implementation uses `OCP\Settings\ISettings` (`AdminSettings.php`) and `OCP\Settings\IIconSection` (`SettingsSection.php`) to register the "Pipelinq" section with priority 10.

#### Scenario: Admin user accesses settings
- GIVEN a user with Nextcloud admin privileges
- WHEN they navigate to Administration settings
- THEN a "Pipelinq" section MUST appear in the admin settings navigation
- AND clicking it MUST display the Pipelinq settings page

#### Scenario: Non-admin user cannot access settings
@e2e exclude access control; covered by PHPUnit
- GIVEN a regular (non-admin) Nextcloud user
- WHEN they attempt to access the Pipelinq admin settings URL directly
- THEN the system MUST deny access (HTTP 403 or redirect)
- AND the "Pipelinq" section MUST NOT appear in their settings navigation

#### Scenario: Settings page structure
- GIVEN an admin user on the Pipelinq settings page
- THEN the page MUST display the following sections in order:
  1. Version Information (app name, version, re-import button, support links)
  2. Register Configuration (register and schema mapping via `CnRegisterMapping`)
  3. Pipelines (pipeline CRUD with stage management)
  4. Product Categories
  5. Lead Sources [V1]
  6. Request Channels [V1]
  7. Prospect Discovery [V1]
- AND sections 3-7 MUST only render when the register is configured (`config.register` is non-empty)

#### Scenario: Non-admin user can read settings via API
@e2e exclude API auth; covered by Newman
- GIVEN a regular (non-admin) Nextcloud user
- WHEN they call `GET /api/settings`
- THEN the system MUST return the current config (register IDs, schema IDs) because the endpoint is annotated `@NoAdminRequired`
- AND the response MUST include `isAdmin: false` to indicate the user cannot modify settings
- AND the response MUST include `openRegisters: true/false` indicating whether OpenRegister is installed

---

### Requirement: Settings and configuration services — documented operations

The user settings, preferences and configuration loading implemented in this app MUST provide the operations enumerated in this change's tasks.md (for example `getPreference`, `setPreference`, `getConfigurationService`, `getObjectService`, `getConfigurationService`, `getObjectService`). Each listed method realises an observable part of user settings, preferences and configuration loading and MUST behave as implemented in the current codebase.

**Feature tier**: V1

#### Scenario: Documented operations are available

- GIVEN the backend service/controller is loaded
- WHEN a caller invokes one of the documented operations for user settings, preferences and configuration loading
- THEN the operation MUST execute and return a result consistent with the current implementation

---

### Requirement: Settings and configuration services — results derived from current CRM state

Operations for user settings, preferences and configuration loading MUST read their inputs from the relevant CRM entities/configuration and compute results from that live state (no hard-coded or stubbed responses). Derivations such as formatting, aggregation, filtering and validation MUST reflect the data present at call time.

**Feature tier**: V1

#### Scenario: Results reflect live state

- GIVEN CRM data backing user settings, preferences and configuration loading
- WHEN a documented operation runs
- THEN its output MUST be derived from that data
- AND it MUST change when the underlying data changes

---

### Requirement: Settings and configuration services — defensive handling of absent or invalid input

Operations for user settings, preferences and configuration loading MUST tolerate missing, empty, or malformed input without throwing unhandled errors — returning empty or default results, or surfacing a validation outcome as implemented, rather than crashing the surrounding flow.

**Feature tier**: V1

#### Scenario: Missing input does not crash the flow

- GIVEN an operation for user settings, preferences and configuration loading is called with absent or invalid input
- WHEN it executes
- THEN it MUST return a safe default or a validation result
- AND it MUST NOT raise an unhandled exception

### Requirement: REQ-AS-120: Object access control and MCP administration are OpenRegister-owned

The Pipelinq admin settings page MUST NOT provide per-schema "Objects API access" controls or
"MCP server" administration. Access control for the objects Pipelinq stores is enforced by
OpenRegister's permission layer using the `groups` arrays declared on each Register and Schema,
and the MCP server is owned and exposed by OpenRegister (`McpServerController`). Per ADR-022
(*Apps Consume OpenRegister Abstractions*), Pipelinq MUST NOT re-implement either capability as
leaf-app configuration. Specifically, the page MUST NOT render an "Objects API Access" section
or an "MCP Server Administration" section, and the backend MUST NOT expose endpoints that
persist a per-schema allowed-groups map (`objecten_access_*`) or MCP server credentials
(`mcp_*`) as Pipelinq application config.

#### Scenario: No Objects API Access section on the settings page
@e2e exclude UI-removal; covered by build + live verification
- GIVEN an admin user on the Pipelinq admin settings page
- THEN the page MUST NOT display an "Objects API Access" section
- AND object-level access control MUST be configured in OpenRegister on the relevant Register/Schema `groups`

#### Scenario: No MCP Server Administration section on the settings page
@e2e exclude UI-removal; covered by build + live verification
- GIVEN an admin user on the Pipelinq admin settings page
- THEN the page MUST NOT display an "MCP Server Administration" section
- AND MCP server access MUST be configured in OpenRegister

#### Scenario: Settings read payload excludes the removed maps
@e2e exclude API payload shape; covered by PHPUnit
- GIVEN an admin user calls `GET /api/settings`
- THEN the response MUST NOT include an `objectenAccess` key
- AND the response MUST NOT include an `mcpConfig` key
- AND the response MUST NOT include an `apiTokens` key
- AND the response MUST NOT include an `oauthConfig` key

#### Scenario: Removed endpoints are not routed
@e2e exclude route removal; covered by route inspection + PHPUnit
- GIVEN the Pipelinq routing table
- THEN there MUST be no route named `settings#getObjectenAccess`, `settings#saveObjectenAccess`, or `settings#saveMcp`
- AND `SettingsController` MUST NOT define `getObjectenAccess`, `saveObjectenAccess`, or `saveMcp`

### Requirement: REQ-AS-121: REST API token issuance and OAuth client configuration are OpenRegister-owned

The Pipelinq admin settings page MUST NOT provide a "REST API Authentication" section — neither a
REST API token-issuance UI nor an OAuth 2.0 client-configuration UI. Authenticated
machine-to-machine access to the objects Pipelinq stores is owned by OpenRegister: API consumers
are persisted as OpenRegister `Consumer` records, authenticated at runtime by OpenRegister's
`AuthorizationService`, and managed through OpenRegister's `/api/consumers` surface. Per ADR-022
(*Apps Consume OpenRegister Abstractions*), Pipelinq MUST NOT re-implement API-credential issuance
or OAuth client configuration as leaf-app settings. Specifically, the page MUST NOT render a "REST
API Authentication" section, and the backend MUST NOT expose endpoints that issue, list, revoke or
validate Pipelinq-local API tokens (`api_token_*`) or persist OAuth client credentials (`oauth_*`)
as Pipelinq application config. The `ApiAuthService` class MUST NOT exist.

#### Scenario: No REST API Authentication section on the settings page
@e2e exclude UI-removal; covered by build + live verification
- GIVEN an admin user on the Pipelinq admin settings page
- THEN the page MUST NOT display a "REST API Authentication" section
- AND API consumer credentials MUST be managed in OpenRegister via `/api/consumers`

#### Scenario: Removed token + OAuth endpoints are not routed
@e2e exclude route removal; covered by route inspection + PHPUnit
- GIVEN the Pipelinq routing table
- THEN there MUST be no route named `settings#listTokens`, `settings#generateToken`, `settings#revokeToken`, or `settings#saveOAuth`
- AND `SettingsController` MUST NOT define `listTokens`, `generateToken`, `revokeToken`, or `saveOAuth`
- AND the `ApiAuthService` class MUST NOT exist

## Requirements

### Requirement: REQ-AS-011: Version Information Display [MVP]

The admin settings page MUST display version information about the Pipelinq installation so administrators can verify which version is running and access support.

#### Scenario: Version info card renders
- GIVEN the admin opens the Pipelinq admin settings page
- THEN the page MUST display a `CnVersionInfoCard` component showing:
  - App name: "Pipelinq"
  - App version: read from `document.getElementById('pipelinq-settings').dataset.version` (set by `AdminSettings.php` via TemplateResponse)
  - A "Re-import configuration" button in the actions slot
  - A support footer with links to `support@conduction.nl` and `sales@conduction.nl` for SLA inquiries

#### Scenario: Version passed from backend
@e2e exclude backend version injection; covered by PHPUnit
- GIVEN `AdminSettings::getForm()` is called
- THEN the TemplateResponse MUST include the app version via `$this->appManager->getAppVersion(Application::APP_ID)`
- AND the version MUST be available to the Vue component as a data attribute on the `#pipelinq-settings` element

---

### Requirement: REQ-AS-012: Register Configuration Mapping [MVP]

The admin settings page MUST display a register configuration mapping interface that allows administrators to map Pipelinq object types to OpenRegister registers and schemas. This uses the shared `CnRegisterMapping` component from `@conduction/nextcloud-vue`.

#### Scenario: Register mapping groups displayed
- GIVEN the admin opens the settings page
- THEN the `CnRegisterMapping` component MUST display one group called "Pipelinq Objects"
- AND the group MUST list 8 object types with their slugs and labels:
  | Slug | Label | Description |
  |------|-------|-------------|
  | client | Client | Companies and organisations |
  | contact | Contact | Contact persons |
  | lead | Lead | Sales leads |
  | request | Request | Customer requests |
  | pipeline | Pipeline | Pipeline stages |
  | product | Product | Products and services |
  | productCategory | Product Category | Product categories |
  | leadProduct | Lead Product | Product line items on leads |
- AND the register config key MUST be `register`

#### Scenario: Save register mapping
@e2e exclude requires OR register data
- GIVEN the admin modifies the register or schema assignments in the mapping UI
- WHEN they click Save
- THEN the component MUST emit a `save` event with the updated configuration
- AND the Settings.vue parent MUST call `settingsStore.saveSettings(configuration)` which posts to `POST /api/settings`
- AND the backend MUST persist each config key via `IAppConfig::setValueString()` for keys: `register`, `client_schema`, `contact_schema`, `lead_schema`, `request_schema`, `pipeline_schema`, `product_schema`, `productCategory_schema`, `leadProduct_schema`
- AND a success notification "Configuration saved" MUST be displayed

#### Scenario: Register not configured hides dependent sections
@e2e exclude UI state; requires unconfigured state
- GIVEN the register mapping has not been configured (config.register is empty)
- WHEN the admin views the settings page
- THEN the Pipeline Manager, Product Category Manager, Lead Sources, Request Channels, and Prospect Settings sections MUST NOT be rendered
- AND the `isConfigured` computed property MUST return `false`

---

### Requirement: REQ-AS-013: Re-import Configuration Action [MVP]

The admin settings page MUST provide a button to re-run the register configuration import, allowing administrators to recover from failed imports or apply updated schemas.

#### Scenario: Re-import button in version card
@e2e exclude UI element requiring configured register
- GIVEN the admin views the settings page
- THEN a "Re-import configuration" button MUST be visible in the Version Information card actions slot
- AND the button MUST show a Refresh icon when idle
- AND the button MUST show a loading spinner and text "Importing..." when the re-import is in progress
- AND the button MUST be disabled during the re-import

#### Scenario: Re-import succeeds
@e2e exclude PHP repair step; covered by PHPUnit
- GIVEN the admin clicks "Re-import configuration"
- WHEN the frontend POSTs to `/apps/pipelinq/api/settings/reimport`
- THEN the backend MUST call `SettingsService::loadSettings(force: true)` which delegates to `SettingsLoadService`
- AND `SettingsLoadService` MUST call `ConfigurationService::importFromApp()` to re-import from `lib/Settings/pipelinq_register.json`
- AND the response MUST include `success: true`, the updated `config` object, and a `result` with register and schema counts
- AND the frontend MUST update the local config state with the returned config
- AND a success NcNoteCard MUST display "Configuration re-imported successfully"

#### Scenario: Re-import fails
@e2e exclude PHP repair step error handling; covered by PHPUnit
- GIVEN OpenRegister is not available or the import throws an exception
- WHEN the admin clicks "Re-import configuration"
- THEN the backend MUST return HTTP 500 with `success: false` and an error message
- AND a red error NcNoteCard MUST display the error message

---

### Requirement: REQ-AS-020: Pipeline Management [MVP]

The admin settings MUST provide full CRUD operations for pipelines. Pipelines are stored as OpenRegister objects with schema `pipeline`. Stages are stored as a JSON array within each pipeline object (`pipeline.stages[]`), not as separate OpenRegister objects.

#### Scenario: List all pipelines
@e2e exclude requires existing pipeline data
- GIVEN the system has 2 pipelines: "Sales Pipeline" (default, 7 stages) and "Service Pipeline" (5 stages)
- WHEN the admin views the Pipelines section
- THEN the `PipelineManager` component MUST fetch pipelines via `objectStore.fetchCollection('pipeline', { _limit: 100 })`
- AND each pipeline card MUST show: title, default indicator (star icon for the default), schema label (from `propertyMappings[].schemaSlug` or legacy `entityType`), stage count (e.g. "7 stages"), and a compact stage flow (e.g., "New -> Contacted -> ... -> Won -> Lost" truncated to first 2 and last 2 if more than 5 stages)
- AND each pipeline MUST have Edit (pencil icon) and Delete (trash icon) action buttons

#### Scenario: Create a new pipeline
@e2e exclude requires OR write; creates side-effect data
- GIVEN the admin clicks "Add pipeline"
- WHEN the PipelineForm overlay opens and they enter title "Enterprise Sales", configure property mappings, add stages, and click Create
- THEN a new pipeline MUST be created via `objectStore.saveObject('pipeline', pipelineData)`
- AND the pipeline list MUST refresh via `objectStore.fetchCollection('pipeline', { _limit: 100 })`

#### Scenario: Create pipeline -- title required
@e2e exclude form validation; covered by PHPUnit
- GIVEN the admin is creating a new pipeline
- WHEN they attempt to save without entering a title
- THEN the PipelineForm MUST display a validation error: "Pipeline title is required" via the `errors.title` computed property
- AND the Create/Save button MUST be disabled (via `isValid` computed)
- AND the pipeline MUST NOT be created

#### Scenario: Create pipeline -- at least one stage required
@e2e exclude validation; covered by PHPUnit
- GIVEN the admin is creating a new pipeline with no stages added
- WHEN they attempt to save
- THEN the Save/Create button MUST be disabled because `isValid` requires `form.stages.length > 0`
- AND the stages section MUST show "No stages yet. Add at least one stage."

#### Scenario: Edit pipeline title and properties
@e2e exclude requires existing pipeline
- GIVEN an existing pipeline "Sales Pipeline"
- WHEN the admin clicks the Edit button, changes the title to "B2B Sales Pipeline", and saves
- THEN the pipeline MUST be updated via `objectStore.saveObject('pipeline', pipelineData)`
- AND the pipeline list MUST refresh to show the new title

#### Scenario: Delete a pipeline
@e2e exclude requires existing pipeline
- GIVEN a pipeline "Old Pipeline" that is NOT the default pipeline
- WHEN the admin clicks the Delete button
- THEN the system MUST count affected items by querying OpenRegister for leads and requests with `pipeline=<id>`
- AND a confirmation dialog (NcDialog) MUST appear with "Are you sure you want to delete "{title}"?"
- AND if affected items > 0, a red warning MUST show: "{count} leads/requests are on this pipeline. They will be removed from the pipeline but not deleted."
- AND if the pipeline has stages, an additional warning MUST show: "This pipeline has {count} stages. All stage configuration will be lost."
- AND upon confirmation, the pipeline MUST be deleted via `objectStore.deleteObject('pipeline', id)` and the list MUST refresh

#### Scenario: Delete default pipeline -- prevented
@e2e exclude backend rule; covered by PHPUnit
- GIVEN the "Sales Pipeline" is marked as default
- WHEN the admin attempts to delete it
- THEN the system MUST prevent deletion immediately (before showing the dialog)
- AND the system MUST display an error via `showError()`: "Cannot delete the default pipeline. Set another pipeline as default first."

---

### Requirement: REQ-AS-030: Stage Management within Pipelines [MVP]

The admin settings MUST provide CRUD operations for stages within each pipeline via the `PipelineForm` component. Stages are stored as a JSON array on the pipeline object, each with: `name`, `order`, `probability`, `isClosed`, `isWon`, and `color`.

#### Scenario: List stages for a pipeline
@e2e exclude requires existing pipeline with stages
- GIVEN the admin is editing "Sales Pipeline" in the PipelineForm
- THEN the form MUST list all stages sorted by their `order` field (via `sortedStages` computed)
- AND each stage row MUST show: drag handle, up/down reorder buttons, order number, name field, probability field (number input), color picker, isClosed switch, isWon switch (disabled unless isClosed is true), and a delete button

#### Scenario: Add a new stage
@e2e exclude requires existing pipeline; creates side-effect data
- GIVEN the admin is editing a pipeline
- WHEN they click "Add stage"
- THEN a new stage MUST be appended with `order` set to `maxOrder + 1`, empty name, null probability, `isClosed: false`, `isWon: false`, and no color

#### Scenario: Add stage -- name required
@e2e exclude validation; covered by PHPUnit
- GIVEN the admin has added a stage with an empty name
- WHEN they attempt to save the pipeline
- THEN the `stageErrors` computed MUST produce `name: "Stage name is required"` for that stage
- AND the Save button MUST be disabled (via `isValid`)

#### Scenario: Reorder stages via drag-and-drop
@e2e exclude drag-and-drop; requires existing stages
- GIVEN stages in order: New (0), Contacted (1), Qualified (2)
- WHEN the admin drags "Qualified" between "New" and "Contacted" using the drag handle
- THEN `vuedraggable` MUST trigger the `@end` event which calls `recomputeOrders()`
- AND the `order` field of all stages MUST be recalculated to sequential integers (0, 1, 2, ...)

#### Scenario: Reorder stages via up/down buttons
@e2e exclude requires existing stages
- GIVEN stages in order: New (0), Contacted (1), Qualified (2)
- WHEN the admin clicks the "up" button on "Qualified"
- THEN the `moveStage(stage, -1)` method MUST swap the `order` values of "Qualified" and "Contacted"
- AND the stage list MUST re-sort to: New (0), Qualified (1), Contacted (2)

#### Scenario: Delete a stage
@e2e exclude requires existing stage
- GIVEN a pipeline with stages: New (0), Contacted (1), Qualified (2)
- WHEN the admin deletes "Contacted"
- THEN the stage MUST be removed from the `form.stages` array
- AND `recomputeOrders()` MUST re-number remaining stages to: New (0), Qualified (1)

#### Scenario: Stage validation -- at least one non-closed stage
@e2e exclude backend validation; covered by PHPUnit
- GIVEN a pipeline with stages: "Active" (isClosed=false) and "Done" (isClosed=true)
- WHEN the admin sets "Active" to isClosed=true
- THEN the `errors.stages` computed MUST produce: "Pipeline must have at least one non-closed stage"
- AND the Save button MUST be disabled

#### Scenario: Stage validation -- isWon requires isClosed
@e2e exclude backend validation; covered by PHPUnit
- GIVEN a stage with `isClosed=false`
- WHEN the admin attempts to set `isWon=true`
- THEN the isWon switch MUST be disabled (`:disabled="!stage.isClosed"`)
- AND the `stageErrors` for this stage MUST include: "A Won stage must also be marked as Closed"

#### Scenario: Stage color picker
- GIVEN the admin is editing a stage
- THEN each stage row MUST include a color input (`type="color"`) defaulting to `#6b7280`
- AND the chosen color MUST be saved with the pipeline and used for visual display in the pipeline board

---

### Requirement: REQ-AS-035: Pipeline Property Mappings [MVP]

The PipelineForm MUST allow administrators to configure property mappings that define which schemas participate in the pipeline and how objects are placed into columns.

#### Scenario: Add a property mapping
@e2e exclude requires existing pipeline
- GIVEN the admin is editing a pipeline
- WHEN they click "Add mapping"
- THEN a new mapping row MUST appear with fields: Schema slug (text, placeholder "e.g. lead, request"), Column property (text, defaulting to "stage"), and Totals property (text, optional, placeholder "e.g. value")

#### Scenario: Configure multiple schema mappings
@e2e exclude requires existing pipelines
- GIVEN a pipeline with mappings for "lead" (column: "stage", totals: "value") and "request" (column: "stage", totals: null)
- WHEN the pipeline is saved
- THEN the `propertyMappings` array MUST be serialized as part of the pipeline object
- AND the pipeline card in the list view MUST display schema slugs from the mappings as the entity type badge

#### Scenario: Remove a property mapping
@e2e exclude requires existing mapping
- GIVEN a pipeline with 2 property mappings
- WHEN the admin clicks the delete button on one mapping
- THEN the mapping MUST be removed from the `propertyMappings` array

---

### Requirement: REQ-AS-040: Default Pipeline Selection [MVP]

The admin settings MUST allow selecting one pipeline as the default. The default pipeline is used when creating new leads or requests that are not explicitly assigned to a pipeline.

#### Scenario: Set default pipeline
@e2e exclude requires existing pipelines
- GIVEN pipelines "Sales Pipeline" (default) and "Service Pipeline" exist
- WHEN the admin edits "Service Pipeline" and sets `isDefault=true`
- THEN `PipelineManager.onSave()` MUST iterate all other pipelines that have `isDefault=true` and save them with `isDefault: false` via `objectStore.saveObject()`
- AND only one pipeline MUST have `isDefault = true` at any time

#### Scenario: Default pipeline indicator
@e2e exclude requires default pipeline data
- GIVEN "Sales Pipeline" is the default
- WHEN the admin views the pipeline list
- THEN "Sales Pipeline" MUST display a yellow star icon (`<Star>` with class `default-star`, color `var(--color-warning)`)
- AND other pipelines MUST NOT display this indicator

#### Scenario: First pipeline auto-becomes default
@e2e exclude backend auto-default; covered by PHPUnit
- GIVEN no pipelines exist (or only one which is being created)
- WHEN the admin creates the first pipeline
- THEN `PipelineManager.onSave()` MUST automatically set `isDefault: true` on the new pipeline

#### Scenario: Cannot unset default without replacement
@e2e exclude backend validation; covered by PHPUnit
- GIVEN "Sales Pipeline" is the only default pipeline
- WHEN the admin edits it and unchecks the "Default pipeline" switch
- THEN `PipelineManager.onSave()` MUST detect no other defaults exist
- AND MUST re-set `isDefault: true` on this pipeline
- AND MUST display an error via `showError()`: "At least one pipeline must be set as default"

---

### Requirement: REQ-AS-045: Pipeline View Association [MVP]

The PipelineForm MUST allow associating a pipeline with a saved view to define which schemas are displayed in the pipeline board.

#### Scenario: Select a view for a pipeline
@e2e exclude requires existing pipeline
- GIVEN the admin is editing a pipeline
- THEN a "View" dropdown (NcSelect) MUST be displayed, populated from `getViews()` (via `viewService.js`)
- AND the dropdown MUST be clearable (optional association)
- AND selecting a view MUST set `form.viewId` on the pipeline

#### Scenario: Totals label configuration
@e2e exclude requires existing pipeline
- GIVEN the admin is editing a pipeline
- THEN a "Totals label" text field MUST be displayed with placeholder "e.g. EUR, hours, items"
- AND the help text MUST explain: "Label shown next to column totals. Leave empty to hide totals."

---

### Requirement: REQ-AS-050: Lead Source Configuration [V1]

The admin settings MUST allow customizing the list of available lead source values. Lead sources are managed as system tags (via `SystemTagService`) and displayed using the reusable `TagManager` component.

#### Scenario: Default lead sources
@e2e exclude OR data; covered by PHPUnit
- GIVEN a fresh Pipelinq installation
- WHEN the repair step (`InitializeSettings`) runs
- THEN `SystemTagService::ensureDefaults()` MUST create the following lead sources with objectType `pipelinq_lead_source`: `website`, `email`, `phone`, `referral`, `partner`, `campaign`, `social_media`, `event`, `other`

#### Scenario: List lead sources
@e2e exclude requires existing sources
- GIVEN the admin views the Lead Sources section
- THEN the `TagManager` component MUST render with title "Lead Sources" and add label "+ Add Source"
- AND tags MUST be fetched via `leadSourcesStore.fetchSources()` on mount
- AND each source MUST display as a chip/pill with inline remove button (x)

#### Scenario: Add a custom source
@e2e exclude requires form interaction; creates side-effect data
- GIVEN the admin clicks "+ Add Source"
- WHEN the inline input appears and they type "Trade Show" and press Enter
- THEN `leadSourcesStore.addSource('Trade Show')` MUST be called
- AND the new source MUST appear as a chip in the list

#### Scenario: Remove a source with usage check
@e2e exclude backend usage check; covered by PHPUnit
- GIVEN lead source "website" exists
- WHEN the admin clicks the remove button (x) on "website"
- THEN the `usageCheck` function MUST query OpenRegister for leads with `source=website` via `countObjectsWithField('lead', 'source', 'website')`
- AND if the count > 0, a confirm dialog MUST show: "{count} items currently use "website". They will retain their value, but it will no longer be available for new items."
- AND upon confirmation, `leadSourcesStore.removeSource(id)` MUST be called

#### Scenario: Rename a source via double-click
@e2e exclude requires existing sources
- GIVEN lead source "social_media" exists
- WHEN the admin double-clicks on the chip label
- THEN the chip MUST switch to edit mode with an inline text input pre-filled with "social_media"
- AND pressing Enter MUST call `leadSourcesStore.renameSource(id, newName)`
- AND pressing Escape MUST cancel the edit

---

### Requirement: REQ-AS-060: Request Channel Configuration [V1]

The admin settings MUST allow customizing the list of available request channel values, using the same `TagManager` component as lead sources.

#### Scenario: Default request channels
@e2e exclude OR data; covered by PHPUnit
- GIVEN a fresh Pipelinq installation
- WHEN the repair step runs
- THEN `SystemTagService::ensureDefaults()` MUST create the following channels with objectType `pipelinq_request_channel`: `phone`, `email`, `website`, `counter`, `post`

#### Scenario: List request channels
@e2e exclude requires existing channels
- GIVEN the admin views the Request Channels section
- THEN the `TagManager` component MUST render with title "Request Channels" and add label "+ Add Channel"
- AND tags MUST be fetched via `requestChannelsStore.fetchChannels()` on mount

#### Scenario: Add a custom channel
@e2e exclude requires form interaction; creates side-effect data
- GIVEN the admin clicks "+ Add Channel"
- WHEN they enter "Service Desk" and press Enter
- THEN `requestChannelsStore.addChannel('Service Desk')` MUST be called

#### Scenario: Remove a channel with usage check
@e2e exclude backend usage check; covered by PHPUnit
- GIVEN channel "phone" is used by existing requests
- WHEN the admin clicks the remove button
- THEN the usage check MUST query `countObjectsWithField('request', 'channel', 'phone')`
- AND the confirm dialog MUST display the usage count before proceeding

---

### Requirement: REQ-AS-065: Prospect Discovery Settings [V1]

The admin settings MUST include an Ideal Customer Profile (ICP) configuration section for prospect discovery, rendered via the `ProspectSettings` component.

#### Scenario: ICP form fields
@e2e exclude V1 ICP feature; requires ICP configuration
- GIVEN the admin views the Prospect Discovery section
- THEN the form MUST display the following fields:
  | Field | Type | Description |
  |-------|------|-------------|
  | SBI Codes | Text (comma-separated) | Dutch Standard Industrial Classification codes |
  | Min Employees | Number | Minimum employee count filter |
  | Max Employees | Number | Maximum employee count filter |
  | Provinces | Multi-select | Dutch provinces (12 options: Drenthe through Zuid-Holland) |
  | Legal Forms | Multi-select | Dutch legal forms (BV, NV, VOF, Eenmanszaak, Stichting, Vereniging, CV, Maatschap) |
  | Exclude Inactive | Checkbox | Exclude inactive companies (default: true) |
  | Keywords | Text (comma-separated) | Keywords for OpenCorporates search |
  | KVK API Key | Password field | API key for KVK integration |
  | OpenCorporates | Checkbox | Enable OpenCorporates as supplementary data source |

#### Scenario: Load existing ICP settings
@e2e exclude V1 ICP feature
- GIVEN ICP settings have been previously saved
- WHEN the ProspectSettings component mounts
- THEN it MUST fetch settings from `GET /apps/pipelinq/api/prospects/settings`
- AND populate the form with the returned values
- AND the KVK API Key MUST display as `***configured***` if previously set (never expose the raw key)

#### Scenario: Save ICP settings
@e2e exclude V1 ICP feature
- GIVEN the admin fills in the ICP form and clicks "Save ICP Settings"
- THEN the form MUST PUT to `/apps/pipelinq/api/prospects/settings` with the payload
- AND SBI codes and keywords MUST be parsed from comma-separated strings to arrays
- AND if the KVK API key shows `***configured***`, it MUST be omitted from the payload (do not overwrite with the mask)
- AND a success NcNoteCard MUST display "ICP settings saved successfully"

---

### Requirement: REQ-AS-070: Default Pipelines on Installation [MVP]

When Pipelinq is installed for the first time, the system MUST create default pipelines and stages via the repair step / configuration import.

#### Scenario: Default Sales Pipeline created
@e2e exclude PHP repair step; covered by PHPUnit
- GIVEN Pipelinq is freshly installed
- WHEN the repair step runs (`InitializeSettings::run()`)
- THEN `SettingsService::createDefaultPipelines()` MUST delegate to `DefaultPipelineService::createDefaultPipelines()`
- AND a "Sales Pipeline" MUST be created with `isDefault: true`
- AND it MUST have stages defined by `PipelineStageData` in this order:
  | Order | Title | Probability | isClosed | isWon |
  |-------|-------|-------------|----------|-------|
  | 0 | New | 10 | false | false |
  | 1 | Contacted | 20 | false | false |
  | 2 | Qualified | 40 | false | false |
  | 3 | Proposal | 60 | false | false |
  | 4 | Negotiation | 80 | false | false |
  | 5 | Won | 100 | true | true |
  | 6 | Lost | 0 | true | false |

#### Scenario: Default Service Pipeline created
@e2e exclude PHP repair step; covered by PHPUnit
- GIVEN Pipelinq is freshly installed
- WHEN the repair step runs
- THEN a "Service Pipeline" MUST be created with `isDefault: false`
- AND it MUST have stages in this order:
  | Order | Title | Probability | isClosed | isWon |
  |-------|-------|-------------|----------|-------|
  | 0 | New | -- | false | false |
  | 1 | In Progress | -- | false | false |
  | 2 | Completed | -- | true | true |
  | 3 | Rejected | -- | true | false |
  | 4 | Converted to Case | -- | true | false |

#### Scenario: Repair step is idempotent
@e2e exclude PHP repair step; covered by PHPUnit
- GIVEN the default pipelines already exist
- WHEN the repair step runs again (e.g., during app update)
- THEN `DefaultPipelineService` MUST check if "Sales Pipeline" already exists
- AND MUST NOT create duplicate pipelines
- AND existing pipelines and stages MUST NOT be modified

#### Scenario: Repair step handles missing OpenRegister
@e2e exclude PHP error handling; covered by PHPUnit
- GIVEN OpenRegister is not installed
- WHEN the repair step runs
- THEN `InitializeSettings::run()` MUST output a warning: "OpenRegister app is not installed -- skipping configuration import"
- AND MUST advance the progress counter and finish without error

---

### Requirement: REQ-AS-075: User Notification Preferences [MVP]

Each user MUST be able to configure their notification preferences via a per-user settings dialog (`UserSettings.vue`), separate from the admin settings.

#### Scenario: User settings dialog content
@e2e exclude requires user settings dialog interaction
- GIVEN a user opens the Pipelinq settings dialog (NcAppSettingsDialog)
- THEN the Notifications section MUST display three toggle switches:
  | Setting Key | Label | Default |
  |------------|-------|---------|
  | notify_assignments | Lead & request assignments | true |
  | notify_stage_status | Pipeline stage & status changes | true |
  | notify_notes | Notes & comments | true |
- AND each toggle MUST show a descriptive hint below it

#### Scenario: Toggle a notification preference
@e2e exclude requires user settings dialog
- GIVEN the user toggles "Lead & request assignments" off
- THEN the frontend MUST PUT to `/apps/pipelinq/api/user/settings` with `{ notify_assignments: false }`
- AND the backend MUST persist the value via `IConfig::setUserValue()` for that user
- AND the toggle MUST show a loading state while saving

#### Scenario: User settings persist per user
@e2e exclude persistence; covered by PHPUnit
- GIVEN user A has `notify_assignments: false` and user B has the default `notify_assignments: true`
- WHEN each user fetches their settings via `GET /apps/pipelinq/api/user/settings`
- THEN user A MUST receive `notify_assignments: false`
- AND user B MUST receive `notify_assignments: true`

---

### Requirement: REQ-AS-080: Settings Persistence [MVP]

All admin settings MUST be persisted via `OCP\IAppConfig` and survive app updates and server restarts.

#### Scenario: Config keys persisted via IAppConfig
@e2e exclude IAppConfig persistence; covered by PHPUnit
- GIVEN the admin saves settings
- THEN the following config keys MUST be persisted via `IAppConfig::setValueString()` under app ID `pipelinq`:
  `register`, `client_schema`, `contact_schema`, `lead_schema`, `request_schema`, `pipeline_schema`, `product_schema`, `productCategory_schema`, `leadProduct_schema`

#### Scenario: Pipeline settings persist as OpenRegister objects
@e2e exclude OR persistence; covered by PHPUnit
- GIVEN the admin has created a custom pipeline "Enterprise Sales" with 5 stages
- WHEN the Nextcloud server restarts
- THEN the pipeline and its stages MUST still exist in OpenRegister and be functional

#### Scenario: Source/channel settings persist as system tags
@e2e exclude system tag persistence; covered by PHPUnit
- GIVEN the admin has added custom lead sources and request channels
- WHEN the app is updated to a new version
- THEN all custom sources and channels MUST be preserved (stored via `SystemTagService`)
- AND the repair step MUST only ensure defaults exist without overwriting customs

#### Scenario: User settings persist via IConfig
@e2e exclude IConfig persistence; covered by PHPUnit
- GIVEN a user has modified notification preferences
- WHEN the server restarts
- THEN user preferences MUST be preserved via `IConfig::getUserValue()` / `IConfig::setUserValue()`

---

### Requirement: REQ-AS-085: Internationalization (i18n) [MVP]

All admin settings UI text MUST support Dutch (nl) and English (en) translations via the Nextcloud `t()` and `n()` translation functions.

#### Scenario: All UI strings use t() function
@e2e exclude i18n code audit; covered by static analysis
- GIVEN the admin settings components: Settings.vue, PipelineManager.vue, PipelineForm.vue, TagManager.vue, ProspectSettings.vue, UserSettings.vue
- THEN every user-visible string MUST be wrapped in `t('pipelinq', '...')` or `n('pipelinq', '...')` for pluralization
- AND the backend MUST use `IL10N::t()` for translatable response messages (e.g., "Configuration re-imported successfully")

#### Scenario: Pluralization for stage count
@e2e exclude i18n; covered by unit tests
- GIVEN a pipeline with 1 stage
- THEN the display MUST show "1 stage" (singular)
- AND for 5 stages it MUST show "5 stages" (plural)
- AND this MUST use `n('pipelinq', '%n stage', '%n stages', count)`

---

### Requirement: REQ-AS-090: Accessible Form Controls [MVP]

The admin settings page MUST comply with WCAG AA accessibility standards for all interactive elements.

#### Scenario: Form inputs have labels
- GIVEN the admin settings page and pipeline form
- THEN all NcTextField components MUST have a `label` prop set
- AND all NcSelect components MUST have accessible labels
- AND all NcCheckboxRadioSwitch components MUST have visible text labels
- AND all icon-only buttons MUST have `title` attributes for screen readers

#### Scenario: Keyboard navigation
@e2e exclude WCAG; covered by accessibility tooling
- GIVEN the admin is using keyboard navigation
- THEN all interactive elements (buttons, inputs, switches, drag handles) MUST be focusable
- AND the TagManager inline inputs MUST support Enter to save and Escape to cancel
- AND the pipeline form MUST be dismissible (Cancel button)

#### Scenario: Color contrast
@e2e exclude WCAG; covered by accessibility tooling
- GIVEN the admin settings page uses CSS custom properties
- THEN all text MUST use Nextcloud theme variables (`var(--color-main-text)`, `var(--color-text-maxcontrast)`) to ensure sufficient contrast
- AND destructive actions MUST use `var(--color-error)` for visual distinction

---

## UI Layout Reference

The admin settings page follows the wireframe in DESIGN-REFERENCES.md section 3.7:

```
Administration > Pipelinq
==========================

PIPELINQ SETTINGS
Configure your Pipelinq installation        [Documentation link]

VERSION INFORMATION
Pipelinq v1.x.x                             [Re-import configuration]
Support: support@conduction.nl
SLA: sales@conduction.nl

REGISTER CONFIGURATION
Map Pipelinq object types to OpenRegister registers and schemas
[CnRegisterMapping component with 8 type mappings]       [Save]

PIPELINES                                       [+ Add pipeline]
-------------------------------------------------------------
| * Sales Pipeline (default)    7 stages  [Edit] [Delete]    |
|   Leads                                                     |
|   New -> Contacted -> ... -> Won -> Lost                    |
-------------------------------------------------------------
|   Service Pipeline            5 stages  [Edit] [Delete]    |
|   Requests                                                  |
|   New -> In Progress -> ... -> Rejected -> Conv. to Case    |
-------------------------------------------------------------

PRODUCT CATEGORIES
[ProductCategoryManager component]

LEAD SOURCES [V1]                                [+ Add Source]
-------------------------------------------------------------
| website [x] | email [x] | phone [x] | referral [x] |      |
| partner [x] | campaign [x] | social_media [x] |            |
| event [x] | other [x]                                      |
-------------------------------------------------------------

REQUEST CHANNELS [V1]                           [+ Add Channel]
-------------------------------------------------------------
| phone [x] | email [x] | website [x] | counter [x] |       |
| post [x]                                                    |
-------------------------------------------------------------

PROSPECT DISCOVERY [V1]
SBI Codes: [____________]    Min Employees: [___]
Keywords: [_____________]    Max Employees: [___]
Provinces: [multi-select]    Legal Forms: [multi-select]
[x] Exclude inactive         KVK API Key: [********]
[ ] Enable OpenCorporates                  [Save ICP Settings]
```

- The settings page MUST use Nextcloud's standard admin settings layout and NcSettingsSection
- Pipeline edit view MUST use an overlay form with draggable stage list (vuedraggable)
- Source/channel items MUST use chip/tag components with inline remove buttons
- All form inputs MUST have accessible labels (WCAG AA)
- Destructive actions (delete pipeline, remove source/channel) MUST require confirmation

---

### Current Implementation Status

**Substantially implemented.** Most MVP and V1 requirements are complete.

Implemented:
- `lib/Settings/AdminSettings.php` -- registers the Pipelinq admin settings section (`ISettings` implementation, section ID `pipelinq`, priority 10). Returns `TemplateResponse` with config JSON and app version.
- `lib/Sections/SettingsSection.php` -- registers the "Pipelinq" section in Nextcloud admin navigation.
- `lib/Controller/SettingsController.php` -- `GET /api/settings` (read, `@NoAdminRequired`), `POST /api/settings` (update, admin-only), `POST /api/settings/reimport` (re-import, admin-only). Also `GET/PUT /api/user/settings` for per-user notification preferences.
- `lib/Service/SettingsService.php` -- manages 9 config keys via IAppConfig. Delegates to `SettingsLoadService` for import and `DefaultPipelineService` for pipeline creation. Also manages user settings via `IConfig`.
- `lib/Repair/InitializeSettings.php` -- repair step with 4 progress steps: check OpenRegister, load config, create default pipelines, ensure default lead sources and request channels via `SystemTagService`.
- `lib/Service/DefaultPipelineService.php` -- creates "Sales Pipeline" (7 stages) and "Service Pipeline" (5 stages), idempotent.
- `lib/Service/PipelineStageData.php` -- defines default stage data for both pipelines.
- `src/views/settings/Settings.vue` -- full admin settings page with 7 sections: version info (`CnVersionInfoCard`), register mapping (`CnRegisterMapping`), pipelines (`PipelineManager`), product categories (`ProductCategoryManager`), lead sources (`TagManager`), request channels (`TagManager`), prospect discovery (`ProspectSettings`). Conditionally renders sections 3-7 only when register is configured.
- `src/views/settings/PipelineManager.vue` -- pipeline CRUD: list view with title/default star/schema label/stage count/stage preview, add/edit/delete. Default pipeline protection (cannot delete default), affected items count via OpenRegister queries, first-pipeline auto-default, prevent unsetting default without replacement.
- `src/views/settings/PipelineForm.vue` -- pipeline edit overlay with: title (required), description, view selector, default toggle, totals label, property mappings (schema slug / column property / totals property), stage management with drag-and-drop (vuedraggable) and up/down buttons, stage fields (name required, probability, color picker, isClosed/isWon switches), validation (title required, at least one stage, at least one non-closed stage, isWon requires isClosed).
- `src/views/settings/TagManager.vue` -- reusable tag/chip manager with add (inline input), remove (with usage check and confirm), rename (double-click to edit inline), keyboard shortcuts (Enter to save, Escape to cancel).
- `src/views/settings/ProspectSettings.vue` -- ICP configuration form with 9 fields, fetches/saves via `/api/prospects/settings`, masks KVK API key.
- `src/views/settings/UserSettings.vue` -- per-user notification preferences dialog with 3 toggles (assignments, stage/status changes, notes), saves per-toggle via PUT.
- `lib/Controller/LeadSourceController.php` / `lib/Controller/RequestChannelController.php` -- CRUD endpoints for lead sources and request channels.
- `lib/Service/SystemTagService.php` + `lib/Service/SystemTagCrudService.php` -- manages lead sources and request channels as system tags.
- `src/store/modules/settings.js`, `leadSources.js`, `requestChannels.js` -- Pinia stores for all settings data.

Gaps / partially implemented:
- Duplicate source/channel prevention -- not validated on frontend or backend (TagManager does not check for duplicates before calling the add event).
- Stage deletion does not migrate items to previous stage -- items remain on the deleted stage reference. The spec originally required migration but the implementation simply removes the stage.
- Register status indicator (connected/disconnected with green/orange badge) -- not implemented; the register mapping component shows the mapping form but no explicit status indicator.

### Standards & References
- Nextcloud Admin Settings API (`OCP\Settings\ISettings`, `OCP\Settings\IIconSection`)
- Nextcloud IAppConfig for persisting application config keys
- Nextcloud IConfig for persisting per-user preferences
- OpenRegister `ConfigurationService::importFromApp()` for register/schema import
- `@conduction/nextcloud-vue` for shared components (`CnRegisterMapping`, `CnVersionInfoCard`)
- `vuedraggable` for stage drag-and-drop reordering
- WCAG AA for accessible form labels and keyboard navigation

### Specificity Assessment
- The spec is highly specific and implementable. Scenarios cover edge cases well (delete default prevention, unique order enforcement, idempotent repair step, usage-checked removal).
- All 7 sections of the settings page are fully specified with implementation references.
- Property mappings and view association (pipeline-to-view) are now specified.
- User notification preferences are specified separately from admin settings.
- **Architectural note**: Stages are stored as a JSON array within the pipeline object (`pipeline.stages[]`), not as separate OpenRegister objects. This is correct per the implementation.

---

## Requirements — retrofit (reverse-spec 2026-05-24)

The following REQ was drafted via `/opsx-reverse-spec` from observed behavior of `SettingsService` generic config accessors. Code already existed; this REQ retroactively specifies it.

### REQ-AS-110: Generic typed app-config accessor MUST scope every read/write to the Pipelinq APP_ID

`SettingsService` MUST expose a pair of generic accessors — `getConfigValue(key, default='')` and `setConfigValue(key, value)` — that wrap `IAppConfig::getValueString()` / `setValueString()` scoped to `Application::APP_ID` (`pipelinq`). All other Pipelinq services (e.g. `ProspectDiscoveryService`) MUST read and write app-scoped configuration through these accessors rather than calling `IAppConfig` directly with a hardcoded app id, so that the app-id binding has a single source of truth.

#### Scenario: Get returns the stored value
@e2e exclude ConfigService unit test; covered by PHPUnit
- GIVEN the key `register` has been previously written with value `"42"`
- WHEN a caller invokes `getConfigValue(key: 'register')`
- THEN the returned value MUST be `"42"`
- AND `IAppConfig::getValueString()` MUST have been called with app id `pipelinq`

#### Scenario: Get returns the supplied default when key is unset
@e2e exclude ConfigService unit test; covered by PHPUnit
- GIVEN no value has been stored for the key `client_schema`
- WHEN a caller invokes `getConfigValue(key: 'client_schema', default: 'fallback')`
- THEN the returned value MUST be `"fallback"`

#### Scenario: Get returns empty string when default omitted and key is unset
@e2e exclude ConfigService unit test; covered by PHPUnit
- GIVEN no value has been stored for the key `unknown_key`
- WHEN a caller invokes `getConfigValue(key: 'unknown_key')`
- THEN the returned value MUST be `""` (empty string — the default-default)

#### Scenario: Set persists the value scoped to APP_ID
@e2e exclude ConfigService unit test; covered by PHPUnit
- GIVEN a caller invokes `setConfigValue(key: 'lead_schema', value: 'lead-42')`
- WHEN a subsequent reader invokes `getConfigValue(key: 'lead_schema')`
- THEN the returned value MUST be `"lead-42"`
- AND `IAppConfig::setValueString()` MUST have been called with app id `pipelinq`

#### Scenario: Other apps' config is not affected
@e2e exclude ConfigService unit test; covered by PHPUnit
- GIVEN another Nextcloud app has a key `register` with value `"77"` set under its own app id
- WHEN a Pipelinq caller invokes `setConfigValue(key: 'register', value: 'new-value')`
- THEN only the Pipelinq-scoped `register` MUST change
- AND the other app's `register` MUST remain `"77"`

**Notes**
- The accessor is intentionally string-only (`getValueString` / `setValueString`). Callers that need typed values are expected to coerce on the boundary (e.g. `(int)$settings->getConfigValue('limit', '100')`). A future tightening could expose typed `getConfigInt`/`getConfigBool` overloads, but the current single-pair API matches every observed call site.
- The argument-name `key` is observed in named-arg call sites (e.g. `ProspectDiscoveryService` uses `getConfigValue(key: 'register')`). The signature is `public function getConfigValue(string $key, string $default='')` — renaming the parameters would silently break those callers.

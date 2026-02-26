# Tasks: mvp-foundation

## 1. Data Layer — Register Configuration

- [x] 1.1 Create `lib/Settings/pipelinq_register.json`
  - **spec_ref**: `specs/register-config/spec.md#REQ-RC-001`
  - **files**: `pipelinq/lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - GIVEN the Pipelinq source code
    - WHEN the file is inspected
    - THEN it MUST be valid OpenAPI 3.0.0 JSON
    - AND it MUST define a register with slug `pipelinq`
    - AND it MUST define 5 schemas: client, contact, lead, request, pipeline
    - AND each schema MUST have the properties defined in REQ-RC-001
    - AND lead MUST include @type `schema:Demand`
    - AND pipeline MUST include @type `schema:ItemList`
    - AND client MUST include @type `schema:Person` / `schema:Organization`

## 2. Backend — Repair Step

- [x] 2.1 Rewrite `lib/Repair/InitializeSettings.php` to use ConfigurationService
  - **spec_ref**: `specs/register-config/spec.md#REQ-RC-002`
  - **files**: `pipelinq/lib/Repair/InitializeSettings.php`
  - **acceptance_criteria**:
    - GIVEN OpenRegister is installed and enabled
    - WHEN the repair step runs (first install or upgrade)
    - THEN it MUST call `ConfigurationService::importFromApp('pipelinq')`
    - AND it MUST store register ID and all 5 schema IDs in IAppConfig
    - AND it MUST handle missing OpenRegister gracefully (log warning, don't crash)
    - AND existing data from the old 3-schema setup MUST be preserved

- [x] 2.2 Update `lib/Service/SettingsService.php` to include lead and pipeline schema keys
  - **spec_ref**: `specs/register-config/spec.md#REQ-RC-003`
  - **files**: `pipelinq/lib/Service/SettingsService.php`
  - **acceptance_criteria**:
    - GIVEN the settings endpoint is called
    - WHEN the response is returned
    - THEN it MUST include `lead_schema` and `pipeline_schema` keys alongside existing ones

## 3. Backend — Admin Settings

- [x] 3.1 Create `lib/Sections/PipelinqSection.php` — admin settings section
  - **spec_ref**: `specs/admin-settings/spec.md#REQ-AS-001`
  - **files**: `pipelinq/lib/Sections/PipelinqSection.php`
  - **acceptance_criteria**:
    - GIVEN a Nextcloud admin opens Settings → Administration
    - THEN a "Pipelinq" section MUST appear in the sidebar

- [x] 3.2 Create `lib/Settings/PipelinqAdmin.php` — admin settings page renderer
  - **spec_ref**: `specs/admin-settings/spec.md#REQ-AS-001`
  - **files**: `pipelinq/lib/Settings/PipelinqAdmin.php`
  - **acceptance_criteria**:
    - GIVEN the admin clicks the Pipelinq section
    - THEN the admin settings page MUST render with initial state (register ID, schema IDs)
    - AND non-admin users MUST NOT see this section

- [x] 3.3 Create admin settings route for re-import action
  - **spec_ref**: `specs/admin-settings/spec.md#REQ-AS-003`
  - **files**: `pipelinq/appinfo/routes.php`, `pipelinq/lib/Controller/SettingsController.php`
  - **acceptance_criteria**:
    - GIVEN the admin clicks "Re-import configuration"
    - WHEN `POST /api/settings/reimport` is called
    - THEN it MUST call `ConfigurationService::importFromApp('pipelinq')`
    - AND it MUST return updated register/schema IDs on success
    - AND it MUST return an error message on failure

- [x] 3.4 Register admin settings in `appinfo/info.xml`
  - **spec_ref**: `specs/admin-settings/spec.md#REQ-AS-001`
  - **files**: `pipelinq/appinfo/info.xml`
  - **acceptance_criteria**:
    - GIVEN the app is enabled
    - THEN the admin settings section and page MUST be registered via info.xml

## 4. Frontend — Store Extension

- [x] 4.1 Register lead and pipeline types in `src/store/store.js`
  - **spec_ref**: `specs/register-config/spec.md#REQ-RC-003`
  - **files**: `pipelinq/src/store/store.js`
  - **acceptance_criteria**:
    - GIVEN the settings contain lead_schema and pipeline_schema
    - WHEN initializeStores() runs
    - THEN the object store MUST register `lead` and `pipeline` types
    - AND missing schema IDs MUST be handled gracefully (skip, don't crash)

## 5. Frontend — Admin Settings UI

- [x] 5.1 Create `templates/admin.php` template
  - **spec_ref**: `specs/admin-settings/spec.md#REQ-AS-002`
  - **files**: `pipelinq/templates/admin.php`
  - **acceptance_criteria**:
    - GIVEN the admin settings page renders
    - THEN it MUST include a div with id `pipelinq-admin` for Vue mounting
    - AND it MUST pass initial state as data attributes

- [x] 5.2 Create `src/views/admin/AdminSettings.vue`
  - **spec_ref**: `specs/admin-settings/spec.md#REQ-AS-002`, `specs/admin-settings/spec.md#REQ-AS-003`
  - **files**: `pipelinq/src/views/admin/AdminSettings.vue`
  - **acceptance_criteria**:
    - GIVEN the register is configured
    - THEN the component MUST show register name, ID, and status (green "Connected")
    - AND it MUST list all 5 schemas with names and IDs
    - GIVEN the register is NOT configured
    - THEN it MUST show "Not configured" status and guidance message
    - AND a "Re-import configuration" button MUST be visible
    - WHEN clicked, it MUST POST to /api/settings/reimport and show success/error notification

- [x] 5.3 Wire up `src/settings.js` entry point for admin settings
  - **spec_ref**: `specs/admin-settings/spec.md#REQ-AS-001`
  - **files**: `pipelinq/src/settings.js`, `pipelinq/webpack.config.js`
  - **acceptance_criteria**:
    - GIVEN the admin settings page loads
    - THEN the settings.js entry MUST mount the AdminSettings Vue component
    - AND webpack MUST build the settings entry point

## 6. Verification

- [x] 6.1 Test end-to-end: install app, verify register and schemas created
  - **spec_ref**: `specs/register-config/spec.md#REQ-RC-002`
  - **acceptance_criteria**:
    - GIVEN a fresh Nextcloud instance with OpenRegister
    - WHEN Pipelinq is enabled
    - THEN the pipelinq register MUST exist with 5 schemas
    - AND `GET /api/settings` MUST return all 5 schema IDs
    - AND the frontend MUST register all 5 object types
    - AND the admin settings page MUST show "Connected" status

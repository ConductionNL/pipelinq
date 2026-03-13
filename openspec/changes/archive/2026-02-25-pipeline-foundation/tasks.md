# Tasks: pipeline-foundation

## 1. Schema Update

- [x] 1.1 Update pipeline schema with stage lifecycle properties
  - **spec_ref**: `specs/pipeline-foundation/spec.md#REQ-PF-001`
  - **files**: `pipelinq/lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - GIVEN the pipeline schema's stages array items
    - THEN each stage item MUST support `isClosed` (boolean), `isWon` (boolean), and `color` (string) properties
    - AND the schema MUST remain valid OpenAPI 3.0.0

## 2. Default Pipeline Creation

- [x] 2.1 Add default pipeline creation to SettingsService
  - **spec_ref**: `specs/pipeline-foundation/spec.md#REQ-PF-002`
  - **files**: `pipelinq/lib/Service/SettingsService.php`
  - **acceptance_criteria**:
    - GIVEN the app is initialized and schemas are imported
    - THEN a "Sales Pipeline" MUST be created with 7 stages (New through Lost) with correct probabilities and isClosed/isWon flags
    - AND a "Service Requests" pipeline MUST be created with 5 stages
    - AND creation MUST be idempotent (skip if pipelines already exist)
    - AND both pipelines MUST have isDefault: true for their entity type

## 3. Pipeline Manager Component

- [x] 3.1 Create PipelineManager.vue for admin settings
  - **spec_ref**: `specs/pipeline-foundation/spec.md#REQ-PF-003`
  - **files**: `pipelinq/src/views/settings/PipelineManager.vue`
  - **acceptance_criteria**:
    - GIVEN the admin settings page
    - THEN a "Pipelines" section MUST list all pipelines with title, entity type, stage count, and stage preview
    - AND a default indicator (star) MUST be shown for default pipelines
    - AND an "Add pipeline" button MUST be visible
    - AND each pipeline MUST have edit and delete actions
    - AND delete MUST show a confirmation dialog

- [x] 3.2 Create PipelineForm.vue with inline stage editor
  - **spec_ref**: `specs/pipeline-foundation/spec.md#REQ-PF-003, #REQ-PF-004`
  - **files**: `pipelinq/src/views/settings/PipelineForm.vue`
  - **acceptance_criteria**:
    - GIVEN the pipeline form
    - THEN title (required), description (optional), entity type (required), and isDefault (toggle) MUST be editable
    - AND a stage list MUST be displayed with name, order, probability, isClosed, isWon, and color for each stage
    - AND the admin MUST be able to add new stages, edit existing stages, and remove stages
    - AND move up/down buttons MUST allow reordering stages
    - AND validation MUST enforce: title required, at least one non-closed stage, isWon requires isClosed, stage name required

## 4. Settings Integration

- [x] 4.1 Integrate PipelineManager into Settings.vue
  - **spec_ref**: `specs/pipeline-foundation/spec.md#REQ-PF-003`
  - **files**: `pipelinq/src/views/settings/Settings.vue`
  - **acceptance_criteria**:
    - GIVEN the admin settings page
    - THEN the PipelineManager component MUST be rendered below the existing schema section
    - AND the pipeline list MUST load on mount via objectStore.fetchCollection('pipeline')

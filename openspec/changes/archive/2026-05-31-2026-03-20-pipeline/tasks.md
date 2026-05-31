# Tasks: pipeline search and stage validation

## 1. Pipeline Search Bar (REQ-PIPE-022)

- [x] 1.1 Add `searchQuery` data property to `PipelineBoard.vue`
  - **spec_ref**: `specs/pipeline/spec.md#REQ-PIPE-022`
  - **files**: `src/views/pipeline/PipelineBoard.vue`
  - **acceptance_criteria**:
    - GIVEN the pipeline board is loaded
    - THEN a `searchQuery` string data property exists, initialised to `''`
    - AND the property is reset to `''` whenever the active pipeline changes

- [x] 1.2 Add `filteredItems` computed property that applies `searchQuery` filter to `allItems`
  - **spec_ref**: `specs/pipeline/spec.md#REQ-PIPE-022`
  - **files**: `src/views/pipeline/PipelineBoard.vue`
  - **acceptance_criteria**:
    - GIVEN `searchQuery` is empty
    - THEN `filteredItems` equals `allItems` (no items removed)
    - GIVEN `searchQuery` is `'gemeente'`
    - THEN `filteredItems` contains only items whose `title` includes `'gemeente'` (case-insensitive)
    - AND the column renderer uses `filteredItems` instead of `allItems`

- [x] 1.3 Render `NcTextField` search input in the pipeline header
  - **spec_ref**: `specs/pipeline/spec.md#REQ-PIPE-022`
  - **files**: `src/views/pipeline/PipelineBoard.vue`
  - **acceptance_criteria**:
    - GIVEN the pipeline board renders
    - THEN an `NcTextField` (type="search") with placeholder `t(appName, 'Search pipeline...')` MUST appear in the header
    - AND the input is bound to `searchQuery` via `v-model`
    - AND the input is positioned between the pipeline selector and the "Show" filter
    - AND the input has an associated `aria-label` for WCAG compliance

- [x] 1.4 Add translation keys for the search input
  - **files**: `l10n/en.json`, `l10n/nl.json`
  - **acceptance_criteria**:
    - `'Search pipeline...'` key added to `en.json`
    - Dutch equivalent `'Zoek in pijplijn...'` added to `nl.json`

## 2. Stage Probability Validation (REQ-PIPE-005 Scenario 24)

- [x] 2.1 Add per-stage `probabilityError` tracking to `PipelineForm.vue`
  - **spec_ref**: `specs/pipeline/spec.md#REQ-PIPE-005-scenario-24`
  - **files**: `src/views/settings/PipelineForm.vue`
  - **acceptance_criteria**:
    - GIVEN the stage editor renders stage rows
    - THEN each stage row object in the stages array includes a `probabilityError` string (empty string = no error)
    - AND `probabilityError` is initialised to `''` when a stage row is created or loaded

- [x] 2.2 Add `validateProbability(stage)` method and wire to stage probability `@input`
  - **spec_ref**: `specs/pipeline/spec.md#REQ-PIPE-005-scenario-24`
  - **files**: `src/views/settings/PipelineForm.vue`
  - **acceptance_criteria**:
    - GIVEN the admin types `120` into a stage probability field
    - THEN `stage.probabilityError` is set to `t(appName, 'Probability must be between 0 and 100')`
    - GIVEN the admin types `75` (valid)
    - THEN `stage.probabilityError` is reset to `''`
    - GIVEN the admin clears the field (empty / null)
    - THEN `stage.probabilityError` is `''` (empty probability is valid)
    - AND boundary values `0` and `100` are treated as valid (no error)

- [x] 2.3 Render inline error message below the probability input
  - **spec_ref**: `specs/pipeline/spec.md#REQ-PIPE-005-scenario-24`
  - **files**: `src/views/settings/PipelineForm.vue`
  - **acceptance_criteria**:
    - GIVEN `stage.probabilityError` is non-empty
    - THEN the error string MUST be displayed immediately below the probability `<input>` element
    - AND the error text MUST use `color: var(--color-error)`
    - AND the error disappears (v-if) when `stage.probabilityError` is `''`

- [x] 2.4 Disable the save button while any stage has a validation error
  - **spec_ref**: `specs/pipeline/spec.md#REQ-PIPE-005-scenario-24`
  - **files**: `src/views/settings/PipelineForm.vue`
  - **acceptance_criteria**:
    - GIVEN at least one stage has a non-empty `probabilityError`
    - THEN the form save/submit button MUST have `disabled` attribute and `aria-disabled="true"`
    - GIVEN all `probabilityError` values are `''`
    - THEN the save button MUST be enabled

- [x] 2.5 Add translation key for the probability validation error
  - **files**: `l10n/en.json`, `l10n/nl.json`
  - **acceptance_criteria**:
    - `'Probability must be between 0 and 100'` added to `en.json`
    - Dutch equivalent `'Kans moet tussen 0 en 100 liggen'` added to `nl.json`

## 3. Verification

- [x] 3.1 Run `npm run build` and verify zero build errors
- [x] 3.2 Open pipeline board, type a partial lead title — verify matching cards appear, others hide, column counts update
- [x] 3.3 Clear the search — verify full board is restored without reloading the page
- [x] 3.4 Switch pipeline with active search — verify search input clears and new pipeline loads fully
- [x] 3.5 Open PipelineForm, set a stage probability to 150 — verify inline error appears and save is disabled
- [x] 3.6 Change the value to 75 — verify error disappears and save is re-enabled
- [x] 3.7 Leave probability blank — verify no error and form is saveable

# Tasks: example-data-out-of-the-register

## Phase 1: Move the records

- [x] 1.1 Move every example record out of `pipelinq_register.json` and `register.d/*.json` into `lib/Settings/pipelinq_example_register.json` (type `mock`), keeping reference data in the register
  - **spec_ref**: `specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed`
- [x] 1.2 Convert the ten `contactmoment` example records onto the `ticket` schema as `interaction` tickets
  - **spec_ref**: `specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed`
- [x] 1.3 Give the eleven refused example leads a pipeline and a client, and merge the three duplicated example clients
  - **spec_ref**: `specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed`

## Phase 2: Import and remove on request

- [x] 2.1 Add `DemoRegisterImporter`: import under `pipelinq.demo`, remove by recorded job and by schema, slug and unchanged name
  - **spec_ref**: `specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed`
- [x] 2.2 Call it right after the seeder in both entry points, the wizard's load action (`SetupController`) and `occ pipelinq:demo:seed` (with `--remove`), so both import and remove the same set
  - **spec_ref**: `specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed`

## Phase 3: Tests

- [x] 3.1 `RegisterCarriesNoExampleDataTest`: the register seeds reference schemas only, the example descriptor is a mock, every example record can be imported
- [x] 3.2 `DemoRegisterImporterTest`: own identity on import, only unchanged records removed
- [x] 3.3 Point the booking, competitor watch and seed service tests at the example descriptor

# first-time-setup Specification

## Purpose
TBD - created by archiving change pipelinq-setup-wizard-complete. Update Purpose after archive.
## Requirements
### Requirement: REQ-SETUP-PIP-004 — Optional Provisioning Action

pipelinq SHALL expose a `provision-register` setup action (`POST /apps/pipelinq/api/setup/action/provision-register`, admin-only) that imports the pipelinq OpenRegister register + schemas and (re)creates the default pipelines, skills, lead sources and request channels by delegating to the same provisioning the install-time repair step uses. The action SHALL be idempotent and SHALL fail gracefully with a precondition error when OpenRegister is not installed. It SHALL NOT be required and SHALL NOT gate the app.

#### Scenario: Provision on demand after enabling OpenRegister later

- **GIVEN** an admin opens the setup wizard and the `provision` step
- **WHEN** the admin runs the `provision-register` action
- **THEN** the pipelinq register, default pipelines and skills SHALL exist
- **AND** running it again SHALL succeed without creating duplicates

#### Scenario: Provision blocked without OpenRegister

@e2e exclude the precondition cannot be created on the CI instance: OpenRegister is a hard dependency there — .github/workflows/code-quality.yml installs it via `additional-apps`, and tests/e2e/ci-seed.sh aborts the entire run with `::error::The 'pipelinq' register is missing` if it is not present — so no browser session can reach an instance where `IAppManager::isInstalled('openregister')` is false. The guard itself is a single early return in SetupController::provisionRegister() yielding HTTP 412 with "OpenRegister is not installed — install and enable it, then run this step." KNOWN COVERAGE GAP, stated rather than papered over: there is no SetupControllerTest, so this branch has no automated assertion at any level. The other half of the scenario — that the step is optional and the app stays usable — IS asserted by tests/e2e/spec-coverage/first-time-setup.spec.ts ("setup status reports every step, and only currency gates completion").

- **GIVEN** OpenRegister is not installed
- **WHEN** the admin runs the `provision-register` action
- **THEN** the action SHALL return a failure with a message to install OpenRegister first
- **AND** the wizard SHALL remain usable (the step is optional)

### Requirement: REQ-SETUP-PIP-005 — Optional Organisation Details

pipelinq SHALL offer an optional `organisation` setup step (`config-fields`) that persists `receipt_company_name`, `receipt_company_vat` and `receipt_company_kvk` app-config keys via `POST /apps/pipelinq/api/setup/config`. The step SHALL be skippable and SHALL NOT gate the app.

#### Scenario: Organisation details persist

- **GIVEN** an admin enters an organisation name and VAT number in the `organisation` step
- **WHEN** the step is advanced
- **THEN** the `receipt_company_name` and `receipt_company_vat` app-config keys SHALL hold the entered values

### Requirement: REQ-SETUP-PIP-006 — Optional Integration Configuration

pipelinq SHALL offer an optional `integrations` setup step (`config-fields`) that persists `shillinq_app_url` and `xwiki_direct_url` app-config keys via `POST /apps/pipelinq/api/setup/config`. Leaving a field blank SHALL leave the corresponding integration disabled. The step SHALL be skippable and SHALL NOT gate the app.

#### Scenario: Shillinq URL persists and enables the integration entry point

@e2e exclude there is no read path a browser can use to observe this key. `shillinq_app_url` is written by `POST /api/setup/config` and read back only through `GET /api/setup/status`'s derived `integrations.done` flag — and on the CI instance that flag is ALREADY true regardless of the value, because SetupController::status() computes `integrationsDone` as `($shillinqUrl !== '' || $xwikiUrl !== '' || (hasShillinq === false && hasXwiki === false))` and neither `shillinq` nor `openconnector` is installed (.github/workflows/code-quality.yml pins `additional-apps` to openregister only). So setting the URL produces no distinguishable state, and the "integration entry point" it enables belongs to an app that is not there. The write path itself — `saveConfig()` persisting an arbitrary posted key and the step flipping to done — IS asserted end to end over the same endpoint by tests/e2e/spec-coverage/first-time-setup.spec.ts ("organisation details persist and flip the optional step to done"), using `receipt_company_name`, whose derived flag is observable.

- **GIVEN** an admin enters a Shillinq base URL in the `integrations` step
- **WHEN** the step is advanced
- **THEN** the `shillinq_app_url` app-config key SHALL hold the entered URL

### Requirement: REQ-SETUP-PIP-007 — Per-Step Status Reporting

`GET /apps/pipelinq/api/setup/status` SHALL report `done` state for the `currency`, `provision`, `organisation` and `integrations` steps. Only the `currency` step SHALL determine `completed` and the writing of `setup_completed_version`.

#### Scenario: Optional steps report done without gating

- **GIVEN** `currency` is set and the optional steps are unset
- **WHEN** the wizard queries status
- **THEN** `completed` SHALL be true
- **AND** the optional steps' `done` flags SHALL reflect their individual config state

### Requirement: REQ-SETUP-PIP-008 — Optional Demo-Data Seed

The system SHALL provide an idempotent demo-data seed invocable two ways from one write path: an occ command `pipelinq:demo:seed` (mirroring the procest `SeedBezwaarBeroepCommand` pattern, including explicit owner context because occ runs without a session) and an optional setup-wizard action (`seed-demo-data`, admin-only) per ADR-042. The seed SHALL create a small coherent linked dataset — clients (person and organisation), leads across pipeline stages, requests across statuses, and contactmomenten across channels — such that lists, dashboards, and the 360° client view render populated. Seeded objects SHALL be identifiable as demo data, re-running SHALL create no duplicates, and a removal mode SHALL delete exactly the seeded objects.

**Feature tier**: MVP

#### Scenario: Seed on a clean install

- GIVEN a clean install with provisioned registers
- WHEN `occ pipelinq:demo:seed` runs
- THEN clients, leads, requests, and contactmomenten MUST exist, linked so customer-360 and the dashboards render populated
- AND every seeded object MUST be identifiable as demo data

#### Scenario: Idempotent re-run

- GIVEN a completed seed
- WHEN the command runs again
- THEN no duplicate objects MUST be created

#### Scenario: Offered as an optional wizard step

- GIVEN an admin in the first-time setup wizard
- WHEN the optional steps are presented
- THEN a demo-data action MUST be offered, invoking the same seeding service as the occ command
- AND skipping it MUST NOT block setup completion

#### Scenario: Removal deletes exactly the seed

@e2e exclude removal is an `occ pipelinq:demo:seed --remove` CLI invocation with no HTTP or UI entry point, and running it inside the browser suite would delete the demo dataset every other spec in `tests/e2e/` renders against — the assertion would destroy the fixture the rest of the run depends on. Its whole point is also a NEGATIVE claim ("no real object MUST be touched"), which needs a controlled before/after over objects of known provenance. Asserted by tests/Unit/Service/DemoSeedServiceTest.php (testRemoveDeletesExactlyTheSeededSet, testRemoveRetainsArchivalSchemaRows, testRemoveSkipsMissingObjects).

- GIVEN a seeded install with additional real data
- WHEN the removal mode runs
- THEN all seeded objects MUST be deleted and no real object MUST be touched


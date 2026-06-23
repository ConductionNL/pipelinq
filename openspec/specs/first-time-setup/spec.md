# first-time-setup Specification

## Purpose
TBD - created by archiving change pipelinq-setup-wizard-complete. Update Purpose after archive.
## Requirements
### Requirement: REQ-SETUP-PIP-004 — Optional Provisioning Action

pipelinq SHALL expose a `provision-register` setup action (`POST /apps/pipelinq/api/setup/action/provision-register`, admin-only) that imports the pipelinq OpenRegister register + schemas and (re)creates the default pipelines, queues, skills, lead sources and request channels by delegating to the same provisioning the install-time repair step uses. The action SHALL be idempotent and SHALL fail gracefully with a precondition error when OpenRegister is not installed. It SHALL NOT be required and SHALL NOT gate the app.

#### Scenario: Provision on demand after enabling OpenRegister later

- **GIVEN** an admin opens the setup wizard and the `provision` step
- **WHEN** the admin runs the `provision-register` action
- **THEN** the pipelinq register, default pipelines, queues and skills SHALL exist
- **AND** running it again SHALL succeed without creating duplicates

#### Scenario: Provision blocked without OpenRegister

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


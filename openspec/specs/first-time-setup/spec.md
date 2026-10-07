# first-time-setup Specification

## Purpose
TBD - created by archiving change pipelinq-setup-wizard-complete. Update Purpose after archive.

## Requirements

### Requirement: REQ-SETUP-PIP-004 — Optional Provisioning Action

pipelinq SHALL expose a `provision-register` setup action (`POST /apps/pipelinq/api/setup/action/provision-register`, admin-only) that imports the pipelinq register and schemas and (re)creates the default pipelines and skills. The action SHALL be idempotent and SHALL fail with a precondition error when OpenRegister is not installed. The setup wizard SHALL NOT offer it as a step. The pipelinq admin settings page SHALL offer it as a "Provision data" action card.

#### Scenario: Provision on demand after enabling OpenRegister later

- GIVEN an administrator who enabled OpenRegister after pipelinq, on the pipelinq admin settings page
- WHEN the administrator clicks Provision data
- THEN the `provision-register` action SHALL run and its message SHALL show on the card
- AND the pipelinq register, default pipelines and skills SHALL exist
- AND running it again SHALL create no duplicates

@e2e exclude the admin card is a thin button over the action; the action itself is asserted by tests/e2e/spec-coverage/first-time-setup.spec.ts ("provision-register is idempotent and leaves the register populated").

#### Scenario: Provision blocked without OpenRegister

@e2e exclude the precondition cannot be created on the CI instance: OpenRegister is a hard dependency there (.github/workflows/code-quality.yml installs it via `additional-apps`, and tests/e2e/ci-seed.sh aborts the run when the pipelinq register is missing). The guard is a single early return in SetupController::provisionRegister() yielding HTTP 412. Known coverage gap: no unit test asserts this branch.

- GIVEN OpenRegister is not installed
- WHEN an administrator runs the `provision-register` action
- THEN the action SHALL return a failure with a message to install OpenRegister first
- AND the app SHALL remain usable, because provisioning gates nothing

#### Scenario: The wizard does not offer provisioning

- GIVEN the pipelinq manifest
- WHEN the setup wizard lists its steps
- THEN no step SHALL run `provision-register`

@e2e exclude a static manifest property, asserted by tests/Unit/Controller/SetupControllerStatusContractTest.php (testTheWizardNoLongerAsksForProvisioningOrBaseUrls).

### Requirement: REQ-SETUP-PIP-005 — Optional Organisation Details

pipelinq SHALL offer an optional `organisation` setup step (`config-fields`) that asks, in this order: organisation name, Chamber of Commerce (KvK) number, VAT number, street and number, postcode, city, country, email address, phone number and website. The values SHALL persist as the app-config keys `receipt_company_name`, `receipt_company_kvk`, `receipt_company_vat`, `receipt_company_street`, `receipt_company_postcode`, `receipt_company_city`, `receipt_company_country`, `receipt_company_email`, `receipt_company_phone` and `receipt_company_website`. A receipt SHALL print `receipt_company_address` when it is set, and otherwise the address composed from the street, postcode, city and country. The step SHALL be skippable and SHALL NOT gate the app.

#### Scenario: The organisation step asks for the name first

- GIVEN the organisation step
- WHEN the wizard renders its fields
- THEN the order SHALL be name, KvK number, VAT number, then the address fields, then the contact fields

@e2e exclude the order is the `order` value on each field of the manifest step; the rendering is nextcloud-vue's.

#### Scenario: The receipt address follows the organisation step

- GIVEN the administrator filled in street, postcode, city and country and no one-line address
- WHEN a receipt is printed
- THEN its header SHALL show "street, postcode city, country"

@e2e exclude asserted by tests/Unit/Service/ReceiptCompanyAddressTest.php.

#### Scenario: Organisation details persist

- GIVEN an administrator enters an organisation name and VAT number in the `organisation` step
- WHEN the step is advanced
- THEN the `receipt_company_name` and `receipt_company_vat` app-config keys SHALL hold the entered values

### Requirement: REQ-SETUP-PIP-007 — Per-Step Status Reporting

`GET /apps/pipelinq/api/setup/status` SHALL report a `done` flag for exactly the step ids the manifest declares: `welcome`, `demo-data`, `currency`, `organisation` and `done`. Only the `currency` step SHALL determine `completed` and the writing of `setup_completed_version`.

#### Scenario: Status and manifest agree

- GIVEN the pipelinq manifest
- WHEN status is requested
- THEN the reported step ids SHALL equal the manifest's step ids

@e2e exclude asserted by tests/Unit/Controller/SetupControllerStatusContractTest.php; the browser half is tests/e2e/spec-coverage/first-time-setup.spec.ts.

#### Scenario: Optional steps report done without gating

- GIVEN `currency` is set and the optional steps are unset
- WHEN the wizard queries status
- THEN `completed` SHALL be true
- AND the `demo-data` and `organisation` steps' `done` flags SHALL reflect their individual config state

### Requirement: REQ-SETUP-PIP-008 — Optional Demo-Data Seed

The system SHALL provide an idempotent demo-data seed invocable two ways from one write path: an occ command `pipelinq:demo:seed` and the setup wizard's example data step. The seed SHALL create a coherent linked dataset (clients, contacts, leads across pipeline stages, tickets, products, point of sale, bookings and marketing records) such that lists, dashboards and the 360° client view render populated. Seeded objects SHALL be identifiable as demo data, re-running SHALL create no duplicates, and a removal mode SHALL delete exactly the seeded objects.

The setup wizard SHALL offer the example datasets as one choice step of cards. The step SHALL declare `loadAction: load-demo-data`, so each card carries its own Load button. The `load-demo-data` action SHALL accept `{ dataset }` in its body, SHALL refuse a dataset id that is not offered, and SHALL store the pick only after the load succeeded. Without a body it SHALL load the stored pick. Picking a card, "None" included, SHALL answer the step.

The register descriptor (`pipelinq_register.json` and `register.d/*.json`) SHALL carry reference data only. Example records SHALL ship in an on-demand descriptor of type `mock`, imported under the configuration identity `pipelinq.demo`, and never by install, upgrade or the provisioning action.

**Feature tier**: MVP

#### Scenario: A card loads its own dataset

- GIVEN the example data step
- WHEN the administrator clicks Load on the Example data card
- THEN the wizard SHALL post `{ "dataset": "demo" }` to the `load-demo-data` action
- AND the example data SHALL be seeded and the step SHALL report done

@e2e exclude the Load button is rendered by nextcloud-vue once `loadAction` ships; the server half is asserted by tests/Unit/Controller/SetupControllerDemoDataTest.php (testTheCardLoadButtonSeedsTheDatasetItNames).

#### Scenario: A failed load leaves the step open

- GIVEN the seed fails
- WHEN the administrator clicks Load
- THEN the failure SHALL be reported and no pick SHALL be stored

@e2e exclude asserted by tests/Unit/Controller/SetupControllerDemoDataTest.php (testAFailedCardLoadLeavesTheStepOpen).

#### Scenario: Declining example data means no example data

- GIVEN a clean install
- WHEN the administrator picks "None" at the example data step and completes setup
- AND the register is provisioned, upgraded or re-provisioned
- THEN no example record MUST exist
- AND only the reference records of the register descriptor MUST have been imported

@e2e exclude a clean install per test is not available to the browser suite. Asserted by tests/Unit/Settings/RegisterCarriesNoExampleDataTest.php (testTheRegisterSeedsReferenceDataOnly, testTheExampleDescriptorIsOnDemand).

#### Scenario: Seed on a clean install

- GIVEN a clean install with provisioned registers
- WHEN `occ pipelinq:demo:seed` runs, or the administrator loads the example data card in the wizard
- THEN clients, leads, tickets, products, point of sale, booking and marketing records MUST exist, linked so customer-360 and the dashboards render populated
- AND every seeded object MUST be identifiable as demo data

#### Scenario: Idempotent re-run

- GIVEN a completed seed
- WHEN the command runs again
- THEN no duplicate objects MUST be created

#### Scenario: Offered as an optional wizard step

- GIVEN an administrator in the first-time setup wizard
- WHEN the example data step is presented
- THEN its cards' Load buttons MUST invoke the same seeding service as the occ command
- AND picking "None" or leaving the step unanswered MUST NOT block setup completion, which only the currency step decides

#### Scenario: Removal deletes exactly the seed

@e2e exclude removal is an `occ pipelinq:demo:seed --remove` CLI invocation with no HTTP or UI entry point, and running it inside the browser suite would delete the fixture the rest of the run depends on. Asserted by tests/Unit/Service/DemoSeedServiceTest.php and tests/Unit/Service/Demo/DemoRegisterImporterTest.php.

- GIVEN a seeded install with additional real data
- WHEN the removal mode runs
- THEN all seeded objects MUST be deleted and no real object MUST be touched

#### Scenario: Removal also clears what an old register seeded

@e2e exclude same CLI-only reason as above. Asserted by tests/Unit/Service/Demo/DemoRegisterImporterTest.php (testRemoveDeletesOnlyUnchangedExampleRecords).

- GIVEN an install that received example records from an earlier register descriptor
- WHEN `occ pipelinq:demo:seed --remove` runs
- THEN every such record whose schema, slug and name still match the example descriptor MUST be deleted
- AND a record whose name was changed since MUST be kept

### Requirement: REQ-SETUP-PIP-006 — Detected Integrations

pipelinq SHALL NOT ask for a Shillinq or XWiki base URL. It SHALL detect Shillinq as an installed app and SHALL link to it on this server. It SHALL detect XWiki through the XWiki Nextcloud app, or through OpenRegister's `xwiki` integration when OpenRegister and integriq are installed. The admin settings page SHALL show both detections read only. The billing deep link SHALL use the detected Shillinq URL and SHALL be empty when Shillinq is not installed.

#### Scenario: Shillinq is detected

- GIVEN Shillinq is installed
- WHEN the billing availability is requested
- THEN `deepLinkUrl` SHALL be this server's `/index.php/apps/shillinq/`

@e2e exclude the CI instance installs no Shillinq; asserted by tests/Unit/Service/IntegrationDetectorTest.php.

#### Scenario: XWiki through OpenRegister

- GIVEN OpenRegister and integriq are installed and the XWiki app is not
- WHEN the admin settings page loads
- THEN it SHALL say XWiki is connected through OpenRegister and integriq

@e2e exclude asserted by tests/Unit/Service/IntegrationDetectorTest.php (testOpenRegisterWithIntegriqCarriesXwiki).

### Requirement: REQ-SETUP-PIP-009 — Required Apps Gate The Wizard

The manifest `dependencies` SHALL list `openregister` as the only required app; every other entry SHALL be `required: false`. The setup wizard SHALL read that list to refuse to continue while a required app is missing.

#### Scenario: OpenRegister missing

- GIVEN OpenRegister is not installed
- WHEN the setup wizard opens
- THEN it SHALL name OpenRegister as missing and SHALL NOT offer the steps

@e2e exclude the gate is rendered by nextcloud-vue; OpenRegister is always installed on the CI instance.

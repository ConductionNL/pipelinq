# First-time setup: review fixes

Delta over `specs/first-time-setup`.

## MODIFIED Requirements

### Requirement: REQ-SETUP-PIP-004 — Optional Provisioning Action

pipelinq SHALL expose a `provision-register` setup action (`POST /apps/pipelinq/api/setup/action/provision-register`, admin-only) that imports the pipelinq register and schemas and (re)creates the default pipelines and skills. The action SHALL be idempotent and SHALL fail with a precondition error when OpenRegister is not installed. The setup wizard SHALL NOT offer it as a step. The pipelinq admin settings page SHALL offer it as a "Provision data" action card.

#### Scenario: Provision from the admin settings page

- GIVEN an administrator on the pipelinq admin settings page
- WHEN the administrator clicks Provision data
- THEN the `provision-register` action SHALL run and its message SHALL show on the card
- AND running it again SHALL create no duplicates

@e2e exclude the admin card is a thin button over the action; the action itself is asserted by tests/e2e/spec-coverage/first-time-setup.spec.ts ("provision-register is idempotent and leaves the register populated").

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

### Requirement: REQ-SETUP-PIP-007 — Per-Step Status Reporting

`GET /apps/pipelinq/api/setup/status` SHALL report a `done` flag for exactly the step ids the manifest declares: `welcome`, `demo-data`, `currency`, `organisation` and `done`. Only the `currency` step SHALL determine `completed` and the writing of `setup_completed_version`.

#### Scenario: Status and manifest agree

- GIVEN the pipelinq manifest
- WHEN status is requested
- THEN the reported step ids SHALL equal the manifest's step ids

@e2e exclude asserted by tests/Unit/Controller/SetupControllerStatusContractTest.php; the browser half is tests/e2e/spec-coverage/first-time-setup.spec.ts.

### Requirement: REQ-SETUP-PIP-008 — Optional Demo-Data Seed

The setup wizard SHALL offer the example datasets as one choice step of cards. The step SHALL declare `loadAction: load-demo-data`, so each card carries its own Load button. The `load-demo-data` action SHALL accept `{ dataset }` in its body, SHALL refuse a dataset id that is not offered, and SHALL store the pick only after the load succeeded. Without a body it SHALL load the stored pick. Picking a card, "None" included, SHALL answer the step.

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

## REMOVED Requirements

### Requirement: REQ-SETUP-PIP-006 — Optional Integration Configuration

Replaced by REQ-SETUP-PIP-006 Detected integrations below. The wizard no longer asks for `shillinq_app_url` or `xwiki_direct_url`.

## ADDED Requirements

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

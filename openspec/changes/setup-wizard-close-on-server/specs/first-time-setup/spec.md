# first-time-setup delta: setup-wizard-close-on-server

## ADDED Requirements

### Requirement: REQ-SETUP-PIP-010 — A Closed Wizard Stays Closed In Every Browser

The manifest SHALL declare `setup.dismissAction: dismiss-setup`. The `dismiss-setup` setup action (admin-only) SHALL store the current setup version in the app-config key `setup_dismissed_version` and SHALL write no other key. `GET /apps/pipelinq/api/setup/status` SHALL return `dismissed`: the stored version as a number, or `false` when none is stored or the stored value is not a number. The admin settings card "Run the setup wizard again" SHALL open the wizard whatever the status or the browser reports about a close.

#### Scenario: Closing the wizard is recorded on the server

- GIVEN an administrator with an open optional setup step
- WHEN they close or finish the setup wizard
- THEN CnAppRoot SHALL post `dismiss-setup`
- AND the status SHALL report `dismissed` equal to `version`

@e2e exclude asserted by tests/Unit/Controller/SetupControllerDismissTest.php and the live check in tasks.md 4.1.

#### Scenario: A fresh browser does not open the wizard again

- GIVEN the wizard was closed in one browser
- WHEN the administrator opens pipelinq in a browser with empty storage
- THEN the setup wizard SHALL NOT open by itself

@e2e exclude needs two browser contexts on one instance; asserted by the live check in tasks.md 4.1.

#### Scenario: Closing overwrites no choice

- GIVEN a dataset and a currency are stored and the organisation step is open
- WHEN `dismiss-setup` runs
- THEN only `setup_dismissed_version` SHALL be written
- AND the organisation step SHALL still report `done: false`

@e2e exclude asserted by tests/Unit/Controller/SetupControllerDismissTest.php.

#### Scenario: The admin card still opens the wizard

- GIVEN the wizard was closed on the server
- WHEN an administrator clicks "Run the setup wizard again" on the admin page
- THEN the setup wizard SHALL open with the manifest's steps

@e2e exclude asserted by tests/vitest/setupWizardRerun.spec.js and the live check in tasks.md 4.2.

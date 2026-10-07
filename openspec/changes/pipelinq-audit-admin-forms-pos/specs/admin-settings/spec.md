# Admin settings: audit fixes

Delta over `specs/admin-settings`.

## ADDED Requirements

### Requirement: Shillinq hand-offs follow the detected app

Pipelinq SHALL hand approved hours (WIP) and approved expenses (AP) to Shillinq only when the Shillinq app is installed on the same server, and SHALL reach it internally through OpenRegister. The admin settings page SHALL NOT ask for a Shillinq webhook URL. The Shillinq options section SHALL only show when Shillinq is installed; otherwise the Detected integrations card SHALL say Shillinq is not installed.

#### Scenario: Shillinq installed, no address typed

- GIVEN the Shillinq app is installed
- WHEN a time entry or an expense is approved
- THEN pipelinq SHALL dispatch the CloudEvent without any configured URL

@e2e exclude backend dispatch, asserted by tests/Unit/Service/ShillinqWipServiceTest.php and tests/Unit/Service/ShillinqApServiceTest.php.

#### Scenario: Shillinq not installed

- GIVEN the Shillinq app is not installed
- WHEN an administrator opens the pipelinq admin settings
- THEN no Shillinq webhook URL field SHALL show
- AND the Detected integrations card SHALL say Shillinq is not installed

@e2e tests/e2e/spec-coverage/expense-shillinq-ap.spec.ts

#### Scenario: The xWiki test names no direct URL

- GIVEN xWiki is not reachable
- WHEN the administrator tests the xWiki connection
- THEN the message SHALL point at the xWiki app or OpenRegister with integriq, never at a direct URL

@e2e exclude a static message, asserted by tests/vitest/adminSettingsNoServiceUrls.spec.js.

### Requirement: The setup wizard can run again from the admin page

The pipelinq admin settings page SHALL offer a "Run the setup wizard again" action that opens the setup wizard with the manifest's own `setup.steps`. Closing or finishing the wizard SHALL return to the admin page.

#### Scenario: Reopen the wizard

- GIVEN an administrator who closed the setup wizard earlier
- WHEN the administrator clicks Run the setup wizard again on the admin settings page
- THEN the setup wizard SHALL open with the same steps as the first run
- AND closing it SHALL leave the administrator on the admin settings page

@e2e exclude the card is a button over nextcloud-vue's CnSetupWizard, asserted by tests/vitest/setupWizardRerun.spec.js.

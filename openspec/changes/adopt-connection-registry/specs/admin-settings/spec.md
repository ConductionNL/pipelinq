## ADDED Requirements

### Requirement: REQ-AS-130: Pipelinq declares its outside connections in one static file

Pipelinq SHALL declare its outside connections in `lib/Settings/connections.json`
in the shape of hydra connection-registry design D2, validating against
integriq's `connections.schema.json` (hydra REQ-CONN-001). The file SHALL name
`pipelinq` as its app and SHALL declare `cti`, `social-mastodon`,
`social-bluesky`, `social-linkedin`, `social-x`, `social-facebook`,
`social-instagram`, `social-threads`, `berichtenbox` and `mail-provider`, and no
connection that has no working code path on `development`. A `settingsUrl`
SHALL only point at a section or page that exists. Berichtenbox SHALL require
the four app-config keys a dispatch needs. No entry SHALL declare an `adapter`
block, because no pipelinq connection picks its adapter with an app-config key.

**Feature tier**: MVP

#### Scenario: The declaration names ten connections in page order
@e2e tests/e2e/integrations-page.spec.ts

- **GIVEN** pipelinq and integriq are installed and integriq has synced pipelinq's declaration
- **WHEN** an admin opens the Integrations page from the settings foldout
- **THEN** the page SHALL list the ten declared connections in declared order
- **AND** every listed row SHALL have `app` equal to `pipelinq`

#### Scenario: Berichtenbox names the keys it waits for
@e2e tests/e2e/integrations-page.spec.ts

- **GIVEN** none of `logius_client_id`, `logius_client_secret`, `pki_cert` and `pki_key` holds a value
- **WHEN** the admin reads the Berichtenbox row
- **THEN** it SHALL read Not configured
- **AND** its message SHALL name the four keys and `occ config:app:set`
- **AND** it SHALL NOT offer Open settings

#### Scenario: Every settings link lands on something that exists
@e2e exclude Asserted on the shipped files: tests/Unit/Settings/ConnectionsDeclarationTest.php checks each anchor against CtiPage.vue and DeliverabilitySettings.vue and the social route against src/manifest.d/78-social-publishing.json.

- **GIVEN** the declaration
- **WHEN** a row carries a `settingsUrl`
- **THEN** the anchor or route it names SHALL exist in pipelinq's source

### Requirement: REQ-AS-131: An admin reads pipelinq's connections on an Integrations page over integriq's registry

Pipelinq SHALL render an `index` page at `/settings/integrations` over
integriq's `app_connection` schema that SHALL declare Integriq as the app it
requires (hydra REQ-CONN-006). Its menu entry SHALL sit in the settings
foldout under the Integrations caption, SHALL carry `query` `app=pipelinq`,
and SHALL only render when integriq is installed. The page SHALL NOT offer the
generic Add button. Its Add integration action SHALL open integriq's
Connections overview with `app=pipelinq&link=1` (hydra design D9). Status
values SHALL render through `connectionStatus` and the settings link through
`connectionSettingsLabel`.

**Feature tier**: MVP

#### Scenario: The menu opens the page on pipelinq's own rows
@e2e tests/e2e/integrations-page.spec.ts

- **GIVEN** integriq holds connection rows for pipelinq and for other apps
- **WHEN** an admin chooses Connections in the settings foldout
- **THEN** the URL SHALL carry `app=pipelinq`
- **AND** only rows with `app` equal to `pipelinq` SHALL be listed

#### Scenario: Without integriq the page says what is missing
@e2e exclude The shared e2e instance installs integriq, so no browser flow reaches a pipelinq without it; tests/vitest/connectionRegistry.spec.js asserts the page declares requiresApp integriq and the menu entry visibleIf.appInstalled integriq, and CnPageRenderer renders the screen.

- **GIVEN** integriq is not installed
- **WHEN** an admin opens `/settings/integrations` by URL
- **THEN** the missing-dependency screen SHALL name Integriq
- **AND** the settings foldout SHALL NOT list Connections

#### Scenario: Add integration goes to integriq, not to a form
@e2e tests/e2e/integrations-page.spec.ts

- **GIVEN** the Integrations page
- **WHEN** the admin looks for a way to add a connection
- **THEN** no generic Add button SHALL be offered
- **AND** the Add integration action SHALL open `/apps/integriq/connections?app=pipelinq&link=1`

### Requirement: REQ-AS-132: Pipelinq reports what its own checks observe

When the CTI Test connection runs, or a CTI settings save succeeds, pipelinq
SHALL send `OCA\Integriq\Event\ConnectionStatusReportedEvent` for `cti` with
`configured` when a platform is chosen and its adapter loads, `unconfigured`
when no platform is chosen, and `error` with the reason when the adapter does
not load. When the Social accounts list loads, pipelinq SHALL send one report
per network: `configured` for `ready`, `unavailable` for `preview` and
`unconfigured` for `not_configured`, each with the broker's reason. The event
class SHALL be named by string and sent only when it exists (ADR-041, hydra
REQ-CONN-004). A report SHALL NOT change the response of the request that sent
it, whether integriq is absent or its listener throws. Pipelinq SHALL NOT write
`status`, `statusMessage` or `checkedAt` on any row itself.

**Feature tier**: MVP

#### Scenario: A CTI test reaches the row
@e2e tests/e2e/integrations-page.spec.ts

- **GIVEN** an admin and no CTI platform chosen
- **WHEN** the admin runs Test connection in the telephony settings
- **THEN** pipelinq SHALL send a report for `cti` with status `unconfigured`
- **AND** the Telephony (CTI) row SHALL read Not configured on the next page load

#### Scenario: The social readiness reaches all seven rows
@e2e tests/e2e/integrations-page.spec.ts

- **GIVEN** OpenRegister's provider catalogue files no Threads application
- **WHEN** a marketer opens Social accounts
- **THEN** pipelinq SHALL send seven reports, one per network
- **AND** the Threads row SHALL read Not configured with the broker's reason

#### Scenario: Without integriq nothing is sent and nothing breaks
@e2e exclude The shared e2e instance installs integriq; tests/Unit/Service/ConnectionReportServiceTest.php asserts nothing is dispatched or logged when the event class is absent, and the controller tests assert the response is unchanged.

- **GIVEN** integriq is not installed
- **WHEN** the admin runs the CTI Test connection
- **THEN** no event SHALL be sent and no warning SHALL be logged
- **AND** the test's own response SHALL be unchanged

#### Scenario: A failing listener never reaches the check
@e2e exclude A listener that throws cannot be installed from a browser; tests/Unit/Service/ConnectionReportServiceTest.php asserts the exception is caught and logged.

- **GIVEN** integriq's report listener throws
- **WHEN** the Social accounts list loads
- **THEN** the list SHALL answer as it would without the report
- **AND** the failure SHALL be logged as a warning naming the connection

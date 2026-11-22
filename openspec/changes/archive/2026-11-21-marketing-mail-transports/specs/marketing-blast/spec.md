## MODIFIED Requirements

### Requirement: Send Via OpenConnector with Per-Tenant Provider

@e2e exclude dispatch resolves and sends through one of three transport kinds, and the CI instance does not install openconnector or the Mail app — .github/workflows/code-quality.yml pins `additional-apps` to openregister and planninq only — so no real send can occur in a browser run; the "no provider SDK" scenario is additionally a negative, static-analysis claim. Asserted by tests/Unit/Service/Marketing/MailTransportServiceTest.php (testResolveTransportPrefersNamedTransport, testResolveTransportFallsBackToDefault, testSendOneDeliveryDispatchesToConnectorSourceTransport, testSendOneDeliveryDispatchesToInstanceMailerTransport, testSendOneDeliveryDispatchesToMailAccountTransport) and tests/Unit/Service/BlastServiceTest.php (testDispatchBlastDeliveriesDelegatesToMailTransportService).

A Blast SHALL dispatch through the `mailTransport` resolved for it —
the instance mail server, the sender's Mail account, or an OpenConnector
source — and SHALL NOT embed provider credentials in Pipelinq code.

#### Scenario: Dispatch via openconnector send-mail action

- **GIVEN** a BlastDelivery queued for a Contact
- **WHEN** `MailTransportService` dispatches it
- **THEN** it SHALL resolve the blast's `mailTransport` (named by
  `transportId`, or the default), send through that transport's adapter, and
  store the returned provider id (when any) on the BlastDelivery

#### Scenario: Pipelinq code never touches provider credentials

- **GIVEN** a SendGrid API key configured on an OpenConnector source
- **WHEN** a Blast is sent through a `provider`-kind transport referencing
  that source
- **THEN** Pipelinq code SHALL NOT import a provider SDK, read the API key,
  or construct provider API requests directly
- **AND** all provider-kind sends SHALL delegate to OpenConnector's
  `CallService::call()` against the resolved source

#### Scenario: Instance mailer needs no OpenConnector source

- **GIVEN** a Blast resolved to the seeded `instance`-kind transport
- **WHEN** the Blast is sent
- **THEN** the send SHALL dispatch through the Nextcloud instance mail
  server and SHALL NOT require any `connectorSourceId` to be configured

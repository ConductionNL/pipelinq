## ADDED Requirements

### Requirement: BRP person lookup goes through integriq only

pipelinq SHALL perform every BRP person lookup through integriq's `brp-haalcentraal` source. It SHALL NOT hold BRP connection settings, OAuth2 credentials or mTLS material, and SHALL NOT call Haal Centraal directly. When integriq is not installed or the source cannot answer, the lookup SHALL fail with a message that names integriq, and the failed attempt SHALL be written to the audit trail as today. Purpose (doelbinding), BSN validation, the Wet BRP audit trail, the cache, retention and who may look up SHALL stay in pipelinq.

#### Scenario: A lookup reaches the BRP through integriq

- **GIVEN** integriq's `brp-haalcentraal` source is set up
- **WHEN** an agent looks up a person on the customer workplace with a purpose
- **THEN** the person SHALL be shown and the audit trail SHALL record the lookup

#### Scenario: Without integriq a lookup fails and says why

@e2e exclude needs an instance without integriq; covered by HaalCentraalClientTest.

- **GIVEN** integriq is not installed
- **WHEN** an agent looks up a person
- **THEN** the lookup SHALL fail with a message that BRP lookups go through integriq
- **AND** no direct call to Haal Centraal SHALL be made

### Requirement: pipelinq does not monitor the BRP connection

pipelinq SHALL NOT offer a BRP monitor page, a certificate expiry check or a performance report of the BRP connection. Those belong to integriq, which watches every connection, and to keepiq, which holds the certificate.

#### Scenario: The BRP monitor is not in pipelinq

- **GIVEN** an administrator in pipelinq
- **WHEN** they look for the BRP monitor
- **THEN** pipelinq SHALL offer no such page
- **AND** the BRP settings section SHALL say that the connection and its certificate are managed in integriq, with a link there

### Requirement: The BRP connection settings move to integriq once

A repair step SHALL move pipelinq's BRP connection settings to integriq's `brp-haalcentraal` source: the base URL, OAuth endpoint and client id as source settings, and the client secret, certificate, key and CA bundle as keepiq secrets the source references. It SHALL NOT overwrite a value already set in integriq. It SHALL clear pipelinq's keys only after integriq confirms every reference resolves. It SHALL never log or store a secret outside keepiq, and SHALL be idempotent.

#### Scenario: Settings move and the keys are cleared

@e2e exclude a repair step has no browser surface; covered by MoveBrpConnectionToIntegriqTest.

- **GIVEN** pipelinq holds a base URL, client id, client secret and certificate paths, and integriq's source has none
- **WHEN** the repair step runs
- **THEN** the source SHALL hold the base URL and client id and references to keepiq secrets
- **AND** pipelinq's `brp.*` connection keys SHALL be empty

#### Scenario: A value already set in integriq is kept

@e2e exclude a repair step has no browser surface; covered by MoveBrpConnectionToIntegriqTest.

- **GIVEN** integriq's source already holds a different base URL
- **WHEN** the repair step runs
- **THEN** integriq's base URL SHALL be kept and the report SHALL name the conflict

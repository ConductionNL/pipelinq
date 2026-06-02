# Marketing Segmentation and Blast — Blast and Attribution Services

## ADDED Requirements

### Requirement: A/B Test Splits Segment Deterministically

When a Blast is configured as A/B with `abSplitPercent`, the segment SHALL
be split deterministically so the same contact always receives the same
variant.

#### Scenario: Segment split deterministically per contact

- **GIVEN** a Blast with `abSplitPercent: 50` and a Segment of 4,000 Contacts
- **WHEN** `BlastService.sendBlast()` runs
- **THEN** a parent Blast and a child `abVariantOf` Blast SHALL exist
- **AND** ~2,000 BlastDeliveries SHALL be queued per variant using `variant = hash(contactId) % 100 < abSplitPercent ? "B" : "A"`
- **AND** the same contact SHALL always receive the same variant

### Requirement: Send Via OpenConnector with Per-Tenant Provider

A Blast SHALL dispatch via openconnector's source-specific send-mail action
and SHALL NOT embed provider credentials in Pipelinq code.

#### Scenario: Dispatch via openconnector send-mail action

- **GIVEN** a BlastDelivery queued for a Contact
- **WHEN** `BlastService.dispatchBlastDeliveries()` runs
- **THEN** it SHALL fetch the openconnector source by `connectorSourceId`, call its `send-mail` action with the rendered template, and store the returned `providerId` on the BlastDelivery

#### Scenario: Pipelinq code never touches provider credentials

- **GIVEN** a SendGrid API key configured in openconnector
- **WHEN** a Blast is sent
- **THEN** `BlastService` SHALL NOT import a provider SDK, read the API key, or construct provider API requests directly
- **AND** all sends SHALL delegate to `OCA\OpenConnector\Service\SourceService::executeAction()`

### Requirement: Throttle Respects Provider Rate Limits

The sending engine SHALL respect per-source rate limits configured in
openconnector.

#### Scenario: Rate limit applied per source

- **GIVEN** a Blast with 50,000 queued BlastDeliveries and a source `sendRateLimit = 100`
- **WHEN** dispatch runs
- **THEN** `BlastService` SHALL read the rate limit from the source config, batch queued rows (default 50), and wait between batches to maintain the configured throughput

### Requirement: Revenue Attribution Joins Clicks to Closed Deals

When a recipient clicks and later closes a Deal, an AttributionLink SHALL
join Blast → Contact → Deal with first-click timestamp and attributed value.

#### Scenario: Attribution link created when deal closes

- **GIVEN** a BlastDelivery with `firstClickAt` set and a Deal that closes won for the same Contact
- **WHEN** `AttributionService.linkBlastToDeal()` runs
- **THEN** an AttributionLink SHALL be created with `blastId`, `contactId`, `dealId`, `firstClickAt`, `closedWonAt`, and `attributedValue`

#### Scenario: Attributed revenue summed per blast

- **GIVEN** 3 AttributionLink rows for the same `blastId`
- **WHEN** `AttributionService.getBlastAttributedValue()` runs
- **THEN** it SHALL return the sum of `attributedValue` across the rows

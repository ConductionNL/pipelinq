---
status: in-progress
---

# marketing-blast Specification

**OpenSpec changes**:
- `marketing-lists-and-double-opt-in` (in progress) — a Blast may name a `listId` instead of a `segmentId`, and a list audience resolves to its confirmed subscriptions at send time. See [marketing-lists](../marketing-lists/spec.md).

## Purpose
Sends marketing email blasts to contact segments, dispatching through openconnector's per-tenant send-mail provider so provider credentials never live in pipelinq code. It supports deterministic A/B splitting (each contact always gets the same variant), respects per-source rate limits, and creates revenue attribution links that join blasts to closed deals with first-click timestamps and attributed value.
## Requirements
### Requirement: A/B Test Splits Segment Deterministically

@e2e exclude the split is a pure hash function over contact ids inside BlastService, applied to a 4,000-member segment; nothing renders the per-contact variant assignment and determinism can only be shown by evaluating the same input twice, which is a unit-level property; asserted by tests/Unit/Service/BlastServiceTest.php (testVariantForIsDeterministicPerContact, testVariantForApproximatesRequestedSplit, testSliceMembersForAbRoutesByVariant, testSendBlastCreatesVariantChildOnAbSplit).

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

@e2e exclude dispatch runs through `OCA\OpenConnector\Service\SourceService::executeAction()`, and the CI instance does not install openconnector — .github/workflows/code-quality.yml pins `additional-apps` to openregister only — so no real send can occur in a browser run; the second scenario is additionally a NEGATIVE claim about pipelinq's own source (no provider SDK import, no API-key read), which is a static-analysis question rather than a rendered one. Asserted by tests/Unit/Service/BlastServiceTest.php (testDispatchBlastDeliveriesCallsOpenconnectorAndRespectsRateLimit, testDispatchBlastDeliveriesFailsClosedWhenSourceServiceUnavailable).

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

@e2e exclude throttling is batch-and-wait timing inside the dispatcher against a 50,000-row queue and an openconnector source config that the CI instance has no openconnector to hold; a browser observes neither the batch size nor the inter-batch wait; asserted by tests/Unit/Service/BlastServiceTest.php (testDispatchBlastDeliveriesCallsOpenconnectorAndRespectsRateLimit).

The sending engine SHALL respect per-source rate limits configured in
openconnector.

#### Scenario: Rate limit applied per source

- **GIVEN** a Blast with 50,000 queued BlastDeliveries and a source `sendRateLimit = 100`
- **WHEN** dispatch runs
- **THEN** `BlastService` SHALL read the rate limit from the source config, batch queued rows (default 50), and wait between batches to maintain the configured throughput

### Requirement: Revenue Attribution Joins Clicks to Closed Deals

@e2e exclude both scenarios are about the AttributionLink OBJECT GRAPH produced by AttributionService when a deal closes — the link's creation and the sum across rows have no UI trigger (the app has no "close this deal" affordance wired to a blast click), and the rendered result of the sum is already asserted end-to-end by tests/e2e/spec-coverage/marketing.spec.ts ("the Attribution tab shows attributed deal count and value per blast"). The computation itself is asserted by tests/Unit/Service/AttributionServiceTest.php (testLinkBlastToDealCreatesAttributionLink, testLinkBlastToDealIsIdempotent, testGetBlastAttributedValueSumsRows, testGetBlastAttributedValueReturnsZeroWhenEmpty).

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


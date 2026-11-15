---
status: in-progress
---

# marketing-blast Specification

**OpenSpec changes**:
- `marketing-lists-and-double-opt-in` (in progress) — a Blast may name a `listId` instead of a `segmentId`, and a list audience resolves to its confirmed subscriptions at send time. See [marketing-lists](../marketing-lists/spec.md).
- [marketing-mail-transports](../../changes/marketing-mail-transports/) (in progress) — a Blast dispatches through the resolved `mailTransport` (instance mail server, a sender's Mail account, or an OpenConnector source) instead of always through OpenConnector.

## Purpose
Sends marketing email blasts to contact segments, dispatching through the resolved mailTransport (instance mail server, a sender's Mail account, or an OpenConnector source) so provider credentials never live in pipelinq code. It supports deterministic A/B splitting (each contact always gets the same variant), respects per-source rate limits, and creates revenue attribution links that join blasts to closed deals with first-click timestamps and attributed value.

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

### Requirement: A Blast May Target a Mailing List

A Blast SHALL name either a Segment or a mailing list as its audience, and SHALL be refused when it names neither. When it names a list the audience SHALL be the list's confirmed subscriptions whose list consent stands, resolved at send time exactly as a Segment is, so the recipients are never a materialised copy.

#### Scenario: A blast targeting a list queues its confirmed subscribers

@e2e exclude the queueing runs inside `BlastService::sendBlast()` and dispatches through `OCA\OpenConnector\Service\SourceService`, which the CI instance has no openconnector to hold — .github/workflows/code-quality.yml pins `additional-apps` to openregister only — so no browser run reaches a send. Asserted by tests/Unit/Service/SubscriptionServiceTest.php (testOnlyConfirmedMembersReachABlast, testConfirmedMemberWithWithdrawnConsentIsSkipped).

- **GIVEN** a Blast whose `listId` names a mailing list with two confirmed subscriptions
- **WHEN** the Blast is sent
- **THEN** two BlastDeliveries SHALL be queued, one per confirmed subscriber
- **AND** each delivery SHALL carry the address stored on the subscription

#### Scenario: A blast with no audience is refused

@e2e exclude the refusal is a guard at the top of `BlastService::sendBlast()` returning the summary status `no-audience`; the wizard's audience step cannot submit an empty audience, so no browser path reaches it, and the send it guards dispatches through openconnector, which the CI instance does not install. Asserted by tests/Unit/Service/BlastServiceTest.php (testSendBlastWithoutAudienceIsRefused).

- **GIVEN** a Blast with neither a `segmentId` nor a `listId`
- **WHEN** the Blast is sent
- **THEN** the send SHALL be refused with status `no-audience` and nothing SHALL be queued

### Requirement: A Campaign Template May Embed Articles

A campaign template SHALL be able to name articles, in the order the marketer chose. A template body carrying the `{{articles}}` marker SHALL have that marker replaced, in both the HTML and the plain-text body, by the named articles rendered as title, summary and hero image. An article whose `portalPageRef` is set SHALL render a "read more" link to that page; an article without one SHALL render no link at all rather than a link that goes nowhere. A template naming no articles, or a body carrying no marker, SHALL render exactly as it does today.

#### Scenario: The articles block renders into the HTML body

@e2e exclude the substitution happens inside `BlastService::renderTemplate()` on the way to an openconnector send, and the CI instance installs no openconnector, so no browser run reaches a rendered body. Asserted by tests/Unit/Service/ArticleRenderingTest.php (testHtmlBlockRendersTitleSummaryAndHero, testTemplateWithoutMarkerIsUnchanged).

- **GIVEN** a campaign template whose HTML body carries `{{articles}}` and which names two published articles
- **WHEN** a delivery is rendered from that template
- **THEN** the HTML body SHALL carry both articles' titles and summaries in the order the template named them
- **AND** the marker SHALL no longer appear in the rendered body

#### Scenario: The articles block renders into the plain-text body

@e2e exclude same send path as above; the text body never reaches a browser. Asserted by tests/Unit/Service/ArticleRenderingTest.php (testTextBlockRendersTitleAndSummary).

- **GIVEN** a campaign template whose plain-text body carries `{{articles}}` and which names one article
- **WHEN** a delivery is rendered from that template
- **THEN** the text body SHALL carry the article's title and summary as plain text with no HTML tags

#### Scenario: An article without a portal page renders no read-more link

@e2e exclude same send path as above. Asserted by tests/Unit/Service/ArticleRenderingTest.php (testArticleWithoutPortalPageRefRendersNoLink, testArticleWithPortalPageRefRendersReadMoreLink).

- **GIVEN** a template naming one article whose `portalPageRef` is empty
- **WHEN** a delivery is rendered from that template
- **THEN** neither body SHALL carry a read-more link for that article
- **AND** when the same article carries a `portalPageRef`, both bodies SHALL carry a read-more link to it

#### Scenario: A template naming no articles renders unchanged

@e2e exclude same send path as above. Asserted by tests/Unit/Service/ArticleRenderingTest.php (testTemplateNamingNoArticlesRendersAnEmptyBlock).

- **GIVEN** a template whose body carries `{{articles}}` but which names no articles
- **WHEN** a delivery is rendered from that template
- **THEN** the marker SHALL be removed and no article markup SHALL be added

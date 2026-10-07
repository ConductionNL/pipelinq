# Marketing campaigns, landing pages and touchpoint attribution

**Spec refs**: `marketing-campaign-attribution` (the UTM decorator and the portal traffic read this change builds on), `marketing-blast`, `marketing-analytics`, portaliq `landing-page-provisioning`, ADR-041 (cross-app commands are typed events), ADR-107 (money has one home), ADR-111 (demo data), ADR-112 (reports are one page)
**Standards**: GA4 `utm_*` campaign parameters

## ADDED Requirements

### Requirement: A campaign owns its campaign value and its channel vocabulary

A `campaign` object MUST carry a name, a goal, a `utmCampaign` slug, a `utmSource` and a `utmMedium`, the mailings and posts it groups, its landing page and form references, its dates and its budget.

`utmCampaign` MUST be minted from the name on first save and MUST NOT change afterwards, because the value is already written into portaliq's rollups and into links that have left the building. Renaming a campaign MUST leave its slug untouched.

`utmSource` and `utmMedium` MUST be lowercase and MUST be drawn from the vocabularies in the app-config values `campaign.utm_sources` and `campaign.utm_mediums`, which an administrator maintains. A value outside the vocabulary MUST be rejected with the offending value and the allowed list named. `Linkedin` and `linkedin` are two campaigns in every analytics tool, so the case rule is enforced rather than corrected.

#### Scenario: The slug is minted once and survives a rename

@e2e exclude minting is a write-path invariant with no rendered difference: both the original and the renamed campaign show the same slug, so a browser cannot tell a frozen slug from a re-minted one that happened to agree. Asserted by tests/Unit/Service/CampaignServiceTest.php (testMintsTheSlugFromTheNameOnFirstSave, testKeepsTheSlugWhenTheCampaignIsRenamed).
- **WHEN** a campaign named "Webinar AI voor gemeenten" is saved and later renamed
- **THEN** its `utmCampaign` is `webinar-ai-voor-gemeenten` both times

#### Scenario: A source outside the vocabulary is refused
- **WHEN** a campaign is saved with `utmSource` `Beurs`
- **THEN** the write is refused with `unknown_utm_source`, and the response names `Beurs` and the configured vocabulary

#### Scenario: The Campaigns page lists the seeded campaign
- **WHEN** a marketer opens the Campaigns page under Marketing
- **THEN** the seeded campaign is listed with its goal and its campaign value

### Requirement: A tracked link is minted from the campaign when there is one

`CampaignLinkDecorator::utmFor()` MUST accept the campaign a blast belongs to and MUST resolve each of the four parameters separately: `utm_source` and `utm_medium` from the campaign when it sets them and `email` otherwise, `utm_campaign` from the campaign's `utmCampaign` when it is set and the blast's own slug otherwise, and `utm_content` always the blast id.

A blast that belongs to no campaign MUST be decorated exactly as it is today. Resolution is per parameter, so a campaign that names only a source MUST still produce the blast's own campaign value rather than an empty one.

`BlastService` MUST load the campaign named by the blast's `campaignId` and pass it to the decorator before it renders the body.

#### Scenario: The campaign's values win over the per-blast defaults

@e2e exclude decoration happens at send time inside the mail body handed to the transport, which no browser ever sees; the CI instance installs neither openconnector nor a mail sink. Asserted by tests/Unit/Service/CampaignLinkDecoratorTest.php (testCampaignSourceAndMediumWinOverTheEmailDefaults, testCampaignSlugWinsOverTheBlastName).
- **WHEN** a blast belongs to a campaign with `utmSource` `nieuwsbrief`, `utmMedium` `email` and `utmCampaign` `webinar-ai-voor-gemeenten`
- **THEN** its links carry `utm_source=nieuwsbrief`, `utm_medium=email`, `utm_campaign=webinar-ai-voor-gemeenten` and `utm_content=<blast id>`

#### Scenario: A partial campaign falls back per parameter

@e2e exclude same reason. Asserted by tests/Unit/Service/CampaignLinkDecoratorTest.php (testAPartialCampaignFallsBackPerParameter).
- **WHEN** the campaign sets `utmSource` and nothing else
- **THEN** `utm_source` is the campaign's, and `utm_medium` and `utm_campaign` keep their per-blast values

#### Scenario: A blast without a campaign is unchanged

@e2e exclude same reason. Asserted by tests/Unit/Service/CampaignLinkDecoratorTest.php (testAddsAllFourParametersToABareLink, testABlastWithoutACampaignIsUnchanged) and tests/Unit/Service/BlastServiceTest.php (testDispatchDecoratesTheTemplateBodyWithCampaignParameters).
- **WHEN** a blast has no `campaignId`
- **THEN** the four parameters are `email`, `email`, the blast slug and the blast id, as before this change

### Requirement: A campaign creates its landing page in portaliq

A "Create landing page" action MUST dispatch `OCA\Portaliq\Event\LandingPageRequestedEvent` with `sourceApp` `pipelinq`, the campaign's portal and route, its title and locale, an article payload built from the campaign's summary and body, a form definition, the campaign's UTM block, and an `externalReference` of `pipelinq:campaign:<campaign id>`. The FQCN MUST be `class_exists()`-guarded; with portaliq absent the action MUST fail with `portaliq_missing` and MUST NOT be attempted.

On success the campaign MUST record the returned `pageId`, `route`, `publicUrl` and `formId`. On failure the campaign MUST record nothing and the marketer MUST be shown portaliq's own error code, one of `unknown_portal`, `duplicate_route`, `invalid_article`, `invalid_form` and `write_failed`, translated for reading but never renamed or collapsed into a generic message: a duplicate route and an invalid form are fixed in different places.

#### Scenario: A created page is recorded on the campaign

@e2e exclude the CI instance does not install portaliq (`.github/workflows/code-quality.yml` pins `additional-apps` to openregister and planninq), so the listener that answers this event does not exist there and no page can be created. Asserted by tests/Unit/Service/LandingPageProvisioningServiceTest.php (testRecordsThePageRouteFormAndPublicUrlOnTheCampaign).
- **WHEN** the action succeeds for the route `/campagne/webinar-ai-voor-gemeenten`
- **THEN** the campaign's `landingPage` carries that route, the page id, the public URL and the form id

#### Scenario: Portaliq's error reaches the marketer unchanged

@e2e exclude same reason: portaliq is absent on the CI instance, so the only reachable code is the `portaliq_missing` branch, which the next scenario covers. Asserted by tests/Unit/Service/LandingPageProvisioningServiceTest.php (testSurfacesDuplicateRouteVerbatim, testSurfacesInvalidFormVerbatim, testRecordsNothingOnFailure).
- **WHEN** portaliq answers `duplicate_route`
- **THEN** the response's `error` is `duplicate_route` and the campaign is not modified

#### Scenario: Portaliq absent is its own answer
- **WHEN** the action runs on an instance without portaliq
- **THEN** the response's `error` is `portaliq_missing` and the campaign is not modified

### Requirement: A landing-page submission becomes a contact, a lead and a touchpoint

Pipelinq MUST declare `OCA\Pipelinq\Event\LandingPageFormSubmittedEvent` with the constructor portaliq's dispatch listener calls, and MUST register a listener for it.

The listener MUST be idempotent on the submission's `nonce`: when a `submit` touchpoint already carries that nonce it MUST return without writing anything. A redelivered submission therefore creates no second contact, no second lead and no second touchpoint.

Otherwise the listener MUST match a contact by the submitted email address, case-insensitively, and create one when none matches, following the app's existing contact conventions. It MUST write a `lead` carrying the campaign, the contact, the submitted values, and `firstTouch` and `lastTouch` UTM blocks taken from `utmFirstTouch` and `utmLastTouch`. It MUST append a `touchpoint` of kind `submit` carrying the campaign, the contact, the lead, the channel resolved from `utmLastTouch.medium`, the UTM block, the submission moment and the nonce. It MUST then call `setLeadId()` with the lead's id and `setHandled(true)`.

A submission that carries no usable email address MUST still write the touchpoint, so the campaign's submission count is right, and MUST NOT create a contact or a lead.

#### Scenario: A first submission creates a contact, a lead and a touchpoint

@e2e exclude portaliq is absent on the CI instance, so nothing dispatches this event; dispatching it from a browser is impossible and constructing it in a seed would test the constructor, not the listener. Asserted by tests/Unit/Listener/LandingPageFormSubmittedListenerTest.php (testCreatesTheContactLeadAndTouchpoint, testWritesFirstAndLastTouchOnTheLead).
- **WHEN** a submission arrives for an unknown email address
- **THEN** a contact, a lead with `firstTouch` and `lastTouch`, and a `submit` touchpoint exist, and the event carries the lead's id and `handled`

#### Scenario: A redelivered submission creates nothing twice

@e2e exclude same reason. Asserted by tests/Unit/Listener/LandingPageFormSubmittedListenerTest.php (testARedeliveredSubmissionWritesNothing).
- **WHEN** the same submission is dispatched a second time with the same `nonce`
- **THEN** no object is written and the counts are unchanged

#### Scenario: A known contact gets a lead, not a duplicate contact

@e2e exclude same reason. Asserted by tests/Unit/Listener/LandingPageFormSubmittedListenerTest.php (testMatchesAnExistingContactCaseInsensitively).
- **WHEN** a submission arrives for `JANE.DOE@EXAMPLE.COM` and a contact exists for `jane.doe@example.com`
- **THEN** that contact is reused and only a lead and a touchpoint are written

### Requirement: Attribution is computed at report time in three models

`CampaignAttributionService` MUST compute first touch, last touch and linear attribution from the campaign's touchpoints, in one pass, and MUST NOT store the result. First touch gives a lead's whole value to its earliest touchpoint, last touch to its latest, and linear splits it evenly over its distinct touchpoints. Touchpoints of equal `occurredAt` MUST be ordered by their creation order so a report is reproducible.

A lead with exactly one touchpoint MUST report the same value under all three models.

#### Scenario: The three models divide one lead's value differently

@e2e exclude the arithmetic of three models over a seeded touchpoint log is a computation, not a rendering; the page shows one model at a time and cannot demonstrate the split. Asserted by tests/Unit/Service/CampaignAttributionServiceTest.php (testFirstTouchGivesTheEarliestTouchpointTheWholeValue, testLastTouchGivesTheLatestTouchpointTheWholeValue, testLinearSplitsEvenlyOverDistinctTouchpoints).
- **WHEN** a lead worth 3000 has an email click, a site visit and a form submit
- **THEN** first touch gives 3000 to the click, last touch gives 3000 to the submit, and linear gives 1000 to each

#### Scenario: One touchpoint means the models agree

@e2e exclude same reason. Asserted by tests/Unit/Service/CampaignAttributionServiceTest.php (testASingleTouchpointMakesTheThreeModelsAgree).
- **WHEN** a lead has one touchpoint
- **THEN** all three models attribute its whole value to that touchpoint

### Requirement: Attribution closes on a paid invoice, or on a won lead, and the report says which

For each lead in a campaign the report MUST resolve a closing basis in this order.

1. The lead's client carries `shillinqOrganisationRef`, shillinq is installed, and that customer has AR invoices in `lifecycleState` `paid` dated inside the report window. Basis `paid_invoice`, value the summed `grossAmount`.
2. Otherwise the lead is won. Basis `won_lead`, value the lead's own `value`.
3. Otherwise the lead contributes nothing and counts as open.

An AR invoice MUST count at most once per report, whichever lead reaches it. Two leads for the same client would otherwise each claim it and the campaign would report double what shillinq booked.

The report MUST name the basis per lead and MUST total per basis, so a reader can see how much of the attributed value is booked money and how much is a forecast. Pipelinq reads shillinq's register through OpenRegister and writes nothing: the ledger is shillinq's (ADR-107).

#### Scenario: A paid invoice closes the lead

@e2e exclude the CI instance does not install shillinq, so no AR invoice exists to read. Asserted by tests/Unit/Service/CampaignAttributionServiceTest.php (testAPaidInvoiceClosesTheLead) and tests/Unit/Service/ShillinqInvoiceReaderTest.php (testReadsOnlyPaidInvoicesInTheWindow).
- **WHEN** the lead's client maps to a shillinq customer with a paid AR invoice of 4840 in the window
- **THEN** the lead's attributed value is 4840 and its basis is `paid_invoice`

#### Scenario: A won lead closes it when there is no invoice

@e2e exclude same reason. Asserted by tests/Unit/Service/CampaignAttributionServiceTest.php (testAWonLeadClosesWhenThereIsNoInvoice, testShillinqAbsentFallsBackToTheWonLead).
- **WHEN** the client has no shillinq mapping, or shillinq is not installed, and the lead is won
- **THEN** the lead's attributed value is the lead's own value and its basis is `won_lead`

#### Scenario: One invoice counts once across two leads

@e2e exclude same reason. Asserted by tests/Unit/Service/CampaignAttributionServiceTest.php (testAnInvoiceCountsOnceAcrossTwoLeadsOfTheSameClient).
- **WHEN** two leads of the same client both resolve to the same paid invoice
- **THEN** the campaign's attributed value counts that invoice once

### Requirement: One campaign report page

`GET /api/campaigns/{id}/report` MUST return the whole record in one response: the campaign, the window, reach and clicks per channel, submissions, leads with their closing basis, the three attribution models, the totals per basis, and the known costs. The page MUST render from that one response and MUST NOT issue a request per lead, per blast or per touchpoint before it paints.

The report MUST be reachable as a card on the Reports page and MUST NOT have a navigation entry of its own (ADR-112). Costs the app does not know MUST be reported as absent rather than as zero, because zero reads as free.

#### Scenario: The report page renders from one response
- **WHEN** a marketer opens the campaign report from the Reports page and picks the seeded campaign
- **THEN** reach, clicks, submissions, leads, attributed value and cost are shown, and no request was made per lead

#### Scenario: The model choice changes the split, not the total

@e2e exclude switching models re-reads the same aggregate and the seeded campaign has one lead, for which the three models agree by design; a browser cannot show a difference the data does not contain. Asserted by tests/Unit/Service/CampaignReportServiceTest.php (testTheTotalIsTheSameUnderEveryModel) and tests/vitest/campaignReport.spec.js (formats the per-model rows).
- **WHEN** the model is switched from first touch to linear
- **THEN** the attributed total is unchanged and the per-touchpoint split differs

#### Scenario: An unknown cost is absent, not zero
- **WHEN** the campaign records no spend for a channel
- **THEN** the cost cell reads "Not recorded" rather than "0"

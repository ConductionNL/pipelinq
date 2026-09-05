# Design: marketing-campaigns

## Context

Three contracts already exist and this change consumes all three unchanged.

| Contract | Where | What this change does with it |
| --- | --- | --- |
| `CampaignLinkDecorator` | `lib/Service/CampaignLinkDecorator.php` | Adds one optional argument. Existing callers and tests keep their behaviour. |
| `LandingPageRequestedEvent` | portaliq `lib/Event/`, merged into portaliq `development` on 2026-09-04 | Dispatched, result read off the same instance. |
| `LandingPageFormSubmittedEvent` | portaliq `lib/Listener/LandingPageSubmissionDispatchListener.php` constructs it | Pipelinq ships its own class in its own namespace, with the constructor portaliq actually calls. |

## Decisions

### 1. The campaign's UTM values win, and a blast without a campaign changes nothing

`utmFor(blast, template, campaign)` builds the four parameters in one place. Precedence per parameter, not per set:

| Parameter | Campaign sets it | Campaign does not |
| --- | --- | --- |
| `utm_source` | the campaign's `utmSource` | `email` |
| `utm_medium` | the campaign's `utmMedium` | `email` |
| `utm_campaign` | the campaign's `utmCampaign` | the blast slug, as today |
| `utm_content` | the blast id | the blast id |

`utm_content` stays the blast id under a campaign, because that is what tells two mailings of one campaign apart in the rollup. Per-parameter precedence means a campaign that names only a source still gets the blast's own campaign slug rather than an empty value, which the decorator would drop.

The campaign row reaches the decorator from `BlastService`, which already loads the blast. The decorator keeps `IAppConfig` as its only dependency, so it stays constructible in a unit test and cannot form a cycle with `CampaignPerformanceService`, which depends on it.

### 2. `utmCampaign` is minted once and then frozen

A campaign slug that changes orphans every rollup row already written under the old value, and the rollup is portaliq's, so Pipelinq cannot go back and fix it. `CampaignService::mint()` derives the slug from the name on first save and refuses to change it afterwards. Renaming the campaign is allowed and does not touch the slug.

### 3. Source and medium are an admin-managed vocabulary, not a schema enum

A schema `enum` is shipped configuration: an administrator who needs `beurs` as a source would have to edit a fragment and re-import the register. The vocabulary therefore lives in app config (`campaign.utm_sources`, `campaign.utm_mediums`), seeded with the GA4-conventional values, and the schema constrains the shape only (`^[a-z0-9][a-z0-9_.-]*$`). `CampaignService` validates a written value against the vocabulary and rejects an unknown one, so the rule is enforced where it can report a reason.

Lowercase is not politeness. `utm_source=LinkedIn` and `utm_source=linkedin` are two campaigns in every analytics tool including portaliq's own rollup, and the split is invisible until a report is short.

### 4. The listener is idempotent on the nonce, and the touchpoint is the ledger

Portaliq stamps a `nonce` on every submission and re-dispatch is possible: `LandingPageSubmissionDispatchListener` reacts to OpenRegister's `ObjectCreatedEvent`, and a repair or a replayed write fires it again. Pipelinq's listener therefore reads the touchpoint log for a `submit` touchpoint carrying that nonce and returns early when it finds one, before it touches a contact or a lead.

The nonce lives on the touchpoint rather than on the lead because the touchpoint is written on every submission and the lead is not: a second submission by a known contact appends a touchpoint and creates no lead. Guarding on the lead would let the second submission through.

### 5. Attribution is computed, never stored

`CampaignAttributionService` reads the campaign's touchpoints and returns first touch, last touch and linear in one pass. Nothing is written. `attributionLink` gains `campaignId`, `touchpointIds[]`, `invoiceRef` and `model` so the existing blast-to-deal row can say which campaign and which model produced it, but the report never reads its own output.

Linear splits a lead's value evenly over its distinct touchpoints. A lead with one touchpoint gives that touchpoint the whole value, which is what first and last touch also say, so the three models agree on a single-touch lead and that is the property the test pins.

### 6. Closing: a paid invoice where there is one, a won lead otherwise

The report resolves each lead in the campaign:

1. The lead's client carries `shillinqOrganisationRef` (the seam `time-billing-handoff-emit` already established), shillinq is installed, and that customer has AR invoices in `lifecycleState: paid` dated inside the report window. Basis `paid_invoice`, value the summed `grossAmount`.
2. Otherwise the lead is won. Basis `won_lead`, value the lead's own `value`.
3. Otherwise the lead contributes nothing and is counted as open.

**An invoice counts once per report.** Two leads for the same client would otherwise each claim the same invoice, and the campaign would report double what shillinq booked. Invoices are collected by id across the whole campaign before they are summed.

Pipelinq reads shillinq's register through OpenRegister, duck-typed, exactly as `CampaignPerformanceService` reads portaliq's. It writes nothing. ADR-107 puts the ledger in shillinq and this stays on the reading side of that line.

### 7. The report page fetches once

`GET /api/campaigns/{id}/report` returns the whole record: channels, clicks, submissions, leads, the three models, the closing basis per lead and the costs. The page renders from one response.

This is not a preference. pipelinq#1781 fixed the blast performance page, which asked the server once per blast before it rendered anything, and the fix is the shape this page is built in from the start.

## Risks

- **[The campaign is created and portaliq is absent, so the marketer sees a stack trace.]** The provisioning service `class_exists()`-guards the event FQCN and returns `portaliq_missing` as its own error code, alongside portaliq's five. The Create landing page action is the only thing that fails; the campaign itself never needed portaliq.
- **[`shillinqOrganisationRef` is a nil-UUID placeholder on every demo client.]** A nil UUID matches no invoice, so the report falls back to `won_lead` and says so. That is the correct answer for an unmapped client, and the report naming its basis is what makes it visible rather than silently zero.
- **[Another agent is building `feat/social-publishing` and touches the Marketing manifest group and the l10n catalogues.]** Manifest additions live in this change's own fragment file. The l10n catalogues will conflict and are merged as a union of keys.

## DEFERRED_QUESTIONS

1. **Portaliq passes `externalReference` in the `correlationId` slot.** `LandingPageSubmissionDispatchListener::relay()` constructs the consumer event with `(string)($data['externalReference'] ?? '')` as both the sixth and the thirteenth argument, and `landingPageSubmission` declares no `correlationId` property at all, so the correlation id set on the request never comes back. Pipelinq accepts the value as given and does not rely on it. Whether portaliq should carry a real `correlationId` through to the submission is portaliq's call.
2. **The article body is authored on the campaign, not linked to an `article` object.** Phase 2 shipped the `article` schema. Pointing `campaign.landingPage.articleId` at one, rather than carrying summary and body inline, is the obvious next step and needs a decision on what happens when the article changes after the page is created.
3. **No `socialPost` schema exists yet.** `campaign.postIds[]` is an untyped id list until phase 3 lands the schema, at which point it wants a `$ref`.
4. **Cost is what the campaign was told, not what was spent.** `budgetEur` and the per-channel `costs[]` are recorded by hand. X spend and WhatsApp fees are known to their own services in phase 3 and phase 1, and wiring them in is a follow-up.

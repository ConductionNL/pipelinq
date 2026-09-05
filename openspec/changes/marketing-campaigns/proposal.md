# Proposal: marketing-campaigns

Phase 4 of the marketing programme (`docs/Technical/marketing-architecture.md`). A campaign becomes the object that owns the UTM vocabulary, creates its own landing page in portaliq, turns a form submission into a lead, and reports what that lead was worth.

Half of the phase already shipped as `marketing-campaign-attribution`: `CampaignLinkDecorator` stamps four parameters on every blast link, `CampaignPerformanceService::forBlast()` reads portaliq's daily rollups, and Search Console queries are imported. This change builds on that code, it does not replace it.

## Problem

1. **The campaign value is minted per blast.** A blast named "Voorjaarsnieuwsbrief 2026" carries `utm_campaign=voorjaarsnieuwsbrief-2026`. Two mailings in the same campaign therefore report as two campaigns, and a social post that carries the campaign name by hand agrees with neither. Nothing in Pipelinq holds the campaign, so nothing can hand out one value.
2. **A landing page is a hand-off nobody made.** Portaliq shipped the provisioning contract on 2026-09-04 (portaliq#433). Pipelinq has no caller, so a marketer who wants a page for a campaign asks a colleague with portaliq rights and pastes the URL back by hand.
3. **A form submission is lost.** Portaliq writes the submission and dispatches `LandingPageFormSubmittedEvent` at the app that asked for the form. Pipelinq ships no such class, so portaliq logs "no consumer registered yet" and the visitor never becomes a lead.
4. **Attribution is one model and one closing rule.** `AttributionService` joins a first click to a won lead and stores the value. It cannot answer "what did last touch say", it has no view of a visit or a submission, and it treats a won lead as revenue even when shillinq shows the invoice unpaid.

## Solution

1. **`campaign` and `touchpoint` schemas.** The campaign carries the name, the goal, a `utmCampaign` slug minted once and then frozen, the source and medium drawn from an admin-managed lowercase vocabulary, the mailings and posts it groups, the landing page and form it created, its dates and its budget. A touchpoint records one interaction: contact, lead, campaign, channel, UTM block, moment and kind (`click`, `visit`, `submit`, `reply`). `blast` gains `campaignId`, `attributionLink` gains `campaignId`, `touchpointIds[]`, `invoiceRef` and `model`, and `lead` gains `firstTouch` and `lastTouch`.
2. **The campaign mints the links.** `CampaignLinkDecorator::utmFor()` takes an optional campaign row. A value the campaign sets wins; a blast that belongs to no campaign keeps today's behaviour byte for byte.
3. **Create landing page.** `LandingPageProvisioningService` dispatches `OCA\Portaliq\Event\LandingPageRequestedEvent` with the campaign's article and form payload, stores the returned route, page id, form id and public URL on the campaign, and hands portaliq's own error code back to the marketer unchanged.
4. **Submission to lead.** `OCA\Pipelinq\Event\LandingPageFormSubmittedEvent` plus a listener that matches or creates the contact by email, writes a lead carrying `firstTouch` and `lastTouch`, appends a `submit` touchpoint, and calls `setLeadId()` and `setHandled()`. The listener is idempotent on the submission's `nonce`.
5. **Three attribution models, computed at report time.** First touch, last touch and linear, all read from the touchpoint log. Nothing is stored as a second copy of the answer.
6. **Two closing rules, and the report says which one it used.** A lead whose client maps to a shillinq customer closes on the sum of that customer's **paid** AR invoices in the campaign window. A lead with no such invoice closes on the won lead's own value. Money stays in shillinq: Pipelinq reads, it never books (ADR-107).
7. **One campaign report page.** Reach per channel, clicks, submissions, leads, attributed value and cost, in one aggregate call, as a card on the Reports page (ADR-112).

## Scope

- New schemas `campaign` and `touchpoint`, and additive properties on `blast`, `lead` and `attributionLink`, in `lib/Settings/register.d/98-marketing-campaigns.json`, with a Dutch demo campaign per ADR-111.
- New services `CampaignService`, `LandingPageProvisioningService`, `TouchpointService`, `CampaignAttributionService`, `ShillinqInvoiceReader`, `CampaignReportService`.
- New event `OCA\Pipelinq\Event\LandingPageFormSubmittedEvent` and listener `LandingPageFormSubmittedListener`.
- New settings `campaign.utm_sources` and `campaign.utm_mediums`.
- New routes `POST /api/campaigns/{id}/landing-page` and `GET /api/campaigns/{id}/report`.
- Frontend: a Campaigns index, a campaign detail page with the Create landing page action, and a campaign report page reached from Reports.

## Out of scope

- `socialPost`. Phase 3 owns that schema and is being built in parallel; `campaign.postIds[]` holds the references until it lands, and no `socialPost` property is declared here.
- Writing anything into shillinq. The invoice read is one-way.
- Multi-touch models beyond first, last and linear. Time decay and position based need a decay constant nobody has picked.
- Per-campaign page views and form submits from portaliq's rollups. The rollup still carries sessions only.

## Decisions (Ruben, 2026-09-04)

Intake decisions 9, 12 and 16 of the marketing programme: campaigns carry a fixed UTM vocabulary, portaliq owns the public page, and attribution closes on a paid invoice where shillinq has one.

# Tasks: marketing-campaigns

## 1. Schemas and demo data

- [x] 1.1 `campaign` and `touchpoint` schemas, plus `campaignId` on `blast`, `firstTouch`/`lastTouch` on `lead`, and `campaignId`/`touchpointIds[]`/`invoiceRef`/`model` on `attributionLink`
  - **spec_ref**: `specs/marketing-campaigns/spec.md#requirement-a-campaign-owns-its-campaign-value-and-its-channel-vocabulary`
  - **files**: `lib/Settings/register.d/98-marketing-campaigns.json`
- [x] 1.2 A Dutch demo campaign with its blast, lead and touchpoints (ADR-111), and the `campaign`/`touchpoint` object types the frontend store needs
  - **files**: `lib/Settings/register.d/98-marketing-campaigns.json`, `src/config/objectTypes.js`

## 2. The campaign mints the links

- [x] 2.1 `CampaignService`: read, mint the slug once, validate source and medium against the vocabulary
  - **spec_ref**: `specs/marketing-campaigns/spec.md#requirement-a-campaign-owns-its-campaign-value-and-its-channel-vocabulary`
  - **files**: `lib/Service/CampaignService.php`, `tests/Unit/Service/CampaignServiceTest.php`
- [x] 2.2 `CampaignLinkDecorator::utmFor()` and `decorate()` take the campaign, resolving each parameter separately
  - **spec_ref**: `specs/marketing-campaigns/spec.md#requirement-a-tracked-link-is-minted-from-the-campaign-when-there-is-one`
  - **files**: `lib/Service/CampaignLinkDecorator.php`, `tests/Unit/Service/CampaignLinkDecoratorTest.php`
- [x] 2.3 `BlastService` loads the blast's campaign and passes it to the decorator
  - **files**: `lib/Service/BlastService.php`, `tests/Unit/Service/BlastServiceTest.php`
- [x] 2.4 Settings `campaign.utm_sources` and `campaign.utm_mediums`, and the write path that enforces them: `POST /api/campaigns`, `PATCH /api/campaigns/{id}`, `GET /api/campaigns/vocabulary`, with the declarative create dialog off
  - **files**: `lib/Service/SettingsService.php`, `lib/Controller/CampaignController.php`, `appinfo/routes.php`, `src/views/marketing/CampaignFormView.vue`, `tests/Unit/Controller/CampaignControllerTest.php`

## 3. Landing page from a campaign

- [x] 3.1 `LandingPageProvisioningService`: guarded dispatch, article and form payload, result stored, error verbatim
  - **spec_ref**: `specs/marketing-campaigns/spec.md#requirement-a-campaign-creates-its-landing-page-in-portaliq`
  - **files**: `lib/Service/LandingPageProvisioningService.php`, `tests/Unit/Service/LandingPageProvisioningServiceTest.php`
- [x] 3.2 `POST /api/campaigns/{id}/landing-page`
  - **files**: `lib/Controller/CampaignController.php`, `appinfo/routes.php`, `tests/Unit/Controller/CampaignControllerTest.php`

## 4. Submission to lead

- [x] 4.1 `OCA\Pipelinq\Event\LandingPageFormSubmittedEvent` matching portaliq's dispatch shape
  - **spec_ref**: `specs/marketing-campaigns/spec.md#requirement-a-landing-page-submission-becomes-a-contact-a-lead-and-a-touchpoint`
  - **files**: `lib/Event/LandingPageFormSubmittedEvent.php`
- [x] 4.2 `TouchpointService`: append, read per campaign, and the nonce lookup that makes the listener idempotent
  - **files**: `lib/Service/TouchpointService.php`, `tests/Unit/Service/TouchpointServiceTest.php`
- [x] 4.3 `LandingPageFormSubmittedListener` and its registration
  - **files**: `lib/Listener/LandingPageFormSubmittedListener.php`, `lib/AppInfo/Application.php`, `tests/Unit/Listener/LandingPageFormSubmittedListenerTest.php`

## 5. Attribution and the report

- [x] 5.1 `ShillinqInvoiceReader`: paid AR invoices for a customer in a window, duck-typed, nothing written
  - **spec_ref**: `specs/marketing-campaigns/spec.md#requirement-attribution-closes-on-a-paid-invoice-or-on-a-won-lead-and-the-report-says-which`
  - **files**: `lib/Service/ShillinqInvoiceReader.php`, `tests/Unit/Service/ShillinqInvoiceReaderTest.php`
- [x] 5.2 `CampaignAttributionService`: first, last and linear over the touchpoint log, and the closing basis per lead
  - **spec_ref**: `specs/marketing-campaigns/spec.md#requirement-attribution-is-computed-at-report-time-in-three-models`
  - **files**: `lib/Service/CampaignAttributionService.php`, `tests/Unit/Service/CampaignAttributionServiceTest.php`
- [x] 5.3 `CampaignReportService` and `GET /api/campaigns/{id}/report`, one aggregate response
  - **spec_ref**: `specs/marketing-campaigns/spec.md#requirement-one-campaign-report-page`
  - **files**: `lib/Service/CampaignReportService.php`, `lib/Controller/CampaignController.php`, `appinfo/routes.php`, `tests/Unit/Service/CampaignReportServiceTest.php`

## 6. Frontend

- [x] 6.1 Campaigns index and campaign detail with the Create landing page action
  - **files**: `src/manifest.d/78-marketing-campaigns.json`, `src/views/marketing/CampaignDetail.vue`, `src/registry.js`
- [x] 6.2 The campaign report page and its Reports card, with no menu entry of its own (ADR-112)
  - **spec_ref**: `specs/marketing-campaigns/spec.md#requirement-one-campaign-report-page`
  - **files**: `src/views/marketing/CampaignReport.vue`, `src/manifest.json`, `src/manifest.d/78-marketing-campaigns.json`
- [x] 6.3 `campaignsApi.js` and the pure `campaignReport.js` shaping helpers, with vitest
  - **files**: `src/services/campaignsApi.js`, `src/services/campaignReport.js`, `tests/vitest/campaignReport.spec.js`
- [x] 6.4 Strings in `l10n/en.json` and `l10n/nl.json`, catalogues rebuilt
  - **files**: `l10n/en.json`, `l10n/nl.json`, `l10n/*.js`

## 7. Docs and e2e

- [x] 7.1 A campaigns section in `docs/Features/marketing.md` and a campaigns user guide
  - **files**: `docs/Features/marketing.md`, `docs/user/marketing-campaigns.md`
- [x] 7.2 `tests/e2e/spec-coverage/marketing-campaigns.spec.ts`
  - **files**: `tests/e2e/spec-coverage/marketing-campaigns.spec.ts`

# Proposal: marketing-campaign-attribution

Phase 2 (acquisition) of the fleet traffic analytics programme, the Pipelinq half. Phase 0 taught Pipelinq to report mail opens and clicks to a Portaliq portal as traffic events (`marketing-email-tracking`). This change closes the loop in the other direction: a blast's links carry campaign parameters so the site visit they cause is attributed to the same campaign, Pipelinq reads that attribution back from Portaliq's daily rollups, and organic search demand is imported from Google Search Console next to it.

## Problem

1. A blast link lands on the portal without campaign parameters, so Portaliq counts the visit as a plain referral. The mail open and the site session never meet, and the fleet contract's `campaigns[]` rollup stays empty for email.
2. `AttributionService` closes blast to deal, but nothing shows what a blast did on the site in between: sessions, page views, form submits. The marketing officer has to open Portaliq and compare by hand.
3. Search demand is invisible. The queries people type before they find the site are the only acquisition signal a small organisation gets for free, and it lives in Search Console behind an OAuth flow nobody sets up.

## Solution

1. **UTM builder.** Every link in a blast body gets `utm_source=email`, `utm_medium=email`, `utm_campaign=<blast slug>` and `utm_content=<blast id>` appended when absent. Parameters the author wrote are kept. The unsubscribe link, merge tags, in-page anchors and `mailto:`/`tel:` links are never touched. The decoration runs on the template body before per-delivery rendering, so it precedes the first-party click redirect and the redirect target carries the parameters Portaliq's collector parses. A per-tenant setting `blast.utm_auto` (default on) turns it off.
2. **Campaign performance read.** `CampaignPerformanceService::forBlast()` reads Portaliq's `portalTrafficDaily` objects for the configured portal and window through OpenRegister (duck-typed, no compile-time dependency) and sums the `campaigns[]` rows whose campaign matches the blast. `GET /api/blasts/{id}/performance` exposes it next to Pipelinq's own opens, clicks and attributed deals. The blast performance page shows a "Site traffic from this campaign" block, or "Not connected to a portal" when `blast.traffic_portal` is empty.
3. **Search Console import.** A daily job pulls the last days of `searchanalytics/query` rows per configured property with a **service account** (no OAuth flow: the admin adds the service account's email as a user on the property) and upserts them as `searchQueryDaily` objects. An occ command runs it on demand. A "Search queries" page lists the top queries by clicks over a window.

## Scope

- New services `CampaignLinkDecorator`, `CampaignPerformanceService`, `SearchConsole\GoogleServiceAccountAuth`, `SearchConsole\SearchConsoleImportService`, `SearchConsole\SearchQueryReportService`.
- New schema `searchQueryDaily` in `lib/Settings/register.d/96-marketing-search-console.json`.
- New `SearchConsoleImportJob` (daily) and `pipelinq:marketing:search-console:import`.
- New settings `blast.utm_auto`, `search.gsc.properties`, the sensitive `search.gsc.service_account_key` (never echoed back) and `search.gsc.last_import_at`.
- New routes `GET /api/blasts/{id}/performance` and `GET /api/marketing/search-queries`.
- Frontend: a Marketing traffic settings section, the site traffic block on the performance page, a Search queries page.
- Docs: UTM and campaign performance sections in the blast guide, a new Search Console guide.

## Out of scope

- Per-campaign page views and form submits. The contract's `campaigns[]` rows carry sessions only; the API returns those two as `null` until Portaliq's rollup grows them.
- Bing Webmaster Tools, Matomo and paid search imports.
- Credential brokering through OpenRegister (ADR-064). The key follows the app's existing pattern for provider secrets: a sensitive app-config value that no read path returns.

## Decisions (Ruben, 2026-09-04)

Decision 5 of the fleet traffic contract: Pipelinq dual-writes email events and attribution is by campaign, not by person. This change keeps that: a mail open and a site visit only meet through the campaign value.

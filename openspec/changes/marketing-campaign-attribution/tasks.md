# Tasks: marketing-campaign-attribution

## 1. UTM builder

- [x] 1.1 `CampaignLinkDecorator`: campaign slug, UTM map, anchor rewrite that keeps author parameters and skips unsubscribe, merge tags, anchors, `mailto:`/`tel:`
  - **spec_ref**: `specs/marketing-campaign-attribution/spec.md#requirement-blast-links-carry-campaign-parameters`
  - **files**: `lib/Service/CampaignLinkDecorator.php`, `tests/Unit/Service/CampaignLinkDecoratorTest.php`
- [x] 1.2 Decorate the template body once per blast in `BlastService::dispatchBlastDeliveries()` before rendering, so the click redirect wraps a URL that already carries UTM
  - **files**: `lib/Service/BlastService.php`, `tests/Unit/Service/BlastServiceTest.php`, `tests/Unit/Service/TrackingLinkServiceTest.php`
- [x] 1.3 Setting `blast.utm_auto` (default on) next to `blast.traffic_portal`
  - **files**: `lib/Service/SettingsService.php`, `src/views/settings/MarketingTrafficSettings.vue`

## 2. Campaign performance

- [x] 2.1 `CampaignPerformanceService::forBlast()` reading `portalTrafficDaily` through OpenRegister, duck-typed, `_rbac:false,_multitenancy:false`
  - **spec_ref**: `specs/marketing-campaign-attribution/spec.md#requirement-campaign-performance-joins-site-sessions-to-a-blast`
  - **files**: `lib/Service/CampaignPerformanceService.php`, `tests/Unit/Service/CampaignPerformanceServiceTest.php`
- [x] 2.2 `GET /api/blasts/{id}/performance` with the attribution endpoint's guard
  - **files**: `lib/Controller/BlastController.php`, `appinfo/routes.php`, `tests/Unit/Controller/BlastControllerTest.php`
- [x] 2.3 "Site traffic from this campaign" block on the blast performance page
  - **files**: `src/views/blasts/PerformanceDashboard.vue`

## 3. Search Console import

- [x] 3.1 Schema `searchQueryDaily`
  - **spec_ref**: `specs/marketing-campaign-attribution/spec.md#requirement-search-console-queries-are-imported-with-a-service-account`
  - **files**: `lib/Settings/register.d/96-marketing-search-console.json`
- [x] 3.2 `GoogleServiceAccountAuth`: RS256 assertion with `openssl_sign`, token exchange through `IClientService`
  - **files**: `lib/Service/SearchConsole/GoogleServiceAccountAuth.php`, `tests/Unit/Service/SearchConsole/GoogleServiceAccountAuthTest.php`
- [x] 3.3 `SearchConsoleImportService`: per-property fetch, idempotent upsert per (property, date, query, page)
  - **files**: `lib/Service/SearchConsole/SearchConsoleImportService.php`, `tests/Unit/Service/SearchConsole/SearchConsoleImportServiceTest.php`
- [x] 3.4 Daily `SearchConsoleImportJob` and `pipelinq:marketing:search-console:import`
  - **files**: `lib/BackgroundJob/SearchConsoleImportJob.php`, `lib/Command/SearchConsoleImportCommand.php`, `appinfo/info.xml`, `appinfo/register_command.php`
- [x] 3.5 Settings: `search.gsc.properties`, sensitive `search.gsc.service_account_key` never echoed back
  - **files**: `lib/Service/SettingsService.php`, `tests/Unit/Service/SettingsServiceTest.php`
- [x] 3.6 `GET /api/marketing/search-queries` and the Search queries page
  - **spec_ref**: `specs/marketing-campaign-attribution/spec.md#requirement-search-queries-page-lists-top-queries`
  - **files**: `lib/Service/SearchConsole/SearchQueryReportService.php`, `lib/Controller/SearchConsoleController.php`, `src/views/marketing/SearchQueries.vue`, `src/manifest.d/77-marketing-search-console.json`, `src/registry.js`

## 4. Docs and e2e

- [x] 4.1 UTM and campaign performance sections in `docs/user/marketing-blasts.md` and `.nl.md`; new `docs/user/search-console.md` and `.nl.md`
- [x] 4.2 `tests/e2e/spec-coverage/marketing-campaign-attribution.spec.ts`

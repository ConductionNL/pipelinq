# Tasks: contactmomenten-rapportage

## 0. Deduplication Check

- [ ] 0.1 Search `openspec/specs/` for any existing reporting/analytics specs that overlap with KPI aggregation, channel distribution, or agent performance — document findings in PR description
- [ ] 0.2 Search `openregister/lib/Service/` for `ReportingService`, `AnalyticsService`, or `KpiService` — verify no existing platform service covers FCR / SLA / avg-duration calculations before building custom logic
- [ ] 0.3 Verify `CnDashboardPage`, `CnChartWidget`, `CnStatsBlock`, and `CnMassExportDialog` are used for all dashboard, chart, KPI, and export UI respectively — no custom equivalents built

## 1. Backend Service

- [ ] 1.1 Create `lib/Service/ReportingService.php` with the following public methods:
  - `getKpis(string $from, string $to): array` — total, FCR %, avg duration (seconds), SLA compliance %
  - `getChannelDistribution(string $from, string $to): array` — contact counts keyed by channel
  - `getChannelTrend(string $from, string $to, string $granularity): array` — time-series data per channel
  - `getAgentPerformance(string $from, string $to): array` — per-agent: count, FCR %, avg duration
  - `getSlaCompliance(string $channel, string $from, string $to): float` — % contacts within target
  - `calculateFcr(array $contactmomenten): float` — count(outcome='opgelost') / count(total) * 100
  - `getSlaTargets(): array` — reads targets from `IAppConfig`
  - All queries use `ObjectService::findObjects($register, $schema, $params)` — 3 positional args
  - File-level docblock: `@spec openspec/changes/contactmomenten-rapportage/tasks.md#task-1`
  - SPDX header: `// SPDX-License-Identifier: EUPL-1.2` after `<?php`

- [ ] 1.2 Create `lib/Controller/ReportingController.php` with thin methods (< 10 lines each):
  - `GET /api/rapportage/kpis` — accepts `?from=&to=` query params
  - `GET /api/rapportage/channels` — channel distribution + trend data
  - `GET /api/rapportage/agents` — agent performance table
  - `GET /api/rapportage/sla` — SLA targets + compliance per channel
  - `PUT /api/rapportage/sla` — update SLA targets; MUST call `IGroupManager::isAdmin()` and return `HTTP 403` if not admin
  - Errors: catch `\Throwable`, log with `$this->logger->error(...)`, return `new JSONResponse(['message' => 'Operation failed'], 500)` — never `$e->getMessage()`
  - File-level docblock: `@spec openspec/changes/contactmomenten-rapportage/tasks.md#task-1`
  - SPDX header

## 2. Routes

- [ ] 2.1 Add 5 reporting API routes to `appinfo/routes.php`:
  - `GET /api/rapportage/kpis`
  - `GET /api/rapportage/channels`
  - `GET /api/rapportage/agents`
  - `GET /api/rapportage/sla`
  - `PUT /api/rapportage/sla`
  - Ensure all specific routes appear **before** any wildcard `{slug}` routes

## 3. Frontend Views

- [ ] 3.1 Create `src/views/rapportage/RapportageDashboard.vue`:
  - `CnDashboardPage` wrapping 4 `CnStatsBlock` cards: Total Contacts, FCR %, Avg Handling Time, SLA Compliance %
  - `CnChartWidget` (type: `donut`) — channel distribution
  - `CnChartWidget` (type: `line`) — contact volume trend (last N days based on selected range)
  - Date range selector component with options: Vandaag / Deze week / Deze maand / Aangepast
  - Auto-refresh every 60 s via `setInterval` in `created()`, cleared in `beforeDestroy()`
  - Every `await` on an API call wrapped in `try/catch` with user-facing error via `CnEmptyState` or toast
  - All user-visible strings via `this.t('pipelinq', 'key')` — no hardcoded Dutch
  - SPDX header: `<!-- SPDX-License-Identifier: EUPL-1.2 -->`

- [ ] 3.2 Create `src/views/rapportage/ChannelAnalytics.vue`:
  - `CnChartWidget` (bar) — contacts per channel for selected period
  - `CnChartWidget` (line) — channel volume trend with one series per active channel
  - `CnDataTable` — channel, contact count, SLA target, compliance %, `CnStatusBadge` (green/red)
  - Date range selector (same options as dashboard, shared state via provide/inject or route query param)
  - SPDX header

- [ ] 3.3 Create `src/views/rapportage/AgentPerformance.vue`:
  - `CnDataTable` — columns: agent name, contacts handled, FCR %, avg handling time (MM:SS)
  - Default sort: contacts descending; columns sortable by click
  - Only agents with ≥ 1 contact in selected period shown
  - Date range selector (shared)
  - SPDX header

## 4. Navigation and Routing

- [ ] 4.1 Add rapportage routes to `src/router/index.js` (history mode, all named, no `/settings` route):
  - `/rapportage` → `RapportageDashboard` (name: `Rapportage`)
  - `/rapportage/kanalen` → `ChannelAnalytics` (name: `ChannelAnalytics`)
  - `/rapportage/agenten` → `AgentPerformance` (name: `AgentPerformance`)

- [ ] 4.2 Add "Rapportage" entry to `src/navigation/MainMenu.vue`:
  - Icon: `mdi-chart-bar`
  - Label via `this.t('pipelinq', 'Reporting')` (Dutch translation in `l10n/nl.json`: `"Reporting": "Rapportage"`)
  - Route `:to="{ name: 'Rapportage' }"`

## 5. Translations

- [ ] 5.1 Add all new translation keys to `l10n/en.json` (English identity-mapped)
- [ ] 5.2 Add Dutch translations for all new keys to `l10n/nl.json`
  - Key examples: `"Reporting"`, `"Total Contacts"`, `"FCR %"`, `"Avg Handling Time"`, `"SLA Compliance"`, `"Channel Analytics"`, `"Agent Performance"`, `"Today"`, `"This week"`, `"This month"`, `"Custom"`

## 6. Testing

- [ ] 6.1 Create `tests/Unit/Service/ReportingServiceTest.php` with ≥ 3 test methods:
  - `testCalculateFcrWithMixedOutcomes()` — verify correct % from mixed outcome set
  - `testGetChannelDistributionGroupsByChannel()` — verify grouping logic
  - `testSlaComplianceEmptyDataset()` — verify 100% returned when no contacts
- [ ] 6.2 Add Newman / Postman collection entries in `tests/integration/` for all 5 endpoints:
  - Happy path (200) for each GET
  - Error path: PUT `/api/rapportage/sla` with non-admin credentials → expect 403
  - Error path: GET `/api/rapportage/kpis` with invalid date format → expect 400
- [ ] 6.3 Add browser test scenario for REQ-CR-001 (dashboard loads with KPI cards) per ADR-008

## 7. Verification

- [ ] 7.1 Run `npm run build` — verify no errors
- [ ] 7.2 Run `composer check:strict` — verify all PHPUnit tests pass
- [ ] 7.3 Call each new API endpoint with `curl` — verify response shape and HTTP status
- [ ] 7.4 Verify `PUT /api/rapportage/sla` returns HTTP 403 for a non-admin user
- [ ] 7.5 Pre-commit checklist (per ADR-015):
  - `grep -rL 'SPDX-License-Identifier' lib/Service/ReportingService.php lib/Controller/ReportingController.php` → expect no output
  - `grep -rn 'getMessage()' lib/Controller/ReportingController.php` → expect no matches
  - `grep -rn "from '@nextcloud/vue'" src/views/rapportage/` → expect zero matches
  - `grep -rn "window\.confirm\|window\.alert" src/views/rapportage/` → expect zero matches

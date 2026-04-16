# Tasks: Dashboard Analytics & Navi AI Agent

---

## Section 0: Deduplication Check

### Task 0.1: Verify no overlap with existing OpenRegister services
- **Spec ref**: `openspec/changes/dashboard/specs/dashboard/spec.md` (Reuse Analysis)
- **Files**: `openspec/specs/`, `openregister/lib/Service/`
- **Acceptance**: Document which OpenRegister services are reused; confirm no custom LLM wrapper, chart component, dashboard layout, or export controller is built
- [ ] Search `openspec/specs/` for existing analytics, navi, or reporting capabilities
- [ ] Verify `ChatService` and `ContextRetrievalHandler` exist in OpenRegister and cover Navi's LLM needs
- [ ] Verify `ExportService` + `CnMassExportDialog` cover report export needs (no custom export controller)
- [ ] Verify `CnChartWidget`, `CnStatsBlock`, `CnKpiGrid`, `CnDashboardPage` cover all UI needs (no custom chart components)
- [ ] Document findings in a comment block in `NaviService.php` file header
- **Finding**: No overlap — custom code limited to `NaviService` (intent detection + OpenRegister dispatch) and `AnalyticsService` (cross-module aggregation). All rendering, export, and LLM management delegated to platform.

---

## Section 1: Backend — Navi AI Analytics

### Task 1.1: Create NaviController
- **Spec ref**: `specs/dashboard/spec.md#REQ-DASH-001`, `#REQ-DASH-002`
- **Files**: `lib/Controller/NaviController.php`, `appinfo/routes.php`
- **Acceptance**:
  - POST `/api/navi/query` with `{ query, conversationId? }` returns `{ resultType, chartData?, tableData?, textResponse, suggestedFollowUps[] }`
  - Unauthenticated requests return HTTP 401 with `{ "message": "Unauthorized" }`
  - Controller body < 10 lines per method; all logic in NaviService
  - `@spec openspec/changes/dashboard/tasks.md#task-1.1` PHPDoc on class and method
- [ ] Create `lib/Controller/NaviController.php` with `query()` method
- [ ] Add `POST /api/navi/query` route to `appinfo/routes.php`
- [ ] Inject `NaviService` and `IUserSession` via constructor (private readonly)
- [ ] Apply `#[NoAdminRequired]` annotation (all authenticated users can query)
- [ ] Return `JSONResponse` with error message (static string, no getMessage()) on exception

### Task 1.2: Create NaviService
- **Spec ref**: `specs/dashboard/spec.md#REQ-DASH-001`, `#REQ-DASH-002`, `#REQ-DASH-003`
- **Files**: `lib/Service/NaviService.php`
- **Acceptance**:
  - `processQuery(string $query, string $userId): array` returns structured response
  - `detectIntent(string $query): string` classifies query (trend / breakdown / count / conversion)
  - `buildContext(string $intent, array $params): array` fetches objects via `ObjectService` with 3 positional args `($register, $schema, $params)`
  - `formatResponse(array $llmResponse, array $rawData): array` shapes the final API response
  - Empty data sets return `resultType: "text"` with human-readable message
  - All `ObjectService` calls use `findObjects($register, $schema, $params)` — never 1-arg signature
  - `@spec openspec/changes/dashboard/tasks.md#task-1.2` PHPDoc on class and public methods
- [ ] Create `lib/Service/NaviService.php`
- [ ] Implement `processQuery()`, `detectIntent()`, `buildContext()`, `formatResponse()`
- [ ] Inject `ChatService`, `ContextRetrievalHandler`, `ObjectService`, `IAppConfig` via constructor
- [ ] Handle missing register/schema config gracefully (return text result, no exception)
- [ ] Ensure no PII in log messages (ADR-005)

### Task 1.3: Unit tests for NaviService
- **Spec ref**: `specs/dashboard/spec.md#REQ-DASH-001`
- **Files**: `tests/Unit/Service/NaviServiceTest.php`
- **Acceptance**: ≥ 4 test methods covering intent detection, context building, empty result, and invalid query
- [ ] Test `detectIntent()` with trend, count, breakdown, and unrecognized queries
- [ ] Test `processQuery()` returns `resultType: "text"` when ObjectService returns empty array
- [ ] Test `formatResponse()` with chart data (series array present)
- [ ] Test `formatResponse()` with table data (rows array present)

### Task 1.4: Unit tests for NaviController
- **Spec ref**: `specs/dashboard/spec.md#REQ-DASH-002`
- **Files**: `tests/Unit/Controller/NaviControllerTest.php`
- **Acceptance**: ≥ 3 test methods covering success, NaviService exception, and missing query param
- [ ] Test successful query returns 200 with expected shape
- [ ] Test NaviService exception returns 500 with static error message (not getMessage())
- [ ] Test missing `query` field returns 400

---

## Section 2: Backend — Unified Analytics

### Task 2.1: Create AnalyticsController
- **Spec ref**: `specs/dashboard/spec.md#REQ-DASH-011`
- **Files**: `lib/Controller/AnalyticsController.php`, `appinfo/routes.php`
- **Acceptance**:
  - `GET /api/analytics/overview?period={period}` returns overview KPIs
  - `GET /api/analytics/trends?metric={metric}&period={period}` returns time-series
  - Unsupported `metric` returns HTTP 400 with `{ "message": "Unsupported metric" }`
  - Controller body < 10 lines per method
  - `@spec openspec/changes/dashboard/tasks.md#task-2.1` PHPDoc
- [ ] Create `lib/Controller/AnalyticsController.php` with `overview()` and `trends()` methods
- [ ] Add `GET /api/analytics/overview` and `GET /api/analytics/trends` routes to `appinfo/routes.php`
- [ ] Apply `#[NoAdminRequired]` (authenticated users only)
- [ ] Return static error messages on exception; log real error via `LoggerInterface`

### Task 2.2: Create AnalyticsService
- **Spec ref**: `specs/dashboard/spec.md#REQ-DASH-010`, `#REQ-DASH-011`
- **Files**: `lib/Service/AnalyticsService.php`
- **Acceptance**:
  - `getOverview(string $period): array` returns `leadConversionRate`, `avgRequestResolutionTime`, `contactMomentVolume`, `customerSatisfactionScore`, `period`, `previousPeriod`
  - `getTrends(string $metric, string $period): array` returns `{ metric, period, series: [{ date, value }] }`
  - `getFunnels(): array` returns lead-to-close and request-to-resolved funnel data
  - All `ObjectService` calls use `findObjects($register, $schema, $params)` — 3 positional args
  - Null returned for metrics with no data (e.g., no survey responses)
  - `@spec openspec/changes/dashboard/tasks.md#task-2.2` PHPDoc
- [ ] Create `lib/Service/AnalyticsService.php`
- [ ] Implement `getOverview()` — aggregate across lead, request, contactmoment, surveyResponse
- [ ] Implement `getTrends()` — time-bucket data from OpenRegister queries
- [ ] Implement `getFunnels()` — conversion rate calculations
- [ ] Inject `ObjectService`, `IAppConfig` via constructor

### Task 2.3: Unit tests for AnalyticsService
- **Spec ref**: `specs/dashboard/spec.md#REQ-DASH-011`
- **Files**: `tests/Unit/Service/AnalyticsServiceTest.php`
- **Acceptance**: ≥ 4 test methods
- [ ] Test `getOverview()` with mixed lead statuses returns correct conversion rate
- [ ] Test `getOverview()` with no survey responses returns `customerSatisfactionScore: null`
- [ ] Test `getTrends()` with unsupported metric throws `\InvalidArgumentException`
- [ ] Test `getTrends()` with no data returns empty series array

### Task 2.4: Unit tests for AnalyticsController
- **Spec ref**: `specs/dashboard/spec.md#REQ-DASH-011`
- **Files**: `tests/Unit/Controller/AnalyticsControllerTest.php`
- **Acceptance**: ≥ 3 test methods
- [ ] Test `overview()` returns 200 with correct JSON shape
- [ ] Test `trends()` with unsupported metric returns 400
- [ ] Test `trends()` returns 200 with `series` array

---

## Section 3: Frontend — Navi AI Widget

### Task 3.1: Create NaviAnalyticsWidget.vue
- **Spec ref**: `specs/dashboard/spec.md#REQ-DASH-001`, `#REQ-DASH-003`
- **Files**: `src/components/widgets/NaviAnalyticsWidget.vue`
- **Acceptance**:
  - Text input + submit button for query entry
  - Conversation history rendered in chronological order
  - Results rendered as `CnChartWidget` (chart), `CnTableWidget` (table), or plain text
  - Follow-up suggestion chips appear below each result (max 3); clicking a chip submits it
  - `conversationId` tracked in component state and sent with each request
  - Empty result displays human-readable message (not blank)
  - All strings via `this.t('pipelinq', '...')` — no hardcoded text
  - All `await` calls in `try/catch` with user-facing error feedback
  - Imports from `@conduction/nextcloud-vue` (never `@nextcloud/vue`)
  - SPDX header: `<!-- SPDX-License-Identifier: EUPL-1.2 -->`
- [ ] Create component with `data()`: `query`, `conversationId`, `messages`, `loading`
- [ ] Implement `submitQuery()` method — POST to `/api/navi/query` via `@nextcloud/axios`
- [ ] Render `CnChartWidget` when `resultType === 'chart'`
- [ ] Render `CnTableWidget` when `resultType === 'table'`
- [ ] Render suggestion chips when `suggestedFollowUps.length > 0`
- [ ] Implement `selectSuggestion(suggestion)` — pre-fills and auto-submits
- [ ] Add `scoped` to `<style>` block; use only Nextcloud CSS variables
- [ ] Register all used components in `components: {}` (Vue 2 requirement)

---

## Section 4: Frontend — Unified Analytics Panel

### Task 4.1: Create AnalyticsDashboard.vue
- **Spec ref**: `specs/dashboard/spec.md#REQ-DASH-010`
- **Files**: `src/components/widgets/AnalyticsDashboard.vue`
- **Acceptance**:
  - Fetches `GET /api/analytics/overview?period={period}` on mount and on period change
  - Fetches `GET /api/analytics/trends?metric=leads&period={period}` for line chart
  - Fetches `GET /api/analytics/trends?metric=requests-by-category&period={period}` for bar chart
  - All 3 requests issued in parallel via `Promise.all`
  - Period selector rendered in `header-actions` slot of the wrapping widget (ADR-018)
  - Period options: "Deze week", "Deze maand", "Dit kwartaal", "Dit jaar"
  - KPIs rendered via `CnStatsBlock` with trend indicators (up/down arrows)
  - Charts rendered via `CnChartWidget`
  - All strings via `this.t('pipelinq', '...')`
  - `try/catch` around all `await` calls
  - SPDX header
- [ ] Create component with `data()`: `period`, `overview`, `leadTrend`, `requestsByCategory`, `loading`, `error`
- [ ] Implement `fetchAll()` — parallel `Promise.all` for all 3 endpoints
- [ ] Implement `onPeriodChange()` — re-fetches all data
- [ ] Render `CnKpiGrid` wrapping 4 `CnStatsBlock` components for overview KPIs
- [ ] Render line chart `CnChartWidget` for lead trends
- [ ] Render bar chart `CnChartWidget` for requests by category
- [ ] Add `scoped` style block; Nextcloud CSS variables only
- [ ] Register all used components in `components: {}`

---

## Section 5: Frontend — Report Export Panel

### Task 5.1: Create ReportExportPanel.vue
- **Spec ref**: `specs/dashboard/spec.md#REQ-DASH-020`, `#REQ-DASH-021`
- **Files**: `src/components/widgets/ReportExportPanel.vue`
- **Acceptance**:
  - Panel renders collapsed by default; clicking header expands/collapses
  - Entity type selector: Leads, Verzoeken, Contactmomenten, Tevredenheidsscores
  - Period selector: week, month, quarter, year
  - Format selector delegated to `CnMassExportDialog` (no custom format picker)
  - "Download Report" button opens `CnMassExportDialog` with entity type + period filter pre-applied
  - No custom export controller — export handled entirely by `ExportService` via `CnMassExportDialog`
  - Expand/collapse toggle responds to keyboard (Enter + Space) — WCAG AA
  - All controls keyboard-navigable via Tab
  - All strings via `this.t('pipelinq', '...')`
  - SPDX header
- [ ] Create component with `data()`: `expanded`, `entityType`, `period`
- [ ] Implement `toggle()` — toggles `expanded` state
- [ ] Implement `downloadReport()` — opens `CnMassExportDialog` with pre-configured filters
- [ ] Add `aria-expanded` attribute on toggle button (WCAG AA)
- [ ] Add keyboard handler for Enter/Space on toggle (not just click)
- [ ] Add `scoped` style block; Nextcloud CSS variables only
- [ ] Register `CnMassExportDialog` in `components: {}`

---

## Section 6: Dashboard Integration

### Task 6.1: Register new widgets in Dashboard.vue
- **Spec ref**: `specs/dashboard/spec.md#REQ-DASH-030`
- **Files**: `src/views/Dashboard.vue`
- **Acceptance**:
  - `DEFAULT_WIDGETS` array extended with `navi-analytics`, `unified-analytics`, `report-export`
  - `DEFAULT_LAYOUT` extended:
    - `unified-analytics`: gridY: 3, gridWidth: 12, gridHeight: 5
    - `navi-analytics`: gridY: 8, gridWidth: 12, gridHeight: 6
    - `report-export`: gridY: 14, gridWidth: 12, gridHeight: 3
  - Template includes `#widget-navi-analytics`, `#widget-unified-analytics`, `#widget-report-export` slots
  - Total widget count in `DEFAULT_WIDGETS`: 10 (7 existing + 3 new)
  - `NaviAnalyticsWidget`, `AnalyticsDashboard`, `ReportExportPanel` imported and registered in `components: {}`
  - SPDX header preserved
- [ ] Add 3 entries to `DEFAULT_WIDGETS` in `Dashboard.vue`
- [ ] Add 3 layout items to `DEFAULT_LAYOUT`
- [ ] Add 3 slot templates in the `CnDashboardPage` template
- [ ] Import and register `NaviAnalyticsWidget`, `AnalyticsDashboard`, `ReportExportPanel`
- [ ] Verify existing widget slots and layout items are unchanged

---

## Section 7: i18n

### Task 7.1: Add translation keys for all new user-visible strings
- **Spec ref**: ADR-007-i18n
- **Files**: `l10n/en.json`, `l10n/nl.json`
- **Acceptance**:
  - All new `this.t('pipelinq', '...')` calls in Sections 3–5 have matching entries in both `en.json` and `nl.json`
  - Keys are English (never Dutch as primary key)
  - Both files contain exactly the same keys (zero gaps)
- [ ] Collect all new translation keys from NaviAnalyticsWidget, AnalyticsDashboard, ReportExportPanel
- [ ] Add English identity entries to `l10n/en.json`
- [ ] Add Dutch translations to `l10n/nl.json`
- [ ] Run `grep -rn "t('pipelinq'" src/components/widgets/Navi*.vue src/components/widgets/Analytics*.vue src/components/widgets/Report*.vue` to verify completeness

---

## Section 8: API Integration Tests

### Task 8.1: Newman/Postman integration tests for Navi API
- **Spec ref**: `specs/dashboard/spec.md#REQ-DASH-001`, `#REQ-DASH-002`, ADR-008-testing
- **Files**: `tests/integration/navi.postman_collection.json`
- **Acceptance**: Tests cover happy path (200), unauthenticated (401), missing query (400)
- [ ] Create Postman collection with environment variable placeholders for credentials (no hardcoded defaults)
- [ ] Test `POST /api/navi/query` with valid query — expect 200
- [ ] Test `POST /api/navi/query` without auth — expect 401
- [ ] Test `POST /api/navi/query` with missing `query` field — expect 400

### Task 8.2: Newman/Postman integration tests for Analytics API
- **Spec ref**: `specs/dashboard/spec.md#REQ-DASH-011`, ADR-008-testing
- **Files**: `tests/integration/analytics.postman_collection.json`
- **Acceptance**: Tests cover all 3 endpoints, error paths
- [ ] Test `GET /api/analytics/overview?period=month` — expect 200 with all KPI fields
- [ ] Test `GET /api/analytics/trends?metric=leads&period=month` — expect 200 with `series` array
- [ ] Test `GET /api/analytics/trends?metric=unknown` — expect 400
- [ ] Test both endpoints without auth — expect 401

---

## Section 9: Pre-commit Verification

### Task 9.1: Run ADR-015 pre-commit checklist
- **Spec ref**: ADR-015-common-patterns
- **Files**: All new files in `lib/` and `src/`
- **Acceptance**: All 15 checklist items pass; any failure fixed across ALL files (not just one)
- [ ] SPDX headers: `grep -rL 'SPDX-License-Identifier' lib/Controller/NaviController.php lib/Service/NaviService.php lib/Controller/AnalyticsController.php lib/Service/AnalyticsService.php src/components/widgets/`
- [ ] ObjectService calls: `grep -rn 'findObject\|saveObject\|findObjects' lib/Service/NaviService.php lib/Service/AnalyticsService.php` — verify all use 3 positional args
- [ ] Error responses: `grep -rn 'getMessage()' lib/Controller/` — must be zero matches
- [ ] Auth checks: POST routes (`/api/navi/query`) have `#[NoAdminRequired]`; no public routes without `#[PublicPage]`
- [ ] Store registration: no new Pinia stores added (analytics data stays in component-local state)
- [ ] Translations: `grep -rn "'" src/components/widgets/ --include='*.vue' | grep -v "this\.t\|import\|//\|console"` — no hardcoded strings
- [ ] try/catch: all `await` calls in new Vue components wrapped with try/catch
- [ ] No raw fetch: `grep -rn 'fetch(' src/components/widgets/ --include='*.vue'` — zero matches
- [ ] Import source: `grep -rn "from '@nextcloud/vue'" src/` — zero matches
- [ ] Component imports: every `<CnChartWidget>`, `<CnTableWidget>`, `<CnStatsBlock>`, `<CnKpiGrid>`, `<CnMassExportDialog>` in new templates is imported AND listed in `components: {}`
- [ ] Translation keys: all `t()` keys English (not Dutch)
- [ ] Route consistency: `/api/navi/query` and `/api/analytics/*` routes registered in `appinfo/routes.php`
- [ ] Task completeness: every `[x]` task above is fully implemented — not a stub or TODO

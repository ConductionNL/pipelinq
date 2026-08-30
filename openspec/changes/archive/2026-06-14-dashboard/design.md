# Design: Dashboard Analytics & Navi AI Agent

## Context

The Pipelinq core dashboard (KPI cards, Requests-by-Status chart, My Work widget, Client Overview widget) is already implemented. This change adds three analytics capabilities on top of the existing dashboard:

1. **Navi AI Analytics Agent** — conversational natural-language analytics powered by `ChatService` + `ContextRetrievalHandler`
2. **Unified Analytics Layer** — real-time cross-module KPI panels and trend charts aggregating all CRM entities
3. **Funder Reporting Export** — structured report generation and download for stakeholder use

No new OpenRegister schemas are introduced. All three features query and aggregate data from existing entities: `lead`, `client`, `request`, `contactmoment`, `pipeline`, `task`, `complaint`, `survey`, `surveyResponse`, `leadProduct`, `product`.

---

## Goals / Non-Goals

**Goals:**
- Provide a natural-language query interface (Navi) returning charts and tables inline
- Provide a cross-module analytics panel with trend visualizations
- Provide exportable management reports (CSV / Excel / JSON) via the existing `ExportService`
- Register all new widgets in the existing `CnDashboardPage` grid
- Integrate with the Nextcloud Dashboard Widget API where appropriate

**Non-Goals:**
- HR / payroll analytics (not part of Pipelinq data model — demand: 2, out of scope)
- Geographic / area condition statistics (Enterprise tier — demand: 2, out of scope)
- AI model training or fine-tuning
- Custom report builder drag-and-drop designer (deferred to V2)
- Multi-tenant analytics beyond what OpenRegister isolation provides
- Predictive scoring (Enterprise tier)

---

## Decisions

### 1. Navi uses ChatService + ContextRetrievalHandler (no custom LLM plumbing)

**Decision**: Implement `NaviService` as a thin orchestration layer that calls `ChatService::chat()` with a constructed system prompt and OpenRegister query results as context. The `ContextRetrievalHandler` already supports RAG-based retrieval from OpenRegister objects.

**Rationale**: `ChatService` and `ContextRetrievalHandler` are provided by OpenRegister and handle multi-turn conversation, context windowing, and LLM call management. Building custom plumbing would duplicate this capability (violates ADR-012-deduplication).

**Alternative considered**: Custom LLM controller. Rejected — ChatService already exists.

### 2. NaviController is a thin pass-through; NaviService owns logic

**Decision**: `NaviController` validates input and delegates to `NaviService`. `NaviService` handles: query parsing, intent detection, OpenRegister dispatch via `ObjectService`, result aggregation, and response shape building.

**Rationale**: Strict 3-layer pattern (ADR-003-backend). Controllers stay under 10 lines per method.

### 3. AnalyticsController provides dedicated aggregate endpoints

**Decision**: Create `AnalyticsController` with summary endpoints: `/api/analytics/overview`, `/api/analytics/trends`, `/api/analytics/funnels`. Frontend calls these instead of fetching raw objects.

**Rationale**: Cross-module aggregation (e.g., conversion rate from lead to closed) requires server-side computation across multiple object types. Pushing this to the frontend would require fetching large collections and computing in the browser.

### 4. Report export delegates to existing ExportService

**Decision**: `ReportExportPanel.vue` calls the existing `ExportService` endpoints from `@conduction/nextcloud-vue`. No custom export controller.

**Rationale**: `ExportService` already supports CSV, Excel, and JSON with format picker. The only custom work is the report definition (which fields, which entities, which period filter).

### 5. NaviAnalyticsWidget renders results as CnChartWidget or CnTableWidget

**Decision**: Navi responses include a `resultType` field (`chart` | `table` | `text`). The frontend renders the appropriate `@conduction/nextcloud-vue` component based on this type.

**Rationale**: Reuses platform chart and table components. No custom chart rendering.

### 6. Dashboard.vue widget registration via CnDashboardPage slots

**Decision**: New widgets are registered by adding entries to `DEFAULT_WIDGETS` in `Dashboard.vue` and providing matching `#widget-{id}` slot templates.

**Rationale**: This matches the existing widget registration pattern. No changes to the CnDashboardPage API are needed.

---

## Architecture

### Backend

#### NaviController
- **Location**: `lib/Controller/NaviController.php`
- **Routes**: `POST /api/navi/query`
- **Purpose**: Accept a natural-language query from the frontend, delegate to `NaviService`, return structured response
- **Dependencies**: `NaviService`, `IUserSession`, `IGroupManager`
- **Response shape**: `{ query, resultType, chartData?, tableData?, textResponse, suggestedFollowUps[] }`

#### NaviService
- **Location**: `lib/Service/NaviService.php`
- **Purpose**: Parse intent, dispatch OpenRegister queries via `ObjectService`, aggregate results, call `ChatService`
- **Dependencies**: `ChatService`, `ContextRetrievalHandler`, `ObjectService`, `IAppConfig`
- **Methods**:
  - `processQuery(string $query, string $userId): array` — main entry point
  - `detectIntent(string $query): string` — classify query type (trend, breakdown, count, conversion)
  - `buildContext(string $intent, array $params): array` — fetch relevant objects from OpenRegister
  - `formatResponse(array $llmResponse, array $rawData): array` — shape final response

#### AnalyticsController
- **Location**: `lib/Controller/AnalyticsController.php`
- **Routes**: `GET /api/analytics/overview`, `GET /api/analytics/trends`, `GET /api/analytics/funnels`
- **Purpose**: Provide pre-aggregated analytics data for the Unified Analytics Layer panel
- **Dependencies**: `AnalyticsService`, `IGroupManager`

#### AnalyticsService
- **Location**: `lib/Service/AnalyticsService.php`
- **Purpose**: Aggregate KPIs and trend series across modules using `ObjectService`
- **Dependencies**: `ObjectService`, `IAppConfig`
- **Methods**:
  - `getOverview(string $period): array` — cross-module KPI snapshot
  - `getTrends(string $metric, string $period): array` — time-series data for charting
  - `getFunnels(): array` — lead-to-close conversion and request-to-resolved funnel

### Frontend

#### NaviAnalyticsWidget.vue
- **Location**: `src/components/widgets/NaviAnalyticsWidget.vue`
- **Purpose**: Chat interface for Navi AI — text input, conversation history, inline result rendering
- **Uses**: `CnChartWidget` (charts), `CnTableWidget` (tables), `@nextcloud/axios` (API calls)
- **State**: Local component state (conversation history, loading, current response)

#### AnalyticsDashboard.vue
- **Location**: `src/components/widgets/AnalyticsDashboard.vue`
- **Purpose**: Unified cross-module analytics panel with KPI trend cards and charts
- **Uses**: `CnDashboardPage`, `CnChartWidget`, `CnStatsBlock`, `CnKpiGrid`
- **Data**: Fetched from `GET /api/analytics/overview` and `GET /api/analytics/trends`

#### ReportExportPanel.vue
- **Location**: `src/components/widgets/ReportExportPanel.vue`
- **Purpose**: Collapsible report configuration panel — period picker, entity type selector, format picker, download button
- **Uses**: `CnMassExportDialog` (from `@conduction/nextcloud-vue`) for format selection and download
- **State**: Local component state (period, entityType, format selection)

### Modified Files

| File | Change |
|------|--------|
| `src/views/Dashboard.vue` | Register 3 new widgets in `DEFAULT_WIDGETS`; add `#widget-navi`, `#widget-analytics`, `#widget-report-export` slots |
| `appinfo/routes.php` | Add routes for NaviController and AnalyticsController |
| `src/router/index.js` | No new routes — widgets are embedded in the dashboard, not separate pages |
| `l10n/en.json` + `l10n/nl.json` | Add translation keys for all new user-visible strings |

---

## Reuse Analysis

This change maximizes reuse of platform services:

| Platform Component | Used By | Purpose |
|-------------------|---------|---------|
| `ChatService` | `NaviService` | LLM call management, multi-turn conversation |
| `ContextRetrievalHandler` | `NaviService` | RAG retrieval from OpenRegister objects |
| `ObjectService` | `NaviService`, `AnalyticsService` | Fetch and filter CRM objects |
| `ExportService` | `ReportExportPanel.vue` via `CnMassExportDialog` | CSV/Excel/JSON export |
| `CnChartWidget` | `NaviAnalyticsWidget.vue`, `AnalyticsDashboard.vue` | Chart rendering (ApexCharts) |
| `CnTableWidget` | `NaviAnalyticsWidget.vue` | Table rendering for query results |
| `CnStatsBlock` | `AnalyticsDashboard.vue` | KPI metric cards |
| `CnKpiGrid` | `AnalyticsDashboard.vue` | Responsive KPI layout |
| `CnMassExportDialog` | `ReportExportPanel.vue` | Format picker + download |
| `CnDashboardPage` | `Dashboard.vue` | Widget grid layout |
| `useDashboardView` | `Dashboard.vue` | Widget/layout/edit composable |

**No custom chart components, export controllers, LLM wrappers, or dashboard layout systems are built.** The only custom logic is: query intent detection in `NaviService`, cross-module aggregation in `AnalyticsService`, and report period filtering in `ReportExportPanel.vue`.

---

## Seed Data

No new OpenRegister schemas are introduced by this change. The analytics and reporting features read from existing entities (`lead`, `client`, `request`, `contactmoment`, `task`, `complaint`, `survey`, `surveyResponse`, `leadProduct`, `product`).

Meaningful dashboard analytics require existing seed data from other changes. The following objects should be present in the development register (added by their respective changes) for the dashboard to render non-zero values:

**Leads (for KPI cards and analytics trends):**
```json
[
  {
    "@self": { "register": "pipelinq", "schema": "lead", "slug": "lead-advies-gemeente-rotterdam" },
    "title": "Adviesopdracht Gemeente Rotterdam",
    "client": "client-gemeente-rotterdam",
    "value": 45000,
    "probability": 70,
    "stage": "Offerte",
    "status": "open",
    "assignee": "jan.de.vries",
    "expectedCloseDate": "2026-06-30"
  },
  {
    "@self": { "register": "pipelinq", "schema": "lead", "slug": "lead-implementatie-waterboard" },
    "title": "Implementatie Waterschapsbeheer",
    "client": "client-waterschap-hollandse-delta",
    "value": 128000,
    "probability": 40,
    "stage": "Uitwerking",
    "status": "open",
    "assignee": "petra.bakker",
    "expectedCloseDate": "2026-05-15"
  },
  {
    "@self": { "register": "pipelinq", "schema": "lead", "slug": "lead-training-vng" },
    "title": "Training VNG-medewerkers",
    "client": "client-vng",
    "value": 12500,
    "probability": 90,
    "stage": "Gewonnen",
    "status": "won",
    "assignee": "jan.de.vries",
    "expectedCloseDate": "2026-04-10"
  }
]
```

**Requests (for status chart and cross-module analytics):**
```json
[
  {
    "@self": { "register": "pipelinq", "schema": "request", "slug": "request-informatievraag-belasting" },
    "title": "Informatievraag gemeentelijke belastingen",
    "status": "new",
    "priority": "normal",
    "channel": "phone",
    "category": "belastingen",
    "requestedAt": "2026-04-14T09:15:00+02:00"
  },
  {
    "@self": { "register": "pipelinq", "schema": "request", "slug": "request-vergunning-uitrit" },
    "title": "Aanvraag uitritvergunning Hoofdstraat 42",
    "status": "in_progress",
    "priority": "high",
    "channel": "email",
    "category": "vergunningen",
    "requestedAt": "2026-04-10T13:30:00+02:00"
  },
  {
    "@self": { "register": "pipelinq", "schema": "request", "slug": "request-wmo-aanvraag" },
    "title": "WMO hulpmiddelenverzoek",
    "status": "completed",
    "priority": "urgent",
    "channel": "counter",
    "category": "wmo-zorg",
    "requestedAt": "2026-04-08T11:00:00+02:00"
  }
]
```

These objects are defined as seed data in their respective changes (`lead-management`, `contactmomenten`, etc.) and are loaded via `importFromApp()` during install.

---

## Migration Plan

1. Create `NaviController` and `NaviService` PHP classes
2. Create `AnalyticsController` and `AnalyticsService` PHP classes
3. Register routes in `appinfo/routes.php`
4. Create `NaviAnalyticsWidget.vue`, `AnalyticsDashboard.vue`, `ReportExportPanel.vue`
5. Register new widgets in `Dashboard.vue` `DEFAULT_WIDGETS` and add slot templates
6. Add translation keys to `l10n/en.json` and `l10n/nl.json`
7. Write unit tests for `NaviService` and `AnalyticsService`
8. Write API integration tests for `NaviController` and `AnalyticsController`

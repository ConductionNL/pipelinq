# Delta Spec: Dashboard Analytics & Navi AI Agent

## Purpose

Extend the existing Pipelinq dashboard with three analytics capabilities:

1. **Navi AI Analytics Agent** (demand: 432, 139 tender mentions) — conversational natural-language analytics
2. **Unified Analytics Layer** (demand: 240, 79 tender mentions) — real-time cross-module KPI panels and trend charts
3. **Funder Reporting Export** (demand: 145, 10 tender mentions) — structured report generation for stakeholder sharing

**Main spec ref**: `openspec/specs/dashboard/spec.md`
**Feature tier**: V1 (Navi AI, Unified Analytics), V2 deferred (custom report builder)
**Schema.org**: `schema:AnalyzeAction` (Navi), `schema:Report` (Funder Reporting)
**No new OpenRegister schemas** — all features aggregate existing entities.

---

## ADDED Requirements

---

### REQ-DASH-001: Navi AI Analytics Widget

The dashboard MUST include a Navi AI Analytics widget backed by `NaviController` and `NaviService`, accepting natural-language queries and returning results as inline charts or tables.

**Feature tier**: V1
**Schema.org**: `schema:AnalyzeAction`
**Backend**: `NaviController` + `NaviService`
**Frontend**: `NaviAnalyticsWidget.vue`

#### Scenario: Submit natural-language query

- GIVEN the user opens the Navi widget on the dashboard
- WHEN the user types a query such as "Hoeveel leads zijn er deze maand gewonnen?" and presses Enter
- THEN the frontend MUST POST to `/api/navi/query` with `{ query: "...", conversationId: "..." }`
- AND the backend MUST respond within 30 seconds with `{ resultType, chartData?, tableData?, textResponse, suggestedFollowUps[] }`
- AND the widget MUST render the result using `CnChartWidget` (for chart results) or `CnTableWidget` (for table results) or plain text (for text results)

#### Scenario: Conversational follow-up

- GIVEN the user has submitted an initial query and received a result
- WHEN the user types a follow-up query referencing the previous result
- THEN the frontend MUST include the `conversationId` in subsequent requests to maintain context
- AND the response MUST reflect the accumulated conversation context

#### Scenario: Empty result set

- GIVEN the user submits a valid query but no matching data exists
- WHEN NaviService processes the query
- THEN the response MUST include `resultType: "text"` with a human-readable message explaining no data was found
- AND the widget MUST display this message instead of an empty chart or table

#### Scenario: Invalid or ambiguous query

- GIVEN the user submits a query the backend cannot parse into a valid intent
- WHEN NaviService processes the query
- THEN the response MUST include `resultType: "text"` with a clarification request
- AND the frontend MUST display the clarification message and keep the input field active

#### Scenario: Navi widget in dashboard layout

- WHEN the user views the dashboard
- THEN the Navi widget MUST appear in the dashboard grid registered as `widget-id: "navi-analytics"`
- AND the widget MUST span 12 columns (full width) by default in the layout
- AND the widget MUST be reorderable via the `CnDashboardPage` drag-drop interface

---

### REQ-DASH-002: Navi API Authorization

The Navi API MUST enforce Nextcloud authentication and MUST NOT expose data outside the current user's OpenRegister access scope.

**Feature tier**: V1

#### Scenario: Unauthenticated request rejected

- GIVEN an unauthenticated request is made to `POST /api/navi/query`
- WHEN the Nextcloud auth middleware evaluates the request
- THEN the server MUST return HTTP 401
- AND the response body MUST contain `{ "message": "Unauthorized" }` (static, no stack trace)

#### Scenario: Query scoped to user's data

- GIVEN the user is authenticated
- WHEN NaviService dispatches OpenRegister queries
- THEN all `ObjectService` calls MUST use the standard multi-tenancy context
- AND the response MUST only contain objects the current user is authorized to read

---

### REQ-DASH-003: Navi Suggested Follow-Ups

The Navi widget MUST display suggested follow-up questions after each response to guide discovery.

**Feature tier**: V1

#### Scenario: Display follow-up chips

- GIVEN Navi has returned a result with a non-empty `suggestedFollowUps` array
- WHEN the result is rendered in the widget
- THEN the widget MUST display up to 3 follow-up suggestion chips below the result
- AND clicking a chip MUST pre-fill the query input with that suggestion and submit it automatically

#### Scenario: No suggested follow-ups

- GIVEN Navi returns a result where `suggestedFollowUps` is empty
- WHEN the result is rendered
- THEN no suggestion chips MUST appear (the chip area MUST be hidden, not empty/blank)

---

### REQ-DASH-010: Unified Analytics Dashboard Panel

The dashboard MUST include an "Analytics" panel (`AnalyticsDashboard.vue`) providing real-time cross-module KPIs and trend charts covering the full client lifecycle.

**Feature tier**: V1
**Backend**: `AnalyticsController` + `AnalyticsService`
**Frontend**: `AnalyticsDashboard.vue`

#### Scenario: Cross-module KPI overview

- GIVEN the user navigates to the dashboard
- WHEN the Analytics panel loads
- THEN the panel MUST fetch `GET /api/analytics/overview` and display the following KPIs using `CnStatsBlock`:
  - **Lead conversion rate** — percentage of leads with `status: "won"` over total leads in the selected period
  - **Average request resolution time** — mean duration between `requestedAt` and `completedAt` for resolved requests
  - **Contact moment volume** — count of `contactmoment` objects in the selected period
  - **Customer satisfaction score** — mean score from `surveyResponse` objects in the selected period (or "N/A" if none)
- AND each KPI MUST display a trend indicator (up/down arrow) comparing to the previous equal period

#### Scenario: Period selector

- WHEN the user views the Analytics panel
- THEN a period selector MUST be visible in the panel header using the `header-actions` slot pattern (ADR-018)
- AND the selector MUST offer: "Deze week", "Deze maand", "Dit kwartaal", "Dit jaar"
- AND changing the period MUST re-fetch `GET /api/analytics/overview?period={period}` and update all KPIs

#### Scenario: Trend chart — leads over time

- WHEN the Analytics panel renders
- THEN a line chart MUST display lead count and pipeline value over time using `CnChartWidget`
- AND the X-axis MUST represent time intervals appropriate to the selected period (days for week/month, weeks for quarter, months for year)
- AND the chart data MUST come from `GET /api/analytics/trends?metric=leads&period={period}`

#### Scenario: Trend chart — requests by category

- WHEN the Analytics panel renders
- THEN a bar chart MUST display request counts grouped by `category` using `CnChartWidget`
- AND the chart data MUST come from `GET /api/analytics/trends?metric=requests-by-category&period={period}`
- AND categories with zero requests in the period MUST be excluded from the chart

#### Scenario: Analytics panel widget registration

- WHEN the dashboard initializes
- THEN the Analytics panel MUST be registered as `widget-id: "unified-analytics"` in the `CnDashboardPage` widget definitions
- AND the widget MUST span 12 columns by default
- AND the widget MUST appear below the existing KPI cards row (gridY: 3 in default layout)

---

### REQ-DASH-011: Analytics API Endpoints

The `AnalyticsController` MUST provide aggregate endpoints consumed by the Analytics panel.

**Feature tier**: V1

#### Scenario: GET /api/analytics/overview

- GIVEN an authenticated user requests `GET /api/analytics/overview?period=month`
- WHEN `AnalyticsController` handles the request
- THEN the response MUST include HTTP 200 with a JSON body containing:
  - `leadConversionRate` (number, 0–100)
  - `avgRequestResolutionTime` (number, hours, or null if no resolved requests)
  - `contactMomentVolume` (integer)
  - `customerSatisfactionScore` (number, 1–5, or null if no survey responses)
  - `period` (string, echoed back)
  - `previousPeriod` (same fields for trend comparison)

#### Scenario: GET /api/analytics/trends

- GIVEN an authenticated user requests `GET /api/analytics/trends?metric=leads&period=month`
- WHEN `AnalyticsController` handles the request
- THEN the response MUST include HTTP 200 with `{ metric, period, series: [{ date, value }] }`
- AND the `date` field MUST be an ISO 8601 date string

#### Scenario: Unsupported metric returns 400

- GIVEN an authenticated user requests `GET /api/analytics/trends?metric=unknown`
- WHEN `AnalyticsController` handles the request
- THEN the server MUST return HTTP 400 with `{ "message": "Unsupported metric" }`
- AND the response MUST NOT include a stack trace or internal details

---

### REQ-DASH-020: Funder Reporting Export Panel

The dashboard MUST include a collapsible "Report Export" panel (`ReportExportPanel.vue`) allowing users to generate and download structured CRM performance reports.

**Feature tier**: V1
**Frontend**: `ReportExportPanel.vue`
**Reuses**: `ExportService` via `CnMassExportDialog`

#### Scenario: Configure and download a report

- GIVEN the user expands the Report Export panel
- WHEN the user selects: entity type (Leads / Requests / Contact Moments / Satisfaction), period (week/month/quarter/year), and format (CSV / Excel / JSON)
- AND clicks "Download Report"
- THEN the frontend MUST open `CnMassExportDialog` with the appropriate entity type and applied period filter
- AND the export MUST be performed by `ExportService` — no custom export controller is permitted

#### Scenario: Panel collapsed by default

- WHEN the dashboard loads
- THEN the Report Export panel MUST render in a collapsed state showing only the panel title and a brief description
- AND the user MUST be able to expand it by clicking the panel header

#### Scenario: Report export widget registration

- WHEN the dashboard initializes
- THEN the Report Export panel MUST be registered as `widget-id: "report-export"` in `CnDashboardPage`
- AND the widget MUST span 12 columns
- AND the widget MUST appear below the Analytics panel in the default layout

#### Scenario: Supported entity types in report

- WHEN the user opens the Report Export panel
- THEN the entity type selector MUST list at minimum:
  - "Leads" (queries `lead` schema)
  - "Verzoeken" (queries `request` schema)
  - "Contactmomenten" (queries `contactmoment` schema)
  - "Tevredenheidsscores" (queries `surveyResponse` schema)
- AND selecting an entity type MUST update the available field columns shown in `CnMassExportDialog`

---

### REQ-DASH-021: Report Export Accessibility

The Report Export panel MUST meet WCAG AA accessibility standards.

**Feature tier**: V1

#### Scenario: Keyboard-navigable controls

- WHEN the user navigates the Report Export panel using keyboard only
- THEN all controls (period selector, entity type selector, format picker, download button) MUST be reachable via Tab
- AND the expand/collapse toggle MUST respond to Enter and Space keys
- AND focus MUST not be trapped inside the panel

---

### REQ-DASH-030: Dashboard Widget Layout — Extended Default

The existing default dashboard layout MUST be extended to include the three new analytics widgets.

**Feature tier**: V1

#### Scenario: Updated default layout includes analytics widgets

- GIVEN a user has not customized their dashboard layout
- WHEN the dashboard renders
- THEN the default layout MUST include (in addition to existing widgets):
  - `navi-analytics` widget at gridY: 4, 12 columns, gridHeight: 6
  - `unified-analytics` widget at gridY: 3, 12 columns, gridHeight: 5
  - `report-export` widget at gridY: 10, 12 columns, gridHeight: 3
- AND all existing widget positions (KPI cards at row 1, chart + My Work at row 2, Client Overview at row 3) MUST remain unchanged

#### Scenario: Total widget count updated

- WHEN the dashboard initializes `CnDashboardPage`
- THEN the widget definitions array MUST contain exactly 10 widget definitions (7 existing + 3 new)
- AND all widgets MUST have `type: 'custom'` with matching `#widget-{id}` slot templates

---

## Out of Scope (explicitly excluded)

The following features from the context brief are excluded from this change:

| Feature | Demand | Reason Excluded |
|---------|--------|-----------------|
| HR and payroll analytics | 2 | Not applicable to Pipelinq data model (no HR entities) |
| Area condition statistics | 2 | Enterprise tier; no geographic data model in Pipelinq |
| Advanced HR decision analytics | 2 | Same as HR/payroll — wrong domain |
| Custom drag-and-drop report builder | — | Deferred to V2 |
| AI model fine-tuning | — | Enterprise tier |
| Predictive lead scoring | — | Enterprise tier |

---

## Standards & References

- `CnDashboardPage` / `CnChartWidget` / `CnStatsBlock` from `@conduction/nextcloud-vue` — dashboard grid and widget components
- `ChatService` + `ContextRetrievalHandler` from OpenRegister — LLM orchestration and RAG retrieval
- `ExportService` + `CnMassExportDialog` from `@conduction/nextcloud-vue` — export pipeline
- `ObjectService` from OpenRegister — cross-entity data access
- NL Design System — CSS custom properties for all styling (no hardcoded colors)
- WCAG AA — accessibility requirements
- ADR-003-backend — 3-layer controller/service/mapper pattern
- ADR-004-frontend — Vue 2, Pinia, `@conduction/nextcloud-vue` only
- ADR-012-deduplication — no custom dashboard layouts, chart components, or LLM plumbing
- ADR-017-component-composition — `CnDashboardPage` and `CnChartWidget` are self-contained; no double-wrapping
- ADR-018-widget-header-actions — period selector MUST use `header-actions` slot

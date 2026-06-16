# Proposal: Dashboard Analytics & Navi AI Agent

## Problem

The current Pipelinq dashboard provides operational KPIs (open leads, open requests, pipeline value, overdue count), a request status chart, a "My Work" list, and a client overview widget. These serve day-to-day monitoring but fall short of the analytics capabilities that dominate procurement requirements:

1. **No conversational analytics** — managers cannot ask natural language questions such as "which pipeline stage has the highest drop-off?" or "what is my conversion rate this quarter?" and get visual answers. 432 tender mentions explicitly demand AI-powered interactive analytics (highest demand of any dashboard feature).
2. **No cross-module unified reporting** — leads, requests, contact moments, complaints, tasks, and surveys are siloed. There is no single place to see how the full client lifecycle performs end-to-end. 240 tender mentions require a single-model cross-module analytics layer.
3. **No exportable management reports** — team leads and account managers need to produce periodic reports for funders, municipalities, or internal stakeholders. 145 tender mentions ask for data-driven decision support with export capability.

Together these three gaps cover 817 of the 823 total weighted tender mentions for dashboard-related features and represent the highest-demand unfulfilled area in Pipelinq.

## Proposed Change

### 1. Navi AI Analytics Agent (demand: 432)

Add a conversational analytics panel to the dashboard powered by Nextcloud's `ChatService` + `ContextRetrievalHandler`. The agent (code-named "Navi") accepts natural language queries, translates them into OpenRegister object queries, and returns results as charts or tables rendered inline in the chat.

- No new data schemas. Navi queries existing OpenRegister objects: `lead`, `client`, `request`, `contactmoment`, `pipeline`, `task`, `complaint`, `survey`, `surveyResponse`.
- Backend: `NaviController` + `NaviService` — handle query parsing, OpenRegister dispatch, and result aggregation.
- Frontend: `NaviAnalyticsWidget.vue` using `CnDashboardPage` widget slot, `CnChartWidget` for inline result rendering.

### 2. Unified Analytics Layer (demand: 240)

Add an "Analytics" dashboard panel providing real-time cross-module KPIs and trend charts that cover the full client lifecycle — from lead acquisition through request handling, contact moments, complaint resolution, and satisfaction scores.

- No new schemas. Aggregates existing entities.
- Backend: `AnalyticsController` with dedicated summary endpoints.
- Frontend: `AnalyticsDashboard.vue` using `CnDashboardPage` + `CnChartWidget` + `CnStatsBlock`.

### 3. Data-Driven Decision Analytics & Funder Reporting (demand: 145)

Add a report export panel that allows users to generate and download structured reports on CRM performance, suitable for sharing with funders and stakeholders.

- Uses existing `ExportService` for CSV/JSON/Excel output.
- Reports cover: lead conversion rates, request resolution SLAs, contact moment volume, and satisfaction scores per period.
- Frontend: `ReportExportPanel.vue` added to the dashboard as a collapsible widget.

### Out of Scope

- HR / payroll analytics (not applicable to Pipelinq data model)
- Geographic / area condition statistics (Enterprise tier)
- Predictive scoring and AI model training (Enterprise tier)
- Multi-tenant analytics isolation beyond what OpenRegister provides
- Custom report builder (V2 — drag-and-drop report designer)
- AI model fine-tuning on domain data (Enterprise)

## Impact

- **New backend files**: 2 controllers, 2 services
- **New frontend files**: 3 Vue components
- **Modified files**: `Dashboard.vue` (widget registration), `appinfo/routes.php` (new routes), `src/router/index.js` (analytics route)
- **Risk**: Medium — adds new routes and a ChatService integration. No schema changes, no migrations.

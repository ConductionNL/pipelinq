# Proposal: contactmomenten-rapportage

## Problem

Pipelinq has no reporting or analytics dashboard for contact moment data. KCC managers cannot monitor service quality, channel effectiveness, first-call resolution (FCR) rates, SLA compliance, or agent performance from a single view. Analytics capabilities appear in 98% of evaluated tenders with a combined demand score of 1,019 (432 for AI-powered analytics, 240 for unified cross-module reporting, 198 for advanced visualization, 145 for funder-facing reports). Without reporting, organizations cannot demonstrate compliance, optimize staffing, or produce evidence for funders.

## Solution

Implement a contactmomenten reporting module with:

1. **KPI Dashboard** — Real-time metrics via `CnDashboardPage` + `CnStatsBlock`: total contactmomenten, FCR rate, average handling time, SLA compliance percentage
2. **Channel analytics** — Distribution charts and time-series trends per channel (telefoon, email, balie, chat, social, brief) via `CnChartWidget`
3. **Agent performance overview** — Per-agent workload, FCR rate, and average handling time in a sortable table
4. **SLA configuration** — Configurable targets per channel stored in `IAppConfig`, editable by admins
5. **Date range filtering** — today / this week / this month / custom, applied uniformly across all views
6. **CSV export** — Export filtered contactmomenten via OpenRegister `ExportService` / `CnMassExportDialog`
7. **Rapportage navigation entry** — Dedicated section in MainMenu with sub-routes for channel and agent views

## Scope

- KPI dashboard with 4 metric cards: Total Contacts, FCR %, Avg Handling Time, SLA Compliance %
- Channel distribution analytics: donut chart (distribution) and line chart (trend over time)
- Agent performance table: contacts handled, FCR %, avg duration per agent
- SLA target configuration: per-channel wait time / response time targets and compliance thresholds
- Date range selector (today / this week / this month / custom) persisted within session
- CSV export of filtered contactmomenten data
- Rapportage section in MainMenu with routes for dashboard, channel analytics, and agent performance

## Out of scope

- Navi AI Analytics Agent (natural language queries against contactmomenten) — Phase 2 / Enterprise
- Unified cross-module reporting (contactmomenten + klachten + cases in one view) — Phase 2
- Scheduled report delivery via email — Phase 2
- PDF export with server-side chart rendering — Phase 2
- Real-time push via WebSockets — Phase 2
- WOO/Open Data reporting endpoints — Phase 3
- Funder-specific branded export templates — Phase 2

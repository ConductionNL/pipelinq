> SUPERSEDED 2026-05-31: feature implemented; archived twin archive/2026-03-21-contactmomenten-rapportage. Archived as already-delivered.

# Proposal: contactmomenten-rapportage

## Problem

No reporting or KPI dashboards exist for contact moment data. KCC managers cannot monitor service levels, first-call resolution rates, SLA compliance, or agent performance. This capability appears in **98% of klantinteractie-tenders** (51/52), making it a hard requirement for most procurement processes.

Current state:
- No contactmoment-specific analytics or KPI widgets
- No SLA threshold configuration per channel
- No agent performance statistics
- No data export for management reporting
- The existing Dashboard shows CRM KPIs (leads, requests) only — not KCC operational metrics

## Solution

Implement a dedicated Rapportage module within Pipelinq that aggregates contactmoment data from OpenRegister and presents it via management dashboards and export tools:

1. **KPI Dashboard** — Real-time metrics: total contacts, first-call resolution rate (FCR), SLA compliance percentage, and average handling time. Auto-refreshes every 60 seconds with a visible "Last updated" timestamp.
2. **Channel Analytics** — Distribution charts and comparison tables showing contact volume per channel (telefoon, e-mail, balie, chat) over configurable time periods with daily/weekly/monthly granularity.
3. **SLA Configuration** — Per-channel SLA targets (wait time, handling time, response time) stored via `IAppConfig` under the `pipelinq` namespace. Configurable warning and critical thresholds with three-color indicator coding.
4. **Agent Performance Overview** — Per-agent statistics (contacts handled, average handling time, FCR rate) and workload distribution chart sourced from contactmoment `agent` field.
5. **CSV Export** — One-click export of any report view as a semicolon-separated CSV file with UTF-8 BOM for correct Dutch character display in Excel.
6. **Reporting Service** — Dedicated `ReportingService` that aggregates contactmoment data from OpenRegister, calculates KPIs, and formats data for dashboard views and exports.

## Scope

- KPI dashboard (total contacts, FCR, SLA compliance, avg handling time) with 60-second auto-refresh
- Channel distribution analytics with daily/weekly/monthly granularity toggle
- Channel comparison table (contacts, avg handling time, FCR, SLA per channel)
- SLA configuration UI in admin settings with per-channel targets and thresholds
- Agent performance overview with ranking and workload distribution chart
- CSV export for all report views
- "Rapportage" navigation entry in the main sidebar menu

## Out of scope

- PDF export with server-side chart rendering (V1)
- Scheduled report delivery via background jobs (V1)
- Agent performance trends over time (V1)
- WOO/Open Data anonymized reporting (V1)
- Real-time telephony queue integration via PBX (V1)
- BI tool API endpoints with aggregate calculations (V1)

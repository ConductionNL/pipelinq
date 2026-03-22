# Proposal: contactmomenten-rapportage

## Problem

No reporting or KPI dashboards exist for contact moment data. KCC managers cannot monitor service levels, first-call resolution rates, SLA compliance, or agent performance. 98% of tenders require this capability.

## Solution

Implement a reporting dashboard with:
1. **KPI Dashboard** with real-time metrics (total contacts, FCR, SLA compliance, avg handling time)
2. **Channel analytics** with distribution charts and comparison tables
3. **SLA configuration** per channel stored in IAppConfig
4. **Export** as CSV and PDF
5. **Reporting service** for data aggregation from OpenRegister

## Scope

- KPI dashboard with auto-refresh
- Channel distribution analytics
- SLA configuration and monitoring
- Agent performance overview
- CSV export
- Reporting navigation entry

## Out of scope

- PDF export with server-side chart rendering (V1)
- Scheduled report delivery (V1)
- Agent performance trends (V1)
- WOO/Open Data reporting (V1)

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



## Design

# Design: contactmomenten-rapportage

## Architecture

### Backend

#### ReportingService (`lib/Service/ReportingService.php`)

- `getDailyKpis(string $date): array` — Total contacts, per channel, avg handling time, FCR rate
- `getSlaCompliance(string $channel, string $date): array` — SLA percentage calculation
- `getChannelDistribution(string $from, string $to, string $granularity): array` — Channel volume over time
- `getAgentPerformance(string $date): array` — Per-agent stats
- `calculateFcr(array $contactmomenten): float` — FCR calculation
- `exportCsv(array $data, array $headers): string` — Generate CSV content

#### ReportingController (`lib/Controller/ReportingController.php`)

| Method | URL | Action |
|--------|-----|--------|
| GET | `/api/rapportage/kpis` | Daily KPI data |
| GET | `/api/rapportage/channels` | Channel distribution |
| GET | `/api/rapportage/agents` | Agent performance |
| GET | `/api/rapportage/export` | Export as CSV |
| GET | `/api/rapportage/sla` | SLA configuration |
| PUT | `/api/rapportage/sla` | Update SLA targets |

### Frontend

#### Routes
- `/rapportage` — RapportageDashboard
- `/rapportage/channels` — ChannelAnalytics
- `/rapportage/agents` — AgentPerformance

#### Views

**RapportageDashboard.vue** — KPI widgets with CnStatsBlock, auto-refresh every 60s, SLA gauges
**ChannelAnalytics.vue** — Channel distribution chart, comparison table, shift analysis
**AgentPerformance.vue** — Agent ranking, workload distribution

### SLA Storage

SLA targets stored in `IAppConfig` under `pipelinq` namespace:
- `sla_telefoon_wait_seconds` (default: 30)
- `sla_telefoon_target_percent` (default: 90)
- `sla_email_response_hours` (default: 8)
- etc.

## Files Changed

### New Files
- `lib/Service/ReportingService.php`
- `lib/Controller/ReportingController.php`
- `src/views/rapportage/RapportageDashboard.vue`
- `src/views/rapportage/ChannelAnalytics.vue`
- `src/views/rapportage/AgentPerformance.vue`

### Modified Files
- `appinfo/routes.php` — Add reporting routes
- `src/router/index.js` — Add reporting routes
- `src/navigation/MainMenu.vue` — Add Rapportage nav item



## Tasks

# Tasks: contactmomenten-rapportage

## 1. Backend
- [x] 1.1 Create `lib/Service/ReportingService.php` with KPI calculation, channel distribution, and CSV export
- [x] 1.2 Create `lib/Controller/ReportingController.php` with reporting and SLA endpoints

## 2. Routes
- [x] 2.1 Add reporting API routes to `appinfo/routes.php`

## 3. Frontend Views
- [x] 3.1 Create `src/views/rapportage/RapportageDashboard.vue` — KPI widgets with auto-refresh
- [x] 3.2 Create `src/views/rapportage/ChannelAnalytics.vue` — Channel distribution
- [x] 3.3 Create `src/views/rapportage/AgentPerformance.vue` — Agent stats

## 4. Navigation and Routing
- [x] 4.1 Add reporting routes to `src/router/index.js`
- [x] 4.2 Add Rapportage entry to `src/navigation/MainMenu.vue`

## 5. Verification
- [ ] 5.1 Run `npm run build` and verify no errors
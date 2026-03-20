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

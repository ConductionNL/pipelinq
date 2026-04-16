# Design: contactmomenten-rapportage

## Architecture

### Data Model

This change does not introduce new OpenRegister schemas. All analytics are computed from existing `contactmoment` objects via `ObjectService`. The following `contactmoment` fields drive the KPI calculations:

| Field | Type | Used for |
|-------|------|----------|
| `channel` | string (facetable) | Channel distribution grouping |
| `agent` | string (facetable) | Agent performance grouping |
| `contactedAt` | string (date-time) | Time-series aggregation and date range filtering |
| `duration` | string (ISO 8601) | Average handling time calculation |
| `outcome` | string | FCR calculation (outcome = `opgelost` → resolved) |
| `channelMetadata` | object | Channel-specific SLA metadata (e.g., wait time for telefoon) |

No schema migrations required.

### Backend

#### ReportingService (`lib/Service/ReportingService.php`)

Aggregates contactmoment objects from OpenRegister for domain-specific KPI calculations:

- `getKpis(string $from, string $to): array` — Returns total contacts, FCR rate, average handling time (seconds), SLA compliance % for the date range
- `getChannelDistribution(string $from, string $to): array` — Contact counts grouped by `channel` value
- `getChannelTrend(string $from, string $to, string $granularity): array` — Daily or weekly contact volume per channel as time-series data
- `getAgentPerformance(string $from, string $to): array` — Per-agent metrics: count, FCR %, average duration in seconds
- `getSlaCompliance(string $channel, string $from, string $to): float` — Percentage of contacts meeting the SLA target for that channel
- `calculateFcr(array $contactmomenten): float` — FCR = count(outcome == 'opgelost') / count(total) * 100
- `getSlaTargets(): array` — Reads SLA targets from `IAppConfig`

All methods use `ObjectService::findObjects($register, $schema, $params)` with `_dateAfter` / `_dateBefore` parameters.

#### ReportingController (`lib/Controller/ReportingController.php`)

| Method | URL | Auth | Description |
|--------|-----|------|-------------|
| GET | `/api/rapportage/kpis` | Any user | KPI data for date range (`?from=&to=`) |
| GET | `/api/rapportage/channels` | Any user | Channel distribution and trend data |
| GET | `/api/rapportage/agents` | Any user | Per-agent performance table |
| GET | `/api/rapportage/sla` | Any user | SLA targets and compliance per channel |
| PUT | `/api/rapportage/sla` | Admin only | Update SLA targets in IAppConfig |

The PUT endpoint MUST call `IGroupManager::isAdmin()` and return HTTP 403 if not admin. Error responses use static messages only — never `$e->getMessage()`.

### SLA Configuration

SLA targets stored in `IAppConfig` under `pipelinq` namespace:

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `sla_telefoon_wait_seconds` | int | 30 | Max wait time before answer (seconds) |
| `sla_telefoon_target_percent` | int | 90 | Target % of calls within wait time |
| `sla_email_response_hours` | int | 8 | Max response time (business hours) |
| `sla_email_target_percent` | int | 95 | Target % of emails within response time |
| `sla_balie_wait_seconds` | int | 300 | Max counter wait time (seconds) |
| `sla_balie_target_percent` | int | 80 | Target % of counter contacts within wait time |
| `sla_chat_response_seconds` | int | 60 | Max first response time for chat |
| `sla_chat_target_percent` | int | 85 | Target % of chats within response time |

### Frontend

#### Routes (added to `src/router/index.js`)

- `/rapportage` — `RapportageDashboard`
- `/rapportage/kanalen` — `ChannelAnalytics`
- `/rapportage/agenten` — `AgentPerformance`

#### Views

**RapportageDashboard.vue** (`src/views/rapportage/RapportageDashboard.vue`)
- `CnDashboardPage` layout with:
  - 4 `CnStatsBlock` KPI cards: Total Contacts, FCR %, Avg Handling Time, SLA Compliance %
  - `CnChartWidget` (donut) — channel distribution
  - `CnChartWidget` (line) — contact volume trend over last 30 days
- Date range selector: today / this week / this month / custom (stored in component state, shared via provide/inject to sub-views)
- Auto-refresh every 60 seconds via `setInterval` in `created()` / cleared in `beforeDestroy()`
- Every `await store.action()` call wrapped in `try/catch` with `CnEmptyState` / toast on error

**ChannelAnalytics.vue** (`src/views/rapportage/ChannelAnalytics.vue`)
- `CnChartWidget` (bar) — contacts per channel for selected period
- `CnChartWidget` (line) — channel volume trend with one series per channel
- `CnDataTable` — per-channel breakdown: channel, contact count, SLA target, compliance %, status badge (green/red via `CnStatusBadge`)
- Date range filter (same picker as dashboard)

**AgentPerformance.vue** (`src/views/rapportage/AgentPerformance.vue`)
- `CnDataTable` — columns: agent display name, contacts handled, FCR %, avg handling time
- Default sort: contacts descending
- Only agents with ≥ 1 contact in selected period shown
- Date range filter (same picker)

#### Navigation

Add "Rapportage" entry to `src/navigation/MainMenu.vue` with `mdi-chart-bar` icon.

## Reuse Analysis

Per ADR-012, the following platform capabilities are used directly without rebuilding:

| Capability | Service / Component | Custom code? |
|------------|---------------------|--------------|
| Contact moment data queries | `ObjectService.findObjects($register, $schema, $params)` | No |
| Date range filtering | OpenRegister `_dateAfter` / `_dateBefore` query params | No |
| Faceted grouping | OpenRegister facet query params | No |
| Chart rendering | `CnChartWidget` (ApexCharts) | No |
| KPI metric cards | `CnStatsBlock` | No |
| Dashboard layout | `CnDashboardPage` + `CnDashboardGrid` | No |
| Data tables | `CnDataTable` | No |
| Status indicators | `CnStatusBadge` | No |
| CSV export | `ExportService` + `CnMassExportDialog` | No |
| SLA configuration storage | `IAppConfig` | No |

**Custom code justified:**
- `ReportingService` — Domain-specific aggregation (FCR, SLA compliance, average handling time) requires computing derived metrics from raw contactmoment data. This logic is not provided by the platform and is specific to the Pipelinq KCC domain.
- `ReportingController` — Dedicated pre-aggregation endpoint prevents the frontend from fetching hundreds of individual contactmomenten to compute dashboard values client-side.

No overlap found with existing OpenRegister services (`ObjectService`, `RegisterService`, `SchemaService`, `ConfigurationService`) or shared `@conduction/nextcloud-vue` components for the aggregation logic itself.

## Seed Data

This change does not introduce new schemas. No seed data additions to `pipelinq_register.json` are required for this change alone.

If the `contactmoment` schema has no mock objects yet, add 5 representative objects to the mock section of `pipelinq_register.json` to enable dashboard testing:

| # | subject | channel | agent | outcome | contactedAt | duration |
|---|---------|---------|-------|---------|-------------|----------|
| 1 | Vraag over parkeervergunning | telefoon | uid:agent.bakker | opgelost | 2026-04-14T09:15:00Z | PT4M30S |
| 2 | Status aanvraag omgevingsvergunning | email | uid:agent.jansen | opgelost | 2026-04-14T10:30:00Z | PT2M00S |
| 3 | Afvalophaal gemist op Keizersgracht | balie | uid:agent.de_vries | doorverwezen | 2026-04-14T11:00:00Z | PT8M15S |
| 4 | Klacht over wegenonderhoud Lijnbaansgracht | chat | uid:agent.bakker | opgelost | 2026-04-14T13:45:00Z | PT6M00S |
| 5 | Vraag over bijstandsuitkering | telefoon | uid:agent.jansen | opgelost | 2026-04-15T14:20:00Z | PT9M45S |

Seed object slug format: `contactmoment-rapportage-seed-{n}`. Use `@self` envelope with `register: pipelinq`, `schema: contactmoment`.

## Files Changed

### New Files
- `lib/Service/ReportingService.php`
- `lib/Controller/ReportingController.php`
- `src/views/rapportage/RapportageDashboard.vue`
- `src/views/rapportage/ChannelAnalytics.vue`
- `src/views/rapportage/AgentPerformance.vue`

### Modified Files
- `appinfo/routes.php` — Add 5 reporting API routes
- `src/router/index.js` — Add 3 rapportage routes
- `src/navigation/MainMenu.vue` — Add Rapportage nav entry

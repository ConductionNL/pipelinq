# Design: contactmomenten-rapportage

## Architecture

### Data Layer

Reporting is built exclusively on the existing `contactmoment` and `agentProfile` entities from OpenRegister (defined in ADR-000). No new OpenRegister schemas are added. All aggregation is performed server-side in `ReportingService` by querying the OpenRegister API with date-range, channel, and agent filters.

SLA targets are NOT stored as OpenRegister objects. They are stored as application configuration via `IAppConfig` under the `pipelinq` namespace (see SLA Storage section below).

### Backend

#### ReportingService (`lib/Service/ReportingService.php`)

Stateless service injected into `ReportingController`. All public methods are annotated with `@spec openspec/changes/2026-03-20-contactmomenten-rapportage/tasks.md`.

| Method | Signature | Description |
|--------|-----------|-------------|
| `getDailyKpis` | `(string $date): array` | Returns total contacts, per-channel breakdown, avg handling time, FCR rate for a given date |
| `getSlaCompliance` | `(string $channel, string $date): array` | Calculates SLA percentage for a channel on a given date using configured thresholds |
| `getChannelDistribution` | `(string $from, string $to, string $granularity): array` | Channel volume grouped by day/week/month over a date range |
| `getAgentPerformance` | `(string $date): array` | Per-agent stats: contacts handled, avg duration, FCR rate |
| `calculateFcr` | `(array $contactmomenten): float` | FCR = contacts without backoffice routing / total contacts (excludes outcome containing "doorverwezen") |
| `exportCsv` | `(array $data, array $headers): string` | Generates semicolon-separated CSV string with UTF-8 BOM header |

**FCR Calculation Rule**: A contactmoment is counted as first-call resolved when its `outcome` field does NOT contain "doorverwezen" and is NOT empty. Contactmomenten with an empty `outcome` are excluded from FCR calculation.

**Average Handling Time**: Derived from the `duration` field (ISO 8601 format, e.g., `PT4M30S`). Null/empty durations are excluded from the average.

#### ReportingController (`lib/Controller/ReportingController.php`)

Thin controller following ADR-003 (Controller → Service → Mapper pattern). All endpoints require `@NoAdminRequired`. SLA update endpoint requires `#[AuthorizedAdminSetting]`.

| Method | URL | Action | Auth |
|--------|-----|--------|------|
| GET | `/api/rapportage/kpis` | Daily KPI data (accepts `?date=YYYY-MM-DD`) | User |
| GET | `/api/rapportage/channels` | Channel distribution (accepts `?from=`, `?to=`, `?granularity=`) | User |
| GET | `/api/rapportage/agents` | Agent performance (accepts `?date=YYYY-MM-DD`) | User |
| GET | `/api/rapportage/export` | Export dataset as CSV (accepts `?type=kpis\|channels\|agents&date=`) | User |
| GET | `/api/rapportage/sla` | Read current SLA configuration | User |
| PUT | `/api/rapportage/sla` | Update SLA targets and thresholds | Admin |

### Frontend

#### Routes (added to `src/router/index.js`)

```
/rapportage          → RapportageDashboard.vue
/rapportage/kanalen  → ChannelAnalytics.vue
/rapportage/agenten  → AgentPerformance.vue
```

#### Views

**RapportageDashboard.vue** (`src/views/rapportage/RapportageDashboard.vue`)
- 4× `CnStatsBlock` widgets: Totaal contacts, FCR Rate, SLA Compliance, Gem. afhandeltijd
- SLA gauge per channel with three-color coding (groen/oranje/rood)
- Date picker defaulting to today
- Auto-refresh via `setInterval` every 60 seconds; clears on `beforeUnmount`
- "Laatste update: HH:MM" timestamp displayed below widgets

**ChannelAnalytics.vue** (`src/views/rapportage/ChannelAnalytics.vue`)
- `CnChartWidget` (ApexCharts bar chart) for channel volume over time
- Granularity toggle buttons: Dag / Week / Maand
- Date range picker (from/to) defaulting to last 30 days
- `CnDataTable` comparison table: Kanaal, Totaal, Gem. afhandeltijd, FCR, SLA%
- "Exporteer als CSV" button calls `/api/rapportage/export?type=channels`

**AgentPerformance.vue** (`src/views/rapportage/AgentPerformance.vue`)
- `CnDataTable` ranked list: Agent, Contacts vandaag, Gem. afhandeltijd, FCR Rate
- `CnChartWidget` (ApexCharts horizontal bar) for workload distribution per agent
- Date picker for historical view
- Workload balance indicator: highlights agents >20% above team average

### SLA Storage

SLA targets stored in `IAppConfig` under `pipelinq` app namespace:

| Key | Default | Description |
|-----|---------|-------------|
| `sla_telefoon_wait_seconds` | `30` | Phone: max wait time in seconds |
| `sla_telefoon_target_percent` | `90` | Phone: percentage to meet within wait target |
| `sla_telefoon_warn_percent` | `85` | Phone: warning threshold |
| `sla_telefoon_critical_percent` | `80` | Phone: critical threshold |
| `sla_email_response_hours` | `8` | Email: response time in hours |
| `sla_email_target_percent` | `95` | Email: percentage to meet within response target |
| `sla_balie_wait_minutes` | `5` | Counter: max wait time in minutes |
| `sla_balie_target_percent` | `90` | Counter: percentage to meet within wait target |
| `sla_chat_response_seconds` | `30` | Chat: max initial response time in seconds |
| `sla_chat_target_percent` | `90` | Chat: percentage to meet within response target |

## Seed Data

### contactmoment (5 examples)

```json
{
  "subject": "Vraag over paspoort aanvraag",
  "summary": "Burger vraagt naar benodigde documenten en levertijd voor een nieuw paspoort.",
  "channel": "telefoon",
  "outcome": "Opgelost - informatie verstrekt over benodigde documenten en afspraakmaken",
  "agent": "j.devries",
  "contactedAt": "2026-03-20T09:15:00Z",
  "duration": "PT4M30S",
  "channelMetadata": { "direction": "inbound", "queueWaitSeconds": 22 }
}
```

```json
{
  "subject": "Klacht grofvuil ophaling gemist",
  "summary": "Burger meldt dat grofvuil al twee weken niet opgehaald is terwijl dit wel op de planning stond.",
  "channel": "email",
  "outcome": "Doorverwezen naar afdeling Afval en Reiniging voor opvolging",
  "agent": "m.bakker",
  "contactedAt": "2026-03-20T10:30:00Z",
  "duration": "PT2M00S",
  "channelMetadata": { "threadId": "email-thread-20260320-0042" }
}
```

```json
{
  "subject": "Aanvraag omgevingsvergunning verbouwing",
  "summary": "Ondernemer informeert naar de procedure voor een omgevingsvergunning voor uitbreiding bedrijfspand.",
  "channel": "balie",
  "outcome": "Formulier verstrekt, afspraak gemaakt met vergunningsadviseur",
  "agent": "a.hassan",
  "contactedAt": "2026-03-20T11:00:00Z",
  "duration": "PT12M00S",
  "channelMetadata": { "location": "Loket 3 - Vergunningen" }
}
```

```json
{
  "subject": "Inlogprobleem DigiD bij aangifte",
  "summary": "Burger kan niet inloggen met DigiD bij het doen van belastingaangifte via de gemeenteportal.",
  "channel": "chat",
  "outcome": "Opgelost - browsercache geleegd en DigiD app geupdate",
  "agent": "j.devries",
  "contactedAt": "2026-03-20T13:45:00Z",
  "duration": "PT5M15S",
  "channelMetadata": { "sessionId": "chat-20260320-1789" }
}
```

```json
{
  "subject": "Bezwaar parkeerboete Marktplein",
  "summary": "Burger betwist parkeerboete omdat verkeersbord zou zijn omgewaaid door storm.",
  "channel": "telefoon",
  "outcome": "Doorverwezen naar Juridische Zaken voor formeel bezwaarproces",
  "agent": "m.bakker",
  "contactedAt": "2026-03-20T14:20:00Z",
  "duration": "PT8M10S",
  "channelMetadata": { "direction": "inbound", "queueWaitSeconds": 45 }
}
```

### agentProfile (3 examples)

```json
{
  "userId": "j.devries",
  "skills": ["uuid-skill-burgerzaken", "uuid-skill-belastingen"],
  "maxConcurrent": 5,
  "isAvailable": true
}
```

```json
{
  "userId": "m.bakker",
  "skills": ["uuid-skill-afval", "uuid-skill-vergunningen"],
  "maxConcurrent": 4,
  "isAvailable": true
}
```

```json
{
  "userId": "a.hassan",
  "skills": ["uuid-skill-vergunningen", "uuid-skill-ruimtelijke-ordening"],
  "maxConcurrent": 3,
  "isAvailable": false
}
```

## Files Changed

### New Files
- `lib/Service/ReportingService.php` — KPI aggregation, channel distribution, FCR calculation, CSV export
- `lib/Controller/ReportingController.php` — REST endpoints for reporting data and SLA config
- `src/views/rapportage/RapportageDashboard.vue` — KPI widgets with SLA gauges and auto-refresh
- `src/views/rapportage/ChannelAnalytics.vue` — Channel distribution chart and comparison table
- `src/views/rapportage/AgentPerformance.vue` — Agent ranking and workload chart

### Modified Files
- `appinfo/routes.php` — Add 6 reporting API routes
- `src/router/index.js` — Add 3 rapportage frontend routes
- `src/navigation/MainMenu.vue` — Add "Rapportage" nav item with chart icon

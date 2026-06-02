# Tasks: contactmomenten-rapportage

## 1. Backend Service

- [x] 1.1 Create `lib/Service/ReportingService.php` with `getDailyKpis()`, `getSlaCompliance()`, `getChannelDistribution()`, `getAgentPerformance()`, `calculateFcr()`, and `exportCsv()` methods
  - FCR: count contactmomenten where `outcome` is non-empty and does NOT contain "doorverwezen", divided by total contactmomenten with non-empty outcome
  - Average handling time: parse ISO 8601 `duration` field; exclude null/empty values
  - CSV: use semicolon separator and UTF-8 BOM (`\xEF\xBB\xBF`) for Excel compatibility

- [x] 1.2 Create `lib/Controller/ReportingController.php` with endpoints:
  - `GET /api/rapportage/kpis` — accepts `?date=YYYY-MM-DD`, returns KPI array
  - `GET /api/rapportage/channels` — accepts `?from=`, `?to=`, `?granularity=dag|week|maand`
  - `GET /api/rapportage/agents` — accepts `?date=YYYY-MM-DD`
  - `GET /api/rapportage/export` — accepts `?type=kpis|channels|agents&date=YYYY-MM-DD`, returns CSV response with `Content-Disposition: attachment`
  - `GET /api/rapportage/sla` — read SLA config from `IAppConfig`
  - `PUT /api/rapportage/sla` — update SLA config in `IAppConfig`; require `#[AuthorizedAdminSetting]`

## 2. Routes

- [x] 2.1 Add 6 reporting API routes to `appinfo/routes.php`:
  ```php
  ['name' => 'reporting#get_kpis',     'url' => '/api/rapportage/kpis',     'verb' => 'GET'],
  ['name' => 'reporting#get_channels', 'url' => '/api/rapportage/channels', 'verb' => 'GET'],
  ['name' => 'reporting#get_agents',   'url' => '/api/rapportage/agents',   'verb' => 'GET'],
  ['name' => 'reporting#export',       'url' => '/api/rapportage/export',   'verb' => 'GET'],
  ['name' => 'reporting#get_sla',      'url' => '/api/rapportage/sla',      'verb' => 'GET'],
  ['name' => 'reporting#update_sla',   'url' => '/api/rapportage/sla',      'verb' => 'PUT'],
  ```
  - Place BEFORE any wildcard `{slug}` routes per ADR-016

## 3. Frontend Views

- [x] 3.1 Create `src/views/rapportage/RapportageDashboard.vue`:
  - 4× `CnStatsBlock` widgets: Totaal contacts, FCR Rate, SLA Compliance, Gem. afhandeltijd
  - SLA gauge with three-color coding (groen ≥ target, oranje ≥ critical, rood < critical)
  - Date picker defaulting to today; fetches `GET /api/rapportage/kpis?date=`
  - Auto-refresh: `setInterval` every 60 000 ms; clear on `beforeUnmount`
  - "Laatste update: HH:MM" timestamp below widgets
  - "Exporteer als CSV" button calls `GET /api/rapportage/export?type=kpis`
  - Empty state: "Nog geen contactmomenten geregistreerd voor deze datum" when total = 0

- [x] 3.2 Create `src/views/rapportage/ChannelAnalytics.vue`:
  - `CnChartWidget` (ApexCharts bar) showing channel volume over time
  - Toggle buttons: Dag / Week / Maand — re-fetches `GET /api/rapportage/channels?granularity=`
  - Date-range picker defaulting to last 30 days
  - `CnDataTable` comparison table: Kanaal, Totaal, Gem. afhandeltijd, FCR Rate, SLA%
  - Table sortable by any column
  - "Exporteer als CSV" button calls `GET /api/rapportage/export?type=channels`

- [x] 3.3 Create `src/views/rapportage/AgentPerformance.vue`:
  - `CnDataTable` ranked by contacts (desc): Agent, Contacts vandaag, Gem. afhandeltijd, FCR Rate
  - `CnChartWidget` (ApexCharts horizontal bar) for workload per agent
  - Agents >20% above team average MUST be visually flagged
  - Date picker defaulting to today
  - "Exporteer als CSV" button calls `GET /api/rapportage/export?type=agents`
  - Empty state: "Geen agentstatistieken beschikbaar voor deze datum"

## 4. Navigation and Routing

- [x] 4.1 Add 3 rapportage routes to `src/router/index.js`:
  ```js
  { path: '/rapportage',         name: 'RapportageDashboard', component: RapportageDashboard },
  { path: '/rapportage/kanalen', name: 'ChannelAnalytics',    component: ChannelAnalytics },
  { path: '/rapportage/agenten', name: 'AgentPerformance',    component: AgentPerformance },
  ```

- [x] 4.2 Add "Rapportage" entry to `src/navigation/MainMenu.vue` using `NcAppNavigationItem` with a chart icon and `:to="{ name: 'RapportageDashboard' }"`

## 5. Verification

- [x] 5.1 Run `npm run build` and verify no TypeScript/ESLint errors
- [x] 5.2 Manual browser testing: open `/rapportage`, verify KPI widgets load and auto-refresh after 60s
- [x] 5.3 Manual browser testing: open `/rapportage/kanalen`, toggle Dag/Week/Maand, verify chart updates
- [x] 5.4 Manual browser testing: click "Exporteer als CSV" on each view, verify file downloads with correct encoding
- [x] 5.5 Verify SLA color coding: set a low SLA target via `PUT /api/rapportage/sla` and confirm orange/red display

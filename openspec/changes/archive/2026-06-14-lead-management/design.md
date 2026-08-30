<!-- ⚠️ EXTENSION NOTICE (auto-inserted by fix_extension_artifacts.py)
     Parent capability: pipeline (Pipeline)
     This spec extends the existing `pipeline` capability. Do NOT define new entities or build new CRUD — reuse what `pipeline` already provides. Your job is to add configuration, seed data, or workflow templates on top of that capability.
-->

# Design: Lead Management

## Architecture Overview

This change is primarily frontend-focused. The V1 pipeline enhancements (quick actions, stale/aging/overdue indicators, CSV import/export) are all Vue component modifications. The analytics view adds new frontend components using the platform's dashboard infrastructure, backed by a thin PHP aggregation service. The non-admin RBAC audit involves backend verification with targeted fixes.

```
Frontend (Vue)
├── PipelineCard.vue — quick actions + stale/aging/overdue indicators
├── LeadList.vue — overdue row highlighting + stale filter + import/export
├── LeadDetail.vue — overdue banner + aging in pipeline progress
└── src/views/rapportage/
    ├── RapportageView.vue      (CnDashboardPage)
    ├── PipelineFunnelWidget.vue (CnChartWidget — bar)
    ├── SourcePerformanceWidget.vue (CnTableWidget)
    ├── LeadAgingWidget.vue     (CnChartWidget — donut)
    └── WinLossWidget.vue       (CnChartWidget — pie + CnStatsBlock)

Backend (PHP)
└── lib/Controller/RapportageController.php
    └── GET /api/rapportage/pipeline-stats (non-admin accessible)
        └── RapportageService.php
            └── ObjectService.findObjects('pipelinq-register', 'lead', filters)
```

## Key Design Decisions

### 1. Quick Actions via CnRowActions on Kanban Cards

`PipelineCard.vue` currently has no action menu. A `CnRowActions` component (from `@conduction/nextcloud-vue`) will be added to the card's top-right corner. Actions dispatch directly via the object store:

- **Move to stage**: sub-menu listing all stages in the lead's pipeline; calls `objectStore.saveObject('lead', { ...lead, stage, stageOrder })`
- **Assign**: opens `NcUserPicker`; calls `objectStore.saveObject('lead', { ...lead, assignee: uid })`
- **Set priority**: sub-menu with low/normal/high/urgent; updates `priority` field

**Rationale**: `CnRowActions` is the standard platform pattern for in-row/in-card actions. No custom action menu component is needed.

### 2. Stale and Aging Computed from `_dateModified`

OpenRegister provides `_dateModified` on every object. Stale badge and aging indicator are computed purely in Vue:

- **Staleness**: `Math.floor((Date.now() - new Date(lead._dateModified)) / 86400000) >= staleThreshold`
- **Aging in stage**: same formula — uses `_dateModified` as a proxy for last stage change in V1

The stale threshold is stored in settings (fetched from backend as part of the existing settings endpoint) and defaults to 14 days.

**Rationale**: Stage-change timestamp (`stageChangedAt`) is not a property on the `lead` schema. Using `_dateModified` is the practical V1 approach. A more precise `stageChangedAt` can be added in a future schema update when needed.

### 3. Analytics via Dedicated RapportageController (Non-Admin)

Pipeline analytics require server-side aggregation across potentially hundreds of leads. A `RapportageController` with a single aggregation endpoint fetches leads from OpenRegister via `ObjectService.findObjects()` and computes summaries in `RapportageService` before returning to the frontend.

The endpoint uses `#[NoAdminRequired]` — analytics is a business feature accessible to all authenticated users.

**Rationale**: Client-side aggregation over potentially large datasets wastes bandwidth and slows the frontend. Server-side aggregation keeps widget components simple and load fast.

### 4. CSV Import/Export via Platform Services

Lead list import/export uses `CnMassImportDialog` and `CnMassExportDialog` from `@conduction/nextcloud-vue`. No custom import/export controllers, parsers, or upload handlers are needed — the platform handles everything.

**Rationale**: This is an explicit DO NOT REBUILD pattern from ADR-001. The platform's `ImportService`/`ExportService` are already integrated in the store.

### 5. Non-Admin RBAC Audit

Lead CRUD goes through OpenRegister's generic object API. Pipelinq may have added `IGroupManager::isAdmin()` guards on mutation endpoints that should only guard admin configuration (pipelines, settings), not operational actions (lead create/update/delete/stage-move).

The audit will:
1. Review every `lib/Controller/` method that touches lead objects
2. Remove any `isAdmin()` check that is not specifically protecting configuration
3. Annotate operational endpoints with `#[NoAdminRequired]` where missing

**Rationale**: ADR-005 (security) requires admin checks for admin operations. Lead CRUD is a business operation — it should follow the same authorization model as Nextcloud Files (any authenticated user can create/edit their own content).

## Component Design

### PipelineCard.vue Enhancements

New computed properties:
- `daysStale`: days since `_dateModified`; stale when >= `staleThreshold` from settings
- `daysInStage`: same as `daysStale` (V1 proxy)
- `isOverdue`: `expectedCloseDate < today && !isClosed`
- `overdueDays`: days since `expectedCloseDate`

New template elements:
- `CnRowActions` at top-right of card header with items: move-to-stage submenu, assign action, priority submenu
- Stale badge (CnStatusBadge, warning color): `"{{ daysStale }}d oud"` — shown only when stale
- Aging indicator (grey text): `"{{ daysInStage }}d in fase"` — always shown
- Overdue date styling: `expectedCloseDate` text turns red when `isOverdue`

### LeadList.vue Enhancements

New elements:
- Row CSS class `:class="{ 'lead-overdue': isLeadOverdue(lead) }"` with red left border
- Computed overdue text in close-date column: `"Xd te laat"` in red
- Stale filter option added to existing filter bar: "Verouderd (>Xd)"
- Action bar: "Importeren" button (opens `CnMassImportDialog`) and "Exporteren" button (opens `CnMassExportDialog`)

### LeadDetail.vue Enhancements

New elements:
- Overdue banner (shown when `expectedCloseDate < today && !isClosed`): `<NcEmptyContent>` style banner or inline alert "X dagen achterstallig"
- Aging text in pipeline progress section: "X dagen in huidige fase" below the current stage indicator

### RapportageView.vue (new)

Uses `CnDashboardPage` with four named widget slots:
- `#widget-funnel` → `PipelineFunnelWidget`
- `#widget-sources` → `SourcePerformanceWidget`
- `#widget-aging` → `LeadAgingWidget`
- `#widget-winloss` → `WinLossWidget`

Fetches `GET /api/rapportage/pipeline-stats` on mount via Pinia settings store or direct axios call. Passes data as props to widget components.

### RapportageController.php (new)

| Method | Route | Auth | Description |
|---|---|---|---|
| `getPipelineStats()` | `GET /api/rapportage/pipeline-stats` | `#[NoAdminRequired]` | Returns aggregated analytics |

**Response shape**:
```json
{
  "stageValues": [
    { "stage": "Nieuw", "count": 4, "totalValue": 48000, "weightedValue": 9600 }
  ],
  "sourcePerformance": [
    { "source": "referral", "total": 5, "won": 2, "conversionRate": 40.0, "avgWonValue": 35000 }
  ],
  "agingBuckets": [
    { "bucket": "0-7d", "count": 3, "totalValue": 42000 },
    { "bucket": "8-14d", "count": 2, "totalValue": 28000 },
    { "bucket": "15-30d", "count": 4, "totalValue": 55000 },
    { "bucket": "30d+", "count": 1, "totalValue": 12000 }
  ],
  "winLoss": {
    "wonCount": 8, "lostCount": 5, "winRate": 61.5,
    "avgWonValue": 22500, "avgLostValue": 12000, "avgDaysToClose": 42
  }
}
```

### RapportageService.php (new)

**Methods**:
- `getStageValues(string $pipelineId = null): array` — fetches all open leads, groups by `stage`, sums value and weighted value
- `getSourcePerformance(string $dateFrom = null, string $dateTo = null): array` — groups leads by `source`, computes won/total/avgValue
- `getAgingBuckets(): array` — computes `_dateModified` age for all open leads, distributes into 4 buckets
- `getWinLossAnalysis(string $dateFrom = null, string $dateTo = null): array` — fetches closed leads, computes win rate and averages

**Dependencies**: `ObjectService` (3-arg API: `findObjects($register, $schema, $params)`)

## File Changes

| File | Action | Description |
|---|---|---|
| `src/views/pipeline/PipelineCard.vue` | Modify | Add CnRowActions menu, stale/aging/overdue indicators |
| `src/views/leads/LeadList.vue` | Modify | Overdue row highlighting, stale filter, import/export buttons |
| `src/views/leads/LeadDetail.vue` | Modify | Overdue banner, aging text in pipeline progress |
| `src/views/rapportage/RapportageView.vue` | Create | Analytics dashboard (CnDashboardPage) |
| `src/views/rapportage/PipelineFunnelWidget.vue` | Create | Pipeline value per stage bar chart |
| `src/views/rapportage/SourcePerformanceWidget.vue` | Create | Source conversion rate table |
| `src/views/rapportage/LeadAgingWidget.vue` | Create | Lead aging distribution donut chart |
| `src/views/rapportage/WinLossWidget.vue` | Create | Win/loss pie chart and KPI cards |
| `src/navigation/MainMenu.vue` | Modify | Add "Rapportage" nav item with chart-bar icon |
| `src/router/index.js` | Modify | Add `/rapportage` route pointing to RapportageView |
| `lib/Controller/RapportageController.php` | Create | Pipeline analytics endpoint |
| `lib/Service/RapportageService.php` | Create | Analytics aggregation logic |
| `appinfo/routes.php` | Modify | Register rapportage route |
| `tests/Unit/Service/RapportageServiceTest.php` | Create | Unit tests for aggregation |
| `tests/Unit/Controller/RapportageControllerTest.php` | Create | Unit tests for controller |
| `l10n/en.json` | Modify | Add i18n keys for new strings |
| `l10n/nl.json` | Modify | Add Dutch translations |

## Seed Data

This change does not introduce new schemas. The `lead` schema already exists in `pipelinq_register.json`. The following seed objects provide realistic demo data for the analytics and pipeline views. All objects use Dutch government organization names and realistic values.

### Lead Seed Objects

```json
[
  {
    "@self": {
      "register": "pipelinq",
      "schema": "lead",
      "slug": "lead-gemeente-amsterdam-crm-2026"
    },
    "title": "Gemeente Amsterdam — CRM implementatie 2026",
    "source": "referral",
    "value": 85000,
    "probability": 60,
    "expectedCloseDate": "2026-06-30",
    "priority": "high",
    "stage": "Gekwalificeerd",
    "stageOrder": 3,
    "status": "open",
    "notes": "Gemeente zoekt vervanging voor verouderd CRM-pakket."
  },
  {
    "@self": {
      "register": "pipelinq",
      "schema": "lead",
      "slug": "lead-provincie-zuidholland-digitalisering-2026"
    },
    "title": "Provincie Zuid-Holland — Digitalisering klantcontact",
    "source": "event",
    "value": 125000,
    "probability": 40,
    "expectedCloseDate": "2026-05-15",
    "priority": "urgent",
    "stage": "Voorstel",
    "stageOrder": 4,
    "status": "open",
    "notes": "Ontmoet op GovTech Expo 2026. Interesse in omnichannel oplossing."
  },
  {
    "@self": {
      "register": "pipelinq",
      "schema": "lead",
      "slug": "lead-rijkswaterstaat-onderhoudscontract-2026"
    },
    "title": "Rijkswaterstaat — Onderhoudscontract software 2026",
    "source": "website",
    "value": 45000,
    "probability": 80,
    "expectedCloseDate": "2026-04-30",
    "priority": "normal",
    "stage": "Onderhandeling",
    "stageOrder": 5,
    "status": "open",
    "notes": "Bestaande klant, contractverlenging lopend jaar."
  },
  {
    "@self": {
      "register": "pipelinq",
      "schema": "lead",
      "slug": "lead-ggd-rotterdam-zorginformatiesysteem"
    },
    "title": "GGD Rotterdam — Zorginformatiesysteem modules",
    "source": "cold-call",
    "value": 32000,
    "probability": 20,
    "expectedCloseDate": "2026-07-15",
    "priority": "low",
    "stage": "Nieuw",
    "stageOrder": 1,
    "status": "open",
    "notes": "Eerste contact gelegd. Nog in oriëntatiefase."
  },
  {
    "@self": {
      "register": "pipelinq",
      "schema": "lead",
      "slug": "lead-waterschap-hollandsedelta-zaaksysteem-gewonnen"
    },
    "title": "Waterschap Hollandse Delta — Zaaksysteemkoppeling",
    "source": "partner",
    "value": 67500,
    "probability": 100,
    "expectedCloseDate": "2026-03-31",
    "priority": "normal",
    "stage": "Gewonnen",
    "stageOrder": 6,
    "status": "won",
    "notes": "Contract getekend 2026-03-28. Implementatie start Q2."
  }
]
```

## Reuse Analysis

This change maximizes use of existing platform capabilities and builds no custom replacements.

| Platform Capability | Used For | Source |
|---|---|---|
| `CnDashboardPage` | Analytics view layout with drag-drop widgets | @conduction/nextcloud-vue |
| `CnChartWidget` (ApexCharts) | Pipeline funnel bar, aging donut, win/loss pie | @conduction/nextcloud-vue |
| `CnTableWidget` | Source performance table | @conduction/nextcloud-vue |
| `CnStatsBlock` | Win rate / avg value KPI cards | @conduction/nextcloud-vue |
| `CnMassImportDialog` | Lead CSV import with validation summary | @conduction/nextcloud-vue |
| `CnMassExportDialog` | Lead CSV export with column selection | @conduction/nextcloud-vue |
| `CnRowActions` | Quick actions menu on kanban cards | @conduction/nextcloud-vue |
| `CnStatusBadge` | Stale lead badge | @conduction/nextcloud-vue |
| `ObjectService.findObjects()` | Data loading in RapportageService | OpenRegister |
| `createObjectStore` with `lead` | Quick action saves | Already registered in store.js |
| `useListView` | LeadList state management (search/filter/pagination) | Already in use |
| `IAppConfig` | Stale threshold setting persistence | Nextcloud core |

**No overlap found** with existing OpenRegister services. `RapportageService` performs read-only application-specific aggregations on lead data. `ObjectService` does not provide analytics aggregation methods. No existing Pipelinq spec covers pipeline analytics reporting.

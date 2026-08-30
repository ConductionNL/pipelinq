# Design: Klantbeeld 360

## Architecture Overview

This change is primarily frontend. All data comes from existing OpenRegister schemas via the
platform's generic `objectStore`. No new schemas, no new PHP controllers. One new analytics backend
service is added for server-side aggregation to avoid fetching full collections client-side in large
installations.

```
AnalyticsDashboard.vue
    ↓ GET /api/analytics/summary?period=month
AnalyticsService (new, thin PHP service)
    ↓ calls ObjectService.findObjects(register, schema, filters)
    → returns aggregated counts/sums

PipelineAnalyticsView.vue
    ↓ objectStore.fetchCollection('lead', { pipeline: uuid, _limit: 500 })
    → computes KPIs + stage distribution client-side

ClientDetail.vue (enhanced)
    ↓ objectStore.fetchCollection('lead', { client: uuid, _limit: 10 })
    ↓ objectStore.fetchCollection('contactmoment', { client: uuid, _limit: 10 })
    ↓ objectStore.fetchCollection('request', { client: uuid, _limit: 5 })
    ↓ objectStore.fetchCollection('contact', { client: uuid })
    → all fetched in parallel via Promise.all on mount
```

## Data Model

No new schemas are introduced. This change uses existing entities from ADR-000:

| Entity | Role in this change | Key fields used |
|--------|---------------------|-----------------|
| `client` | Central entity for 360 view | `name`, `type`, `email`, `phone` |
| `contact` | Persons linked to client | `name`, `role`, `email`, `client` (UUID → parent) |
| `lead` | Opportunities linked to client | `title`, `value`, `stage`, `probability`, `expectedCloseDate`, `status`, `pipeline` |
| `contactmoment` | Interactions linked to client | `subject`, `channel`, `contactedAt`, `agent`, `outcome` |
| `request` | Service requests linked to client | `title`, `status`, `priority`, `requestedAt` |
| `pipeline` | Pipeline definitions for analytics | `title`, `stages`, `isDefault` |

## API Design

### New endpoint: Analytics summary

```
GET /api/analytics/summary?period={week|month|quarter}
```

Response:
```json
{
  "openPipelineValue": 385000,
  "openRequests": 12,
  "contactmomentenCount": 47,
  "activeLeads": 23,
  "period": "month"
}
```

Auth: Nextcloud built-in. Admin check not required — all users may view.
Error: `{ "message": "Analytics unavailable" }` with HTTP 500 if OpenRegister unreachable.

### Existing endpoints reused (no changes)

```
GET /apps/openregister/api/objects/pipelinq/lead?client={uuid}&_limit=10
GET /apps/openregister/api/objects/pipelinq/contactmoment?client={uuid}&_limit=10
GET /apps/openregister/api/objects/pipelinq/request?client={uuid}&_limit=5
GET /apps/openregister/api/objects/pipelinq/contact?client={uuid}
GET /apps/openregister/api/objects/pipelinq/lead?pipeline={uuid}&_limit=500
GET /apps/openregister/api/objects/pipelinq/pipeline
```

## Backend

### AnalyticsService (`lib/Service/AnalyticsService.php`)

Single service, stateless:

- `getSummary(string $period): array` — Returns aggregated KPIs for the given period.
  Calls `ObjectService::findObjects` for leads, requests, contactmomenten. Filters by
  `createdAt` / `contactedAt` based on period boundary. Aggregates in PHP.
- `getPeriodBoundary(string $period): \DateTimeInterface` — Converts `week/month/quarter`
  to a `DateTime` for filtering.

### AnalyticsController (`lib/Controller/AnalyticsController.php`)

One action:

| Method | URL | Auth |
|--------|-----|------|
| GET | `/api/analytics/summary` | `#[NoAdminRequired]` |

Validates `period` param (enum: week, month, quarter; default: month). Returns JSONResponse.
Returns static error string on exception — never `$e->getMessage()`.

### Routes

```php
// appinfo/routes.php
['name' => 'analytics#summary', 'url' => '/api/analytics/summary', 'verb' => 'GET'],
```

## Key Design Decisions

### 1. Client 360 — extend ClientDetail, don't create a new route

**Decision**: Add new `CnDetailCard` sections within the existing `ClientDetail.vue`.

**Rationale**: The client detail page is already the natural home. A separate `/clients/:id/360`
route would duplicate navigation state. Follows the same pattern as the existing Contacts section.

**Load strategy**: All relation fetches triggered in parallel via `Promise.all` in `mounted()`.
Each section shows an individual loading state until its fetch resolves.

### 2. Pipeline analytics — standalone route with client-side aggregation

**Decision**: `PipelineAnalyticsView.vue` at `/pipeline-analytics` fetches all leads for the
selected pipeline and computes KPIs (value sum, win rate, stage counts) as computed properties.

**Rationale**: Pipeline lead counts are small enough (< 500) for client-side aggregation.
Avoids a dedicated backend endpoint. KPIs update instantly when the user switches pipelines.

**Win rate formula**: `won / (won + lost)` where `lead.status === 'won'` or `'lost'`.
Displayed as percentage; shown as `—` when no closed leads exist (no division by zero).

### 3. Analytics dashboard — server-side aggregation

**Decision**: Use `AnalyticsService` for cross-module KPIs rather than fetching full collections.

**Rationale**: Large installations may have thousands of contactmomenten. Fetching all client-side
would be slow and wasteful. A thin PHP aggregation service is justified here.

**Platform adherence**: Dashboard rendered via `CnDashboardPage` + `CnStatsBlock`.
No custom chart components — `CnChartWidget` (ApexCharts) for any charts.

### 4. Contact–Organisation UX — no new routes

**Decision**: Add a "Parent Organisation" `CnDetailCard` to `ContactDetail.vue` using
`fetchUses` for the forward lookup (contact → client). Add "Link to Organisation" button that
opens a `CnFormDialog` with a client search/select.

**Rationale**: Forward lookup is correct direction (contact references client via `contact.client`).
`CnFormDialog` provides schema-driven select — no custom dialog needed.

## Frontend Component Design

### AnalyticsDashboard.vue (`src/views/analytics/AnalyticsDashboard.vue`)

```
CnDashboardPage
  ├── Time period selector (NcSelect: week/month/quarter) in header-actions slot
  ├── CnKpiGrid (4 × CnStatsBlock)
  │   ├── Open Pipeline Value (EUR formatted)
  │   ├── Open Requests
  │   ├── Contactmomenten (selected period)
  │   └── Active Leads
  └── (placeholder for future chart widgets — V1)
```

State: `period` (default: 'month'), `summary` (from API), `loading`, `error`.
On mount + on `period` change: `GET /api/analytics/summary?period={period}`.

### PipelineAnalyticsView.vue (`src/views/pipeline/PipelineAnalyticsView.vue`)

```
CnDetailPage
  ├── Pipeline selector (NcSelect) in header
  ├── CnKpiGrid (4 × CnStatsBlock)
  │   ├── Total Pipeline Value
  │   ├── Win Rate (%)
  │   ├── Average Deal Size
  │   └── Active Opportunities
  └── CnDetailCard "Stage Funnel"
      └── CnChartWidget (bar, horizontal, leads per stage)
```

State: `pipelines[]`, `selectedPipeline`, `leads[]` (fetched on pipeline select).
Computed: `openLeads`, `wonLeads`, `lostLeads`, `totalValue`, `winRate`, `avgDealSize`, `stageData`.

### ClientDetail.vue — new sections (appended after existing sections)

```
Existing sections (unchanged):
  Client Information card
  Contacts card (existing — enhanced with full contact rows)

New sections:
  Summary Statistics card (CnStatsBlock ×4)
  Leads card (CnDetailCard, table of 10)
  Contactmomenten card (CnDetailCard, table of 10)
  Requests card (CnDetailCard, table of 5)
```

### ContactDetail.vue — new section

```
New section (after existing detail):
  Parent Organisation card (CnDetailCard)
    - If contact.client set: show name, type, navigate on click
    - If not set: CnEmptyState + "Link to Organisation" button
      → opens CnFormDialog with NcSelect of clients
```

## File Changes

### New Files

| File | Purpose |
|------|---------|
| `src/views/analytics/AnalyticsDashboard.vue` | Cross-module KPI dashboard |
| `src/views/pipeline/PipelineAnalyticsView.vue` | Per-pipeline funnel analytics |
| `lib/Service/AnalyticsService.php` | Server-side KPI aggregation |
| `lib/Controller/AnalyticsController.php` | REST endpoint for analytics summary |

### Modified Files

| File | Change |
|------|--------|
| `src/views/clients/ClientDetail.vue` | Add 5 new `CnDetailCard` sections + summary stats |
| `src/views/contacts/ContactDetail.vue` | Add Parent Organisation `CnDetailCard` |
| `src/views/leads/LeadList.vue` | Add close-date warning indicator and probability badge |
| `src/router/index.js` | Add `/analytics` and `/pipeline-analytics` routes |
| `src/navigation/MainMenu.vue` | Add Analytics nav item |
| `appinfo/routes.php` | Add analytics summary route |

## Reuse Analysis

Per ADR-012, the following existing capabilities are reused — no custom rebuilds:

| Capability | Platform provision | Why no custom code |
|------------|-------------------|-------------------|
| Data fetching | `createObjectStore` + `fetchCollection` | Handles pagination, CSRF, error state |
| KPI cards | `CnStatsBlock`, `CnKpiGrid` | `@conduction/nextcloud-vue` — no custom cards |
| Dashboard layout | `CnDashboardPage` (GridStack) | Provided — no custom layout engine |
| Charts | `CnChartWidget` (ApexCharts) | Provided — no Chart.js or D3 |
| Detail sections | `CnDetailCard` | Provided — no custom card wrappers |
| Form/select dialog | `CnFormDialog` | Provided — used for org linking |
| Pagination | `CnPagination` | Provided — no custom pagination |
| List views | `CnIndexPage`, `useListView` | Reused in analytics list if needed |
| Audit trail | `CnObjectSidebar` (`CnAuditTrailTab`) | Automatic — not rebuilt |
| RBAC | `AuthorizationService` | Enforced at API layer by platform |
| Error handling | `objectStore` error state | Surfaces errors without custom handlers |

**Deduplication findings**: Searched `openregister/lib/Service/` — no existing `AnalyticsService`
or aggregation endpoint. Searched `openspec/specs/` — no existing analytics spec with cross-module
KPIs. New `AnalyticsService` is justified; it cannot live in OpenRegister core because it is
pipelinq-domain-specific (lead win rate, contactmoment volume per CRM schema).

## Seed Data

This change does not introduce new schemas. Per ADR-001 seed data rules, seed objects are not
required for frontend-only or analytics-only changes. However, the following example objects
illustrate the data the Klantbeeld 360 views will display. They should be present in
`lib/Settings/pipelinq_register.json` (maintained by the base register change).

### client — 3 example objects

```json
{
  "@self": { "register": "pipelinq", "schema": "client", "slug": "client-gemeente-delft" },
  "name": "Gemeente Delft",
  "type": "organization",
  "email": "info@delft.nl",
  "phone": "015-260 2222",
  "address": "Phoenixstraat 16, 2611 AL Delft",
  "industry": "Overheid",
  "website": "https://www.delft.nl"
}
```

```json
{
  "@self": { "register": "pipelinq", "schema": "client", "slug": "client-reiswerk-bv" },
  "name": "Reiswerk B.V.",
  "type": "organization",
  "email": "info@reiswerk.nl",
  "phone": "030-291 4455",
  "address": "Catharijnesingel 38, 3511 GC Utrecht",
  "industry": "Toerisme",
  "website": "https://www.reiswerk.nl"
}
```

```json
{
  "@self": { "register": "pipelinq", "schema": "client", "slug": "client-stichting-opzoom" },
  "name": "Stichting Opzoom",
  "type": "organization",
  "email": "bestuur@opzoom.org",
  "phone": "010-414 7733",
  "address": "Opzoomerstraat 4, 3024 ED Rotterdam",
  "industry": "Non-profit",
  "website": "https://www.opzoom.org"
}
```

### contact — 3 example objects (linked to clients above)

```json
{
  "@self": { "register": "pipelinq", "schema": "contact", "slug": "contact-maria-de-vries" },
  "name": "Maria de Vries",
  "email": "m.devries@delft.nl",
  "phone": "015-260 3311",
  "role": "Inkoopadviseur",
  "client": "{{client-gemeente-delft.uuid}}"
}
```

```json
{
  "@self": { "register": "pipelinq", "schema": "contact", "slug": "contact-bas-kooistra" },
  "name": "Bas Kooistra",
  "email": "b.kooistra@reiswerk.nl",
  "phone": "030-291 4456",
  "role": "Directeur",
  "client": "{{client-reiswerk-bv.uuid}}"
}
```

```json
{
  "@self": { "register": "pipelinq", "schema": "contact", "slug": "contact-anita-smeets" },
  "name": "Anita Smeets",
  "email": "a.smeets@opzoom.org",
  "phone": "010-414 7734",
  "role": "Penningmeester",
  "client": "{{client-stichting-opzoom.uuid}}"
}
```

### lead — 4 example objects

```json
{
  "@self": { "register": "pipelinq", "schema": "lead", "slug": "lead-digitaal-loket-delft" },
  "title": "Digitaal Loket Implementatie",
  "client": "{{client-gemeente-delft.uuid}}",
  "contact": "{{contact-maria-de-vries.uuid}}",
  "value": 95000,
  "probability": 65,
  "stage": "Voorstel",
  "stageOrder": 3,
  "status": "active",
  "source": "referral",
  "priority": "high",
  "expectedCloseDate": "2026-06-30"
}
```

```json
{
  "@self": { "register": "pipelinq", "schema": "lead", "slug": "lead-zaaksysteem-integratie" },
  "title": "Zaaksysteem Integratie Module",
  "client": "{{client-gemeente-delft.uuid}}",
  "value": 42000,
  "probability": 40,
  "stage": "Kwalificatie",
  "stageOrder": 2,
  "status": "active",
  "source": "website",
  "priority": "normal",
  "expectedCloseDate": "2026-07-15"
}
```

```json
{
  "@self": { "register": "pipelinq", "schema": "lead", "slug": "lead-reisplatform-upgrade" },
  "title": "Reisplatform Upgrade 2026",
  "client": "{{client-reiswerk-bv.uuid}}",
  "contact": "{{contact-bas-kooistra.uuid}}",
  "value": 27500,
  "probability": 80,
  "stage": "Onderhandeling",
  "stageOrder": 4,
  "status": "active",
  "source": "cold-call",
  "priority": "high",
  "expectedCloseDate": "2026-05-01"
}
```

```json
{
  "@self": { "register": "pipelinq", "schema": "lead", "slug": "lead-fondsenwerving-platform" },
  "title": "Fondsenwerving Platform",
  "client": "{{client-stichting-opzoom.uuid}}",
  "value": 18000,
  "probability": 90,
  "stage": "Gewonnen",
  "stageOrder": 5,
  "status": "won",
  "source": "event",
  "priority": "normal"
}
```

### pipeline — 1 example object

```json
{
  "@self": { "register": "pipelinq", "schema": "pipeline", "slug": "pipeline-verkooppijplijn" },
  "title": "Verkooppijplijn",
  "description": "Standaard verkoopproces voor CRM-kansen",
  "stages": [
    { "name": "Prospectie", "probability": 10 },
    { "name": "Kwalificatie", "probability": 30 },
    { "name": "Voorstel", "probability": 60 },
    { "name": "Onderhandeling", "probability": 80 },
    { "name": "Gewonnen", "probability": 100 },
    { "name": "Verloren", "probability": 0 }
  ],
  "totalsLabel": "EUR",
  "isDefault": true
}
```

## NL Design System

- KPI cards use `var(--color-primary-element)` for value metrics; `var(--color-warning)` for
  overdue or low-probability indicators. Color is never the sole conveyor — warning states also
  show an icon (WCAG AA).
- Charts via `CnChartWidget` inherit NL Design token colors automatically.
- All `<style>` blocks MUST use `scoped`. No hardcoded colors or spacing.
- Responsive: KPI grid collapses to 2 columns at 768px; funnel chart shows a data table fallback
  below 640px.
- All buttons, links, and interactive elements are keyboard-navigable with visible focus ring.

## Security

- `AnalyticsController` uses `#[NoAdminRequired]` — all authenticated users may view org KPIs.
- All error responses return static messages; no `$e->getMessage()` in JSONResponse.
- No PII in logs; `$user->getUID()` used for identity (never `getDisplayName()`).
- Backend identity derived from `IUserSession` — frontend-sent user IDs are not trusted.

- **Vue 2 + Pinia + @nextcloud/vue + @conduction/nextcloud-vue**. NO Vuex. Options API only.
- State: Pinia stores in `src/store/modules/`. Use `createObjectStore` for OpenRegister CRUD.
- API calls: `axios` from `@nextcloud/axios` — auto-attaches CSRF token. NEVER raw `fetch()` for mutations.
  Loading state with `try/finally`.
- Translations: ALL user-visible strings via `t(appName, 'text')`. NO hardcoded strings.
  Translation keys MUST be English — Dutch translations go in `l10n/nl.json`.
- CSS: ONLY Nextcloud CSS variables (`var(--color-primary-element)`, etc.). NO hardcoded colors.
  NEVER reference `--nldesign-*` directly — nldesign app handles theming.
- Router: history mode, base `generateUrl('/apps/{app}/')`. Requires matching PHP routes in `routes.php`.
  Deep link URL templates MUST match the router mode — use path format (`/apps/{app}/entities/{uuid}`),
  NOT hash format (`/apps/{app}/#/entities/{uuid}`).
- OpenRegister dependency: settings returns `openRegisters` (bool) + `isAdmin`.
  Show empty state if OR missing. NEVER use `OC.isAdmin` — get from backend.
- NEVER `window.confirm()` or `window.alert()` — use `NcDialog` or `CnFormDialog` (WCAG, theming).
- NEVER read app state from DOM (`document.getElementById`, `dataset`) — use backend API or store.
- EVERY `await store.action()` call MUST be wrapped in `try/catch` with user-facing error feedback.
- NEVER import from `@nextcloud/vue` directly — use `@conduction/nextcloud-vue` which re-exports all
  NC components plus Conduction components. This ensures consistent theming and component versions.
- EVERY component used in `<template>` MUST be imported AND registered in `components: {}`.
  Vue 2 silently renders unknown elements — missing imports cause invisible runtime failures.

### NL Design System

- ALL UI components MUST use CSS custom properties from NL Design System tokens.
- MUST support theme switching via nldesign app's token sets.
- MUST meet WCAG AA compliance: keyboard-navigable, associated labels, color is not the sole
  method of conveying information.
- SHOULD work on 320px–1920px viewports; critical functionality MUST work at 768px (tablet).
- Exceptions: PDF generation (docudesk), admin-only screens (simpler styling allowed).

### @conduction/nextcloud-vue — ALWAYS check before building custom

**Pages & Layout:**
  `CnIndexPage` (schema-driven list+CRUD) | `CnDetailPage` (detail+sidebar) |
  `CnPageHeader` (title+icon) | `CnActionsBar` (add+search+toggle)

**Data Display:**
  `CnDataTable` (sortable+paginated) | `CnCardGrid` + `CnObjectCard` (card views) |
  `CnDetailGrid` (label-value pairs) | `CnFilterBar` (search+filters) |
  `CnFacetSidebar` (faceted filters) | `CnPagination` | `CnCellRenderer` (type-aware)

**Forms & Dialogs:**
  `CnFormDialog` (schema-driven create/edit) | `CnAdvancedFormDialog` (properties+JSON+metadata) |
  `CnSchemaFormDialog` (JSON Schema editor) | `CnTabbedFormDialog` (tabbed form framework) |
  `CnDeleteDialog` | `CnCopyDialog`

**Mass Actions:**
  `CnMassDeleteDialog` | `CnMassCopyDialog` | `CnMassExportDialog` (CSV/JSON/XML) |
  `CnMassImportDialog` (upload+summary) | `CnMassActionBar` (floating selection bar)

**Dashboard & Widgets:**
  `CnDashboardPage` (GridStack drag-drop layout) | `CnDashboardGrid` (layout engine) |
  `CnWidgetWrapper` (widget shell) | `CnWidgetRenderer` (NC Dashboard API v1/v2) |
  `CnChartWidget` (ApexCharts: area/line/bar/pie/donut/radial) |
  `CnTableWidget` (data table widget) | `CnTileWidget` (quick-access tile) |
  `CnInfoWidget` (label-value grid) | `CnKpiGrid` (responsive KPI layout) |
  `CnStatsBlock` (metric card) | `CnStatsPanel` (stats sections) | `CnProgressBar` |
  `CnObjectDataWidget` (schema-driven editable data grid, inline edit + save via objectStore) |
  `CnObjectMetadataWidget` (read-only object metadata display)

**UI Elements:**
  `CnStatusBadge` | `CnEmptyState` | `CnIcon` (MDI) | `CnCard` | `CnDetailCard` |
  `CnRowActions` | `CnTimelineStages` (workflow progression) |
  `CnUserActionMenu` (user context menu) | `CnJsonViewer` (CodeMirror)

**Detail Sidebar:**
  `CnObjectSidebar` (Files/Notes/Tags/Tasks/Audit tabs) | `CnIndexSidebar` |
  `CnNotesCard` (inline notes) | `CnTasksCard` (inline tasks)

**Settings:**
  `CnSettingsSection` + `CnVersionInfoCard` (MUST be first on admin pages) |
  `CnSettingsCard` | `CnConfigurationCard` | `CnRegisterMapping`
  User settings: `NcAppSettingsDialog` (NOT `NcDialog`)

**Composables:**
  `useListView` (search/filter/sort/pagination) | `useDetailView` (load/edit/delete) |
  `useSubResource` (related items) | `useDashboardView` (widgets/layout/edit)

**Store Plugins:**
  `auditTrailsPlugin` | `relationsPlugin` | `filesPlugin` | `lifecyclePlugin` |
  `selectionPlugin` | `searchPlugin` | `registerMappingPlugin`

**Utilities:**
  `columnsFromSchema()` | `filtersFromSchema()` | `fieldsFromSchema()` |
  `formatValue()` | `buildHeaders()` | `buildQueryString()`

### Page Construction Patterns (follow these recipes)

**App.vue:** `NcContent` → 3 states: loading (`NcLoadingIcon`), no-OpenRegister (`NcEmptyContent`),
  ready (`MainMenu` + `NcAppContent` + `router-view` + optional `CnIndexSidebar`).
  Inject `sidebarState` for child components. `created()` calls `initializeStores()`.

**MainMenu:** `NcAppNavigation` with `NcAppNavigationItem` per route (icon + name + `:to`).
  Footer: `NcAppNavigationSettings` (gear foldout) with admin/config nav items.
  Settings item emits `@click="$emit('open-settings')"` — opens `NcAppSettingsDialog` modal.
  Do NOT route to `/settings` — in-app settings is a modal overlay, not a page.

**Dashboard:** `CnDashboardPage` with `CnStatsBlock` KPIs (4 cards: open/overdue/value/completed),
  status distribution chart, "My Work" list (grouped: overdue → due this week → rest).
  Fetch all collections in parallel via `Promise.all`. Widget templates via `#widget-{id}` slots.

**Index page:** `CnIndexPage` with `useListView(entityType, { sidebarState, objectStore })`.
  Inject sidebarState. Row click → `$router.push({ name: 'EntityDetail', params: { id } })`.
  Add button → new entity detail with id='new'.

**Detail page:** Two modes — edit (form component) / view (`CnDetailPage` + `CnDetailCard` sections).
  Header actions: Edit + Delete buttons. Related entities in table inside `CnDetailCard`.
  Props: `entityId` from route. `isNew = entityId === 'new'`. Sidebar via `CnObjectSidebar`.
  **Relations:** Every entity referenced in the spec MUST have a `CnDetailCard` section.
  Use `fetchUsed` for reverse lookups (find objects that reference THIS entity) and
  `fetchUses` for forward lookups (find objects THIS entity references).
  If the spec lists a "linked X section", it MUST be implemented — not deferred or stubbed.

**Settings — two surfaces, never a route:**
  *Admin settings* (`/settings/admin/{appid}`): `AdminRoot.vue` rendered by `settings.js` entry point,
  registered via `AdminSettings.php`. Layout: `CnVersionInfoCard` (FIRST) → `CnRegisterMapping` →
  `CnSettingsSection` per feature. Load via `GET /api/settings`, save via `POST /api/settings`.
  *In-app settings*: `UserSettings.vue` wrapping `NcAppSettingsDialog` — opened as a modal from the
  gear menu (`@open-settings` event on MainMenu), handled in `App.vue` with `:open` / `@update:open`.
  Do NOT create a `/settings` route. Do NOT create a standalone `SettingsView.vue` page component.

**Router:** Flat routes (no nesting), all named, props via arrow function for params.
  Routes: `/` (Dashboard), `/{entities}` (list), `/{entities}/:id` (detail).
  No `/settings` route — settings is a modal (see Settings section above).

**Store init:** `initializeStores()` in `store/store.js` — fetches settings, then calls
  `objectStore.registerObjectType(name, schemaSlug, registerSlug)` for each entity.
  Object store uses `createObjectStore` with plugins (files, auditTrails, relations).
  Settings store: Pinia `defineStore` with `fetchSettings()` and `saveSettings()`.

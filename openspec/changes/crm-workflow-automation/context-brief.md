# Context Brief: Crm Workflow Automation

**App:** Pipelinq — CRM and customer interaction
**Spec:** crm-workflow-automation
**Platform:** Nextcloud + OpenRegister

## Features (10 total, sorted by market demand)

### Webhooks API — First-class webhooks management endpoints for creating, listing, and managing
**demand: 602** (200 tender mentions) | Category: integration

### Added support to execute a decision service in the DMN REST API.
**demand: 270** (85 tender mentions) | Category: core

### Added support to query directly on runtime variable instances both in the Java API and REST API.
**demand: 77** (25 tender mentions) | Category: integration

### Marketing Automation
**demand: 5** | Category: other

### Marketing Automation
**demand: 5** | Category: other

### Marketing Automation
**demand: 4** | Category: other

### Marketing Automation
**demand: 4** | Category: other

### Marketing Automation
**demand: 2** | Category: other

### Marketing Automation
**demand: 2** | Category: other

### advanced marketing automation
**demand: 1** | Category: other

## User Stories

(No user stories linked to this spec. Generate from the features above.)

## Customer Journeys

(No journeys linked. Infer from stakeholders and features above.)

## Stakeholders

(No stakeholders linked. Infer from the features and user stories above.)

## Other App Entities (do NOT redefine)

agentProfile, automation, automationLog, calendarLink, client, complaint, contact, contactmoment, emailLink, intakeForm, intakeSubmission, kennisartikel, kenniscategorie, kennisfeedback, lead, leadProduct, pipeline, product, productCategory, queue, relationship, request, skill, survey, surveyResponse, task

## Company-Wide Architecture Rules (17 ADRs)

These rules are MANDATORY for all Conduction apps.

### ADR-001-data-layer
- ALL domain data → OpenRegister objects. NO custom Entity/Mapper for domain data.
- App config → `IAppConfig`. NOT OpenRegister.
- Cross-entity references: OpenRegister relations (register+schema+objectId). NO foreign keys.
  MUST NOT store foreign keys or embed full objects.

### Schema standards

- Schemas: PascalCase, schema.org vocabulary, explicit types + required flags + description field.
- MUST NOT invent custom property names when a schema.org equivalent exists.
- Contact schemas MUST align with vCard properties (fn, email, tel, adr).
- Dutch government fields SHOULD use a mapping layer translating between international standards
  and Dutch specs — do not hardcode Dutch field names as primary.
- Schema changes that remove or rename properties are BREAKING. Adding optional properties is non-breaking.

### Register templates

- Location: `lib/Settings/{app}_register.json` (OpenAPI 3.0 + `x-openregister` extensions).
- Three template categories:
  - **App configuration** — define data models (schemas/registers/views/mappings).
    Mark with `x-openregister.type: "application"`.
  - **Mock data** — fictional but realistic seed data for dev/test.
    Mark with `x-openregister.type: "mock"`.
  - **Government standards** — aligned to Dutch API specs (BAG, BRP, KVK, DSO).
- Import mechanism: `ConfigurationService::importFromApp(appId, data, version, force)` →
  `ImportHandler::importFromApp()`. Called from repair step or `SettingsLoadService`.
- Idempotency: re-importing with `force: false` MUST NOT create duplicates. Match by slug
  using `ObjectService::searchObjects` with `_rbac: false` and `_multitenancy: false`.
  Use `version_compare` for skip logic.

### Seed data

Apps that store data in OpenRegister are empty on first install. An empty app cannot be
meaningfully tested — there are no objects to view, search, filter, or interact with.
This blocks both automated browser testing and manual QA. The Loadable Register Template
pattern (see Register templates above) already supports seed data via `components.objects[]`
with the `@self` envelope.

**Requirements:**

- Every app using OpenRegister MUST include 3-5 realistic objects per schema in
  `lib/Settings/{app}_register.json`.
- Use `@self` envelope: `{ "@self": { "register": ..., "schema": ..., "slug": ... }, ...properties }`.
  Register/schema MUST match keys; slug is unique human-readable identifier for matching.
- Use general organisation data (municipality, consultancy, travel agency, non-profit) —
  NOT context-specific. Varied, realistic field values.
- Mock data quality: real Dutch street names, valid postcodes (`[1-9][0-9]{3}[A-Z]{2}`),
  correct municipality/KVK codes, BSNs that pass 11-proef. Fictional but distinguishable from real.
- Cross-register consistency: BRP→BAG, KVK→BAG, DSO→BAG references must be valid.
- Loaded on install alongside schemas via same `importFromApp()` pipeline.
- MUST be idempotent — re-importing skips existing objects matched by slug.

**In OpenSpec artifacts:**

- **In design.md**: MUST include a Seed Data section when change introduces/modifies schemas —
  define seed objects per schema with concrete field values and related items (files, notes, tasks, contacts).
- **In tasks.md**: MUST include a seed data generation task when change introduces/modifies schemas.

**Exceptions** (no seed data required):

- **nldesign** — has no OpenRegister schemas.
- **ExApp sidecar wrappers** (openklant, opentalk, openzaak, valtimo, n8n-nextcloud) — proxy
  external services and do not use OpenRegister.
- **nextcloud-vue** — shared library, no seed data applicable.
- Changes that only modify frontend components or non-schema backend logic (e.g., settings,
  permissions) do not require seed data.

**Limitations:** OpenRegister's `ImportHandler` currently supports only flat seed objects.
Related items (files, notes, tasks, contacts) linked through the relation system are tracked
on the product roadmap. Until then, seed data is limited to object properties defined in schemas.

### Deduplication check

- Before proposing new capability: search `openspec/specs/` and `openregister/lib/Service/` for overlap
  with ObjectService, RegisterService, SchemaService, ConfigurationService, and shared Vue components.
- If similar capability exists: MUST reference it and explain why new code is needed rather than extending.
- Proposals duplicating existing functionality without justification MUST be rejected.
- **In design.md**: MUST include a "Reuse Analysis" section listing existing OpenRegister services leveraged.
- **In tasks.md**: MUST include a "Deduplication Check" task verifying no overlap — document findings
  even if "no overlap found".

### Schema migrations

- Breaking schema changes → new migration in repair step. NEVER modify existing migrations.

### OpenRegister + @conduction/nextcloud-vue — DO NOT REBUILD

The platform provides 258+ backend methods and 69+ frontend components. Apps ONLY build
custom logic for domain-specific business rules. Everything below is provided for FREE.

**CRUD & Data Management** (use ObjectService + CnIndexPage + CnDetailPage):
- Single & bulk create, read, update, delete — `ObjectService.saveObject()`, `deleteObject()`
- List with pagination, sorting, filtering — `ObjectService.findAll()` + `CnDataTable`
- Schema-driven forms — `CnFormDialog` (auto-generates from schema) or `CnAdvancedFormDialog`
- Detail views — `CnDetailPage` with `CnDetailGrid`, `CnDetailCard` sections
- Record merging/deduplication — `ObjectService.mergeObjects()`
- Object locking — `ObjectService.lockObject()` / `unlockObject()`

**Import & Export** (use ImportService/ExportService + CnMassImportDialog/CnMassExportDialog):
- CSV, Excel, JSON import with intelligent field mapping — `ImportService`
- CSV, Excel, JSON export with column selection — `ExportService`
- Bulk import with validation and progress — `CnMassImportDialog`
- Filtered export with format picker — `CnMassExportDialog`
- NO custom import dialogs, parsers, upload handlers, or export controllers

**Search & Discovery** (use IndexService + CnFilterBar + CnFacetSidebar):
- Full-text search with field weighting — `IndexService`
- Faceted navigation with counts — `FacetBuilder` + `CnFacetSidebar`
- Semantic search with embeddings — `VectorizationService`
- Hybrid search (keyword + semantic) — automatic
- Search analytics — `SearchTrailService` (popular terms, activity)
- NO custom search endpoints, query builders, or search pages

**File Management** (use FileService + CnObjectSidebar):
- Upload (single/multipart), download, share links — `FileService`
- File tagging, public/private toggle — `FileService`
- Bulk download as ZIP — `createObjectFilesZip()`
- Text extraction from PDFs/Office docs — `TextExtractionService`
- File tab in object sidebar — `CnObjectSidebar` → `CnFilesTab`
- NO custom file upload components, file controllers, or download handlers

**Audit & Compliance** (use AuditTrailService + CnObjectSidebar):
- Full change tracking with before/after snapshots — automatic
- Audit trail tab — `CnObjectSidebar` → `CnAuditTrailTab`
- GDPR data subject access requests — `inzageverzoek()`, `verwerkingsregister()`
- Audit export and analytics — `AuditTrailController`
- NO custom audit logging, change tracking, or compliance controllers

**Dashboard & Analytics** (use CnDashboardPage + CnChartWidget + CnStatsBlock):
- Drag-drop widget dashboard — `CnDashboardPage` with GridStack
- KPI cards — `CnKpiGrid`, `CnStatsBlock`, `CnStatsPanel`
- Charts (line/bar/pie/donut) — `CnChartWidget` (ApexCharts)
- Data tables as widgets — `CnTableWidget`
- Editable data grids — `CnObjectDataWidget`
- NO custom dashboard layouts, chart components, or KPI cards

**Forms & Dialogs** (use CnFormDialog + schema-driven generation):
- Auto-generated create/edit forms — `CnFormDialog` reads schema → generates fields
- JSON/metadata editing — `CnAdvancedFormDialog` with Properties/Data/Metadata tabs
- Schema editor — `CnSchemaFormDialog`
- Delete/Copy/Mass operations — `CnDeleteDialog`, `CnCopyDialog`, `CnMassDeleteDialog`
- NO custom form components, validation logic, or dialog wrappers

**Navigation & Pagination** (use CnPagination + CnActionsBar + useListView):
- Pagination control with size selector — `CnPagination`
- Action bar (add, search, toggle views) — `CnActionsBar`
- List state management — `useListView` composable (handles search, filter, sort, page)
- Detail state management — `useDetailView` composable
- NO custom pagination logic, debounced search, or list state management

**Authorization & RBAC** (use AuthorizationService + PropertyRbacHandler):
- Role-based access control — `AuthorizationService`
- Field-level permissions — `PropertyRbacHandler`
- Object-level restrictions — `PermissionHandler`
- Authorization audit — `AuthorizationAuditService`
- NO custom permission checks, role systems, or access control middleware

**Webhooks & Events** (use WebhookService):
- Create, test, retry webhooks — `WebhookService`
- CloudEvents format — automatic
- Event subscriptions — selective per schema/action
- NO custom webhook controllers or event dispatchers

**Notifications & Activity** (use NotificationService + ActivityService):
- Nextcloud notifications — `NotificationService`
- Activity feed — `ActivityService`
- Calendar events — `CalendarEventService`
- Deck/Kanban cards — `DeckCardService`

**Store & State** (use createObjectStore + plugins):
- Object stores — `createObjectStore(name)` generates Pinia CRUD store
- Store plugins: `auditTrails`, `files`, `lifecycle`, `relations`, `search`, `selection`
- Column/field/filter generation from schema — `columnsFromSchema()`, `fieldsFromSchema()`
- NO custom Pinia stores for CRUD, Vuex, or manual API call management

**Chat & AI** (use ChatService):
- Multi-turn conversation — `ChatService`
- RAG-based knowledge retrieval — `ContextRetrievalHandler`
- LLM response generation — `ResponseGenerationHandler`

**Data Retention & Archival** (use ArchivalService):
- Legal hold — `LegalHoldService`
- Destruction schedules — `DestructionService`
- Retention policies — `RetentionService`

**Semantic & Hybrid Search** (use SolrController + SettingsController):
- Semantic search via vector embeddings — `SettingsController.semanticSearch()`
- Hybrid search (keyword + semantic combined) — `SolrController.hybridSearch()`
- Vector embedding generation — `VectorizationService`
- NO custom search algorithms — configure via OpenRegister settings

**GraphQL API** (use GraphQLController):
- Query objects across schemas via GraphQL — `GraphQLController.execute()`
- Alternative to REST for complex cross-entity queries

**Organization / Multi-Tenancy** (use OrganisationController):
- Organization CRUD — `OrganisationController`
- Tenant-scoped data isolation — automatic via `TenantLifecycleService`
- NO custom multi-tenancy logic

**Task & Workflow Management** (use TasksController + WorkflowEngineController):
- Task creation and tracking — `TasksController`
- Workflow orchestration — `WorkflowEngineRegistry`
- Scheduled workflows — `ScheduledWorkflowController`
- NO custom task/workflow systems

**Text Extraction** (use FileTextController):
- Extract text from PDFs and Office docs — `TextExtractionService`
- Entity recognition (PII detection) — `EntityRecognitionHandler`
- Content anonymization — automatic

**Timeline & Stages** (use CnTimelineStages):
- Workflow progression visualization — `CnTimelineStages` component
- Stage tracking with status colors

### What apps SHOULD build (custom business logic only):
- External API integrations (SAP, Peppol, TenderNed, etc.)
- PDF/document generation with business-specific templates
- Workflow triggers and business rules specific to the domain
- Notification dispatch with app-specific event types
- Custom settings pages with app-specific configuration
- Background jobs for domain-specific processing

### ADR-002-api
- URL pattern: `/index.php/apps/{app}/api/{resource}` — lowercase plural, hyphens.
- Methods: GET=read, POST=create, PUT=update, DELETE=remove. No custom methods.
- Pagination: support `_page` + `_limit`. Response includes `total`, `page`, `pages`.
- Errors: appropriate HTTP status + `message` field. NO stack traces in responses.
- Auth: Nextcloud built-in only. NO custom login/session/token flows.
- Public endpoints: annotate `#[PublicPage]` + `#[NoCSRFRequired]`. Register CORS OPTIONS route.

### ADR-003-backend
- **Controller → Service → Mapper** (strict 3-layer). Controllers NEVER call mappers directly.
- Controllers: thin (<10 lines/method). Routing + validation + response only.
- Services: ALL business logic. Stateless — no instance state between requests.
- Mappers: DB CRUD only. No business logic.
- DI: constructor injection with `private readonly`. NO `\OC::$server` or static locators.
- Entity setters: POSITIONAL args only. `$e->setName('val')` — NEVER `$e->setName(name: 'val')`.
  (`__call` passes `['name' => val]` but `setter()` uses `$args[0]`.)
- Routes: `appinfo/routes.php`. Specific routes BEFORE wildcard `{slug}` routes.
- Config: `IAppConfig` with sensitive flag for secrets. NEVER read DB directly.
- Lifecycle: schema init via repair steps (`IRepairStep`), background via job queue, events via dispatcher.
- **Spec traceability**: every class and public method MUST have `@spec` PHPDoc tag(s) linking to
  the OpenSpec change that caused it: `@spec openspec/changes/{name}/tasks.md#task-N`.
  Multiple `@spec` tags allowed (code touched by multiple changes). File-level `@spec` in header docblock.
  This enables: code → docblock → spec traceability alongside code → git blame → commit → issue → spec.

### ADR-004-frontend
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

### ADR-005-security
- Auth: Nextcloud built-in ONLY. NO custom login, sessions, tokens, password storage.
- Admin check: `IGroupManager::isAdmin()` on BACKEND. Frontend-only checks = vulnerability.
- Multi-tenant isolation: enforce at API/service level, not UI only.
- NO PII in logs, error responses, or debug output.
- Audit trails: use `$user->getUID()` — NEVER `$user->getDisplayName()` (mutable, spoofable).
- Identity: always derive from `IUserSession` on backend — NEVER trust frontend-sent user IDs or display names.
- File uploads: validate type + size before storage.
- API responses: NO stack traces, SQL, or internal paths.
- Test collections: NEVER commit default credentials — use env variable placeholders.

### ADR-006-metrics
- Every app: `GET /api/metrics` (Prometheus text, admin auth) + `GET /api/health` (JSON, public).
- Metric names: `{app}_` prefix. MUST include `{app}_health_status` and `{app}_info`.
- Health check MUST verify OpenRegister connectivity (for apps that depend on it).

### ADR-007-i18n
# ADR-007: Internationalization (i18n)

## Status
Accepted

## Context
All Conduction Nextcloud apps serve Dutch government users but must support multiple languages. We need a consistent approach to internationalization across all apps.

## Decision

### Primary Language: English
- **English (en) is the source/primary language** for all code and translation keys.
- All `t()` keys and `$this->l10n->t()` strings MUST be written in English.
- `l10n/en.json` is the identity-mapped source file (key == value).
- Hardcoded Dutch strings in code MUST be converted to English keys with Dutch translations in `nl.json`.

### Required Languages
- Minimum: English (en) + Dutch (nl) translations.
- `l10n/en.json` and `l10n/nl.json` MUST exist in every app with a UI.
- Both files MUST contain exactly the same keys, with zero gaps.

### Frontend Translation
- JS: `t(appName, 'key')` for singular, `n(appName, 'singular', 'plural', count)` for plurals.
- `Vue.mixin({ methods: { t, n } })` for Options API components.
- `<script setup>` components MUST import `t` directly from `@nextcloud/l10n` (mixin does not apply).

### Backend Translation
- PHP: `$this->l10n->t('key')` for user-facing messages in JSONResponse.
- Controllers returning user-facing messages MUST inject `OCP\IL10N`.
- Log messages, internal exceptions, and database values are NOT translated.

### API and Data
- API field names: always English (language-neutral data layer).
- Date/number formatting: respect user locale via Nextcloud core.
- Each app with OpenRegister: define `register-i18n` spec listing translatable fields.

## Consequences
- All apps maintain two translation files that must stay in sync.
- Dutch strings used as translation keys (e.g., `t('app', 'Besluiten')`) are a violation — the English equivalent must be the key.
- New features must include both `en.json` and `nl.json` entries before merging.

### ADR-008-testing
- Every new PHP service/controller → PHPUnit tests in `tests/Unit/` (≥3 methods).
- Every new Vue component → test file (if test framework exists).
- Every new API endpoint → Newman/Postman collection in `tests/integration/`.
- Every spec scenario → browser test (GIVEN/WHEN/THEN verified via Playwright).
- All tests MUST pass in `composer check:strict`.
- Integration tests MUST cover error paths (403, 401, 400) — not just happy path (200).
- Test collections: use env variable placeholders for credentials — NEVER hardcode defaults.

### Smoke testing (before opening PR)

After implementing, verify your code actually works — quality gates catch lint/types, not logic:

1. Call each new API endpoint with `curl` — verify response shape and status code
2. Test at least one error path per endpoint (missing param, wrong auth, invalid input)
3. If the spec says a feature is deferred, verify it is NOT registered/enabled
4. If tasks.md marks a task `[x]`, verify it is fully implemented — not a stub or TODO

### Task completeness verification

Before marking a task `[x]` in tasks.md or opening a PR:
- Re-read every task in tasks.md
- For each `[x]` task, verify the implementation exists AND works — not a placeholder
- Stub components, empty relation sections, and TODO comments are NOT complete
- If a task cannot be completed, leave it `[ ]` and explain in the PR description

### ADR-009-docs
- Every user-facing feature → docs in `docs/` with screenshots from running app.
- English primary, Dutch recommended. Update docs when behavior changes.

### ADR-010-nl-design
- ALL UI: CSS custom properties from NL Design System tokens. NO hardcoded colors, fonts, spacing.
- Theme switching: support `nldesign` app's token sets (Rijkshuisstijl, Utrecht, municipality-specific).
- Components: `@nextcloud/vue` primary. Custom components styled via NL Design tokens only.
- Scoped styles: ALL `<style>` blocks MUST use `scoped` attribute.
- WCAG AA mandatory: keyboard-navigable, labelled forms, color not sole conveyor, alt text on images.
- Responsive: work from 320px to 1920px. Critical features accessible at 768px.
- Specs: reference token names ("primary action color") NOT hex values. Include a11y verification in ACs.
- Exception: PDF generation (docudesk) may use fixed dimensions. Admin screens MAY simplify but MUST meet WCAG AA.

### ADR-011-schema-standards
- schema.org types/properties as primary vocabulary (`schema:Person`, `schema:Organization`, `schema:Event`).
- Contact schemas: align with vCard properties (`fn`, `email`, `tel`, `adr`).
- Dutch government fields: mapping layer translating between international standards and Dutch APIs (VNG, ZGW).
- NO custom property names when schema.org equivalent exists.
- Relations: OpenRegister relation mechanism (register + schema + objectId). NO foreign keys or embedded objects.
- Versioning: removing/renaming properties = BREAKING → migration via repair step. Adding optional = non-breaking.
- Specs MUST define data models using schema.org vocabulary; design docs MUST include schema definitions with types, required flags, relations.
- Exception: app-specific workflow states (pipeline stages, process statuses) MAY use custom vocabularies.

### ADR-012-deduplication
- Before proposing new capability: search OpenRegister specs + services for overlap. Reference + justify if similar exists.
- Design docs MUST include "Reuse Analysis" listing which OpenRegister services are leveraged.
- If logic could benefit other apps → propose adding to OpenRegister core, not app-specific.
- Tasks MUST include "Deduplication Check" verifying no overlap with:
  ObjectService, RegisterService, SchemaService, ConfigurationService, shared specs, @conduction/nextcloud-vue.
- Document findings even if "no overlap found".
- Exception: OpenRegister checks internal duplication only. nldesign checks token sets. nextcloud-vue checks own components.

### ADR-013-container-pool
# ADR-013: Unified Container Pool

**Status:** accepted
**Date:** 2026-04-12

## Context

Specter (intelligence/research) and Hydra (build/review/merge) both run LLM workloads in Docker containers. Today they operate independently: Hydra spins up builder/reviewer/security containers on demand, Specter has a separate `run_llm_containers.sh` wrapper. Both compete for the same Claude Max rate limits.

We want to unify these into a **single priority-scheduled container pool** so that:
- Critical work (bugfixes, reviews) preempts lower-priority work (discovery, research)
- A fixed number of containers (e.g. 10) run continuously, pulling from a shared queue
- Token rotation and rate limit recovery happen at the pool level, not per-script
- Adding a new workload type (audit, spec generation, test) is just a new queue entry

## Decision

### Container types (priority order)

| Priority | Type | Source | Container image | Model |
|----------|------|--------|-----------------|-------|
| 1 | **bugfix** | Hydra: fix iteration after review failure | `hydra-builder` | sonnet |
| 2 | **code-review** | Hydra: PR code review | `hydra-reviewer` | sonnet |
| 3 | **security-review** | Hydra: PR security review | `hydra-security` | sonnet |
| 4 | **build** | Hydra: initial spec build | `hydra-builder` | sonnet |
| 5 | **audit** | Hydra: codebase audit | `hydra-builder` | sonnet |
| 6 | **spec-generation** | Specter: push_spec_pipeline | `specter-llm-worker` | sonnet |
| 7 | **schema-synthesis** | Specter: generate/dedup schemas | `specter-llm-worker` | haiku |
| 8 | **classification** | Specter: classify/redistribute features | `specter-llm-worker` | haiku |
| 9 | **translation** | Specter: translate requirements | `specter-llm-worker` | haiku |
| 10 | **discovery** | Specter: research, feature extraction | `specter-llm-worker` | haiku |

### Architecture

```
┌─────────────────────────────────────────────────────┐
│  Scheduler (cron or daemon)                         │
│                                                     │
│  reads: queue table (postgres)                      │
│  writes: container assignments, status updates      │
│                                                     │
│  ┌──────────────────────────────────────────┐       │
│  │ Pool: 10 container slots                 │       │
│  │                                          │       │
│  │  slot-1: [bugfix]     ← highest prio     │       │
│  │  slot-2: [code-review]                   │       │
│  │  slot-3: [build]                         │       │
│  │  slot-4: [build]                         │       │
│  │  slot-5: [classify]                      │       │
│  │  slot-6: [classify]                      │       │
│  │  slot-7: [translate]                     │       │
│  │  slot-8: [discovery]                     │       │
│  │  slot-9: [idle]       ← waiting for work │       │
│  │  slot-10: [idle]                         │       │
│  └──────────────────────────────────────────┘       │
│                                                     │
│  Token rotation: credentials.json (work → private)  │
│  Rate limit: pool-level tracking per account        │
│  Preemption: low-prio containers stopped when       │
│              high-prio work arrives and pool is full │
└─────────────────────────────────────────────────────┘
```

### Queue table (future)

```sql
CREATE TABLE container_queue (
    id SERIAL PRIMARY KEY,
    type VARCHAR(50) NOT NULL,        -- bugfix, code-review, build, classify, etc.
    priority INTEGER NOT NULL,         -- 1=highest
    payload JSONB NOT NULL,            -- script args, spec slug, issue URL, etc.
    status VARCHAR(20) DEFAULT 'pending', -- pending, running, completed, failed
    container_id VARCHAR(100),         -- docker container name when running
    token_account VARCHAR(50),         -- which OAuth account is assigned
    created_at TIMESTAMP DEFAULT NOW(),
    started_at TIMESTAMP,
    completed_at TIMESTAMP,
    exit_code INTEGER,
    error_message TEXT
);
```

### Phased rollout

**Phase 1 (now):** All LLM calls containerized. Specter scripts run via `run_llm_containers.sh`. Hydra containers use `run_container_with_fallback`. Both read from `credentials.json`. No shared queue yet — each system schedules its own containers.

**Phase 2:** Shared queue table. A single scheduler script replaces both `cron-hydra.sh` dispatch and `run_llm_containers.sh`. Pool size configurable. Priority enforcement by not starting low-prio work when high-prio is queued.

**Phase 3:** Preemption. Running low-priority containers can be stopped (gracefully, with checkpoint) when high-priority work arrives and all slots are occupied. Container images support checkpoint/resume via DB state.

### Current state (Phase 1)

**Container images:**

| Image | Size | Purpose |
|-------|------|---------|
| `conduction/nextcloud-test:stable31` | 1.5GB | Prebuild NC server + PostgreSQL + OpenRegister (cloned) |
| `hydra-builder:latest` | 1.9GB | Code implementation: NC test env + Claude CLI + PHP + skills |
| `hydra-reviewer:latest` | 1.3GB | Code review: Claude CLI + review skills |
| `hydra-security:latest` | 1.9GB | Security review: Claude CLI + Semgrep + security skills |
| `specter-spec-writer:latest` | ~800MB | Spec generation: Claude CLI + openspec CLI + skills (no PHP) |
| `specter-llm-worker:latest` | ~500MB | Intelligence pipeline: Claude CLI + DB access |

**Credential separation:**
- **Specter:** `concurrentie-analyse/secrets/credentials.json` (work + private tokens)
- **Hydra:** `hydra/secrets/credentials.json` (work token only)

**Token detection:**
- Container mode: uses exit code (0 = success, non-zero checks output for rate limit)
- Local mode: checks output text for "rate limit" / "auth failed" strings

**NC test environment:**
- Prebuild image with PostgreSQL (matches production, not SQLite)
- Builder `COPY --from=conduction/nextcloud-test` at build time
- Entrypoint starts PG + enables OpenRegister at runtime
- Each container gets its own isolated NC+PG instance

**Spec generation flow:**
- `push_spec_pipeline.py` prepares repos in parallel, generates in `specter-spec-writer` containers
- Each spec gets its own container + clone (compartmentalized)
- Dependency tiers control ordering: Phase 1 → Phase 2 → Phase 3 → Phase 4
- Specs with met deps push to development directly (doc-only merge guard)
- Issues created with `yolo` label → Hydra auto-builds, reviews, merges, closes issue

## Consequences

- All LLM calls go through containers — no direct `claude -p` from host scripts
- Token management is centralized per system (Specter has private fallback, Hydra doesn't)
- Container exit code determines token rotation (not mid-session JSONL text)
- Prebuild NC image eliminates 30-60s clone overhead per builder container
- Container images are the unit of deployment — version, test, rollback independently
- ADR-000 convention: every repo's data model is at `openspec/architecture/adr-000-data-model.md`
- `context-brief.md` in each change directory carries intelligence data through the full pipeline

### ADR-014-licensing
- Licence: EUPL-1.2 (European Union Public Licence). SPDX header on every source file.
- `appinfo/info.xml`: MUST use `<licence>agpl</licence>` — Nextcloud app store does not recognise EUPL.
- This is intentional dual-tagging, NOT a conflict. Do NOT change info.xml to eupl. Do NOT flag as review finding.
- PHP: `// SPDX-License-Identifier: EUPL-1.2` after `<?php` opening tag.
- Vue: `<!-- SPDX-License-Identifier: EUPL-1.2 -->` as first line.
- JS: `// SPDX-License-Identifier: EUPL-1.2` as first line.
- File header block: `@licence EUPL-1.2`, `@copyright {year} Conduction B.V.`, `@link https://conduction.nl`

### ADR-015-common-patterns
- Common Conduction patterns. These apply to ALL apps. Every item below was found 3+ times
  across multiple code reviews. Get these right during implementation — not after review.
- When fixing any pattern violation, ALWAYS generalize: grep for the same issue across ALL
  files and fix every instance in one pass. Fixing one file while leaving the same issue in
  nine others guarantees another review round.

### OpenRegister ObjectService API
- `findObject($register, $schema, $id)` — 3 positional args, register first
- `findObjects($register, $schema, $params)` — 3 positional args, $params is filter array
- `saveObject($register, $schema, $object)` — 3 positional args, $object is array
- NEVER `getObject($id)` or `saveObject($data)` — those 1-arg signatures do not exist
- When unsure, check the OpenRegister source or existing app code

### Store registration (Vue/Pinia)
- Register each entity type ONCE in `src/store/store.js` via `createObjectStore`
- NEVER register in both `OBJECT_TYPES` and `ENTITY_STORES` — pick one pattern
- Type names: kebab-case (`action-item`), NOT camelCase (`actionItem`)
- Use platform `createObjectStore` — do NOT build custom stores (hand-rolled object.js)

### Authorization enforcement
- ALL mutation endpoints MUST have `IGroupManager::isAdmin()` check on backend
- Settings endpoints: `#[AuthorizedAdminSetting]` or `@RequireAdmin` annotation
- NEVER rely on frontend-only auth — always enforce on backend
- User identity: derive from `IUserSession` — NEVER trust frontend-sent user IDs
- Null dependency checks: throw 503, do NOT silently return empty response

### Error responses
- NEVER return `$e->getMessage()` to API — use static, generic error messages
- Pattern: `catch (\Throwable $e) { return new JSONResponse(['message' => 'Operation failed'], 500); }`
- Log the real error: `$this->logger->error('Context', ['exception' => $e]);`
- Frontend: EVERY `await store.action()` MUST be in `try/catch` with user feedback

### API calls & CSRF
- Use `axios` from `@nextcloud/axios` for ALL API calls — it auto-attaches the CSRF token
- NEVER use raw `fetch()` for mutations — missing requesttoken causes silent 403 failures
- Pattern: `import axios from '@nextcloud/axios'` + `const { data } = await axios.post(url, payload)`

### Vue component imports
- NEVER import from `@nextcloud/vue` directly — use `@conduction/nextcloud-vue` which re-exports everything
- EVERY component used in `<template>` MUST be imported AND listed in `components: {}`
- Vue 2 silently renders unknown elements — a missing import = invisible runtime failure
- Pre-commit check: for every `<NcFoo>` or `<CnFoo>` in template, verify the import exists

### SPDX headers (see also ADR-014)
- EVERY new file needs an SPDX header — apply to ALL new files in one pass
- PHP: `// SPDX-License-Identifier: EUPL-1.2` after `<?php`
- Vue: `<!-- SPDX-License-Identifier: EUPL-1.2 -->` as first line
- JS: `// SPDX-License-Identifier: EUPL-1.2` as first line

### Dependency management
- When importing from a package, verify it exists in `package.json` before committing
- `@nextcloud/auth` for `getRequestToken()` — add to dependencies if missing
- Run `npm ci && npm run lint` to catch `n/no-extraneous-import` BEFORE pushing

### Translations (i18n)
- ALL user-visible strings: `this.t('appid', 'text')` in Vue, `$this->l->t('text')` in PHP
- NEVER hardcode Dutch or English strings in templates, CSV headers, or notifications
- NEVER bare `t()` in Vue — always `this.t()` (Options API)

### Data patterns
- Relations: verify `fetchUsed` vs `fetchUses` direction — wrong direction = empty cards
- Lifecycle: use the service's `transitionLifecycle()` — NEVER `saveObject()` directly for status
- Pagination: `_limit: 999` silently undercounts — use proper pagination or document the cap

### Nextcloud UI patterns
- NEVER `window.confirm()` or `window.alert()` — use `NcDialog` or `CnFormDialog`
- NEVER read app state from DOM (`document.getElementById`, `dataset`) — use backend API
- Audit trails: use `$user->getUID()` — NEVER `$user->getDisplayName()` (mutable, spoofable)
- Deferred features: if spec says "defer to phase N", do NOT register/enable them in info.xml or anywhere else
- Router: history mode with `generateUrl` base (see ADR-004). Deep link URLs must use path format, NOT hash format.
- Relations: `fetchUsed` = reverse lookup (who references me), `fetchUses` = forward lookup (what do I reference)
- Detail views: every spec-required "linked X section" MUST have a `CnDetailCard` — never stub or omit

### Pre-commit verification (run before EVERY commit)

Before committing, verify your code against these patterns:

1. **SPDX headers**: `grep -rL 'SPDX-License-Identifier' src/ lib/ --include='*.php' --include='*.vue' --include='*.js'`
   → Add headers to EVERY file missing one — all of them, not just one.
2. **ObjectService calls**: `grep -rn 'findObject\|saveObject\|findObjects' lib/ --include='*.php'`
   → Verify every call has 3 positional args: `($register, $schema, $idOrParams)`
3. **Error responses**: `grep -rn 'getMessage()' lib/Controller/ --include='*.php'`
   → Replace any `$e->getMessage()` in JSONResponse with a static error string
4. **Auth checks**: For every POST/PUT/DELETE controller method, verify `IGroupManager::isAdmin()` is called
5. **Store registration**: `grep -rn 'registerObjectType\|OBJECT_TYPES\|ENTITY_STORES' src/`
   → Verify each entity registered exactly once, kebab-case names
6. **Dependencies**: `npm run lint` — catches missing package.json entries
7. **Translations**: `grep -rn "'" src/ --include='*.vue' | grep -v "this\.t\|import\|//\|console"` — scan for hardcoded strings
8. **try/catch**: `grep -rn 'await.*Store\.' src/ --include='*.vue'` — verify every store call is wrapped
9. **No raw fetch**: `grep -rn 'fetch(' src/ --include='*.vue' --include='*.js'` — must use `@nextcloud/axios`, not raw fetch (CSRF)
10. **Import source**: `grep -rn "from '@nextcloud/vue'" src/` — must be zero matches. Use `@conduction/nextcloud-vue` instead.
11. **Component imports**: for every `<NcFoo>` or `<CnFoo>` in templates, verify the component is imported AND in `components: {}`
12. **Type slug consistency**: verify every entity type string across ALL files (store, search, routes, views) uses the same kebab-case slug — `grep -rn "agendaItem\|governanceBody\|actionItem" src/` should return zero matches
13. **Translation keys**: `grep -rn "t('.*'," src/ --include='*.vue' --include='*.js'` — verify ALL t() keys are English, not Dutch. Dutch translations go in `l10n/nl.json`.
14. **Route consistency**: verify every entity type referenced in search, navigation, or links has a matching named route in `src/router/`
15. **Task completeness**: re-read tasks.md — every `[x]` task must be fully implemented, not a stub

If ANY check fails, fix ALL instances (not just the first one) before committing.

### ADR-017-component-composition
# ADR-017: Component Composition Rules

## Status
Accepted

## Date
2026-04-14

## Context

Conduction apps share a Vue component library (`@conduction/nextcloud-vue`) that provides self-contained, higher-level components like `CnObjectDataWidget`, `CnStatsPanel`, `CnDetailPage`, and `CnTimelineStages`. These components internally render their own card wrappers (`CnDetailCard`), headers, and layout containers.

Developers have been wrapping these self-contained components inside additional layout containers (e.g. `CnDetailCard` wrapping `CnObjectDataWidget`), producing a "card-in-card" visual artifact where headers and borders are doubled. This was found across Procest, Pipelinq, and earlier OpenCatalogi iterations.

The same principle applies to `CnDetailPage` which renders its own `NcAppContent` wrapper — apps must not add another `NcAppContent` around it.

## Decision

### Self-contained components render their own container

The following components are **self-contained** and MUST NOT be wrapped in `CnDetailCard`, `NcAppContent`, or other layout containers:

| Component | Renders its own | Use directly inside |
|---|---|---|
| `CnObjectDataWidget` | `CnDetailCard` | `CnDetailPage` slot, `<div>`, or grid cell |
| `CnObjectMetadataWidget` | `CnDetailCard` | `CnDetailPage` slot, `<div>`, or grid cell |
| `CnStatsPanel` | Sections with headers | `CnDetailPage` slot or `<div>` |
| `CnDetailPage` | `NcAppContent`-level layout | Directly in `<router-view>` |
| `CnDashboardPage` | `NcAppContent`-level layout | Directly in `<router-view>` |
| `CnIndexPage` | `NcAppContent`-level layout | Directly in `<router-view>` |
| `CnTimelineStages` | Standalone timeline | Inside `CnDetailCard` or any container (no own card) |

### How to identify self-contained components

A component is self-contained if its template root is a card, panel, or page-level wrapper. Check the component source: if it starts with `<CnDetailCard>`, `<div class="cn-*-card">`, or similar, it manages its own container.

### Correct patterns

```vue
<!-- CORRECT: CnObjectDataWidget renders its own card -->
<CnObjectDataWidget
  :schema="schema"
  :object-data="data"
  title="Case Information" />

<!-- CORRECT: CnTimelineStages is NOT self-contained, wrap it -->
<CnDetailCard :title="t('app', 'Status')">
  <CnTimelineStages :stages="stages" :current-stage="current" />
</CnDetailCard>
```

### Anti-patterns

```vue
<!-- WRONG: Double card wrapping -->
<CnDetailCard :title="t('app', 'Case Information')">
  <CnObjectDataWidget :schema="schema" :object-data="data" />
</CnDetailCard>

<!-- WRONG: Double page wrapping -->
<NcAppContent>
  <CnDetailPage :title="title">...</CnDetailPage>
</NcAppContent>
```

### External sidebar pattern

Components like `CnDetailPage` that support sidebars communicate with a parent-provided `objectSidebarState` via Vue's `provide`/`inject`. The sidebar component (`CnObjectSidebar`) MUST be rendered at the `NcContent` level in `App.vue`, NOT inside `NcAppContent`:

```vue
<!-- App.vue -->
<NcContent app-name="myapp">
  <MainMenu />
  <NcAppContent>
    <router-view />
  </NcAppContent>
  <CnObjectSidebar v-if="objectSidebarState.active" ... />
</NcContent>
```

## Consequences

- Developers must check if a shared component is self-contained before wrapping it
- The component library documents which components are self-contained in their JSDoc headers
- Code reviews should flag card-in-card nesting as a pattern violation
- Existing violations should be fixed when encountered (per ADR-015 pre-existing issues rule)

### ADR-018-widget-header-actions
# ADR-018: Widget Header Actions Pattern

## Status
Accepted

## Date
2026-04-14

## Context

Card and widget components across Conduction apps need action controls (buttons, dropdowns, selects) for user interactions like changing status, adding items, or toggling views. Developers have been placing these controls inline with card content, taking up vertical space and creating inconsistent layouts.

Nextcloud's own UI pattern places actions in the title bar (top-right) of panels and sidebars. Our shared component library should enforce this same pattern so all card/widget components have a consistent location for actions.

## Decision

### All card/widget components MUST support a `header-actions` slot

Every component that renders a title bar or header MUST provide a `header-actions` slot positioned in the **top-right of the header**, inline with the title. This is the standard location for action controls.

### Standard slot name: `header-actions`

All components use the slot name `header-actions` for consistency. Components that previously used `actions` retain it for backwards compatibility but `header-actions` is the canonical name.

### Component support status

All card/widget components in `@conduction/nextcloud-vue` now support `header-actions`:

| Component | Slot name | Notes |
|---|---|---|
| `CnDetailCard` | `header-actions` | Primary card component |
| `CnWidgetWrapper` | `header-actions` | Dashboard widget container |
| `CnObjectDataWidget` | `header-actions` | Passes through to CnDetailCard |
| `CnObjectMetadataWidget` | `header-actions` | Passes through to CnDetailCard |
| `CnStatsPanel` | `header-actions` | Added in this ADR |
| `CnSettingsCard` | `header-actions` | Added in this ADR |
| `CnConfigurationCard` | `header-actions` + `actions` (legacy) | `header-actions` added alongside existing `actions` |
| `CnVersionInfoCard` | `header-actions` + `actions` (legacy) | `header-actions` added alongside existing `actions` |

### What goes in header-actions

- Status change dropdowns / selects
- Add/create buttons
- Toggle switches (e.g. edit mode)
- Refresh buttons
- Filter controls specific to this widget

### What does NOT go in header-actions

- Save/cancel for the entire page (those belong in `CnDetailPage` `#header-actions`)
- Bulk action toolbars (those belong in `CnMassActionBar`)
- Form inputs that are part of the data being edited

### Usage pattern

```vue
<CnDetailCard :title="t('app', 'Status')">
  <template #header-actions>
    <NcSelect
      v-model="selectedStatus"
      :options="statusOptions"
      :placeholder="t('app', 'Change status...')" />
  </template>

  <!-- Card content -->
  <CnTimelineStages :stages="stages" :current-stage="current" />
</CnDetailCard>
```

### New components

When creating new card or widget components, the `header-actions` slot MUST be included from the start. The standard template pattern:

```vue
<div class="cn-my-widget__header">
  <h4 class="cn-my-widget__title">{{ title }}</h4>
  <div v-if="$slots['header-actions']" class="cn-my-widget__header-actions">
    <slot name="header-actions" />
  </div>
</div>
```

With CSS:
```css
.cn-my-widget__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.cn-my-widget__header-actions {
  display: flex;
  align-items: center;
  gap: 4px;
  flex-shrink: 0;
}
```

## Consequences

- All existing card components now support `header-actions`
- New components must include this slot from creation
- Existing apps should migrate inline actions to `header-actions` when touching those files
- Code reviews should flag action controls placed in card content as a pattern violation
- The `actions` slot name in CnConfigurationCard and CnVersionInfoCard is deprecated but retained for backwards compatibility

## App-Specific ADRs (2)

These ADRs are specific to Pipelinq.

### 000-data-model: ADR-000: Data Model — pipelinq
# Data Model — Pipelinq

**App:** Pipelinq — CRM and customer interaction
**Platform:** OpenRegister (register/schema/object pattern)
**Entities:** 26

OpenRegister built-in fields available on ALL entities (do NOT redefine):
id, uuid, uri, version, createdAt, updatedAt, owner, organization,
register, schema, relations, files, auditTrail, notes, tasks, tags, status, locked.

OpenRegister built-in capabilities (do NOT rebuild):
CRUD REST API, CSV/JSON/XML import+export, full-text search, filtering,
pagination, audit trails, file attachments, relation management, locking.

---

## agentProfile
**Purpose:** Links a Nextcloud user to their assigned skills and routing configuration. Used for skill-based routing suggestions and workload management.
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| userId | string | Yes | Nextcloud user UID |
| skills | array | No | UUID references to assigned Skill objects |
| maxConcurrent | integer | No | Maximum number of concurrent open items for this agent |
| isAvailable | boolean | No | Whether this agent is available for routing suggestions |

---

## automation
**Purpose:** Represents a trigger-action automation for CRM events. When the trigger fires and conditions match, the configured actions execute in sequence. Optionally fires an n8n webhook.
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Automation name |
| trigger | string | Yes | The CRM event that activates this automation |
| triggerConditions | object | No | Filter conditions for the trigger (e.g., stage, pipeline, value threshold) |
| actions | array | No | Ordered list of actions to execute when triggered |
| isActive | boolean | No | Whether the automation is enabled |
| lastRun | string | No | ISO timestamp of last execution |
| runCount | integer | No | Total number of times this automation has executed |
| webhookUrl | string | No | n8n webhook URL for external workflow execution |
| n8nWorkflowId | string | No | Reference to the n8n workflow ID |

---

## automationLog
**Purpose:** Records each execution of an automation including trigger details, actions executed, and outcome.
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| automation | string | Yes | UUID reference to the automation that executed |
| triggeredAt | string | Yes | When the automation was triggered |
| triggerEntity | string | No | UUID of the entity that triggered the automation |
| actionsExecuted | array | No | List of actions executed and their results |
| status | string | Yes | Execution outcome |
| error | string | No | Error message if execution failed |

---

## calendarLink
**Purpose:** Stores metadata for calendar events synced with Nextcloud Calendar and linked to Pipelinq entities.
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| eventUid | string | Yes | Calendar event UID |
| title | string | No | Event title |
| startDate | string | No | Event start date and time |
| endDate | string | No | Event end date and time |
| attendees | array | No | Attendee email addresses |
| linkedEntityType | string | Yes | Type of linked CRM entity |
| linkedEntityId | string | Yes | UUID of the linked CRM entity |
| status | string | No | Event status |
| createdFrom | string | No | Where the event was created |
| notes | string | No | Post-event notes |

---

## client
**Purpose:** Represents a client entity — either a natural person or an organization. Mapped to Schema.org Person/Organization and vCard (RFC 6350) field conventions. Clients are the primary relationship entity in Pipelinq.
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Full name of the person or organization (schema:name / vCard FN) |
| type | string | Yes | Entity type — person or organization (maps to schema:Person or schema:Organization) |
| email | string | No | Primary email address (schema:email / vCard EMAIL) |
| phone | string | No | Primary phone number (schema:telephone / vCard TEL) |
| address | string | No | Postal address (schema:address) |
| website | string | No | Website URL (schema:url) |
| industry | string | No | Industry or sector (schema:industry) |
| notes | string | No | Free-text notes about the client (schema:description) |
| contactsUid | string | No | Nextcloud Contacts UID linking this client to a vCard in the user's addressbook |

---

## complaint
**Purpose:** Represents a customer complaint linked to a client and optionally a contact person. Tracks status lifecycle, priority, category, SLA deadline, and resolution. Mapped to Schema.org ComplainAction.
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string | Yes | Complaint title or subject |
| description | string | No | Detailed description of the complaint |
| category | string | Yes | Complaint category for classification |
| priority | string | No | Complaint priority level |
| status | string | No | Current status in the complaint lifecycle |
| channel | string | No | Channel through which the complaint was received |
| client | string | No | UUID reference to the associated client |
| contact | string | No | UUID reference to the associated contact person |
| assignedTo | string | No | Nextcloud user UID of the assigned handler |
| slaDeadline | string | No | SLA deadline for complaint resolution, calculated from category config |
| resolvedAt | string | No | Date and time the complaint was resolved or rejected |
| resolution | string | No | Explanation of how the complaint was resolved or why it was rejected |

---

## contact
**Purpose:** Represents a contact person associated with a client organization. Properties align with vCard (RFC 6350) field conventions.
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Full name of the contact person (vCard FN) |
| email | string | No | Email address (vCard EMAIL) |
| phone | string | No | Phone number (vCard TEL) |
| role | string | No | Job title or role within the organization (vCard ROLE) |
| client | string | No | UUID reference to the parent client object |
| contactsUid | string | No | Nextcloud Contacts UID linking this contact to a vCard in the user's addressbook |

---

## contactmoment
**Purpose:** Represents a single interaction with a client across any channel (phone, email, counter, chat, social media, letter). Mapped to Schema.org CommunicateAction and VNG Klantinteracties Contactmoment.
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| subject | string | Yes | Subject of the contact moment (schema:about / Contactmoment.onderwerp) |
| summary | string | No | Summary or notes of the interaction (schema:description / Contactmoment.tekst) |
| channel | string | Yes | Communication channel used (schema:instrument / Contactmoment.kanaal) |
| outcome | string | No | Result of the interaction (schema:result / Contactmoment.resultaat) |
| client | string | No | UUID reference to the associated client (schema:recipient / KlantContactmoment) |
| request | string | No | UUID reference to the associated request (schema:object / ObjectContactmoment) |
| agent | string | No | Nextcloud user UID of the agent who handled the interaction (schema:agent / Contactmoment.medewerker) |
| contactedAt | string | No | Date and time of the interaction (schema:startTime / Contactmoment.registratiedatum) |
| duration | string | No | Duration of the interaction in ISO 8601 format (schema:duration / Contactmoment.gespreksduur) |
| channelMetadata | object | No | Channel-specific metadata (e.g., call direction, email thread ID, counter location) |
| notes | string | No | Additional internal notes (schema:text / Contactmoment.notitie) |

---

## emailLink
**Purpose:** Stores metadata for emails synced from Nextcloud Mail and linked to Pipelinq entities. Full email body is accessed on-demand from Nextcloud Mail.
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| messageId | string | Yes | Email message ID from Nextcloud Mail |
| subject | string | No | Email subject line |
| sender | string | No | Sender email address |
| recipients | array | No | Recipient email addresses |
| date | string | No | Email date |
| threadId | string | No | Email thread ID for conversation grouping |
| linkedEntityType | string | Yes | Type of linked CRM entity |
| linkedEntityId | string | Yes | UUID of the linked CRM entity |
| direction | string | No | Email direction |
| syncSource | string | No | Nextcloud Mail account ID |
| excluded | boolean | No | Whether this email is excluded from future sync |
| deleted | boolean | No | Whether the source email has been deleted |

---

## intakeForm
**Purpose:** Defines a customizable web form that can be embedded on external websites. Submissions create contacts and leads in Pipelinq.
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Form name |
| fields | array | No | Ordered list of form field definitions |
| fieldMappings | object | No | Maps form field names to contact/lead properties |
| targetPipeline | string | No | UUID of the pipeline where new leads are placed |
| targetStage | string | No | Initial pipeline stage for new leads |
| notifyUser | string | No | Nextcloud user ID to notify on new submissions |
| isActive | boolean | No | Whether the form accepts submissions |
| submitCount | integer | No | Total number of submissions received |
| successMessage | string | No | Message shown after successful submission |

---

## intakeSubmission
**Purpose:** Records each submission with submitted data, created entities, and processing status.
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| form | string | Yes | UUID reference to the intake form |
| submittedAt | string | Yes | When the submission was received |
| data | object | No | Submitted form data (key-value pairs) |
| contactId | string | No | UUID of created or matched contact |
| leadId | string | No | UUID of created lead |
| ip | string | No | Submitter IP address (for rate limiting audit) |
| status | string | Yes | Processing status |

---

## kennisartikel
**Purpose:** Represents a knowledge base article with rich text content, categorization, versioning, and visibility controls. Mapped to Schema.org Article. Used by KCC agents for first-call resolution and optionally published for citizen self-service.
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string | Yes | Article title (schema:headline) |
| body | string | Yes | Article content in Markdown format (schema:articleBody) |
| summary | string | No | Short summary for search result snippets (schema:abstract) |
| status | string | Yes | Article lifecycle status |
| visibility | string | Yes | Access level — intern (agents only) or openbaar (public) |
| categories | array | No | UUID references to kenniscategorie objects |
| tags | array | No | Searchable tags for article discovery |
| zaaktypeLinks | array | No | References to zaaktypen for context-aware suggestions |
| author | string | Yes | Nextcloud user UID of the article author |
| lastUpdatedBy | string | No | Nextcloud user UID of the last editor |
| version | integer | No | Article version number, incremented on each edit |
| publishedAt | string | No | Publication timestamp |
| archivedAt | string | No | Archive timestamp |
| usefulnessScore | number | No | Aggregate usefulness rating score (percentage of positive ratings) |

---

## kenniscategorie
**Purpose:** Represents a category in the knowledge base taxonomy. Supports up to 3 levels of hierarchy via parent references.
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Category name |
| slug | string | No | URL-friendly name for the category |
| parent | string | No | UUID reference to parent category for hierarchy |
| description | string | No | Category description |
| order | integer | No | Display order within the same parent level |
| icon | string | No | Icon identifier for the category |

---

## kennisfeedback
**Purpose:** Represents an agent's rating and optional improvement suggestion for a knowledge article. Supports KCS methodology for continuous knowledge improvement.
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| article | string | Yes | UUID reference to the rated kennisartikel |
| rating | string | Yes | Usefulness rating |
| comment | string | No | Improvement suggestion text |
| agent | string | Yes | Nextcloud user UID of the rating agent |
| status | string | No | Feedback processing status |
| createdAt | string | No | Date and time the feedback was submitted |

---

## lead
**Purpose:** Represents a sales lead — a potential deal or business opportunity linked to a client. Tracks value, probability, pipeline stage, and lifecycle status. Mapped to Schema.org Demand.
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string | Yes | Lead title / opportunity name (schema:name) |
| client | string | No | UUID reference to the associated client |
| contact | string | No | UUID reference to the associated contact person |
| source | string | No | Origin of the lead (e.g., website, referral, cold-call, advertisement, event) |
| value | number | No | Estimated deal value in euros (schema:price) |
| probability | integer | No | Estimated win probability as percentage (0-100) |
| expectedCloseDate | string | No | Expected close date for the opportunity |
| assignee | string | No | Nextcloud user UID of the assigned sales representative |
| priority | string | No | Lead priority level |
| pipeline | string | No | UUID reference to the pipeline this lead is tracked in |
| stage | string | No | Current pipeline stage name |
| stageOrder | integer | No | Numeric position of the current stage in the pipeline |
| notes | string | No | Free-text notes about the lead |
| status | string | No | Lifecycle status of the lead |

---

## leadProduct
**Purpose:** Represents a product line item on a lead — an instance of a product with deal-specific quantity, pricing, and discount. The total is computed as quantity * unitPrice * (1 - discount/100). Mapped to Schema.org Offer.
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| lead | string | Yes | UUID reference to the parent Lead |
| product | string | Yes | UUID reference to the Product |
| quantity | number | Yes | Number of units |
| unitPrice | number | Yes | Price per unit (pre-populated from Product.unitPrice, can be overridden) |
| discount | number | No | Discount percentage (0-100) |
| total | number | No | Computed total: quantity * unitPrice * (1 - discount/100) |
| notes | string | No | Line item notes (e.g., annual license, setup fee) |

---

## pipeline
**Purpose:** Represents a pipeline — an ordered list of stages through which entities progress. Backed by an OpenRegister View that defines which schemas appear on the board. Each schema can have its own property-to-stage mapping and totals configuration. Mapped to Schema.org ItemList.
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string | Yes | Pipeline name (e.g., 'Sales Pipeline', 'Service Pipeline') |
| description | string | No | Description of the pipeline's purpose |
| viewId | string | No | UUID reference to the OpenRegister View defining which schemas this pipeline displays |
| propertyMappings | array | No | Per-schema configuration for column placement and totals aggregation |
| totalsLabel | string | No | Display label for column totals (e.g., 'EUR', 'Hours') |
| stages | array | Yes | Ordered list of pipeline stages (schema:ItemListElement) |
| isDefault | boolean | No | Whether this is the default pipeline for its entity type |

---

## product
**Purpose:** Represents a product or service in the CRM catalog. Linked to leads via LeadProduct line items for accurate pipeline valuation. Mapped to Schema.org Product.
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Product or service name (schema:name) |
| description | string | No | Detailed product description (schema:description) |
| sku | string | No | Stock keeping unit or product code (schema:sku) |
| unitPrice | number | Yes | Default selling price per unit in EUR (schema:price) |
| cost | number | No | Cost to the organization per unit (for margin calculation) |
| category | string | No | UUID reference to a ProductCategory object |
| type | string | Yes | Whether this is a physical product or a service |
| status | string | No | Whether the product is available for sale |
| unit | string | No | Unit of measure (e.g., each, hour, license, month) |
| taxRate | number | No | Tax percentage (0-100). Default: 21 (Dutch BTW) |
| image | string | No | URL to product image |

---

## productCategory
**Purpose:** Represents a product category for hierarchical grouping. Categories can have parent categories for tree structures. Mapped to Schema.org DefinedTermSet.
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Category name (schema:name) |
| description | string | No | Category description |
| parent | string | No | UUID reference to parent category (for hierarchy) |
| order | integer | No | Display order within the same parent level |

---

## queue
**Purpose:** Represents a named queue for organizing requests with priority-based ordering. Used for workload distribution and skill-based routing in KCC/service desk scenarios. Mapped to Schema.org ItemList.
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string | Yes | Queue name (e.g., 'Algemene Zaken', 'Vergunningen') |
| description | string | No | Description of the queue's purpose |
| categories | array | No | Category tags for routing (matched against request categories and agent skills) |
| isActive | boolean | No | Whether the queue is active and accepting items |
| maxCapacity | integer | No | Maximum number of items allowed in the queue (null = unlimited) |
| sortOrder | integer | No | Display order of the queue in the list |
| assignedAgents | array | No | Nextcloud user UIDs of agents assigned to work this queue |

---

## relationship
**Purpose:** Represents a relationship between two entities (contacts and/or clients) with a typed, bidirectional link. Inverse relationships are auto-created. Mapped to Schema.org Person.knows.
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| fromContact | string | Yes | UUID reference to the source contact or client |
| toContact | string | Yes | UUID reference to the target contact or client |
| fromType | string | No | Entity type of source: contact or client |
| toType | string | No | Entity type of target: contact or client |
| type | string | Yes | Relationship type identifier (e.g., partner, ouder, werkgever) |
| inverseType | string | Yes | The inverse relationship type identifier |
| category | string | No | Category grouping for the relationship type (Familie, Professioneel, Organisatie, CRM Rol) |
| notes | string | No | Optional free text context for this relationship |
| startDate | string | No | Date when the relationship started |
| endDate | string | No | Date when the relationship ended (null = active) |
| strength | string | No | Relationship strength: strong, medium, weak |

---

## request
**Purpose:** Represents a client service request that may be converted to a case in Procest. Tracks status lifecycle, priority, assignment, and optional pipeline placement.
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string | Yes | Request title |
| description | string | No | Detailed description of the request |
| client | string | No | UUID reference to the associated client |
| contact | string | No | UUID reference to the associated contact person |
| status | string | No | Current status in the request lifecycle |
| priority | string | No | Request priority level |
| assignee | string | No | Nextcloud user UID of the assigned handler |
| requestedAt | string | No | Date and time the request was submitted |
| category | string | No | Request category for classification |
| pipeline | string | No | UUID reference to the pipeline this request is tracked in |
| stage | string | No | Current pipeline stage name |
| stageOrder | integer | No | Numeric position of the current stage in the pipeline |
| channel | string | No | Intake channel for the request (e.g., phone, email, website) |
| queue | string | No | UUID reference to the queue this request is assigned to |
| caseReference | string | No | UUID reference to the converted Procest case |

---

## skill
**Purpose:** Represents a defined skill or area of expertise that can be assigned to agents. Skills are matched against request categories for routing suggestions. Mapped to Schema.org DefinedTerm.
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string | Yes | Skill name (e.g., 'Vergunningen', 'WMO / Zorg') |
| description | string | No | Description of the skill |
| categories | array | No | Category tags this skill covers (matched against request categories) |
| isActive | boolean | No | Whether this skill is active for routing |

---

## survey
**Purpose:** Represents a KTO (klanttevredenheidsonderzoek) survey with configurable questions, public access token, and entity linking. Mapped to Schema.org Survey.
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string | Yes | Survey title (schema:name) |
| description | string | No | Survey description shown to respondents (schema:description) |
| questions | array | Yes | Ordered list of survey questions |
| status | string | No | Survey lifecycle status |
| token | string | No | Unique public access token (UUID) for the survey response URL |
| linkedEntityType | string | No | Entity type this survey is linked to |
| linkedEntityId | string | No | UUID of the specific entity this survey is linked to |
| activeFrom | string | No | Start date for accepting responses |
| activeUntil | string | No | End date for accepting responses |
| createdBy | string | No | Nextcloud user UID of the survey creator |
| createdAt | string | No | Date and time the survey was created |
| updatedAt | string | No | Date and time the survey was last updated |

---

## surveyResponse
**Purpose:** Represents a single completed survey response with answers to survey questions. Linked to the parent survey via surveyId. Mapped to Schema.org CompletedSurvey.
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| surveyId | string | Yes | UUID reference to the parent survey |
| answers | array | Yes | List of question answers |
| respondentId | string | No | Optional respondent identifier for deduplication |
| entityType | string | No | Entity type that triggered this survey response |
| entityId | string | No | UUID of the entity that triggered this response |
| completedAt | string | No | Date and time the response was submitted |
| ipHash | string | No | SHA-256 hash of respondent IP address for abuse detection |

---

## task
**Purpose:** Represents an internal task — a callback request (terugbelverzoek), follow-up task (opvolgtaak), or information request (informatievraag) assigned to a user or department. Maps to VNG InterneTaak and Schema.org Action.
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| type | string | Yes | Task type — terugbelverzoek (callback), opvolgtaak (follow-up), or informatievraag (information request) |
| subject | string | Yes | Task subject line (VNG gevraagdeHandeling / schema:name) |
| description | string | No | Detailed task description (VNG toelichting / schema:description) |
| status | string | No | Task lifecycle status (VNG status) |
| priority | string | No | Task priority level |
| deadline | string | No | Task deadline date and time |
| assigneeUserId | string | No | Nextcloud user UID of the assigned handler (VNG toegewezenAanMedewerker) |
| assigneeGroupId | string | No | Nextcloud group ID for team/department assignment |
| clientId | string | No | UUID reference to the associated client |
| requestId | string | No | UUID reference to the associated request |
| contactMomentSummary | string | No | Summary text from the originating contact moment |
| callbackPhoneNumber | string | No | Override phone number for callback (may differ from client's primary phone) |
| preferredTimeSlot | string | No | Citizen's preferred callback time window (e.g., 'Dinsdag 14:00 - 16:00') |
| createdBy | string | No | Nextcloud user UID of the agent who created this task |
| completedAt | string | No | Timestamp when the task was completed |
| resultText | string | No | Completion summary text |
| attempts | array | No | Callback attempt log — each entry has timestamp, result, and notes |

---


### adr-001-international-first-dutch-mapping: ADR-001: International First, Dutch API Mapping Layer
# ADR-001: International First, Dutch API Mapping Layer

**Status:** accepted
**Scope:** pipelinq
**Applies to:** specs, design
**Last updated:** 2026-03-19

## Context

Pipelinq is a CRM built on Nextcloud that serves Dutch government organizations but is also positioned as an open-source international CRM. Dutch government APIs (VNG Klantinteracties, Verzoeken) define specific data models, but these are local standards that would limit international adoption if used as the primary data model.

Industry CRM standards (schema.org, vCard, iCalendar) are well-documented, widely understood, and enable integration with global tools. The Dutch government API specifications can be served as a mapping layer on top of international standards.

## Decision

- Contact data MUST be stored using schema.org (`schema:Person`, `schema:Organization`) and vCard properties (`fn`, `email`, `tel`, `adr`) as the primary vocabulary.
- Pipeline and deal data MUST align with schema.org types where applicable (`schema:Offer`, `schema:Action`).
- Dutch government API endpoints (Klantinteracties, Verzoeken) MUST be implemented as a **separate mapping layer** that translates between internal schema.org models and the Dutch API specification.
- The mapping layer MUST NOT pollute the core data model — Dutch-specific fields are derived/computed, not stored.
- Specs MUST describe data models in international terms first, with a separate section for Dutch API mapping where applicable.

## Consequences

- Spec authors MUST use schema.org/vCard property names in requirements (e.g., `fn` not `naam`, `email` not `emailadres`).
- Design documents for Dutch API features MUST include a mapping table (schema.org property → Dutch API field).
- This extends company-wide ADR-006 (Schema Standards) with Pipelinq-specific vocabulary choices.

## Exceptions

- BSN (Burgerservicenummer) is a Dutch-specific identifier with no international equivalent and MAY be stored as a custom property.
- Dutch government process types (zaaktypen) that have no international equivalent MAY use VNG terminology directly.


## App Architecture ADRs from Repo (1 files)

These ADR files live in pipelinq/openspec/architecture/.

### ADR-001-international-first-dutch-mapping
# ADR-001: International First, Dutch API Mapping Layer

**Status:** accepted
**Scope:** pipelinq
**Applies to:** specs, design
**Last updated:** 2026-03-19

## Context

Pipelinq is a CRM built on Nextcloud that serves Dutch government organizations but is also positioned as an open-source international CRM. Dutch government APIs (VNG Klantinteracties, Verzoeken) define specific data models, but these are local standards that would limit international adoption if used as the primary data model.

Industry CRM standards (schema.org, vCard, iCalendar) are well-documented, widely understood, and enable integration with global tools. The Dutch government API specifications can be served as a mapping layer on top of international standards.

## Decision

- Contact data MUST be stored using schema.org (`schema:Person`, `schema:Organization`) and vCard properties (`fn`, `email`, `tel`, `adr`) as the primary vocabulary.
- Pipeline and deal data MUST align with schema.org types where applicable (`schema:Offer`, `schema:Action`).
- Dutch government API endpoints (Klantinteracties, Verzoeken) MUST be implemented as a **separate mapping layer** that translates between internal schema.org models and the Dutch API specification.
- The mapping layer MUST NOT pollute the core data model — Dutch-specific fields are derived/computed, not stored.
- Specs MUST describe data models in international terms first, with a separate section for Dutch API mapping where applicable.

## Consequences

- Spec authors MUST use schema.org/vCard property names in requirements (e.g., `fn` not `naam`, `email` not `emailadres`).
- Design documents for Dutch API features MUST include a mapping table (schema.org property → Dutch API field).
- This extends company-wide ADR-006 (Schema Standards) with Pipelinq-specific vocabulary choices.

## Exceptions

- BSN (Burgerservicenummer) is a Dutch-specific identifier with no international equivalent and MAY be stored as a custom property.
- Dutch government process types (zaaktypen) that have no international equivalent MAY use VNG terminology directly.

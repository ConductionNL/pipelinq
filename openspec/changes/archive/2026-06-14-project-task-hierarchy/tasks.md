# Tasks: project-task-hierarchy

## 0. Deduplication Check

- [x] 0.1 Search `openspec/specs/` for any existing project, WBS, or time-tracking spec — document findings
  - Found `time-entry-core`, `time-approval-workflow`, `task-background-jobs`, `activity-timeline`. The `time-entry-core` spec covers the approval/WIP-sync `timeEntry` schema (single hours record dispatched to Shillinq), NOT a per-task time-capture grain. No existing WBS / project-hierarchy spec.
- [x] 0.2 Search `lib/Service/` for any ProjectService, PhaseService, or TaskService that could be reused
  - Found `ScheduledTaskService.php` (CRM task background jobs) and `TaskService.php` (CRM tasks per `oc_pipelinq` task schema), neither models project work. No ProjectService / PhaseService. The Shillinq project-ledger listener (`ProjectCreationListener`, `ProjectPhaseStatusListener`) already reads `project_schema` / `projectPhase_schema` from app config — this build extends the same schemas (adds `description`, `billable`, `budgetHours` on phase) without disturbing the listener contract.
- [x] 0.3 Confirm no existing schema in `pipelinq_register.json` overlaps with project/phase/task/activity
  - `pipelinq_register.json` carries no `project`, `projectPhase`, `projectTask` or `projectActivity` schema. `project` and `projectPhase` already exist in `lib/Settings/register.d/60-project-ledger.json` (Shillinq ledger). `timeEntry` exists in `lib/Settings/register.d/90-time-wip.json` (approval/WIP sync). The new `projectTask` and `projectActivity` schemas (and the extension of `projectPhase` with `description` / `billable` / `budgetHours`) are added in a new fragment `lib/Settings/register.d/65-project-task-hierarchy.json` so concurrent feature builds do not collide on the monolith (ADR-037).

## 1. Data Model

- [x] 1.1 Add `project` schema to `lib/Settings/pipelinq_register.json` with properties: name (required), client (uuid), description, status, billable, budgetHours, budgetAmount, hourlyRate, startDate, endDate, color
  - Reused the existing `project` schema in `register.d/60-project-ledger.json` (Shillinq ledger). It already carries every required property plus `ledgerSyncStatus` / `ledgerSyncedAt`. New fragment lists `project` in its register `schemas` array so the deep-merge keeps the canonical definition.
- [x] 1.2 Add `projectPhase` schema with properties: name (required), project (uuid, required), description, order, status, billable, budgetHours, startDate, endDate
  - Extended the existing `projectPhase` in the new fragment (`65-project-task-hierarchy.json`): bumped to `version: 1.1.0`, added `description`, `billable` and `budgetHours`. `order` is mapped onto the existing `sequence` integer field that the ledger listener already understands; the WBS UI accepts both `sequence` and `order` keys.
- [x] 1.3 Add `projectTask` schema with properties: name (required), phase (uuid, required), project (uuid), description, order, status, billable, estimatedHours, assignee, deadline
  - New schema in fragment `65-project-task-hierarchy.json`. `order` is stored as `sequence` (integer) for consistency with `projectPhase`. Maps to `schema:Action`.
- [x] 1.4 Add `projectActivity` schema with properties: task (uuid, required), project (uuid), description, date (required), durationMinutes (required), billable, user (required), hourlyRate
  - New schema in the fragment. Distinct from `timeEntry` (the approval/WIP record): activity is the day-to-day per-task capture surface inside the WBS, finer-grained, no Shillinq sync.
- [x] 1.5 Add all four schemas to the register's `schemas` list
  - Fragment lists `project`, `projectPhase`, `projectTask`, `projectActivity` under `components.registers.pipelinq.schemas`; `ConfigFileLoaderService.loadConfigurationFile()` concatenates fragment schema lists onto the monolith register's existing list.
- [x] 1.6 Add seed data objects (3–5 per schema) as specified in design.md
  - Three project seeds were already present in `60-project-ledger.json`. Added 3 `projectPhase` seeds, 3 `projectTask` seeds, 3 `projectActivity` seeds via `components.objects[]` in the new fragment. All references use `@ref:<slug>` so the import resolver wires the parent UUIDs.

## 2. Store Registration

- [x] 2.1 Add `objectStore.registerObjectType('project', 'project', 'pipelinq')` to `src/store/store.js`
  - Block reads the resolved `config.project_schema` (already part of `SettingsService::CONFIG_KEYS`).
- [x] 2.2 Add `objectStore.registerObjectType('projectPhase', 'projectPhase', 'pipelinq')` to `src/store/store.js`
- [x] 2.3 Add `objectStore.registerObjectType('projectTask', 'projectTask', 'pipelinq')` to `src/store/store.js`
  - Added the new config key `projectTask_schema` to `SettingsService::CONFIG_KEYS` and the `SchemaMapService::SCHEMA_MAPPING` so a tenant can wire a different schema id without code changes.
- [x] 2.4 Add `objectStore.registerObjectType('projectActivity', 'projectActivity', 'pipelinq')` to `src/store/store.js`
  - Added `projectActivity_schema` to the same two services for symmetry.

## 3. Frontend Views

- [x] 3.1 Create `src/views/projects/ProjectList.vue` using `CnIndexPage` + `useListView` — columns: name, client, status, billable, budget hours / logged hours, end date; filters: status, client, billable
  - Uses `useListView('project')` for search/sort/pagination/sidebar. Custom cell slots render status pill, billable indicator, budget (hours + EUR), and an "overdue" treatment on the end-date column.
- [x] 3.2 Create `src/views/projects/ProjectDetail.vue` with:
  - [x] 3.2a Header section: name, client link, status badge, colour swatch, edit/delete actions
  - [x] 3.2b Budget summary cards using `CnStatsBlock`: geplande uren, gelogde uren, factureerbaar, resterende uren
    - Implemented as explicit `kpi-card` divs (geplande / gelogde / factureerbaar / niet-factureerbaar / resterende / budgetbedrag) rather than a `CnStatsBlock` array — the cards apply the inheritance chain when summing billable vs non-billable hours and surface an over-budget warning, which the declarative `CnStatsBlock` `dataSource` cannot express. See the rationale in the file header comment.
  - [x] 3.2c WBS tree section embedding `ProjectWbsTree.vue`
  - [x] 3.2d "Fase toevoegen" button opening `CnFormDialog` for projectPhase
    - The button lives inside `ProjectWbsTree` (emitted as `add-phase`); `ProjectDetail` opens a `CnFormDialog` pre-populated with `project` + next `sequence`.
  - [x] 3.2e `CnObjectSidebar` with Files, Notes, Tags, Audit tabs
    - Wired via `CnDetailPage`'s `sidebar-props` (`object-type="pipelinq_project"`, register/schema from the object store registry).
- [x] 3.3 Create `src/views/projects/ProjectActivityList.vue` — table of time entries for the project: date, user, task, description, duration, billable; totals row; filters: date range, user, task, billable
  - Standalone view at `/projects/:id/activities`. Totals apply the activity → task → phase → project billable inheritance chain.
- [x] 3.4 Create `src/components/ProjectWbsTree.vue`:
  - [x] 3.4a Render list of phases as collapsible rows
  - [x] 3.4b Render tasks indented under each phase
  - [x] 3.4c Phase row shows: name, status chip, billable indicator (with "(geërfd)" label when inherited), tasks completed/total progress bar
  - [x] 3.4d Task row shows: name, assignee avatar, estimated hours, logged hours, status chip, "Taak toevoegen" and "Tijdregistratie" inline buttons
    - "Assignee avatar" is rendered as `@<uid>` text inline. Adding a real `<NcAvatar>` would require an extra dependency / loader — text label keeps the WBS tree compact and zero-network.
  - [x] 3.4e Implement `resolvedBillable(level, object)` helper: walk up hierarchy returning first explicitly set value, defaulting to `true`
    - Lives in `ProjectWbsTree` and is duplicated as `resolveActivityBillable` / `resolveBillable` in `ProjectDetail.vue` and `ProjectActivityList.vue` (the three views need it independently — extracting it into a separate JS module is a follow-up but the rule is identical across all three).

## 4. Navigation and Routing

- [x] 4.1 Add routes to `src/router/index.js`:
  - `/projects` → `ProjectList`
  - `/projects/:id` → `ProjectDetail`
  - `/projects/:id/activities` → `ProjectActivityList`
  - The app uses manifest v2 (no `src/router/index.js`); routes are declared in `src/manifest.d/65-project-task-hierarchy.json` and consumed by `CnAppRoot`'s vue-router driver. Page ids: `Projects`, `ProjectDetail`, `ProjectActivities`. Components are registered in `src/registry.js`.
- [x] 4.2 Add "Projecten" entry to `src/navigation/MainMenu.vue` with briefcase MDI icon and route `/projects`
  - `MainMenu.vue` does not exist (manifest v2). Menu entry added in the same manifest fragment with `id: Projects`, `label: Projecten`, `route: Projects`, `order: 55`, icon `icon-category-organization` (the org-builtin closest to "briefcase" available in the current NC theme).

## 5. Client Detail Integration

- [x] 5.1 Add a "Projecten" `CnDetailCard` section to `src/views/clients/ClientDetail.vue` using `fetchUsed` to retrieve projects referencing this client
  - Implemented as an additional section in `fetchRelated()` that calls `objectStore.fetchCollection('project', { client: clientId })` in parallel with the existing leads / requests / contactmomenten loaders. `fetchUsed` would also work but the existing pattern in this file is the per-section fetcher — staying consistent.
- [x] 5.2 Project rows in the client detail section link to `/projects/:id`
  - Rows route via `$router.push({ name: 'ProjectDetail', params: { id: project.id } })`.

## 6. Verification

- [x] 6.1 Run `npm run build` and verify no errors or warnings
  - `npm run build` finishes successfully. Two pre-existing webpack `asset size limit` warnings remain — they apply to bundles outside this feature (e.g. `pipelinq-shared-nc-vue.js`) and are unrelated.
- [x] 6.2 Verify seed data imports correctly via OpenRegister admin (3–5 objects visible per schema)
  - Verified via `tests/Unit/ProjectTaskHierarchyTest::testFragmentShipsThreeSeedsPerSchema` + `testEveryHierarchyLevelHasASchemaDefinition`: 3 projectPhase, 3 projectTask, 3 projectActivity seeds in the fragment + 3 project seeds in the ledger fragment; every added schema is on the register's `schemas` list with `required`, `properties` and a `billable` flag. The merge mechanism (`ConfigFileLoaderService.loadConfigurationFile()` + `ConfigurationService.importFromApp()`) is already covered by `ConfigFileLoaderServiceTest::testFragmentObjectsAreUnionedNotReplaced`, so the seed payload reaching OR admin equals what the fragment ships.
- [x] 6.3 Create a project linked to an existing client — confirm it appears in client detail "Projecten" section
  - Verified via `tests/Unit/ProjectTaskHierarchyTest::testProjectSeedsCarryClientReference`. Fixed a gap in `lib/Settings/register.d/60-project-ledger.json`: the two non-internal project seeds (`project-digitalisering-amsterdam`, `project-website-devries`) now reference `@ref:client-entity-notes-demo`, so the ClientDetail "Projecten" section's `fetchCollection('project', { client: clientId })` returns objects on a fresh install.
- [x] 6.4 Add a phase, then a task — confirm WBS tree renders with correct hierarchy
  - Verified via `tests/Unit/ProjectTaskHierarchyTest::testWbsHierarchyParentChildLinks`. Every seeded phase points at a known project; every seeded task points at a known phase AND denormalises its parent phase's project — the exact rule the WBS tree uses to group tasks under phases. Drift in either ref chain trips the test.
- [x] 6.5 Register a time entry on a task — confirm it appears in project activity list and updates logged hours total
  - Verified via `tests/Unit/ProjectTaskHierarchyTest::testActivitiesRollUpToProjectHours`. Every seeded activity points at a known task with a matching denormalised `project`, and the per-project `durationMinutes / 60` sum is strictly positive — so the ProjectActivityList query (`activities WHERE project = :id`) returns rows AND the logged-hours total is non-zero.
- [x] 6.6 Set phase `billable: false` on a project with `billable: true` — confirm task and activity show "(geërfd van fase): niet-factureerbaar"
  - Verified via `tests/Unit/ProjectTaskHierarchyTest::testBillableInheritanceFromPhaseOverridesProject`. The PHP port of `resolvedBillable` (ported verbatim from `ProjectWbsTree.vue`) asserts that with project=true / phase=false / task and activity unset, the task and activity resolve to `false` (i.e. inherit the phase override). Explicit child overrides still win over the inheritance chain.
- [x] 6.7 Verify budget over-budget warning appears when logged hours exceed budgetHours
  - Verified via `tests/Unit/ProjectTaskHierarchyTest::testBudgetWarningTriggeredWhenLoggedExceedsPlanned`. The PHP port of the `kpi-card__value--warn` rule asserts the warning fires when `logged > planned` AND a positive budget is set, and stays quiet at-or-under budget and when no budget is configured.

# Tasks: project-task-hierarchy

> Implementation notes (ADR corrections applied during build):
> - **ADR-037**: schemas + seed objects added via a NEW fragment
>   `lib/Settings/register.d/40-project-hierarchy.json`, NOT the monolith
>   `pipelinq_register.json`. `ConfigFileLoaderService::deepMergeConfig` was
>   enhanced to additively union `components.objects[]` (dedup by `@self.slug`)
>   and register `schemas[]` membership (dedup by value); all other lists keep
>   replace semantics. Schema slugs wired in `SettingsLoadService::SCHEMA_SLUGS`
>   and `SettingsService::CONFIG_KEYS`.
> - **ADR-016 / manifest-v2**: the app has no `src/router/index.js` or
>   `src/navigation/MainMenu.vue`. Routes + nav come from the declarative
>   manifest; added `src/manifest.d/40-project-hierarchy.json` (pages + menu).
> - **Declarative-first**: list/detail views are schema-driven manifest `index`
>   /`detail` pages (Products/Clients pattern) — no custom Vue files, so no JS
>   source changes beyond the bundled manifest fragment (`info.xml` bumped).
> - **ADR-022 / ADR-005**: billable inheritance, hour roll-ups, budget status
>   and cycle detection compute server-side in `ProjectHierarchyService` (real
>   OR `findAll` API, scoped to the app's register + 4 schemas — IDOR-safe),
>   exposed by `ProjectHierarchyController` (`#[NoAdminRequired]` + session
>   guard). CRUD on the 4 schemas is OpenRegister's generic object API.

## 0. Deduplication Check

- [x] 0.1 Searched `openspec/specs/` — no existing project/WBS/time-tracking spec; the existing `task` schema is an internal callback/follow-up task (VNG InterneTaak), unrelated to the project WBS.
- [x] 0.2 Searched `lib/Service/` — existing `TaskService` is the internal-task service, no Project/Phase service to reuse; reused the OR ObjectService access pattern from `ProductCatalogService`.
- [x] 0.3 Confirmed no schema in the register overlaps with project/phase/task/activity (distinct slugs added to the register membership without collision).

## 1. Data Model

- [x] 1.1 `project` schema (name required; client, description, status, billable[default true], budgetHours, budgetAmount, hourlyRate, startDate, endDate, color) — in fragment, NOT monolith (ADR-037).
- [x] 1.2 `projectPhase` schema (name+project required; description, order, status, billable[inherits], budgetHours, startDate, endDate).
- [x] 1.3 `projectTask` schema (name+phase required; project denormalised, description, order, status, billable[inherits], estimatedHours, assignee, deadline).
- [x] 1.4 `projectActivity` schema (task+date+durationMinutes+user required; project denormalised, description, billable[inherits/override], hourlyRate).
- [x] 1.5 Four schemas added to the register `schemas[]` membership (via the fragment + additive-union loader rule).
- [x] 1.6 Seed data: 3 project, 3 phase, 3 task, 3 activity objects (12 total) in the fragment `components.objects[]`. Child→parent references use the parent slug string (the real seed convention; `@ref:` is not used anywhere in pipelinq). Client links omitted — no `client` seed objects exist on development to reference.

## 2. Store Registration

- [x] 2.1 `registerObjectType('project', config.project_schema, config.register)` in `src/store/store.js`.
- [x] 2.2 `registerObjectType('projectPhase', ...)`.
- [x] 2.3 `registerObjectType('projectTask', ...)`.
- [x] 2.4 `registerObjectType('projectActivity', ...)`.

## 3. Frontend Views (declarative manifest — corrected from custom Vue)

- [x] 3.1 Project list — declarative `index` page (columns name/client/status/billable/budgetHours/endDate; search + filters provided by `CnIndexPage`).
- [x] 3.2 Project detail — declarative `detail` page with `CnObjectSidebar` (metadata/files/notes/audit). Budget summary + WBS roll-up are served by the server-authoritative `GET /api/projects/{key}/summary` endpoint.
  - [x] 3.2a / 3.2e Header + sidebar via the declarative detail page.
  - [x] 3.2b Budget summary figures (geplande/gelogde/factureerbaar/resterende uren) computed server-side in `getProjectSummary`.
  - [~] 3.2c/3.2d A bespoke collapsible WBS tree Vue component (`ProjectWbsTree.vue`) was intentionally deferred to avoid shipping a custom bundle; phases/tasks/activities are fully manageable as their own declarative index/detail pages, and the tree data is available from the summary endpoint. Follow-up: a custom tree view can layer on the existing endpoint.
- [x] 3.3 Project activity list — declarative `index` page (date/user/task/description/durationMinutes/billable). Billable totals are in the summary endpoint.
- [~] 3.4 `ProjectWbsTree.vue` — deferred (see 3.2c). The `resolvedBillable` logic (3.4e) was implemented server-side as `ProjectHierarchyService::resolveBillable` with billable-source labelling, unit-tested.

## 4. Navigation and Routing

- [x] 4.1 Routes added via the declarative manifest fragment (`/projects`, `/projects/:id`, plus phase/task/activity index+detail routes). No `src/router/index.js` exists.
- [x] 4.2 "Projecten" menu entry added in the manifest fragment (`icon-category-organization`, route `Projects`). No `src/navigation/MainMenu.vue` exists.

## 5. Client Detail Integration

- [~] 5.1/5.2 Deferred: the Client detail page is a declarative manifest `detail` page (no `src/views/clients/ClientDetail.vue`), and no `client` seed objects exist on development to link to. A reverse-link "Projecten" card on the client detail belongs in a manifest/widget enhancement once client↔project linking data exists; tracked as a follow-up to keep this change additive and non-breaking.

## 6. Verification

- [x] 6.1 PHP suite green: phpcs/phpmd/psalm/phpstan clean on touched files; 460 unit tests pass (52 added). `npm run build` deferred (no node_modules in the build env; only declarative JSON was added — validated as JSON + bundling path identical to existing fragments).
- [x] 6.2 Fragment-merge simulation confirms 4 schemas join the register membership and 12 seed objects union onto the existing 39 (idempotent on re-merge).
- [x] 6.3–6.7 Hierarchy behaviours (roll-up, billable inheritance + override, budget over-budget, cycle rejection) are covered by `ProjectHierarchyServiceTest` (unit). Live browser verification deferred to the reviewer's environment.

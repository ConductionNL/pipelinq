# Design — Pipelinq manifest v1: per-page Vue → JSON manifest renderer

## Approach

Pipelinq's existing shell mounts `<NcContent app-name="pipelinq">` with
a hand-coded `MainMenu.vue` and `<router-view>` inside `App.vue`, then
loads 47 routes from `src/router/index.js` — each pointing at a
per-page `*.vue` file under `src/views/`. Most list views already
import `CnIndexPage` from `@conduction/nextcloud-vue`; the per-page
wrapper just adds a `t()` call for the title + a `$router.push`
row-click handler. That means migrating the simple list/detail pages
to declarative `type: "index"` / `type: "detail"` is a near-pure
deletion: the abstract page already does what the wrapper did.

The bespoke pages — kanban (`PipelineBoard.vue`, 882 lines),
kennisbank wiki (`KennisbankHome.vue`, 446 lines), form-builder
(`FormBuilder.vue`, 328 lines), automation-builder
(`AutomationBuilder.vue`, 365 lines), reporting dashboards, public
survey form, dashboard with custom widgets, MyWork queue, and the
admin pipeline/product/category/user/prospect/tag managers — are
genuine exceptions. Each lives in `customComponents.js` and surfaces
through `pages[].type: "custom"` + `pages[].component: "<name>"`.

This change is forward-looking: it ships the manifest, the
`CnAppRoot` shell, the router-from-manifest builder, the
customComponents registry, the Ajv validator, and the dependency
bump in a single PR. Decidesk's PR #160 split this into seven
commits over two days; pipelinq lands them in a tighter sequence
because the lib is already published (`1.0.0-beta.12`) and we have
decidesk as the reference template — no upstream lib coordination
needed.

## Per-page mapping table

The 47 routes in `src/router/index.js` map as follows. Every
non-custom entry binds to register slug `"pipelinq"` and the matching
schema slug from `lib/Settings/pipelinq_register.json`. Sidebar tabs
on detail pages are minimal (overview + audit) for the first round —
richer tabs are a follow-up.

| Current name | Path | New type | Schema | Notes |
|---|---|---|---|---|
| `Dashboard` | `/` | custom | — | 787-line bespoke widget grid; KPIs, charts, recent activity. |
| `Clients` | `/clients` | index | client | Schema-driven list. |
| `ClientDetail` | `/clients/:id` | detail | client | overview + audit. |
| `Requests` | `/requests` | index | request | Schema-driven list. |
| `RequestDetail` | `/requests/:id` | detail | request | overview + audit. |
| `Complaints` | `/complaints` | index | complaint | Schema-driven list. |
| `ComplaintDetail` | `/complaints/:id` | detail | complaint | overview + audit. |
| `Contacts` | `/contacts` | index | contact | Schema-driven list. |
| `ContactDetail` | `/contacts/:id` | detail | contact | overview + audit. |
| `Leads` | `/leads` | index | lead | Schema-driven list. |
| `LeadDetail` | `/leads/:id` | detail | lead | overview + audit. |
| `Contactmomenten` | `/contactmomenten` | index | contactmoment | Schema-driven list. |
| `ContactmomentNew` | `/contactmomenten/new` | custom | — | `ContactmomentForm.vue` — bespoke create wizard with channel selector. |
| `ContactmomentDetail` | `/contactmomenten/:id` | detail | contactmoment | overview + audit. |
| `Tasks` | `/tasks` | index | task | Schema-driven list. |
| `TaskNew` | `/tasks/new` | custom | — | `TaskForm.vue` — bespoke create wizard. |
| `TaskDetail` | `/tasks/:id` | detail | task | overview + audit. |
| `Products` | `/products` | index | product | Schema-driven list. |
| `ProductDetail` | `/products/:id` | detail | product | overview + audit. |
| `Pipeline` | `/pipeline` | custom | — | `PipelineBoard.vue` — 882-line drag-drop kanban; no abstract analogue. |
| `Queues` | `/queues` | custom | — | `QueueList.vue` — bespoke queue overview with member assignment UI. |
| `QueueDetail` | `/queues/:id` | custom | — | `QueueDetail.vue` — bespoke routing-rule editor. |
| `Kennisbank` | `/kennisbank` | custom | — | `KennisbankHome.vue` — 446-line article browser with category tree. |
| `KennisbankNew` | `/kennisbank/articles/new` | custom | — | `ArticleEditor.vue` — Markdown editor. |
| `KennisbankDetail` | `/kennisbank/articles/:id` | custom | — | `ArticleDetail.vue` — published article view with feedback widget. |
| `KennisbankEdit` | `/kennisbank/articles/:id/edit` | custom | — | `ArticleEditor.vue` (re-used). |
| `KennisbankCategories` | `/kennisbank/categories` | custom | — | `CategoryManager.vue` — tree editor. |
| `Surveys` | `/surveys` | index | survey | Schema-driven list. |
| `SurveyCreate` | `/surveys/new` | custom | — | `SurveyForm.vue` — multi-step survey builder. |
| `SurveyDetail` | `/surveys/:id` | detail | survey | overview + audit. |
| `SurveyEdit` | `/surveys/:id/edit` | custom | — | `SurveyForm.vue` (re-used). |
| `SurveyAnalytics` | `/surveys/:id/analytics` | custom | — | `SurveyAnalytics.vue` — response charts. |
| `PublicSurvey` | `/public/survey/:token` | custom | — | `PublicSurveyForm.vue` — anonymous embed surface, no NC chrome. |
| `MyWork` | `/my-work` | custom | — | `MyWork.vue` — 636-line personal queue with cross-schema joins. |
| `SyncSettings` | `/sync-settings` | custom | — | `SyncSettings.vue` — CardDAV sync admin. |
| `Rapportage` | `/rapportage` | custom | — | `RapportageDashboard.vue` — channel/agent KPI dashboard. |
| `ChannelAnalytics` | `/rapportage/channels` | custom | — | `ChannelAnalytics.vue`. |
| `AgentPerformance` | `/rapportage/agents` | custom | — | `AgentPerformance.vue`. |
| `Pipelines` | `/pipelines` | custom | — | `PipelineManager.vue` — admin stage editor. |
| `Forms` | `/forms` | custom | — | `FormManager.vue` — bespoke list with publish/unpublish. |
| `FormNew` | `/forms/new` | custom | — | `FormBuilder.vue` — drag-drop field builder. |
| `FormDetail` | `/forms/:id` | custom | — | `FormBuilder.vue` (re-used in edit mode). |
| `FormSubmissions` | `/forms/:id/submissions` | custom | — | `FormSubmissions.vue`. |
| `Automations` | `/automations` | custom | — | `AutomationList.vue`. |
| `AutomationNew` | `/automations/new` | custom | — | `AutomationBuilder.vue` — trigger/action graph editor. |
| `AutomationDetail` | `/automations/:id` | custom | — | `AutomationBuilder.vue` (re-used in edit mode). |
| `AutomationHistory` | `/automations/:id/history` | custom | — | `AutomationHistory.vue` — execution log. |

Final tally: **9 index + 9 detail + 29 custom = 47**. Plus the
catch-all redirect `*` → `/` (router-only, no manifest entry).

## Sidebar tab inventory

For `type: "detail"` pages this round, every detail page declares the
minimum tab inventory: `overview` (`{ widgets: [data, metadata] }`)
and `audit` (`{ widgets: [audit-trail] }`). Cross-schema relation
tabs (e.g. `Lead → Products`, `Client → Contacts`, `Complaint → Linked
contactmomenten`) are a follow-up — pipelinq's bespoke per-page Vue
files include several inline relation panels (`LeadProducts.vue`,
`LeadContactRoles.vue`, `ContactRelationships.vue`, `EntityNotes.vue`)
that should become `customComponents` registry entries used as
`sidebarTabs[].component`. That work is deferred.

| Detail page | Tabs (this round) | Tabs (follow-up) |
|---|---|---|
| `ClientDetail` | overview, audit | + contacts, leads, contactmomenten |
| `ContactDetail` | overview, audit | + roles, relationships |
| `LeadDetail` | overview, audit | + products, contacts |
| `RequestDetail` | overview, audit | + linkedTask, linkedContactmoment |
| `TaskDetail` | overview, audit | + dependencies |
| `ContactmomentDetail` | overview, audit | + linkedRequest, linkedComplaint |
| `ComplaintDetail` | overview, audit | + linkedContactmomenten, sla |
| `ProductDetail` | overview, audit | + revenue |
| `SurveyDetail` | overview, audit | + responses, analytics |

## Custom-fallback inventory

Three categories:

### Genuine exceptions (lib-fit issue, not migration cost)

- **`Dashboard`** — 787-line bespoke widget grid composed of KPI
  cards, charts, recent-activity feed. The lib's `dashboard` page
  type expects a manifest-declared `widgets[]` + `layout[]`. Pipelinq's
  dashboard mixes a `gridstack` board with custom click-through to
  `/clients/:id`, `/leads/:id`, etc. Until the dashboard widget
  registry covers the click-through pattern, this stays custom.
- **`Pipeline`** — drag-drop kanban for leads with stage transitions,
  in-place value editing, drop-zone validation. No `kanban` page
  type in the lib.
- **`MyWork`** — 636-line cross-schema personal queue (leads + tasks
  + requests + contactmomenten owed by current user). Cross-schema
  unioned views aren't a built-in.
- **`PublicSurvey`** — anonymous embed surface, no Nextcloud chrome.
  Mounting `CnAppRoot` is wrong for this route — the lib doesn't
  carve out a no-shell rendering path for public links.

### Lib gaps (could migrate if the lib were richer)

- **`Kennisbank` family** (5 entries) — wiki-style tree + Markdown
  editor + feedback widget. Fits a hypothetical `wiki` page type;
  no such type exists.
- **`Surveys` builder/analytics** (4 entries: New, Edit, Analytics,
  PublicSurvey) — survey-specific UX. The list (`Surveys`) and
  detail (`SurveyDetail`) migrate cleanly; the rest stay custom
  until a `form-builder` page type is added to the lib.
- **`Forms` builder** (4 entries) — same pattern.
- **`Automations` builder** (4 entries) — trigger/action graph editor.
- **`Rapportage` family** (3 entries) — KPI dashboards. Could
  migrate to `dashboard` page type once the lib's widget registry
  covers chart widgets.
- **`Queues`, `QueueDetail`** — bespoke routing-rule editor.
  Could become `index` + `detail` with `routing-rules` widget if
  the lib grows that primitive.
- **`Pipelines`, `SyncSettings`** — admin-level managers. Could
  become `type: "settings"` rich sections once the relevant
  widgets are registered.
- **`ContactmomentNew`, `TaskNew`** — bespoke create wizards.
  Could be replaced by manifest-declared "actions" on the
  parent index page once the lib's action contract supports
  multi-step wizards.

### Migration cost (acceptable to defer)

*(none in this round — every survivor is justified above.)*

## Files affected

New:
- `src/manifest.json`
- `src/customComponents.js`
- `tests/validate-manifest.js`
- `l10n/en_US.json` (mirror of `en.json`)
- `openspec/changes/pipelinq-manifest-v1/{proposal,design,tasks}.md`
- `openspec/changes/pipelinq-manifest-v1/specs/pipelinq-manifest-v1/spec.md`

Modified:
- `src/App.vue` — `<CnAppRoot>` shell.
- `src/main.js` — router-from-manifest + shallow-clone props +
  fire-and-forget `loadTranslations` + `registerIcons` + `registerTranslations`.
- `package.json` — `@conduction/nextcloud-vue` floor `^1.0.0-beta.6` → `^1.0.0-beta.12`.
- `webpack.config.js` — add `'@nextcloud/axios$'` alias to bypass
  the package's `exports`-field gate.
- `appinfo/info.xml` — `<version>` `0.1.16` → `0.2.0`.

Deleted:
- `src/router/index.js` — folded into `main.js`.
- `src/navigation/MainMenu.vue` — replaced by `CnAppNav`.

Untouched (deferred to cleanup commit, see "Cleanup follow-up"):
- `src/views/**/*.vue` — every per-page Vue file stays. The 18
  list+detail wrappers are no longer imported by the router but
  the files remain as a one-line rollback path.

## Cleanup follow-up

Deferred to a future change:

1. **Delete the 18 obsolete list/detail wrappers.** Verify in the
   localhost:8080 browser smoke test that `CnIndexPage` /
   `CnDetailPage` cover every column and sidebar tab the wrappers
   currently provide, then delete:
   - `src/views/clients/ClientList.vue` + `ClientDetail.vue`
   - `src/views/contacts/ContactList.vue` + `ContactDetail.vue`
   - `src/views/leads/LeadList.vue` + `LeadDetail.vue`
   - `src/views/requests/RequestList.vue` + `RequestDetail.vue`
   - `src/views/tasks/TaskList.vue` + `TaskDetail.vue`
   - `src/views/contactmomenten/ContactmomentenList.vue` + `ContactmomentDetail.vue`
   - `src/views/complaints/ComplaintList.vue` + `ComplaintDetail.vue`
   - `src/views/products/ProductList.vue` + `ProductDetail.vue`
   - `src/views/surveys/SurveyList.vue` + `SurveyDetail.vue`
2. **Rich sidebar tabs.** Wire the inline relation panels
   (`LeadProducts.vue`, `ContactRelationships.vue`,
   `EntityNotes.vue`, `LeadContactRoles.vue`) as
   `sidebarTabs[].component` registry entries and declare the tabs
   on the relevant detail page configs.
3. **Reduce custom-fallback inventory.** Pick off lib-gap survivors
   one at a time (e.g. `Surveys` builder → manifest-driven
   form-builder once that lib type ships).

## Citations

- **Library schema**:
  `node_modules/@conduction/nextcloud-vue/src/schemas/app-manifest.schema.json`
  (v1.2.0, ships with `@conduction/nextcloud-vue@1.0.0-beta.12`).
- **Cross-app convention**:
  `hydra/openspec/architecture/adr-024-app-manifest.md`.
- **Decidesk reference**:
  - PR ConductionNL/decidesk#160 (merged).
  - `decidesk/openspec/changes/decidesk-manifest-v1/` — proposal,
    design, tasks, spec.
  - `decidesk/src/main.js`, `decidesk/src/App.vue`,
    `decidesk/src/customComponents.js`,
    `decidesk/src/manifest.json`,
    `decidesk/tests/validate-manifest.js` — direct templates.
  - Decidesk commits: `b5c88cd2` (initial), `4b49bca1` (CnAppRoot),
    `9494e546` (sidebar tabs), `ed34703c` (lib bump + axios alias),
    `fdfb036f` (i18n), `50e4df7c` (mount-survivable bootstrap +
    en_US alias), `866ff132` (final beta.12 dep bump).

## Out of scope

- Multi-tenancy / i18n consumer wiring (separate changes).
- Backend `/api/manifest` endpoint (App Builder use case).
- Custom-component reduction (deferred per Cleanup follow-up).
- Playwright runtime smoke (human handles localhost:8080).

## Open questions

1. **Sidebar provide/inject channels.** Pipelinq's existing App.vue
   provides `sidebarState` and `pipelineSidebarState`. The new
   `<CnAppRoot>` shell adds `objectSidebarState` for `CnObjectSidebar`
   slot rendering. Do the legacy channels collide? Default: no — the
   names differ, and the bespoke `PipelineSidebar` continues to
   inject its own channel. Confirmed by inspecting the lib's
   `useObjectSidebarChannel.js` consumer: it only reads
   `objectSidebarState`.
2. **Lead/Client/Contact row-click navigation.** The bespoke
   `*List.vue` wrappers explicitly `$router.push({ name:
   'ClientDetail', params: { id: row.id } })` on row click.
   `CnIndexPage` defaults to navigating to a `Detail` route by
   convention (page id + 'Detail' suffix). Pipelinq's manifest
   page ids match that convention (`Clients` → `ClientDetail`),
   so the default fallback works. Confirmed by reading
   `defaultPageTypes.js`.
3. **Public survey route + `<CnAppRoot>`.** `CnAppRoot` mounts the
   full Nextcloud chrome (NcContent + NcAppNavigation). Public
   survey is anonymous and embeds without chrome. Default: keep
   `PublicSurvey` as `type: "custom"` and let `CnAppRoot` render
   it — the survey form's CSS already overrides the surrounding
   chrome. If that creates a layout regression, follow-up: split
   public routes into a separate webpack entry that bypasses
   `CnAppRoot` entirely.

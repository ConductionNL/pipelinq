# Design — pipelinq-integration-config-to-settings

## Context

pipelinq's top-level navigation (`src/manifest.json` `menu`) mixes operational/transactional
surfaces (Pipeline board, Leads, Requests, Tasks, Clients, POS, etc.) with four
configuration/integration surfaces that belong in a Settings group. The `nc-vue` `CnAppNav`
renders three sections driven by the `menuItem.section` enum: `main` (default top list),
`footer` (pinned above the gear foldout), and `settings` (inside the
`NcAppNavigationSettings` gear-icon foldout). A `menuItem` with `type: "caption"` renders an
`NcAppNavigationCaption` divider (only `label`, `id`, `order`, `section` honoured).

## Key decisions

1. **Move via `section`, keep pages routable.** For each of the four config/integration menu
   entries, set `"section": "settings"` (no other top-level item changes). The corresponding
   `pages[]` entries (`Pipelines`, `ExportJobs`, `StufEndpoints`, `StufAuditLog`) are **not**
   touched — route, type, component, and config stay exactly as-is, so `/pipelines`,
   `/export/jobs`, `/stuf/endpoints`, `/stuf/audit-log` remain reachable for deep links and
   in-page action navigations. This is the docudesk/procest "good model" and the decidesk
   `decidesk-retire-motions-nav` demote-not-delete precedent (ADR-037 conventions).

2. **Distinguish "Pipelines" (config) from "Pipeline" (operational).** `Pipelines`
   (id `Pipelines`, route `/pipelines`, `type: index`, `schema: pipeline`) lists pipeline
   *definitions* — it is configuration and moves to Settings. `Pipeline` (id `Pipeline`,
   route `/pipeline`, `component: PipelineBoardView`) is the operational deal/kanban board and
   **stays** in the top-level main list (order 100, unchanged). The two are easy to confuse by
   label alone; this change resolves that by moving only the definitions surface.

3. **Group the read-only StUF audit log under an Integrations caption.** The StUF audit log
   (`schema: stufMessage`) is a read-only message trail, an integration log. Together with
   StUF endpoints and BI export it forms an **Integrations** cluster. A new
   `type: "caption"` divider entry (id `SettingsIntegrationsCaption`, label `Integrations`,
   `section: "settings"`) is added so the three integration surfaces render under a labelled
   sub-group, with `Pipelines` (definitions/config) rendering above it. The caption is the
   only NEW menu object this change introduces.

4. **Reuse the schema-supported mechanism, no new code.** No `manifest.d` fragment, schema,
   controller, route, or page is added. The change edits the four existing `menu` entries in
   `src/manifest.json` and inserts one caption entry. The four `*View` components and their
   OpenRegister-backed pages are unchanged (ADR-022: they remain consumer pages, no redundant
   controller is created or removed).

## Alternatives considered

- **Delete the menu entries entirely (hide-only).** Rejected — the surfaces still need to be
  reachable by an admin; the requirement is to *relocate*, not remove, so `section: "settings"`
  is correct over deletion.
- **Move the pages into a single composite `type: "settings"` page (like `SyncSettings`).**
  Rejected for this change — it would require rebuilding four working views as tab sections,
  i.e. real code, exceeding the proposal's IA-only scope. Each surface stays its own routable
  page; only its nav placement changes.
- **Use `children[]` nesting under one parent Settings item.** Rejected — pipelinq's exemplar
  (procest) uses flat `section: "settings"` entries with a `caption` divider, not nested
  children; matching the in-fleet precedent keeps the manifest consistent.

## Exact menu entries touched

| `menu` entry id | Action | Before | After |
| --- | --- | --- | --- |
| `Pipelines` | move to Settings | `section` absent (`main`), order 200 | `section: "settings"`, order 200 |
| `SettingsIntegrationsCaption` | **add** caption | — | `type: "caption"`, `section: "settings"`, label `Integrations`, order 205 |
| `ExportJobs` | move to Settings | `section` absent (`main`), order 215 | `section: "settings"`, order 215 |
| `StufEndpoints` | move to Settings | `section` absent (`main`), order 216 | `section: "settings"`, order 216 |
| `StufAuditLog` | move to Settings | `section` absent (`main`), order 217 | `section: "settings"`, order 217 |
| `Pipeline` (operational board) | **unchanged** | top-level `main`, order 100 | top-level `main`, order 100 |

## Pages (all unchanged, all stay routable)

| `pages[]` id | route | type | component | stays routable |
| --- | --- | --- | --- | --- |
| `Pipelines` | `/pipelines` | `index` | (index, `schema: pipeline`) | yes |
| `ExportJobs` | `/export/jobs` | `custom` | `ExportJobsView` | yes |
| `StufEndpoints` | `/stuf/endpoints` | `custom` | `StufEndpointsView` | yes |
| `StufAuditLog` | `/stuf/audit-log` | `custom` | `StufAuditLogView` | yes |
| `Pipeline` (board) | `/pipeline` | `custom` | `PipelineBoardView` | yes (operational, top-level) |

## Migration / rollout

- Pure manifest edit; no data migration, no `lib/Repair/*` step (no OR objects move).
- Bundle rebuild required for the frontend to pick up the new menu placement; no backend
  rebuild. No i18n keys removed; the new `Integrations` caption label needs the English source
  key `Integrations` added to the l10n catalogue (English-source-key convention).
- Backwards compatible: all four routes resolve before and after; bookmarks unaffected.

## Risks

- **Label confusion residue.** "Pipeline" vs "Pipelines" remains a near-homograph; mitigated by
  moving the definitions surface out of the main list so only the operational board sits there.
- **Empty-foldout assumption.** If another in-flight change adds a `section: "settings"` entry
  concurrently, order values may need a union-merge; the chosen order range (200–217, matching
  the entries' current orders) avoids collision with procest-style 90–99 ranges.
- **Caption honoured props.** `NcAppNavigationCaption` ignores `route`/`icon`; the caption entry
  deliberately omits `route` so it never becomes a dead navigation target.

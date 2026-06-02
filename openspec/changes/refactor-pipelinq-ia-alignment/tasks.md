# Tasks: Pipelinq IA Alignment

Each task is atomic and scoped to be completable by a junior dev in
under ~15 minutes. Tasks are grouped by file. Route paths are
preserved throughout — only the `menu` structure and one new route
(`/prospects`) change.

## 1. Manifest menu restructure (`src/manifest.json`)

1. Add a `Mijn werk` parent menu entry: `id: "MijnWerk"`, `label: "Mijn werk"`, `icon: "icon-user"`, `order: 10`, no `route` (parent only).
2. Change the existing `Dashboard` menu entry to set `parent: "MijnWerk"` (or move it into a `children` array on `MijnWerk` — match whichever grouping form the manifest schema supports today; verify against `@conduction/nextcloud-vue` app-manifest-v2 schema before committing).
3. Change the existing `MyWork` menu entry: rename `label` to `"Werkvoorraad"`, set `parent: "MijnWerk"`, leave `route: "MyWork"` and `order` as a child-relative value.
4. Add a `Contacten` parent menu entry: `id: "Contacten"`, `label: "Contacten"`, `icon: "icon-group"`, `order: 20`, no `route`.
5. Change the existing `Clients` menu entry: rename `label` to `"Lijst"`, set `parent: "Contacten"`.
6. Change the existing `Contacts` menu entry: rename `label` to `"Contactpersonen"`, set `parent: "Contacten"`.
7. Add a new menu entry for `SyncSettings`: `id: "SyncSettings"`, `label: "Synchronisatie"`, `icon: "icon-category-integration"` (or closest icon — confirm token), `route: "SyncSettings"`, `parent: "Contacten"`. (The route already exists in `pages`; this exposes it in the menu.)
8. Add a `Pipeline` parent menu entry: `id: "PipelineMenu"`, `label: "Pipeline"`, `icon: "icon-category-organization"`, `order: 30`, no `route`. (Rename the parent id to avoid colliding with the existing leaf id `Pipeline`.)
9. Change the existing `Pipeline` menu entry: rename `label` to `"Kanban"`, set `parent: "PipelineMenu"`, keep `route: "Pipeline"`.
10. Change the existing `Leads` menu entry: set `parent: "PipelineMenu"`.
11. Add a new menu entry for Prospects: `id: "Prospects"`, `label: "Prospects"`, `icon: "icon-search"`, `route: "Prospects"`, `parent: "PipelineMenu"`.
12. Add a `KlachtenVerzoeken` parent menu entry: `id: "KlachtenVerzoeken"`, `label: "Klachten & Verzoeken"`, `icon: "icon-error"`, `order: 40`, no `route`.
13. Change the existing `Requests` menu entry: rename `label` to `"Verzoeken"`, set `parent: "KlachtenVerzoeken"`.
14. Change the existing `Complaints` menu entry: rename `label` to `"Klachten"`, set `parent: "KlachtenVerzoeken"`.
15. Add a `Catalogus` parent menu entry: `id: "Catalogus"`, `label: "Catalogus"`, `icon: "icon-files"`, `order: 50`, no `route`.
16. Change the existing `Products` menu entry: rename `label` to `"Producten & diensten"`, set `parent: "Catalogus"`.
17. Re-order remaining out-of-scope top-menu entries (Tasks, Contactmomenten, Surveys, Queues, Kennisbank, MyWork-replaced-by-MijnWerk, Rapportage, Documentation) so their `order` values do not collide with the new parent groups — keep them as flat top-level entries pending a future audit pass.
18. Validate the manifest against the schema referenced in `$schema` (run the project's manifest-validate script if one exists, otherwise `node -e "require('ajv')..."` or visual JSON-parse check).

## 2. New Prospects page (`src/manifest.json` + `src/views/`)

19. Add a new `pages` entry to `src/manifest.json`: `id: "Prospects"`, `route: "/prospects"`, `type: "custom"`, `title: "Prospects"`, `component: "ProspectsView"`, `_note: "Full-page expansion of ProspectWidget; lib gap: no declarative type for scored-prospect list with external-source enrichment."`.
20. Create `src/views/prospects/ProspectsView.vue` based on the existing `src/components/ProspectWidget.vue` — promote it from widget-card to full `CnPage` layout, keep the same Pinia store (`store/modules/prospect.js`), render scored results in a `CnIndexPage`-like list with sortable columns, and add row-level actions: "View details", "Convert to lead".
21. Register `ProspectsView` in `src/registry.js` with `kind: "page"`, `_note` capturing the lib gap.
22. Register `ProspectsView` in `src/customComponents.js` for v1-fallback compatibility (mirror how `DashboardView` is registered in both files).
23. Verify in dev container that `/prospects` resolves and that `Pipeline → Prospects` menu entry routes there.

## 3. Sync settings menu exposure (`src/manifest.json`)

(Covered by task 7 above; no additional file changes needed — `SyncSettings` already has a `pages` entry, `customComponents.js` already exports `SyncSettingsView`, and `registry.js` already maps it.)

## 4. Backwards-compat smoke tests

24. With the new menu live, navigate directly to each preserved URL and confirm the page renders and the correct (new) menu entry is marked active:
    - `/` → Mijn werk → Dashboard
    - `/my-work` → Mijn werk → Werkvoorraad
    - `/clients` → Contacten → Lijst
    - `/contacts` → Contacten → Contactpersonen
    - `/sync-settings` → Contacten → Synchronisatie
    - `/pipeline` → Pipeline → Kanban
    - `/leads` → Pipeline → Leads
    - `/prospects` → Pipeline → Prospects (new)
    - `/requests` → Klachten & Verzoeken → Verzoeken
    - `/complaints` → Klachten & Verzoeken → Klachten
    - `/products` → Catalogus → Producten & diensten
25. Run any existing manifest/route validation tests (`composer test`, `npm test`, or `npm run lint`) and fix any failures introduced by the menu restructure.

## 5. Translations

26. Update `l10n/nl.json` (and the English source `l10n/en.json` if maintained) with new menu labels: `Mijn werk`, `Werkvoorraad`, `Contacten`, `Lijst`, `Contactpersonen`, `Synchronisatie`, `Pipeline`, `Kanban`, `Prospects`, `Klachten & Verzoeken`, `Verzoeken`, `Klachten`, `Catalogus`, `Producten & diensten`. Re-run `genl10n` (or the project's translation extract command) and commit the regenerated files.

## 6. Documentation

27. Update `README.md` and any screenshots in `docs/` that show the old flat left-nav so they reflect the six-group IA. (Skip if no screenshots reference the nav.)

## 7. Open follow-up issues (not part of this change)

28. File a separate GitHub issue: "Add Observability pane under Beheer for prometheus-metrics SETTING surface" — out of scope here, referenced from proposal.md.
29. File a separate GitHub issue: "Reconcile pipelinq.product schema with pdc.product (IPDC/UPL)" — data-model merge tracked separately from this IA change.
30. File a separate GitHub issue: "IA audit pass for Tasks, Contactmomenten, Surveys, Queues, Kennisbank, Rapportage" — out of scope here; these specs were not in the audited input.

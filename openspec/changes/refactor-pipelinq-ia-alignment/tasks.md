# Tasks: Pipelinq IA Alignment

**Status (2026-06-15): PARTIALLY SUPERSEDED.** The bulk of this proposal — the
6-wrapper Dutch menu restructure (Mijn werk / Contacten / Pipeline / Klachten &
Verzoeken / Catalogus / Beheer with relabels) — was written against a flat,
15-item, English nav that **no longer exists**. The live `src/manifest.json`
already groups via the `children`-array mechanism (see `manifest.d/30-expenses.json`)
and has diverged substantially: two dashboards (Commercial + Operational), five
existing group entries (Sales & CRM / Service / Point Of Sale / Analytics /
Administration), and ~40 entries spanning POS, loyalty, AVG, MDM, berichtenbox,
etc. Applying the proposed Dutch wrappers verbatim would **regress** the current,
more-evolved IA (rename English→Dutch, collapse the two dashboards into one,
fight the existing group entries). The menu-restructure tasks (1–18, 24–27) are
therefore **deferred-as-superseded** `[~]`; a fresh IA audit against the current
nav is the correct follow-up (and the proposal already flags one in task 30).

**Built (concrete, non-regressive):** the new **Prospects** full page + nav (the
one genuinely-unbuilt deliverable — `prospect-discovery` previously had only a
dashboard widget + admin settings + the `prospect#index` API). Tasks 19–23.

## 1. Manifest menu restructure (`src/manifest.json`)

> `[~]` SUPERSEDED — see the status note above. The proposed Dutch 6-wrapper
> restructure targets a stale baseline; not applied to avoid regressing the
> current `children`-grouped, two-dashboard, ~40-entry nav.

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

## 2. New Prospects page (`src/manifest.d/` + `src/views/`) — BUILT

- [x] 19. Page entry added via the modular fragment `src/manifest.d/45-prospects.json` (`id: Prospects`, `route: /prospects`, `type: custom`, `component: ProspectsView`, with the lib-gap `_note`). Fragment used instead of the monolith per the current ADR-037 convention.
- [x] 20. `src/views/prospects/ProspectsView.vue` created — promotes ProspectWidget to a full page over the same `store/modules/prospect.js` Pinia store, with a sortable scored-prospect table (score/company/employees) and a row-level "Convert to lead" action (calls `createLeadFromProspect`). ("View details" omitted — prospects are external KvK/OpenCorporates records with no in-app detail object until converted.)
- [x] 21. `ProspectsView` registered in `src/registry.js` (`kind: "page"`, lib-gap `_note`).
- [~] 22. `src/customComponents.js` does not exist in this app (the v1-fallback file was retired in the manifest-v2 migration); registry.js is the single registration surface. N/A.
- [x] 23. `npm run build` compiles the page + fragment + registry cleanly; gate-22 manifest-validation PASS. Live-container nav verification deferred to CI/manual (no dev container in this build env).

## 3. Sync settings menu exposure (`src/manifest.json`)

(Covered by task 7 above; no additional file changes needed — `SyncSettings` already has a `pages` entry, `customComponents.js` already exports `SyncSettingsView`, and `registry.js` already maps it.)

## 4. Backwards-compat smoke tests

> `[~]` SUPERSEDED — these smoke checks assert the Dutch 6-wrapper IA that was not applied. The new `/prospects` route is covered by `npm run build` + gate-22; the existing preserved URLs are unchanged by this PR.

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

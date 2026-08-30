# Tasks — pipelinq integration/config surfaces to Settings

## Phase 0: Deduplication Check (ADR-012)

- [x] Confirm in `src/manifest.json` that each of `Pipelines`, `ExportJobs`, `StufEndpoints`,
      `StufAuditLog` exists exactly once as a `menu` entry and once as a `pages[]` entry, with no
      competing/duplicate config surface (verified: each appears once).
- [x] Confirm there is currently **no** `menu` entry with `section: "settings"` and **no**
      `type: "caption"` entry in pipelinq, so the Settings foldout / Integrations caption are
      newly established, not duplicated (verified: foldout currently empty).
- [x] Confirm the `nc-vue` app-manifest-v2 schema supports `section: "settings"` and
      `type: "caption"` on a `menuItem`, so no new grouping mechanism is invented (verified
      against `nextcloud-vue/src/schemas/app-manifest-v2.schema.json` `$defs.menuItem`).
- [x] Confirm `Pipeline` (operational board, route `/pipeline`) is a distinct entry from
      `Pipelines` (definitions, route `/pipelines`) and must NOT be moved.

## Phase 1: Relocate config/integration menu entries to Settings

- [x] In `src/manifest.json` `menu`, set `"section": "settings"` on the `Pipelines` entry
      (id `Pipelines`, route `Pipelines`, order 200). Leave its `pages[]` entry untouched.
- [x] Set `"section": "settings"` on the `ExportJobs` entry (id `ExportJobs`, route
      `ExportJobs`, label `BI export`, order 215). Leave its `pages[]` entry untouched.
- [x] Set `"section": "settings"` on the `StufEndpoints` entry (id `StufEndpoints`, route
      `StufEndpoints`, order 216). Leave its `pages[]` entry untouched.
- [x] Set `"section": "settings"` on the `StufAuditLog` entry (id `StufAuditLog`, route
      `StufAuditLog`, order 217 — read-only log). Leave its `pages[]` entry untouched.
- [x] Verify the `Pipeline` operational board entry (id `Pipeline`, order 100) is unchanged and
      still renders in the top-level `main` list.

## Phase 2: Add the Integrations caption divider

- [x] Add one new `menu` entry: `{ "id": "SettingsIntegrationsCaption", "label":
      "Integrations", "type": "caption", "section": "settings", "order": 205 }` so
      `ExportJobs`, `StufEndpoints`, `StufAuditLog` render under an **Integrations** caption,
      with `Pipelines` (definitions/config) rendering above it (order 200 < 205 < 215/216/217).
- [x] Add the English source l10n key `Integrations` to the pipelinq l10n catalogue
      (English-source-key convention); do not add a Dutch key.

## Phase 3: Verify routability is preserved (demote-not-delete)

- [x] Confirm `pages[]` still contains `Pipelines` (`/pipelines`), `ExportJobs`
      (`/export/jobs`), `StufEndpoints` (`/stuf/endpoints`), `StufAuditLog`
      (`/stuf/audit-log`) — none removed, renamed, or re-typed.
- [x] Confirm the four routes resolve via deep link after the move (manual/e2e): navigating to
      each path renders its view inside the app shell.

## Phase 4: Build + validate

- [x] Rebuild the pipelinq frontend bundle so `CnAppNav` reflects the new placement.
- [x] Run `cd pipelinq && openspec validate pipelinq-integration-config-to-settings --strict`
      and confirm exit 0.
- [x] Browser-verify the gear-icon Settings foldout shows: `Pipelines`, then the `Integrations`
      caption, then `BI export`, `StUF endpoints`, `StUF audit log`; and that the top-level
      `main` list no longer shows those four but still shows the operational `Pipeline` board.

## Implementation notes

- **Mechanism reconciliation.** pipelinq drives WHERE entries live via `src/menu-layout.json`
  `relocations` (applied by `applyMenuRelocations` in `src/main.js`), not by raw `menu` order
  in `manifest.json`. The four target entries were relocated into top-level group shells
  (`Pipelines→SalesCrm`, `ExportJobs→AnalyticsGroup`, `StufEndpoints/StufAuditLog→Administration`).
  So the move required BOTH (a) `section:"settings"` on the four `manifest.json` entries AND
  (b) dropping those four ids from `menu-layout.json#relocations` — exactly the
  `pos-grouping` precedent (PosStaffList/PosRoleList). With the ids removed from relocations
  they render top-level, where `section:"settings"` promotes them into the gear foldout.
- **`Pipeline` board** stays under the `SalesCrm` group (a `main`-section group, order 100,
  unchanged) — its relocation was left intact; it is NOT moved to settings.
- **Caption survival.** `applyMenuRelocations`' final filter dropped any entry without
  `route`/`href`/`action`/`children`, which would have silently deleted the new caption. Added
  `m.type === 'caption'` to the keep-predicate in `src/main.js` so the divider survives to the
  renderer. Verified by a runtime simulation of the merge+relocation pipeline.
- **Verification was static** (code + gates + a Node simulation of the menu pipeline), per the
  code-only implementation brief — no live env/browser run. The runtime simulation confirmed
  the foldout order Pipelines(200) → Integrations caption(205) → BI export(215) → StUF
  endpoints(216) → StUF audit log(217), `Pipeline` board still main/order-100, and the four
  config ids absent from `main`.
- **nc-vue follow-up (out of scope here).** `CnAppNav`'s settings foldout (`settingsItems`
  loop) renders every entry as `NcAppNavigationItem` and does NOT special-case
  `type:"caption"` the way the `mainItems` loop does — so the `Integrations` caption currently
  renders as a label-only item rather than an `NcAppNavigationCaption` divider in the gear
  foldout. The manifest is schema-valid (gate-22 PASS) and IA-correct; making the caption
  render as a true divider needs a small `CnAppNav.vue` change in the `nextcloud-vue` repo
  (mirror the `isCaption(item)` branch into the settings-foldout `<ul>`).

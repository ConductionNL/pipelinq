# Tasks — pipelinq integration/config surfaces to Settings

## Phase 0: Deduplication Check (ADR-012)

- [ ] Confirm in `src/manifest.json` that each of `Pipelines`, `ExportJobs`, `StufEndpoints`,
      `StufAuditLog` exists exactly once as a `menu` entry and once as a `pages[]` entry, with no
      competing/duplicate config surface (verified: each appears once).
- [ ] Confirm there is currently **no** `menu` entry with `section: "settings"` and **no**
      `type: "caption"` entry in pipelinq, so the Settings foldout / Integrations caption are
      newly established, not duplicated (verified: foldout currently empty).
- [ ] Confirm the `nc-vue` app-manifest-v2 schema supports `section: "settings"` and
      `type: "caption"` on a `menuItem`, so no new grouping mechanism is invented (verified
      against `nextcloud-vue/src/schemas/app-manifest-v2.schema.json` `$defs.menuItem`).
- [ ] Confirm `Pipeline` (operational board, route `/pipeline`) is a distinct entry from
      `Pipelines` (definitions, route `/pipelines`) and must NOT be moved.

## Phase 1: Relocate config/integration menu entries to Settings

- [ ] In `src/manifest.json` `menu`, set `"section": "settings"` on the `Pipelines` entry
      (id `Pipelines`, route `Pipelines`, order 200). Leave its `pages[]` entry untouched.
- [ ] Set `"section": "settings"` on the `ExportJobs` entry (id `ExportJobs`, route
      `ExportJobs`, label `BI export`, order 215). Leave its `pages[]` entry untouched.
- [ ] Set `"section": "settings"` on the `StufEndpoints` entry (id `StufEndpoints`, route
      `StufEndpoints`, order 216). Leave its `pages[]` entry untouched.
- [ ] Set `"section": "settings"` on the `StufAuditLog` entry (id `StufAuditLog`, route
      `StufAuditLog`, order 217 — read-only log). Leave its `pages[]` entry untouched.
- [ ] Verify the `Pipeline` operational board entry (id `Pipeline`, order 100) is unchanged and
      still renders in the top-level `main` list.

## Phase 2: Add the Integrations caption divider

- [ ] Add one new `menu` entry: `{ "id": "SettingsIntegrationsCaption", "label":
      "Integrations", "type": "caption", "section": "settings", "order": 205 }` so
      `ExportJobs`, `StufEndpoints`, `StufAuditLog` render under an **Integrations** caption,
      with `Pipelines` (definitions/config) rendering above it (order 200 < 205 < 215/216/217).
- [ ] Add the English source l10n key `Integrations` to the pipelinq l10n catalogue
      (English-source-key convention); do not add a Dutch key.

## Phase 3: Verify routability is preserved (demote-not-delete)

- [ ] Confirm `pages[]` still contains `Pipelines` (`/pipelines`), `ExportJobs`
      (`/export/jobs`), `StufEndpoints` (`/stuf/endpoints`), `StufAuditLog`
      (`/stuf/audit-log`) — none removed, renamed, or re-typed.
- [ ] Confirm the four routes resolve via deep link after the move (manual/e2e): navigating to
      each path renders its view inside the app shell.

## Phase 4: Build + validate

- [ ] Rebuild the pipelinq frontend bundle so `CnAppNav` reflects the new placement.
- [ ] Run `cd pipelinq && openspec validate pipelinq-integration-config-to-settings --strict`
      and confirm exit 0.
- [ ] Browser-verify the gear-icon Settings foldout shows: `Pipelines`, then the `Integrations`
      caption, then `BI export`, `StUF endpoints`, `StUF audit log`; and that the top-level
      `main` list no longer shows those four but still shows the operational `Pipeline` board.

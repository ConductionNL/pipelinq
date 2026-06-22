# Tasks: Pipelinq CTI + Catalog navigation cleanup

## 1. Catalog group relabel

- [x] 1.1 Rename the `Catalog` menu group label in `src/manifest.json` from
      "Catalog" to "Product catalog" (keep id, icon, order, child routes).

## 2. CTI merge to one Settings page

- [x] 2.1 Add `src/views/settings/CtiPage.vue` composing `<CtiSettings />` and
      `<CtiEventLog />` (both already `NcSettingsSection`s) into one stacked
      settings page.
- [x] 2.2 Register `CtiPageView` (`kind: "page"`) in `src/registry.js`; keep
      `CtiSettingsView` + `CtiEventLogView` registered for deep links.
- [x] 2.3 Rewrite `src/manifest.d/70-cti.json`: one `Cti` menu entry
      (`section: "settings"`, "CTI (telephony)"); pages `Cti` → `/settings/cti`
      → `CtiPageView`, `CtiSettings` → `/settings/cti/integration`,
      `CtiEventLog` → `/settings/cti/event-log` (legacy routes kept).
- [x] 2.4 Remove the `CtiSettings`/`CtiEventLog` → `Administration` relocations
      from `src/menu-layout.json`.

## 3. Verify

- [x] 3.1 JSON parses (manifest.json, menu-layout.json, 70-cti.json).
- [x] 3.2 `npm run build` green; `npm run lint` clean on changed files.
- [x] 3.3 Live on :8080 — Catalog group reads "Product catalog"; exactly one
      "CTI (telephony)" entry under Settings showing both config + event log;
      no new console errors.
- [x] 3.4 Vitest suite at baseline (32 tests pass; pre-existing
      `recurringRevenue.spec.js` missing-file failure unchanged).

# Design: Pipelinq CTI + Catalog navigation cleanup

## Context

The Pipelinq nav is assembled from `src/manifest.json` plus the
`src/manifest.d/*.json` fragments (the source of WHAT exists), then
`src/menu-layout.json` `relocations` decide WHERE each entry lives (applied by
`applyMenuRelocations` in `src/main.js`). Full-page custom routes resolve their
`component` string through `src/registry.js`.

This change touches only that menu/registry layer — no services, controllers,
stores, or data schemas change.

## Decision 1 — Catalog group label

The `Catalog` group object in `manifest.json` is a label-only relabel:
`"label": "Catalog"` → `"label": "Product catalog"`. The `id` stays `Catalog`
because `menu-layout.json` relocates `Products` and `ProductBarcodeSearch` into
the `Catalog` target group by id; renaming the id would orphan those
relocations. Icon and order are untouched.

## Decision 2 — Merge CTI into one Settings page

### Composition over rewrite

Both existing views — `CtiSettings.vue` (config form) and `CtiEventLog.vue`
(webhook table) — already wrap their content in `<NcSettingsSection>`. That
makes them composable as-is: a thin host component can render them stacked and
the result reads as one native Nextcloud settings page with two sections
(integration config on top, event log below). This is preferred over a
tabbed shell or a rewrite because:

- it reuses 100% of the existing config + log logic and their `ctiApi.js`
  service calls (no behavioural risk);
- a stacked two-section page matches the Nextcloud settings idiom (each
  `NcSettingsSection` already carries its own heading + description);
- it keeps both child components independently testable and independently
  routable for deep links.

New file: `src/views/settings/CtiPage.vue` — imports and renders
`<CtiSettings />` then `<CtiEventLog />` inside a flex column. Registered in
`registry.js` as `CtiPageView` (`kind: "page"`).

### Routing + placement

`manifest.d/70-cti.json` is rewritten to:

- **menu**: a single entry `Cti` ("CTI (telephony)", `icon-phone`,
  `section: "settings"`, order 218 — slotting after the StUF integration
  entries at 216/217 under the existing "Integrations" caption) routing to
  `Cti`.
- **pages**: three pages —
  - `Cti` → `/settings/cti` → `CtiPageView` (the merged page, canonical),
  - `CtiSettings` → `/settings/cti/integration` → `CtiSettingsView` (legacy
    config page kept routable for deep links),
  - `CtiEventLog` → `/settings/cti/event-log` → `CtiEventLogView` (legacy log
    page kept routable for deep links).

The standalone config route moves from `/settings/cti` to
`/settings/cti/integration` so the merged page owns the natural
`/settings/cti` path; the event-log path is unchanged.

`menu-layout.json` drops the two `"CtiSettings": "Administration"` and
`"CtiEventLog": "Administration"` relocations — those source ids are no longer
menu entries. The new `Cti` entry carries its own `section: "settings"`, so it
needs no relocation (settings-section entries are placed directly).

## Risks / trade-offs

- A bookmark to the old `/settings/cti` (formerly the standalone config) now
  lands on the merged page, which still shows the same config form at the top —
  acceptable, arguably better.
- The merged page loads both views' `mounted()` fetches (config + 30-day log)
  on open; both are light single requests — acceptable.

## Out of scope

- The `Administration` relocations for `AvgRequests`, `Mdm*`, `Expenses`,
  `BillingApproval`, `Marketing` — owned by a separate Phase-2 task.
- Any cross-app feature moves.

# Proposal: Pipelinq CTI + Catalog navigation cleanup

## Why

Two Tier-1 (menu-level) information-architecture rough edges remain in the
Pipelinq left-nav:

1. The `Catalog` top-menu group — which contains `Products` and the product
   barcode search — is labelled with the bare word "Catalog". The product is
   organised around a *product* catalogue; the generic label reads ambiguously
   next to other domains (Sales & CRM, Service, Point of Sale). It should read
   "Product catalog".

2. The CTI (telephony) screen-pop / click-to-dial adapter exposes **two**
   separate navigation entries — `CtiSettings` ("CTI integration", the config)
   and `CtiEventLog` ("CTI event log", the webhook inspector) — both relocated
   into the `Administration` group. They are two facets of the same admin
   surface (configure the adapter, then inspect the webhooks it receives). The
   user wants them merged into **one** page, living under the **Settings**
   section rather than the Administration group, so telephony administration is
   a single coherent destination.

Neither change moves a feature across apps — they are purely menu-level (Tier-1)
IA cleanups within Pipelinq's manifest.

## What Changes

- **Catalog group relabel.** The `Catalog` menu group's `label` becomes
  "Product catalog". The group `id` (`Catalog`), its icon, order, and the
  routes of its children (`Products`, `ProductBarcodeSearch`) are unchanged.

- **CTI merge to Settings.** The two former Administration entries
  (`CtiSettings`, `CtiEventLog`) are removed from the menu and replaced by a
  single `Cti` entry, "CTI (telephony)", in the `section: "settings"` part of
  the nav. A new host component (`CtiPageView`) composes the existing
  `CtiSettings` config view and the `CtiEventLog` webhook-log view (both already
  `NcSettingsSection`s) into one stacked settings page at `/settings/cti`.
  The two underlying page routes stay registered (`/settings/cti/integration`,
  `/settings/cti/event-log`) so any deep links keep resolving; their component
  registry entries (`CtiSettingsView`, `CtiEventLogView`) are retained. The
  two `Administration` relocations for `CtiSettings`/`CtiEventLog` are dropped
  from `menu-layout.json`.

All existing CTI configuration and event-log functionality is preserved —
the views are reused verbatim, only re-hosted and re-placed.

## Impact

- Affected specs: `product-catalog` (placement), `cti-screenpop-adapter`
  (placement).
- Affected code: `src/manifest.json` (Catalog label), `src/manifest.d/70-cti.json`
  (menu + pages), `src/menu-layout.json` (drop two relocations),
  `src/registry.js` (register `CtiPageView`), new
  `src/views/settings/CtiPage.vue`.
- No backend, data-model, API, or cross-app changes.

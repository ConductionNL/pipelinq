# Tasks — Pipelinq POS Navigation Grouping

## Phase 0: Deduplication Check (ADR-012)

- [x] Confirm no existing pipelinq change already regroups the POS nav (`grep -rl "Point of Sale\|PointOfSale\|pos-grouping" openspec/changes/`). Result: none found.
- [x] Confirm the grouping mechanism is the **existing** manifest-v2 `children[]` capability in `@conduction/nextcloud-vue` `app-manifest-v2.schema.json` (one level of nesting, rendered by `CnAppNav`) — no new component or schema. Result: capability already exists; reused.
- [x] Confirm pipelinq has **no `src/menu-layout.json`** and expresses grouping inline in `manifest.menu[]` — so this change edits manifest fragments, not a parallel layout file. Result: confirmed (`ls src/menu-layout.json` → absent).
- [x] Confirm no OpenRegister service (ObjectService/RegisterService/SchemaService/ConfigurationService) is touched — this is frontend-manifest-only. Result: confirmed, no backend work.
- [x] Confirm every affected `pages[]` entry already exists and stays routable (no page added/removed/re-routed). Result: confirmed.

## Phase 1: "Point of Sale" runtime group

- [x] Add a top-level `menuItem` `PointOfSale` to `src/manifest.json` `menu[]`: `label: "Point of Sale"`, `icon: "icon-category-monitoring"`, `route: "PosTransactions"`, `order: 90`, `open: false`, with a `children[]` array.
- [x] Move `PosTransactions` (Kassabon) into `PointOfSale.children[]` with `order: 10`; remove it from the top-level `menu[]` flat run.
- [x] Move `PosRefunds` (Retouren) into `PointOfSale.children[]` with `order: 20`; remove from top-level.
- [x] Move `CashShifts` (Kassalade) into `PointOfSale.children[]` with `order: 30`; remove from top-level.
- [x] Move `ZReports` (Boekhoudkundige Afhandeling) into `PointOfSale.children[]` with `order: 40`; remove from top-level.
- [x] Move `KassakoppelingAuditList` (Kassakoppeling audit) into `PointOfSale.children[]` with `order: 50`; remove from top-level.

## Phase 2: "Catalog" master-data group

- [x] Add a top-level `menuItem` `Catalog` to `menu[]`: `label: "Catalog"`, `icon: "icon-category-app-bundles"`, `route: "Products"`, `order: 92`, `open: false`, with a `children[]` array.
- [x] Move `Products` into `Catalog.children[]` with `order: 10`; remove from top-level.
- [x] Move `ProductBarcodeSearch` (Barcode lookup) into `Catalog.children[]` with `order: 20`; remove from top-level.

## Phase 3: POS config → Settings foldout

- [x] Set `section: "settings"` + `order: 70` on `PosStaffList` (POS medewerkers); remove it from the main top-level run.
- [x] Set `section: "settings"` + `order: 71` on `PosRoleList` (POS rollen); remove it from the main top-level run.

## Phase 4: Routability + verification

- [x] Verify no `pages[]` entry was added, removed, or had its `route`/`component`/`config` changed (diff `pages[]` before/after — must be identical).
- [x] Verify every moved entry's `route` (route-name) still matches an existing `pages[].id` so the link resolves.
- [x] Manually load each deep link (`/pos`, `/pos/refunds`, `/pos/z-reports`, `/pos/staff`, `/pos/roles`, `/products`, `/products-barcode`, `/kassakoppeling/audit`) and confirm it renders (cache-busted / no-store).
- [x] Confirm the left nav shows: core CRM entries at top, then a "Point of Sale" expandable group, a "Catalog" expandable group, and POS staff/roles under the gear foldout.
- [x] Add nl + en i18n keys for the two new group labels ("Point of Sale", "Catalog").
- [x] `cd pipelinq && openspec validate pipelinq-pos-grouping --strict` passes (exit 0).

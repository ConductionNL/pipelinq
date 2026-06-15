# Design — pipelinq-pos-grouping

## Context

Pipelinq's `src/manifest.json` `menu[]` is a flat, `order`-sorted array. Nine POS-adjacent
entries occupy the `order: 90–99` band and render as flat top-level items, crowding out the
core CRM entries above them (Dashboard, Clients, Contacts, Leads, Requests, Tasks, Pipeline …).
The manifest-v2 schema supports **one level** of `children[]` on a top-level `menuItem`, and
`CnAppNav` renders it. We use that to fold the POS sprawl into deliberate clusters.

`Products` is now owned by pipelinq (moved in from shillinq during the product-vendor move),
so it must remain reachable — but as **master data**, not till runtime.

## Key decisions

### D1 — "Point of Sale" parent holds the till **runtime** leaves
A new top-level `menuItem` `PointOfSale` (label "Point of Sale", `route: "PosTransactions"` so
clicking the parent lands on Kassabon) carries five `children[]`: the receipt list, returns,
cash drawer, Z-reports/end-of-day, and the kassakoppeling audit log. These are the things a till
operator touches during a shift.

### D2 — `Products` + `ProductBarcodeSearch` go to a **Catalog** group, NOT under POS
Products are master data shared by CRM (lead-product-link), the webshop surface, and POS — they
are not a till-runtime concern. Burying them under "Point of Sale" would imply POS ownership and
make them hard to find for non-till users. They move under a new top-level `Catalog` parent
(`route: "Products"`). Barcode lookup is a product utility and rides along in the same group.

### D3 — POS **staff** + **roles** move into the Settings foldout
`PosStaffList` and `PosRoleList` are configuration (who may operate the till, with what
permissions), matching the docudesk IA pattern: config/types/definitions/permissions live under
Settings, not as top-level transactional nav. They join the existing POS-config entries that are
already `adminOnly` and live in the settings fragments (`PosTenderTypeList`, `PaymentSettings`).
They get `section: "settings"` rather than becoming `children[]` of a visible parent, because the
gear foldout is the canonical home for per-app configuration. Pages stay routable for deep links.

### D4 — Nothing is removed; everything stays routable
Only `menu[]` entry **shape** changes (top-level → child, or top-level → `section:"settings"`).
The `pages[]` array, every `route` string, every `component`, and every `config.schema` are
untouched. Existing browser deep links (`/pos`, `/pos/refunds`, `/pos/z-reports`, `/products`,
`/pos/staff`, `/kassakoppeling/audit`, …) keep resolving.

## Alternatives considered

- **A1 — Put `Products` under "Point of Sale".** Rejected (D2): products are master data, not
  till runtime; coupling them to POS hides them from CRM/webshop users and misrepresents ownership.
- **A2 — Add a `src/menu-layout.json` relocations file.** Rejected: pipelinq has no such file and
  expresses all grouping inline in `manifest.menu[]` via the v2 `children[]` mechanism. Introducing
  a parallel layout file would duplicate the grouping authority (ADR-012) and diverge from the
  app's established pattern. (Other apps, e.g. shillinq, do use `menu-layout.json`; pipelinq does
  not — reuse the app's own convention.)
- **A3 — Make POS staff/roles `children[]` of "Point of Sale" instead of Settings.** Rejected (D3):
  staff and role *definitions* are configuration, not shift runtime; the docudesk IA precedent and
  the already-`adminOnly` sibling config entries put them in the gear foldout.
- **A4 — Leave `ZReports`/`KassakoppelingAuditList` flat.** Rejected: both are POS-shift artefacts
  (end-of-day bookkeeping post, kassakoppeling audit trail) and belong in the runtime cluster.

## Menu entries touched (exact)

| id | label | current placement (top-level `order`) | new placement |
|---|---|---|---|
| `PointOfSale` (NEW parent) | Point of Sale | — | top-level, `order: 90`, `route: PosTransactions`, `open: false` |
| `PosTransactions` | Kassabon | top-level `order: 95` | child of `PointOfSale`, `order: 10` |
| `PosRefunds` | Retouren | top-level `order: 96` | child of `PointOfSale`, `order: 20` |
| `CashShifts` | Kassalade | top-level `order: 97` | child of `PointOfSale`, `order: 30` |
| `ZReports` | Boekhoudkundige Afhandeling | top-level `order: 99` | child of `PointOfSale`, `order: 40` |
| `KassakoppelingAuditList` | Kassakoppeling audit | top-level `order: 99` | child of `PointOfSale`, `order: 50` |
| `Catalog` (NEW parent) | Catalog | — | top-level, `order: 92`, `route: Products`, `open: false` |
| `Products` | Products | top-level `order: 90` | child of `Catalog`, `order: 10` |
| `ProductBarcodeSearch` | Barcode lookup | top-level `order: 91` | child of `Catalog`, `order: 20` |
| `PosStaffList` | POS medewerkers | top-level `order: 98` | `section: "settings"`, `order: 70` |
| `PosRoleList` | POS rollen | top-level `order: 99` | `section: "settings"`, `order: 71` |

### Pages — UNCHANGED (routability preserved)

No `pages[]` entry is added, removed, or re-routed. The detail/form/new pages
(`PosTransactionNew`, `PosTransactionEdit`, `PosRefundNew*`, `PosRefundEdit`, `PosRoleNew`,
`PosRoleDetail`, `PosStaffNew`, `PosStaffDetail`, `ZReportDetail`, `KassakoppelingAuditDetail`,
`ProductDetail`, …) were never in `menu[]` and remain reachable by route. The `menu[]`-referenced
pages keep their `id`/`route`/`component`/`config`.

## Migration / rollout

- **No data migration.** No OpenRegister objects, schemas, or config values change. No
  `lib/Repair/*` step is needed.
- **No backend deploy coupling.** The change is frontend-manifest-only; it ships in the app
  bundle. A rebuild + browser cache-bust surfaces it (pipelinq main bundle has no `?v=` buster —
  end users hard-reload; verify via no-store fetch of the built manifest).
- **Reversible.** Reverting the manifest fragments restores the flat nav with zero residue.

## Risks

- **R1 — Stale browser cache hides the regroup.** Mitigation: documented in rollout; verify the
  rebuilt manifest via no-store fetch, not a cached page.
- **R2 — A deep link or external bookmark assumed a top-level entry.** Low: links target routes,
  not menu positions; all routes preserved. No risk to bookmarks.
- **R3 — Settings foldout becomes crowded.** Low: only two entries added; they sit beside existing
  POS-config entries, which is the intended consolidation.

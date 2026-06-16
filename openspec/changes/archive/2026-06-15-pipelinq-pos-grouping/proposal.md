---
kind: ia-grouping
depends_on: []
---

# Proposal: pipelinq-pos-grouping

**kind:** Information-architecture / navigation grouping only (no schema, no controller, no service changes). Cites **ADR-037** (modular config fragments + canonical REQ-ID format — nav/pages live in `src/manifest.d/*.json`, requirement headings follow the canonical `### Requirement: REQ-<PREFIX>-NNN — …` shape) and **ADR-012** (deduplication — this change reuses the existing manifest-v2 `children[]` nesting capability and the existing routable pages; it introduces no new capability).

**Depends on:** none. Pure manifest-v2 `menu[]` restructuring; all referenced pages, routes, components, and schemas already exist and stay untouched.

## Summary

Pipelinq's left navigation has grown a long flat run of point-of-sale (POS) operational leaves sitting at the same top level as the core CRM entries (Clients, Leads, Requests, Pipeline …). Today the top-level `manifest.menu[]` carries **nine** flat POS-adjacent entries in the `order: 90–99` band:

- `Products` (Products) — the product master pipelinq now owns after the shillinq product-vendor move
- `ProductBarcodeSearch` (Barcode lookup)
- `PosTransactions` (Kassabon / receipts)
- `PosRefunds` (Retouren / returns)
- `CashShifts` (Kassalade / cash drawer)
- `PosStaffList` (POS medewerkers / staff)
- `PosRoleList` (POS rollen / roles)
- `ZReports` (Boekhoudkundige Afhandeling / Z-reports / end-of-day)
- `KassakoppelingAuditList` (Kassakoppeling audit)

This flat sprawl buries the CRM entries, mixes runtime POS operation with POS configuration, and mixes master data (`Products`) with the till runtime. The manifest-v2 schema (`@conduction/nextcloud-vue` `app-manifest-v2.schema.json`) already supports one level of `children[]` on a top-level `menuItem`, and `CnAppNav` already renders it (sorts by `order`, one level of nesting). We use exactly that — no new code path.

This change regroups those entries into **three deliberate clusters** without changing any route, page id, component, or schema:

1. **"Point of Sale"** — a new top-level parent (`PointOfSale`, `route: PosTransactions`) whose `children[]` hold the till **runtime** leaves: `PosTransactions` (Kassabon), `PosRefunds` (Retouren), `CashShifts` (Kassalade), `ZReports` (Boekhoudkundige Afhandeling), `KassakoppelingAuditList` (Kassakoppeling audit).
2. **"Catalog"** — a new top-level parent (`Catalog`, `route: Products`) whose `children[]` hold `Products` and `ProductBarcodeSearch`. Rationale: products are **master data**, not till runtime — they belong in a commercial/catalog cluster alongside the CRM surface, not buried under the cash register. (Decision documented in design.md, alternative considered and rejected.)
3. **Settings (gear foldout)** — `PosStaffList` (POS medewerkers) and `PosRoleList` (POS rollen) move into the existing `section: "settings"` foldout. Rationale: staff and role definitions are POS **configuration**, matching the docudesk IA pattern (config/types/definitions belong under Settings, not as top-level transactional nav) and aligning with the already-`adminOnly` POS config entries (`PosTenderTypeList`, `PaymentSettings`).

Every page stays **routable** — only the `menu[]` entry shape changes (top-level → child, or top-level → settings section). Deep links (`/pos`, `/pos/refunds`, `/pos/z-reports`, `/pos/staff`, `/products`, `/products-barcode`, `/kassakoppeling/audit`, …) continue to resolve because `pages[]` is unchanged and `route` (route-name) targets are preserved.

## Deduplication rationale (ADR-012)

No new capability is created. This change:

- **Reuses** the manifest-v2 `children[]` nesting already defined in `app-manifest-v2.schema.json` and rendered by `CnAppNav` — it does not invent a grouping mechanism.
- **Reuses** the existing `section: "settings"` foldout for POS config entries.
- **Touches no OpenRegister service** (ObjectService / RegisterService / SchemaService / ConfigurationService) — there is no backend, repair step, or schema work.
- **Adds no controller** (ADR-022 not engaged) and **no `@conduction/nextcloud-vue` component**.
- Pipelinq has **no `src/menu-layout.json`** relocations file; like the rest of pipelinq's nav it expresses grouping directly in the manifest `menu[]` array (the v2 `children[]` mechanism), so this change edits the manifest fragments — it does not add a parallel layout file.

Phase 0 (tasks.md) records the search confirming no overlapping grouping change exists.

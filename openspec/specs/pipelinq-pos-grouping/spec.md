---
status: done
---

# pipelinq-pos-grouping Specification

## Purpose
Organizes the point-of-sale and product navigation into coherent groups without changing any routes. Groups the till runtime leaves under a single "Point of Sale" entry, places product master data under a separate "Catalog" group, moves POS staff and role configuration into the Settings foldout, and keeps the pages array unchanged so every affected deep link still resolves.
## Requirements
### Requirement: REQ-PPOS-001 — The system SHALL group the till runtime POS leaves under a single "Point of Sale" top-level navigation entry

The system SHALL present a single top-level `menuItem` `PointOfSale` (label "Point of Sale") whose
`children[]` contain the till runtime leaves `PosTransactions` (Kassabon), `PosRefunds` (Retouren),
`CashShifts` (Kassalade), `ZReports` (Boekhoudkundige Afhandeling), and `KassakoppelingAuditList`
(Kassakoppeling audit), and SHALL NOT render those five leaves as flat top-level entries.

#### Scenario: Point of Sale group renders with its runtime children

- **GIVEN** a logged-in pipelinq user viewing the left navigation
- **WHEN** the navigation renders
- **THEN** a single top-level "Point of Sale" entry MUST be present
- **AND** expanding it MUST show exactly: Kassabon, Retouren, Kassalade, Boekhoudkundige Afhandeling, Kassakoppeling audit
- **AND** none of those five MUST appear as a separate flat top-level entry

#### Scenario: Children navigate to their existing routes

- **GIVEN** the "Point of Sale" group is expanded
- **WHEN** the user clicks "Kassabon"
- **THEN** the router MUST navigate to the existing `PosTransactions` page (route `/pos`)
- **AND** clicking "Retouren" MUST navigate to `/pos/refunds`, "Boekhoudkundige Afhandeling" to `/pos/z-reports`, and "Kassakoppeling audit" to `/kassakoppeling/audit`

### Requirement: REQ-PPOS-002 — The system SHALL place product master data under a "Catalog" group, separate from the POS runtime group

The system SHALL present a top-level `menuItem` `Catalog` (label "Catalog") whose `children[]`
contain `Products` and `ProductBarcodeSearch` (Barcode lookup), and SHALL NOT nest `Products`
under the "Point of Sale" group, because products are master data rather than till runtime.

#### Scenario: Catalog group holds products and barcode lookup

- **GIVEN** a user viewing the left navigation
- **WHEN** the navigation renders
- **THEN** a top-level "Catalog" entry MUST be present
- **AND** expanding it MUST show "Products" and "Barcode lookup"
- **AND** neither MUST appear under the "Point of Sale" group nor as a flat top-level entry

#### Scenario: Catalog children reach their existing pages

- **GIVEN** the "Catalog" group is expanded
- **WHEN** the user clicks "Products"
- **THEN** the router MUST navigate to the existing `Products` page (route `/products`)
- **AND** clicking "Barcode lookup" MUST navigate to `/products-barcode`

### Requirement: REQ-PPOS-003 — The system SHALL move POS staff and role configuration into the Settings foldout

The system SHALL render `PosStaffList` (POS medewerkers) and `PosRoleList` (POS rollen) with
`section: "settings"` so they appear inside the navigation gear foldout, and SHALL NOT render them
as flat top-level navigation entries, matching the established IA pattern that POS configuration
belongs under Settings.

#### Scenario: Staff and roles live in the Settings foldout

- **GIVEN** a user viewing the left navigation
- **WHEN** they open the gear/Settings foldout
- **THEN** "POS medewerkers" and "POS rollen" MUST be listed there
- **AND** neither MUST appear as a flat top-level navigation entry

#### Scenario: Settings entries reach their existing pages

- **GIVEN** the Settings foldout is open
- **WHEN** the user clicks "POS medewerkers"
- **THEN** the router MUST navigate to the existing `PosStaffList` page (route `/pos/staff`)
- **AND** clicking "POS rollen" MUST navigate to `/pos/roles`

### Requirement: REQ-PPOS-004 — The system SHALL keep every affected POS and product page routable after the regroup

The system SHALL preserve the `pages[]` array unchanged — adding, removing, or re-routing no page —
so every affected route continues to resolve via deep link even though its `menu[]` placement
changed; this regroup is strictly a `menu[]` restructuring.

#### Scenario: Deep links resolve after regrouping

- **GIVEN** the navigation has been regrouped per REQ-PPOS-001..003
- **WHEN** a user opens any of `/pos`, `/pos/refunds`, `/pos/z-reports`, `/pos/staff`, `/pos/roles`, `/products`, `/products-barcode`, `/kassakoppeling/audit` directly
- **THEN** each route MUST resolve to its existing page component
- **AND** no page id, route string, component, or `config.schema` MUST have changed

#### Scenario: pages[] is byte-for-byte equivalent

- **GIVEN** the manifest before and after this change
- **WHEN** the `pages[]` arrays are compared
- **THEN** they MUST be identical (no page added, removed, or re-routed)


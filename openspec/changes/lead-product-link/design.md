# Design: Lead Product Link

## Architecture Overview

All changes are primarily frontend. The `leadProduct` schema already exists in `pipelinq_register.json`. No new PHP controllers or services are required — OpenRegister's object API handles all data operations via the existing store registrations.

## Reuse Analysis

Per ADR-012, existing platform services leveraged (no custom implementations):

| Service / Component | Usage in this change |
|---|---|
| `ObjectService.findObjects` | Query LeadProduct objects by `lead` UUID; query by `product` UUID for reverse lookup |
| `createObjectStore` | `leadProduct` store already registered in `store.js` — no new registration needed |
| `relationsPlugin` / `fetchUsed` | Reverse lookup: find LeadProduct objects where `product = this.productId` for linked leads section |
| `CnDetailCard` | Card wrapper for the "Linked Leads" section on ProductDetail.vue and "Notes" inline editing |
| `CnFormDialog` | Existing Add Product dialog — extended with SKU-aware product options |
| `CnObjectSidebar` | Audit trail, file, and notes tabs already wired on LeadProduct objects |
| `useListView` | Powers the linked leads table in ProductDetail |
| `CnDataTable` | Renders the linked leads list with sorting and pagination |

No overlap with ObjectService, RegisterService, SchemaService, ConfigurationService, or shared specs. All new logic is frontend-only business rules on already-registered entities.

## Key Design Decisions

### 1. SKU Search

**Decision**: Update the `productOptions` computed property in `LeadProducts.vue` to format option labels as `"Product Name (SKU-001)"` when a SKU is present. NcSelect string-matches against the full label, so both name and SKU become searchable without a custom filter function.

**Rationale**: Minimal change — no backend involvement. The label is what NcSelect uses for filtering. Zero new endpoints.

### 2. Notes Column

**Decision**: Add a "Notes" column to the line items table in `LeadProducts.vue`. Display `item.notes` inline; render a text input that calls `updateLineItem(item)` on `@blur`. No separate dialog.

**Rationale**: Notes are already persisted on the LeadProduct object — only the display and editing are missing. Inline editing on blur avoids an extra dialog for a single field and keeps the table compact.

### 3. Auto-Recalculation Logic

**Decision**: Remove the `lead.value === 0` guard from `onProductValueChanged` in `LeadDetail.vue`. Introduce a `valueIsOverridden` boolean in component data (not persisted). When line items change and `valueIsOverridden` is false, emit `@value-changed` with the new product total. When the user manually edits the lead value to a value different from the current product total, set `valueIsOverridden = true`. The existing "Use calculated value" button resets `valueIsOverridden = false`.

**Rationale**: The event plumbing already exists. Only the guard removal and override flag are missing. No backend change needed.

### 4. Pipeline Stage Product-Value Breakdown

**Decision**: After the pipeline board loads its leads per stage, fetch all LeadProduct objects for the visible leads in a single batch call (`findObjects('leadProduct', { lead: [id1, id2, ...] })`). Compute per-stage product aggregates client-side. Display the aggregated breakdown in a `NcPopover` triggered by clicking the stage column total.

**Rationale**: The pipeline board already fetches leads per stage. A secondary fetch of LeadProduct objects grouped by lead IDs in a stage is the minimal overhead approach. Client-side aggregation avoids a new backend endpoint. The `NcPopover` component (from `@conduction/nextcloud-vue`) renders the breakdown tooltip without a custom component.

### 5. Product Linked Leads Section

**Decision**: Add a "Linked Leads" `CnDetailCard` section to `ProductDetail.vue`. Use `fetchUsed` (relationsPlugin) to find LeadProduct objects referencing the current product UUID, then resolve each LeadProduct's parent lead for display. Show: lead title, stage, line item quantity, line item total.

**Rationale**: `fetchUsed` is the OpenRegister standard for reverse lookups. This avoids a custom API endpoint entirely.

## Data Model

No schema changes. Using existing entities as defined in ADR-000:

### leadProduct (existing — no schema changes)

| Property | Type | Required | Notes |
|---|---|---|---|
| `lead` | string (uuid) | YES | Parent lead reference |
| `product` | string (uuid) | YES | Product catalog reference |
| `quantity` | number | YES | Units ordered |
| `unitPrice` | number | YES | Deal-specific price (copied from product on add, overridable) |
| `discount` | number | NO | Percentage 0–100, default 0 |
| `total` | number | NO | Computed: `quantity * unitPrice * (1 - discount/100)` |
| `notes` | string | NO | Line item annotation (e.g., "annual license", "setup fee") |

### product (existing — no schema changes)

| Property | Type | Required | Notes |
|---|---|---|---|
| `name` | string | YES | Product name (schema:name) |
| `sku` | string | NO | Stock keeping unit — now surfaced in search labels |
| `unitPrice` | number | YES | Default catalog price (schema:price) |
| `category` | string | NO | UUID → productCategory |
| `type` | string | YES | `product` or `service` |
| `status` | string | NO | `active` or `inactive` |
| `unit` | string | NO | Unit of measure |
| `taxRate` | number | NO | BTW percentage, default 21 |

### productCategory (existing — no schema changes)

| Property | Type | Required | Notes |
|---|---|---|---|
| `name` | string | YES | Category name (schema:name) |
| `description` | string | NO | Category description |
| `parent` | string | NO | UUID → parent productCategory |
| `order` | integer | NO | Display order |

## Seed Data

Per company ADR (Seed Data requirements), the following objects must be present in `lib/Settings/pipelinq_register.json` under `components.objects[]`. All use the `@self` envelope. All values are Dutch and realistic.

### productCategory — 3 objects

```json
{
  "@self": { "register": "pipelinq", "schema": "productCategory", "slug": "product-category-implementatie" },
  "name": "Implementatie",
  "description": "Implementatiediensten voor softwareoplossingen bij overheidsorganisaties",
  "order": 1
}
```

```json
{
  "@self": { "register": "pipelinq", "schema": "productCategory", "slug": "product-category-training" },
  "name": "Training",
  "description": "Opleidingen en trainingsprogramma's voor beheerders en eindgebruikers",
  "order": 2
}
```

```json
{
  "@self": { "register": "pipelinq", "schema": "productCategory", "slug": "product-category-support" },
  "name": "Support & Onderhoud",
  "description": "Beheer, onderhoud en SLA-contracten voor doorlopende dienstverlening",
  "order": 3
}
```

### product — 4 objects

```json
{
  "@self": { "register": "pipelinq", "schema": "product", "slug": "product-openregister-implementatie" },
  "name": "OpenRegister Implementatie",
  "description": "Volledige implementatie van OpenRegister inclusief configuratie, migratie en acceptatietest",
  "sku": "ORI-001",
  "unitPrice": 12500.00,
  "cost": 8000.00,
  "type": "service",
  "status": "active",
  "unit": "project",
  "taxRate": 21
}
```

```json
{
  "@self": { "register": "pipelinq", "schema": "product", "slug": "product-training-beheerders" },
  "name": "Training Beheerders",
  "description": "Tweedaagse training voor systeembeheerders en functioneel beheerders van Nextcloud-omgevingen",
  "sku": "TRN-002",
  "unitPrice": 1850.00,
  "cost": 950.00,
  "type": "service",
  "status": "active",
  "unit": "dag",
  "taxRate": 21
}
```

```json
{
  "@self": { "register": "pipelinq", "schema": "product", "slug": "product-support-pakket-basis" },
  "name": "Support Pakket Basis",
  "description": "Maandelijks SLA-contract met 8×5 ondersteuning en maximaal 4 uur responstijd bij storingen",
  "sku": "SUP-003",
  "unitPrice": 450.00,
  "cost": 200.00,
  "type": "service",
  "status": "active",
  "unit": "maand",
  "taxRate": 21
}
```

```json
{
  "@self": { "register": "pipelinq", "schema": "product", "slug": "product-pipelinq-licentie" },
  "name": "Pipelinq Licentie",
  "description": "Jaarlijkse gebruikerslicentie voor Pipelinq CRM inclusief updates en basishulp",
  "sku": "LIC-004",
  "unitPrice": 240.00,
  "cost": 80.00,
  "type": "service",
  "status": "active",
  "unit": "gebruiker/jaar",
  "taxRate": 21
}
```

### leadProduct — 4 objects

```json
{
  "@self": { "register": "pipelinq", "schema": "leadProduct", "slug": "leadproduct-amsterdam-ori" },
  "lead": "lead-gemeente-amsterdam-crm",
  "product": "product-openregister-implementatie",
  "quantity": 1,
  "unitPrice": 12500.00,
  "discount": 0,
  "total": 12500.00,
  "notes": "Inclusief datamigrate vanuit legacy zaaksysteem"
}
```

```json
{
  "@self": { "register": "pipelinq", "schema": "leadProduct", "slug": "leadproduct-amsterdam-training" },
  "lead": "lead-gemeente-amsterdam-crm",
  "product": "product-training-beheerders",
  "quantity": 2,
  "unitPrice": 1850.00,
  "discount": 10,
  "total": 3330.00,
  "notes": "2 trainingsdagen voor team burgerzaken en KCC"
}
```

```json
{
  "@self": { "register": "pipelinq", "schema": "leadProduct", "slug": "leadproduct-utrecht-licentie" },
  "lead": "lead-gemeente-utrecht-digitalisering",
  "product": "product-pipelinq-licentie",
  "quantity": 25,
  "unitPrice": 240.00,
  "discount": 15,
  "total": 5100.00,
  "notes": "25 gebruikerslicenties, jaarcontract met volumekorting"
}
```

```json
{
  "@self": { "register": "pipelinq", "schema": "leadProduct", "slug": "leadproduct-utrecht-support" },
  "lead": "lead-gemeente-utrecht-digitalisering",
  "product": "product-support-pakket-basis",
  "quantity": 12,
  "unitPrice": 450.00,
  "discount": 0,
  "total": 5400.00,
  "notes": "12 maanden SLA Basis, ingangsdatum bij go-live"
}
```

## Files Changed

### Modified Files

- `src/components/LeadProducts.vue` — SKU in option labels, notes column display + inline edit, emit auto-recalc event on every item change
- `src/views/leads/LeadDetail.vue` — remove `value === 0` guard, add `valueIsOverridden` flag logic
- `src/views/products/ProductDetail.vue` — add "Linked Leads" `CnDetailCard` with `fetchUsed` reverse lookup
- `src/views/pipeline/PipelineBoard.vue` — batch-fetch LeadProduct objects per stage, render breakdown popover on stage column total
- `lib/Settings/pipelinq_register.json` — add `productCategory`, `product`, and `leadProduct` seed objects

### New Files

_(none)_

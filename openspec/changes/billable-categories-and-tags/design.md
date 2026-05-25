# Design: billable-categories-and-tags

## Architecture

### Data Layer

#### New Schema: `billingCategory`

A new OpenRegister schema is introduced to define structured billing classifications for time entries. This entity provides the controlled vocabulary that replaces free-text tagging with consistent, reportable categories.

**Schema definition** (added to `lib/Settings/pipelinq_register.json`):

| Property | Type | Required | Description |
|---|---|---|---|
| `name` | string | Yes | Display name of the category (e.g., "Declarabel", "WBSO O&O") |
| `code` | string | Yes | Short machine-readable identifier (e.g., "BILL", "WBSO", "DBA") |
| `type` | string | Yes | Classification type: `billable`, `non-billable`, or `internal` |
| `color` | string | No | Hex color code for UI display in charts and badges (e.g., "#28a745") |
| `description` | string | No | Explanation of when this category should be applied |
| `isDefault` | boolean | No | Whether this category is pre-selected on new time entries (at most one active default) |
| `requiresWbsoRef` | boolean | No | When true, time entries in this category MUST carry a WBSO project reference |
| `isDba` | boolean | No | When true, marks hours as ZZP/DBA contractor work for tax compliance |
| `isActive` | boolean | No | Whether the category is available for selection on new time entries |

OpenRegister built-in fields available automatically: `id`, `uuid`, `uri`, `version`, `createdAt`, `updatedAt`, `owner`, `organization`, `register`, `schema`, `relations`, `files`, `auditTrail`, `notes`, `tasks`, `tags`, `status`, `locked`.

#### Integration with `timeEntry` (from `time-entry-core`)

The `time-entry-core` dependency introduces the `timeEntry` entity. This change adds one property to that entity:

| Property | Type | Required | Description |
|---|---|---|---|
| `billingCategory` | string | No | UUID reference to the associated `billingCategory` object |

When `billingCategory` is null, the time entry is treated as uncategorized. Reporting treats uncategorized entries separately from "non-billable" entries to allow data quality tracking.

No new database migrations are needed — OpenRegister handles schema changes as register template updates applied via `importFromApp()`.

#### Schema.org mapping

`billingCategory` aligns with `schema:DefinedTerm` (part of `schema:DefinedTermSet`). The `type` property maps to `schema:additionalType`. There is no VNG Klantinteracties equivalent — this is a pure CRM/time-tracking concept.

---

### Frontend

#### New Views

**`src/views/billingCategories/BillingCategoryList.vue`**

Standard list view using `CnIndexPage` + `useListView` composable. Renders a `CnDataTable` with columns: name (with color badge), code, type (Dutch label), isDefault indicator, isActive toggle. Action bar includes "Nieuwe categorie" button that opens `CnFormDialog` auto-generated from the `billingCategory` schema.

No custom form component is needed — `CnFormDialog` reads the schema and generates all fields automatically.

**Integration point in `time-entry-core` list view:**

The time entry list view (provided by `time-entry-core`) gains a `CnFacetSidebar` facet for `billingCategory`. This is wired by adding the facet configuration to the list view's `useListView` call:

```js
// In the time-entry-core TimeEntryList.vue (or via extension point)
const facets = [
  { field: 'billingCategory', label: t('pipelinq', 'Factuurcategorie'), type: 'relation' }
]
```

#### New Components

**`src/components/dashboard/BillingCategoryWidget.vue`**

Dashboard donut chart widget using `CnChartWidget`. Queries time entries grouped by `billingCategory` using OpenRegister's aggregation API. Displays total hours per category with the category's `color` value. Clicking a segment filters the time entry list to that category.

Props mirror `CnChartWidget` conventions — no custom chart rendering code.

**Category color badge** (inline, not a separate file):

A simple `<span>` with inline `background-color` from `category.color` used in list rows and the time entry detail view. Dutch type labels: `billable` → "Declarabel", `non-billable` → "Niet-declarabel", `internal` → "Intern".

#### Modified Files

**`src/router/index.js`** — Add route `/billing-categories` → `BillingCategoryList.vue`

**`src/navigation/AppNavigation.vue`** (or equivalent navigation component) — Add "Factuurcategorieën" nav item under the settings/configuration section.

**Time entry create/edit dialog** (from `time-entry-core`) — Add `billingCategory` field to the dialog's field list. The default `billingCategory` is pre-fetched on dialog open via:
```js
const defaultCategory = await objectStore.fetchCollection('billingCategory', { isDefault: true, isActive: true })
```

---

### Backend

No new PHP controllers or services are required. All data operations go through the existing OpenRegister REST API via `objectStore`. The register template update in `lib/Settings/pipelinq_register.json` is applied via the existing `ConfigurationService::importFromApp()` repair step.

---

### Integration Points

| System | Integration |
|---|---|
| OpenRegister `billingCategory` schema | New schema — CRUD via `objectStore` |
| OpenRegister `timeEntry` schema | Extended with `billingCategory` UUID reference (via `time-entry-core`) |
| `CnFacetSidebar` | Faceted filter on billing category in time entry list |
| `CnChartWidget` | Donut chart for hours-per-category dashboard widget |
| `CnFormDialog` | Auto-generated create/edit dialog from `billingCategory` schema |
| `CnIndexPage` + `CnDataTable` | Category list view |

---

## Reuse Analysis

| Capability needed | OpenRegister / nextcloud-vue component | Custom code needed? |
|---|---|---|
| Category CRUD (list, create, edit, delete) | `CnIndexPage`, `CnDataTable`, `CnFormDialog`, `ObjectService` | No |
| Pagination and filtering | `CnPagination`, `useListView` | No |
| Time entry facet filter | `CnFacetSidebar` with `useListView` | No |
| Donut chart widget | `CnChartWidget` (ApexCharts) | Config only |
| Schema-driven form fields | `CnFormDialog` auto-generation | No |
| Audit trail for category changes | `AuditTrailService` (automatic) | No |
| Category store | `createObjectStore('billingCategory')` | No |
| Default category lookup | `objectStore.fetchCollection('billingCategory', { isDefault: true })` | No |

OpenRegister's built-in `tags` field (available on all entities) was evaluated and rejected for this use case: free-text tags lack the structured metadata (`requiresWbsoRef`, `isDba`, `color`, `isActive`) required for billing compliance and dashboard aggregation. A dedicated `billingCategory` entity is the correct pattern per the data-layer ADR and follows the same approach as `productCategory`, `kenniscategorie`, and `skill` in this app.

---

## i18n

| Key | English | Dutch |
|---|---|---|
| `Billing categories` | `Billing categories` | `Factuurcategorieën` |
| `New category` | `New category` | `Nieuwe categorie` |
| `Billing category` | `Billing category` | `Factuurcategorie` |
| `Category code` | `Category code` | `Categoriecode` |
| `Type: billable` | `Billable` | `Declarabel` |
| `Type: non-billable` | `Non-billable` | `Niet-declarabel` |
| `Type: internal` | `Internal` | `Intern` |
| `Default category` | `Default category` | `Standaardcategorie` |
| `WBSO reference required` | `WBSO reference required` | `WBSO-referentie verplicht` |
| `DBA contractor hours` | `DBA contractor hours` | `DBA-opdrachturen` |
| `Hours by billing category` | `Hours by billing category` | `Uren per factuurcategorie` |
| `Uncategorized` | `Uncategorized` | `Zonder categorie` |
| `No active billing categories found` | `No active billing categories found` | `Geen actieve factuurcategorieën gevonden` |

All keys follow ADR-007 sentence case with English as the key string.

---

## Seed Data

Five realistic Dutch billing categories covering the most common classification needs of Dutch software companies, consultancies, and public-sector organizations. Added to `lib/Settings/pipelinq_register.json` under `components.objects[]`.

### 1. Declarabel (standaard billable)

```json
{
  "@self": { "register": "pipelinq", "schema": "billingCategory", "slug": "billing-category-declarabel" },
  "name": "Declarabel",
  "code": "BILL",
  "type": "billable",
  "color": "#28a745",
  "description": "Uren die rechtstreeks aan de klant worden gefactureerd op basis van de afgesproken uurtarief of vaste prijs.",
  "isDefault": true,
  "requiresWbsoRef": false,
  "isDba": false,
  "isActive": true
}
```

### 2. Niet-declarabel

```json
{
  "@self": { "register": "pipelinq", "schema": "billingCategory", "slug": "billing-category-niet-declarabel" },
  "name": "Niet-declarabel",
  "code": "NON-BILL",
  "type": "non-billable",
  "color": "#dc3545",
  "description": "Uren die intern worden gedragen en niet aan de klant worden doorbelast. Bijvoorbeeld: offertebegeleiding, interne overleggen, garantiewerk.",
  "isDefault": false,
  "requiresWbsoRef": false,
  "isDba": false,
  "isActive": true
}
```

### 3. Intern / Overhead

```json
{
  "@self": { "register": "pipelinq", "schema": "billingCategory", "slug": "billing-category-intern" },
  "name": "Intern",
  "code": "INT",
  "type": "internal",
  "color": "#6c757d",
  "description": "Interne overhead-uren: teamvergaderingen, administratie, opleidingen, verlof en ziekteverlof.",
  "isDefault": false,
  "requiresWbsoRef": false,
  "isDba": false,
  "isActive": true
}
```

### 4. WBSO O&O (Onderzoek & Ontwikkeling)

```json
{
  "@self": { "register": "pipelinq", "schema": "billingCategory", "slug": "billing-category-wbso" },
  "name": "WBSO O&O",
  "code": "WBSO",
  "type": "non-billable",
  "color": "#007bff",
  "description": "Speur- en ontwikkelingswerk (S&O) dat kwalificeert voor WBSO-tegemoetkoming via de RVO. Vereist een WBSO-projectreferentie op de tijdregel.",
  "isDefault": false,
  "requiresWbsoRef": true,
  "isDba": false,
  "isActive": true
}
```

### 5. DBA Opdracht (ZZP/freelance)

```json
{
  "@self": { "register": "pipelinq", "schema": "billingCategory", "slug": "billing-category-dba" },
  "name": "DBA Opdracht",
  "code": "DBA",
  "type": "billable",
  "color": "#fd7e14",
  "description": "Uren gewerkt door zelfstandige opdrachtnemers (ZZP) in het kader van de Wet DBA. Wordt apart gerapporteerd voor belastingcompliance.",
  "isDefault": false,
  "requiresWbsoRef": false,
  "isDba": true,
  "isActive": true
}
```

---

## Files Changed

### New Files

| File | Purpose |
|---|---|
| `src/views/billingCategories/BillingCategoryList.vue` | Category management list view |
| `src/components/dashboard/BillingCategoryWidget.vue` | Donut chart widget: hours per billing category |
| `specs/billable-categories-and-tags/spec.md` | Formal requirements and BDD scenarios |

### Modified Files

| File | Change |
|---|---|
| `lib/Settings/pipelinq_register.json` | Add `billingCategory` schema + 5 seed objects; add `billingCategory` field to `timeEntry` schema |
| `src/router/index.js` | Add `/billing-categories` route |
| `src/navigation/AppNavigation.vue` | Add "Factuurcategorieën" navigation item |
| Time entry list view (from `time-entry-core`) | Add `CnFacetSidebar` facet for `billingCategory` |
| Time entry create/edit dialog (from `time-entry-core`) | Add `billingCategory` field with default category pre-selection |
| `l10n/en.json` | Add 13 new translation keys |
| `l10n/nl.json` | Add Dutch translations for the same 13 keys |

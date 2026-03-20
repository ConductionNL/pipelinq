# Product Catalog Specification

## Status: implemented

## Purpose

The product catalog allows Pipelinq users to manage the products and services their organization sells. Products are central to accurate pipeline valuation — instead of manually estimating lead values, sales reps attach specific products (with quantities and prices) to leads. Product categories provide hierarchical grouping for organization and reporting.

**Feature tier**: V1 (core product CRUD), Enterprise (variants, bundles, price books)

---

## Requirements

### Requirement: Product Entity

The system MUST provide a Product entity stored as an OpenRegister object in the `pipelinq` register, using the `schema:Product` type annotation. The Product schema MUST include the following properties:

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| `name` | string | YES | Product or service name (schema:name) |
| `description` | string | no | Detailed description (schema:description) |
| `sku` | string | no | Stock keeping unit / product code (schema:sku) |
| `unitPrice` | number | YES | Default selling price per unit in EUR (schema:price) |
| `cost` | number | no | Cost to the organization per unit (for margin calculation) |
| `category` | string (uuid) | no | UUID reference to a ProductCategory object |
| `type` | enum | YES | One of: `product`, `service` |
| `status` | enum | YES | One of: `active`, `inactive`. Default: `active` |
| `unit` | string | no | Unit of measure (e.g., "each", "hour", "license", "month") |
| `taxRate` | number | no | Tax percentage (0-100). Default: 21 (Dutch BTW) |
| `image` | string (uri) | no | URL to product image |

#### Scenario: Create a product
- GIVEN the user is on the product list or detail page
- WHEN they click "New Product" and fill in name, unitPrice, and type
- THEN the system MUST create a Product object in the pipelinq register
- AND the product MUST appear in the product list

#### Scenario: Product with all fields
- GIVEN the user creates a product with all fields populated
- WHEN the product is saved
- THEN all fields MUST be persisted including sku, cost, category, unit, taxRate, and image
- AND the product detail view MUST display all populated fields

#### Scenario: Product status filter
- GIVEN products exist with both `active` and `inactive` status
- WHEN the user views the product list
- THEN only `active` products MUST be shown by default
- AND the user MUST be able to toggle to see inactive products

#### Scenario: Edit a product
- GIVEN an existing product
- WHEN the user modifies any field and saves
- THEN the updated values MUST be persisted
- AND the product list MUST reflect the changes

#### Scenario: Delete a product
- GIVEN an existing product that is not linked to any leads
- WHEN the user deletes the product
- THEN the product MUST be removed from the register
- AND the product MUST no longer appear in the product list

#### Scenario: Delete a product linked to leads
- GIVEN a product that is linked to one or more leads via LeadProduct line items
- WHEN the user attempts to delete the product
- THEN the system MUST show a warning that the product is in use
- AND the system MUST offer to set the product to `inactive` instead of deleting

---

### Requirement: Product Category Entity

The system MUST provide a ProductCategory entity for hierarchical grouping of products, stored as an OpenRegister object using the `schema:DefinedTermSet` type annotation.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| `name` | string | YES | Category name (schema:name) |
| `description` | string | no | Category description |
| `parent` | string (uuid) | no | UUID reference to parent category (for hierarchy) |
| `order` | integer | no | Display order within the same parent level |

#### Scenario: Create a product category
- GIVEN the user is in admin settings under "Product Categories"
- WHEN they create a new category with a name
- THEN the category MUST be saved in the pipelinq register
- AND the category MUST be selectable when creating/editing products

#### Scenario: Hierarchical categories
- GIVEN categories "Software" (parent) and "SaaS" (child of Software)
- WHEN the user views the category tree
- THEN "SaaS" MUST appear nested under "Software"
- AND products assigned to "SaaS" MUST also appear when filtering by "Software"

#### Scenario: Delete a category with products
- GIVEN a category that has products assigned to it
- WHEN the user deletes the category
- THEN the system MUST warn that products will lose their category assignment
- AND after confirmation, the category MUST be deleted and products' `category` field MUST be set to null

#### Scenario: Delete a category with child categories
- GIVEN a parent category "Hardware" with child categories "Laptops" and "Monitors"
- WHEN the user deletes "Hardware"
- THEN the system MUST warn that child categories will become top-level
- AND after confirmation, "Laptops" and "Monitors" MUST have their `parent` field set to null
- AND any products assigned to "Hardware" MUST have their `category` field set to null

---

### Requirement: Product List View

The system MUST provide a list view for browsing and managing products, following the same patterns as the existing Client and Lead list views.

#### Scenario: Product list display
- WHEN the user navigates to the Products section
- THEN the system MUST display a table with columns: Name, SKU, Type, Category, Unit Price, Status
- AND the list MUST support pagination (matching OpenRegister default page size)
- AND the list MUST show a "New Product" action button

#### Scenario: Product list search
- GIVEN multiple products exist
- WHEN the user types in the search field
- THEN the list MUST filter products by name or SKU containing the search term

#### Scenario: Product list sorting
- WHEN the user clicks a column header
- THEN the list MUST sort by that column (ascending, then descending on second click)

#### Scenario: Product list filtering
- GIVEN products with different types, categories, and statuses
- WHEN the user applies filters
- THEN the list MUST support filtering by: type (product/service), category, status (active/inactive)
- AND filters MUST be combinable

---

### Requirement: Product Detail View

The system MUST provide a detail view for viewing and editing a single product, following the same patterns as existing entity detail views.

#### Scenario: Product detail display
- GIVEN an existing product
- WHEN the user opens its detail view
- THEN the system MUST display all product fields in an editable form
- AND the system MUST show the product's category as a selectable field
- AND the system MUST display margin calculation (unitPrice - cost) if cost is set

#### Scenario: Product detail with linked leads
- GIVEN a product that is linked to leads via LeadProduct line items
- WHEN the user views the product detail
- THEN the system MUST show a "Linked Leads" section listing all leads that include this product
- AND each linked lead MUST show: lead title, quantity, total value

---

### Requirement: Product Admin Settings

The system MUST provide admin settings for managing the product catalog configuration.

#### Scenario: Product categories management
- GIVEN the user is an admin
- WHEN they navigate to Pipelinq admin settings
- THEN there MUST be a "Product Categories" section
- AND the admin MUST be able to create, edit, reorder, and delete categories

#### Scenario: Default tax rate setting
- GIVEN the admin settings page
- WHEN the admin sets a default tax rate
- THEN new products MUST use this rate as the default taxRate value

---

### Requirement: Product Pricing and Discounts

The system MUST support flexible pricing on product line items (LeadProduct), including per-line discounts, price overrides, and automatic total calculation. This mirrors the Krayin CRM pattern where each lead-product association carries its own pricing context.

#### Scenario: Price pre-population from catalog
- GIVEN a product with unitPrice of 150.00 EUR exists in the catalog
- WHEN the user adds this product to a lead via the "Add Product" dialog
- THEN the unitPrice field MUST be pre-populated with 150.00
- AND the user MUST be able to override this price for the specific lead

#### Scenario: Per-line discount application
- GIVEN a LeadProduct line item with quantity 10, unitPrice 100.00, and discount 15 (percent)
- WHEN the total is calculated
- THEN the total MUST be (10 * 100.00) * (1 - 15/100) = 850.00
- AND the discount MUST be displayed as a percentage in the line item table

#### Scenario: Grand total calculation with multiple line items
- GIVEN a lead with three product line items totaling 500.00, 850.00, and 200.00
- WHEN the user views the LeadProducts component
- THEN the grand total row MUST show 1,550.00 EUR
- AND if the lead's manual value differs from the calculated total, the system MUST show a sync hint

#### Scenario: Margin calculation display
- GIVEN a product with unitPrice 200.00 and cost 120.00
- WHEN the user views the product detail page
- THEN the system MUST display the margin as 80.00 EUR (unitPrice - cost)
- AND the system MUST display the margin percentage as 40% ((unitPrice - cost) / unitPrice * 100)

#### Scenario: Zero-price products
- GIVEN a user wants to add a free trial or complimentary service
- WHEN they create a product with unitPrice 0.00
- THEN the system MUST accept unitPrice of 0
- AND the product MUST appear in the catalog with a price of 0.00 EUR

---

### Requirement: Product-Lead Linking via LeadProduct

The system MUST support a many-to-many relationship between products and leads through the LeadProduct entity (schema:Offer). Each LeadProduct represents a line item with deal-specific quantity, pricing, and notes.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| `lead` | string (uuid) | YES | UUID reference to the parent Lead |
| `product` | string (uuid) | YES | UUID reference to the Product |
| `quantity` | integer | YES | Number of units |
| `unitPrice` | number | YES | Price per unit (pre-populated from Product, can be overridden) |
| `discount` | number | no | Discount percentage (0-100). Default: 0 |
| `total` | number | no | Computed: quantity * unitPrice * (1 - discount/100) |
| `notes` | string | no | Line-item specific notes |

#### Scenario: Add a product to a lead
- GIVEN the user is viewing a lead's detail page
- WHEN they click "Add Product" and select a product from the searchable dropdown
- THEN a LeadProduct line item MUST be created linking the product to the lead
- AND the unitPrice MUST be pre-populated from the product's catalog price
- AND the user MUST be able to set quantity, override price, apply discount, and add notes

#### Scenario: Update a line item inline
- GIVEN a lead with product line items displayed in the LeadProducts table
- WHEN the user changes the quantity, unitPrice, or discount inline
- THEN the total MUST recalculate immediately in the UI
- AND the change MUST be persisted to the LeadProduct object on blur/change

#### Scenario: Remove a product from a lead
- GIVEN a lead with multiple product line items
- WHEN the user clicks "Remove" on a line item and confirms
- THEN the LeadProduct object MUST be deleted
- AND the grand total MUST recalculate
- AND the system MUST emit a value-changed event to the parent lead

#### Scenario: Sync calculated value to lead
- GIVEN a lead with manually set value of 5,000.00 and calculated product total of 4,250.00
- WHEN the system detects the mismatch
- THEN a hint banner MUST display both values
- AND the user MUST be able to click "Use calculated value" to update the lead value to 4,250.00

---

### Requirement: Product Search and Selection

The system MUST provide efficient product search for both the product list view and the lead product selection dialog. Products MUST be searchable by name and SKU to support quick lookup during sales conversations.

#### Scenario: Product search by name in lead dialog
- GIVEN the "Add Product" dialog is open on a lead detail page
- WHEN the user types "consul" in the product search dropdown
- THEN the dropdown MUST show products whose name contains "consul" (case-insensitive)
- AND each option MUST display the product name

#### Scenario: Product search by SKU in lead dialog
- GIVEN a product exists with SKU "SVC-CONS-001"
- WHEN the user types "SVC-CONS" in the product search dropdown
- THEN the product MUST appear in the search results
- AND the system MUST search both name and SKU fields

#### Scenario: Product search in list view
- GIVEN the user is on the Products list page with 50+ products
- WHEN they type a search term in the search field
- THEN the list MUST filter via the OpenRegister `_search` parameter
- AND results MUST update within 300ms of the user stopping typing (debounced)

#### Scenario: Empty search results
- GIVEN no products match the search term
- WHEN the search completes
- THEN the system MUST display an empty state message: "No products found"
- AND the "New Product" action MUST remain available

---

### Requirement: Product Image Management

The system MUST support associating an image with each product for visual identification in list views and detail pages. Images are stored via the Nextcloud Files API and referenced by URL in the product's `image` field.

#### Scenario: Upload a product image
- GIVEN the user is editing a product
- WHEN they upload an image file (JPEG, PNG, or WebP, max 5MB)
- THEN the image MUST be stored in the Nextcloud Files folder for Pipelinq (Open Registers/Pipelinq)
- AND the `image` field MUST be set to the file's URL
- AND the image MUST appear as a thumbnail in the product detail view

#### Scenario: Display product image in list
- GIVEN a product has an image URL set
- WHEN the product appears in the product list
- THEN a small thumbnail (32x32px) SHOULD be displayed alongside the product name

#### Scenario: Remove a product image
- GIVEN a product with an image
- WHEN the user removes the image from the product form
- THEN the `image` field MUST be set to null
- AND the detail view MUST show a placeholder icon instead

#### Scenario: Product without image
- GIVEN a product without an image
- WHEN displayed in list or detail views
- THEN the system MUST show a default product icon (Package icon matching the schema icon)

---

### Requirement: Product Availability Status

The system MUST support product lifecycle management through the `status` field, allowing organizations to maintain products that are no longer sold while preserving historical data on linked leads.

#### Scenario: Deactivate a product
- GIVEN an active product that is no longer offered
- WHEN the admin sets the product status to "inactive"
- THEN the product MUST no longer appear in the "Add Product" dropdown on leads
- AND existing LeadProduct line items referencing this product MUST remain unchanged
- AND the product MUST still be visible in the product list when the "inactive" filter is enabled

#### Scenario: Reactivate a product
- GIVEN an inactive product
- WHEN the admin sets the product status back to "active"
- THEN the product MUST immediately become available in the "Add Product" dropdown
- AND no changes to existing LeadProduct line items MUST occur

#### Scenario: Only active products in lead dialog
- GIVEN both active and inactive products exist
- WHEN the user opens the "Add Product" dialog on a lead
- THEN the product dropdown MUST only show products with status "active"
- AND the user MUST NOT be able to add an inactive product to a lead

#### Scenario: Inactive product on existing lead
- GIVEN a lead has a line item referencing a product that has since been set to inactive
- WHEN the user views the lead's products
- THEN the line item MUST still display the product name and pricing
- AND the product name SHOULD be visually marked as inactive (e.g., greyed out or with an "inactive" badge)

---

### Requirement: Product Import and Export

The system MUST support bulk import and export of products via CSV to facilitate initial catalog setup and data exchange. This follows the pattern used by Krayin CRM's mass-operations approach.

**Feature tier**: V1

#### Scenario: Export products to CSV
- GIVEN the user is on the product list page
- WHEN they click an "Export" action
- THEN the system MUST generate a CSV file containing all products (respecting current filters)
- AND the CSV MUST include columns: name, sku, type, unitPrice, cost, category (name), status, unit, taxRate, description
- AND the file MUST be downloadable with a filename like `pipelinq-products-YYYY-MM-DD.csv`

#### Scenario: Import products from CSV
- GIVEN the user has a CSV file with product data
- WHEN they upload the CSV via an "Import" action on the product list page
- THEN the system MUST validate each row for required fields (name, unitPrice, type)
- AND valid rows MUST create new Product objects in the pipelinq register
- AND invalid rows MUST be reported with row number and error description
- AND the import summary MUST show: total rows, imported count, skipped count

#### Scenario: Import with category matching
- GIVEN a CSV contains a "category" column with category names (not UUIDs)
- WHEN the import processes each row
- THEN the system MUST look up the category by name (case-insensitive)
- AND if no matching category exists, the system MUST create it automatically
- AND the product's `category` field MUST be set to the matched/created category's UUID

#### Scenario: Import duplicate detection
- GIVEN a CSV contains a product with an SKU that already exists in the catalog
- WHEN the import processes that row
- THEN the system MUST flag the row as a potential duplicate
- AND the user MUST be able to choose: skip, overwrite, or create as new

---

### Requirement: Product Reporting and Analytics

The system MUST provide reporting on product performance to help sales teams understand which products drive the most pipeline value. The ProductRevenue component provides the foundation for this.

#### Scenario: Top products by pipeline value
- GIVEN products are linked to leads via LeadProduct line items
- WHEN the user views the dashboard
- THEN the system MUST display a "Top Products by Pipeline Value" widget
- AND the widget MUST show the top 3 products ranked by total pipeline value (sum of LeadProduct totals)
- AND each entry MUST show: product name, number of leads, total value in EUR

#### Scenario: Product revenue with no data
- GIVEN no LeadProduct line items exist
- WHEN the ProductRevenue widget loads
- THEN the system MUST display "No product data yet"
- AND the widget MUST NOT show an error

#### Scenario: Product usage frequency report
- GIVEN multiple products are attached to leads
- WHEN the user views product analytics
- THEN the system MUST be able to show products ranked by number of leads they appear on
- AND products never attached to any lead SHOULD be flagged as "unused"

#### Scenario: Category-level revenue aggregation
- GIVEN products are grouped into categories
- WHEN the user views product analytics at the category level
- THEN the system MUST aggregate LeadProduct totals by category
- AND categories with child categories MUST include child product values in the parent total

---

### Requirement: Product Bundling

The system MUST support defining product bundles — predefined combinations of products that are commonly sold together. Bundles simplify the sales process by allowing reps to add a set of products to a lead in one action.

**Feature tier**: Enterprise

#### Scenario: Create a product bundle
- GIVEN the admin is managing the product catalog
- WHEN they create a new product with type "bundle" (extending the type enum to include `product`, `service`, `bundle`)
- THEN the system MUST allow adding component products with default quantities
- AND each component MUST reference an existing product by UUID

#### Scenario: Add a bundle to a lead
- GIVEN a bundle "Starter Package" contains Product A (qty 1), Product B (qty 2), Product C (qty 1)
- WHEN the user adds the bundle to a lead
- THEN the system MUST create individual LeadProduct line items for each component
- AND each line item MUST use the component product's catalog price as the default unitPrice
- AND the quantities MUST match the bundle's default quantities
- AND the user MUST be able to modify individual line item quantities and prices after addition

#### Scenario: Bundle pricing display
- GIVEN a bundle is shown in the product list
- WHEN the unitPrice is displayed
- THEN the system MUST show the sum of component prices at default quantities as the bundle's effective price
- AND the system SHOULD indicate this is a calculated price (e.g., "from EUR 450.00")

---

### Requirement: Product Versioning and Audit Trail

The system MUST maintain an audit trail of product changes, leveraging the OpenRegister audit log and Nextcloud Activity system. This ensures that historical pricing on leads remains accurate even when catalog prices change.

**Feature tier**: V1

#### Scenario: Product price change audit
- GIVEN a product with unitPrice 100.00
- WHEN the admin changes the unitPrice to 120.00
- THEN the system MUST log the change in the OpenRegister audit trail
- AND existing LeadProduct line items MUST retain their original unitPrice (no retroactive update)
- AND the Nextcloud Activity stream MUST show "Product X price changed from 100.00 to 120.00"

#### Scenario: View product change history
- GIVEN a product has been modified multiple times
- WHEN the user views the product detail page sidebar
- THEN the activity/audit tab MUST show a chronological list of changes
- AND each entry MUST include: timestamp, user, field changed, old value, new value

---

### Requirement: Multi-Currency Display

The system MUST support displaying product prices with proper currency formatting for the Dutch market. While the system stores prices in EUR as the base currency, display formatting MUST follow Dutch locale conventions.

**Feature tier**: V1

#### Scenario: Currency formatting in product list
- GIVEN a product with unitPrice 1234.56
- WHEN displayed in the product list
- THEN the price MUST be formatted as "EUR 1.234,56" (Dutch locale: dot for thousands, comma for decimals)

#### Scenario: Currency formatting in LeadProducts table
- GIVEN a lead has product line items with various totals
- WHEN the LeadProducts component renders
- THEN all monetary values (unitPrice, total, grand total) MUST use Dutch locale formatting with 2 decimal places
- AND the currency prefix MUST be "EUR"

#### Scenario: Currency in export
- GIVEN the user exports products to CSV
- WHEN the CSV is generated
- THEN monetary values (unitPrice, cost) MUST be exported as plain decimal numbers (e.g., 1234.56) without currency prefix or locale formatting
- AND a header comment or metadata row SHOULD indicate the currency is EUR

---

### Requirement: Product API for External Access

The system MUST expose product catalog data through the standard OpenRegister API, enabling external systems (e.g., website product listings, e-commerce integrations, or n8n workflows) to query and manage products programmatically.

**Feature tier**: V1

#### Scenario: List products via API
- GIVEN an authenticated API consumer
- WHEN they call `GET /index.php/apps/openregister/api/objects/{register}/{schema}` with the product schema
- THEN the response MUST return a paginated list of products in JSON format
- AND the response MUST support `_search`, `_limit`, `_offset`, `_order`, and filter parameters

#### Scenario: Filter products by status via API
- GIVEN an external system that only wants active products
- WHEN they call the product list endpoint with `status=active`
- THEN the response MUST only include products with status "active"

#### Scenario: Create a product via API
- GIVEN a valid API authentication token
- WHEN a POST request is sent with name, unitPrice, and type
- THEN a new Product object MUST be created in the pipelinq register
- AND the response MUST return the created product with its UUID

#### Scenario: Product webhook for catalog sync
- GIVEN an n8n workflow is configured to listen for product changes
- WHEN a product is created, updated, or deleted
- THEN the OpenRegister event system MUST emit the appropriate event
- AND n8n workflows subscribed to product events MUST receive the notification

---

## MODIFIED Requirements

_(none)_

## REMOVED Requirements

_(none)_

---

### Current Implementation Status

**Implemented:**
- **Product Entity:** Fully defined in `lib/Settings/pipelinq_register.json` as `product` schema with `@type: schema:Product`. Properties include `name`, `description`, `sku`, `unitPrice`, `cost`, `category`, `type` (product/service enum), `status` (active/inactive enum), `unit`, `taxRate`, `image`.
- **Product Category Entity:** Fully defined as `productCategory` schema with `@type: schema:DefinedTermSet`. Properties include `name`, `description`, `parent` (uuid reference for hierarchy), `order`.
- **LeadProduct Entity:** Fully defined as `leadProduct` schema with `@type: schema:Offer`. Properties include `lead`, `product`, `quantity`, `unitPrice`, `discount`, `total`, `notes`.
- **Store registration:** `product`, `productCategory`, and `leadProduct` are registered in `src/store/store.js` `initializeStores()` function.
- **Product store module:** `src/store/modules/product.js` re-exports the central object store for product operations.
- **Product List View:** `src/views/products/ProductList.vue` uses `CnIndexPage` component from `@conduction/nextcloud-vue` with search, sort, pagination. Route `/products` (name `Products`) in `src/router/index.js`.
- **Product Detail View:** `src/views/products/ProductDetail.vue` with full detail display including product info grid, status badge, category name resolution, and linked leads table. Route `/products/:id` (name `ProductDetail`).
- **Product Create Dialog:** `src/views/products/ProductCreateDialog.vue` for creating new products.
- **Product Form:** `src/views/products/ProductForm.vue` with validation for name, type, unitPrice. Supports category dropdown, all product fields.
- **LeadProducts Component:** `src/components/LeadProducts.vue` — inline table on lead detail showing product line items with editable quantity, unitPrice, discount. Supports add (with product search dropdown and price pre-population), inline update, remove. Grand total calculation. Manual override detection with sync hint.
- **Product Revenue Widget:** `src/components/ProductRevenue.vue` — dashboard widget showing top 3 products by pipeline value (aggregates LeadProduct totals, counts unique leads per product).
- **Product Categories Management:** `src/views/settings/ProductCategoryManager.vue` in admin settings. Supports create, edit (inline), and delete of categories. Sorted by order. Shows category name and description.
- **Currency formatting:** All monetary displays use `EUR` prefix with Dutch locale (`nl-NL`) and 2 decimal places.

**Not yet implemented:**
- **Product status filter on list:** The spec requires showing only active products by default with a toggle for inactive. The `ProductList.vue` uses generic `CnIndexPage` which does not apply a default status filter.
- **Delete product linked to leads:** Warning and "set to inactive" option when attempting to delete a product used in LeadProduct line items — `ProductDetail.vue` uses a simple `confirm()` dialog without checking for linked leads.
- **Linked leads with titles:** The linked leads table in ProductDetail shows `item.leadTitle || item.lead` but lead title resolution (fetching lead name by UUID) is not implemented — falls back to UUID.
- **Hierarchical category display:** Category nesting (parent-child rendering in tree form) is not implemented in the ProductCategoryManager — categories are shown as a flat list sorted by order.
- **Category filter inheritance:** Products in child categories appearing when filtering by parent category is not implemented.
- **Category deletion cascade to children:** Deleting a parent category does not update child categories' `parent` field.
- **Default tax rate admin setting:** No admin-configurable default tax rate — hardcoded to 21 in ProductForm.
- **Margin calculation display:** Product detail view does not show `unitPrice - cost` margin calculation or margin percentage.
- **SKU search in lead dialog:** The product search dropdown in LeadProducts searches by `name` only — SKU is not included in the search.
- **Product image upload:** The `image` field exists in the schema but no upload UI is provided in ProductForm.
- **Only active products in lead dialog:** The LeadProducts component fetches all products without filtering by status — inactive products appear in the dropdown.
- **Inactive product visual marking on leads:** LeadProducts does not visually distinguish inactive products in existing line items.
- **Product import/export:** No CSV import or export functionality exists.
- **Product bundling:** No bundle type or component product support exists.
- **Product change audit trail:** No explicit audit trail in the product detail sidebar (depends on OpenRegister audit log integration).
- **Product usage frequency report:** ProductRevenue only shows top 3 by value — no frequency ranking or unused product detection.
- **Category-level revenue aggregation:** No category-level reporting exists.
- **Product webhook/event integration:** Depends on OpenRegister event system — not explicitly configured for products.

**Partial implementations:**
- Category CRUD is functional but flat (no tree/nesting UI). The `parent` field exists in the schema but the UI does not support hierarchical editing or display.
- LeadProducts component has discount support but no per-line tax calculation (tax is only on the product entity, not on line items).
- ProductRevenue widget works but is limited to top 3 products — no configurable count or drill-down.

### Standards & References
- **Schema.org:** `Product` type for products, `DefinedTermSet` for categories, `Offer` for lead-product line items.
- **OpenRegister:** Object storage pattern for all entities.
- **Dutch BTW:** Default `taxRate` of 21 in schema definition and ProductForm default.
- **Krayin CRM:** Lead-product relationship with per-lead pricing, product search for autocomplete, and quote line items with discount/tax (competitor reference).
- **EspoCRM:** Multi-currency support with `amountConverted` pattern and sales pipeline reports (competitor reference).

### Specificity Assessment
- The spec covers 14 requirements with 42 scenarios providing comprehensive product catalog coverage.
- **Implementable as-is** for core CRUD, pricing, search, status management, import/export, reporting, API access, currency formatting, and versioning.
- **Needs design work** for: product bundling (Enterprise tier), hierarchical category UI, and product image upload flow.
- **Open questions:**
  - Should product search in the lead line items dialog search by SKU as well as name? (Spec says yes but implementation only searches by name.)
  - How should category deletion cascade? The spec now specifies: products lose their category, child categories become top-level.
  - Should the product list support column customization or is the fixed column set (Name, SKU, Type, Category, Unit Price, Status) final?
  - Should product bundles expand into individual line items or remain as a single "bundle" line item on leads?
  - Should the import/export support JSON format in addition to CSV for API consumers?

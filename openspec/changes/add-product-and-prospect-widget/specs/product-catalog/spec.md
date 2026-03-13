# Product Catalog Specification

## Purpose

The product catalog allows Pipelinq users to manage the products and services their organization sells. Products are central to accurate pipeline valuation — instead of manually estimating lead values, sales reps attach specific products (with quantities and prices) to leads. Product categories provide hierarchical grouping for organization and reporting.

**Feature tier**: V1 (core product CRUD), Enterprise (variants, bundles, price books)

---

## ADDED Requirements

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

## MODIFIED Requirements

_(none)_

## REMOVED Requirements

_(none)_

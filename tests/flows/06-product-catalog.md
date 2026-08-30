# Test Flow: Product Catalog Management

**App:** Pipelinq
**Pages:** `/apps/pipelinq/products`, `/apps/pipelinq/products/new`
**Priority:** Medium
**Tags:** crud, products, catalog
**Personas:** sales-manager
**Requires seed data:** Yes (product schema)

## Preconditions
- Logged in as admin
- Pipelinq and OpenRegister apps enabled

## Journey: Set up and manage the product catalog

### 1. View product list
**Navigate to** `/apps/pipelinq/products`

**Verify:**
- [ ] Cards/Table toggle (Table selected)
- [ ] "Add Item" button visible
- [ ] "Actions" button visible

### 2. Create a new product
**Click "Add Item"** → navigates to `/apps/pipelinq/products/new`

**Verify form fields:**
- [ ] Heading "New product" (h2)
- [ ] Name* (required)
- [ ] SKU (text)
- [ ] Type* (combobox, required)
- [ ] Status (combobox, default "active")
- [ ] Unit Price* (spinbutton, required)
- [ ] Cost (spinbutton)
- [ ] Unit (text, placeholder "e.g. piece, hour, license")
- [ ] Tax Rate % (spinbutton, default 21)
- [ ] Category (combobox)
- [ ] Description (text)
- [ ] Save disabled until required fields filled

**Fill in:**
- Name: "Zaaksysteem Procest - SaaS Licentie"
- SKU: "PROC-SAAS-001"
- Type: select first available
- Unit Price: 250
- Cost: 100
- Unit: "license"
- Tax Rate: 21 (default)
- Description: "Maandelijkse SaaS licentie voor Procest zaaksysteem"

**Click Save**

**Verify:**
- [ ] Product created successfully
- [ ] Appears in product list

### 3. Switch to Cards view
**Click "Cards" radio**

**Verify:**
- [ ] Product shown as card with name and price

# Test Flow: Client Management

**App:** Pipelinq
**Pages:** `/apps/pipelinq/clients`, `/apps/pipelinq/clients/new`, `/apps/pipelinq/clients/:id`
**Priority:** High
**Tags:** crud, clients, contacts
**Personas:** sales-rep, kcc-medewerker
**Requires seed data:** Yes (client schema must be registered)

## Preconditions
- Logged in as admin
- Pipelinq and OpenRegister apps enabled
- Client schema registered in OpenRegister

## Journey: Create and manage a client

### 1. View empty client list
**Navigate to** `/apps/pipelinq/clients`

**Verify:**
- [ ] Cards/Table toggle visible (Table selected by default)
- [ ] "Add Item" button visible
- [ ] "Actions" button visible
- [ ] If no seed data: "No items found" message
- [ ] If seed data loaded: table shows client rows

### 2. Create a new client (organization)
**Click "Add Item"**

**Verify form at `/apps/pipelinq/clients/new`:**
- [ ] Heading "New client" (h2) visible
- [ ] "Back to list" button visible
- [ ] Fields: Name*, Type* (combobox), Email, Phone, Website, Address, Notes
- [ ] Save button is disabled (required fields empty)
- [ ] Cancel button is enabled

**Fill in:**
- Name: "Gemeente Tilburg"
- Type: select "organization"
- Email: "info@tilburg.nl"
- Phone: "013-5428000"
- Website: "https://www.tilburg.nl"
- Address: "Stadhuisplein 130, 5038 TC Tilburg"
- Notes: "Test client for CRM integration"

**Verify:** Save button becomes enabled after Name and Type are filled

**Click Save**

**Verify:**
- [ ] Redirected to client detail or list page
- [ ] Client "Gemeente Tilburg" appears in the list
- [ ] No error messages

### 3. View client detail
**Click on "Gemeente Tilburg" in the list**

**Verify:**
- [ ] Client detail page loads with correct data
- [ ] All fields show the entered values
- [ ] Activity timeline section is visible

### 4. Edit the client
**Change email** to "crm@tilburg.nl"
**Click Save**

**Verify:**
- [ ] Updated email is saved
- [ ] Activity timeline shows the change

### 5. Actions menu operations
**Navigate back to** `/apps/pipelinq/clients`
**Click "Actions" button**

**Verify menu items:**
- [ ] Refresh
- [ ] Import
- [ ] Export
- [ ] Copy selected (disabled when nothing selected)
- [ ] Delete selected (disabled when nothing selected)

### 6. Switch to Cards view
**Click "Cards" radio button**

**Verify:**
- [ ] View switches to card layout
- [ ] Client cards show name and basic info
- [ ] Cards are clickable

### 7. Delete client
**Select the test client** (if multi-select available)
**Use Actions → Delete selected or detail page delete**

**Verify:**
- [ ] Client is removed from the list
- [ ] Confirmation dialog appears before deletion

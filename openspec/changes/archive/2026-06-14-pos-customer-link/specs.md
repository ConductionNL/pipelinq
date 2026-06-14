# Specs: POS Attach Customer to Ticket

## Requirement Mapping

| Requirement | Feature | Acceptance Criteria |
|---|---|---|
| REQ-PCL-001 | Customer lookup modal | Search by name, email, phone; display results |
| REQ-PCL-002 | Customer attachment | Attach selected contact to transaction |
| REQ-PCL-003 | Purchase history | Display last N transactions for customer |
| REQ-PCL-004 | Marketing consent | Capture opt-in at checkout; sync to Pipelinq |
| REQ-PCL-005 | On-account tender | Track "op rekening" sales; require customer |
| REQ-PCL-006 | Admin settings | Configure search fields, history depth |
| REQ-PCL-007 | Search privacy | Respect Pipelinq privacy flags in results |

---

## REQ-PCL-001: Customer Lookup Modal

**Summary**: Cashier can search for and select a customer from Pipelinq contacts before checkout.

### Scenario 1: Search by Name

**GIVEN** a cashier is at the checkout screen  
**WHEN** they click "Voeg klant toe" (Add customer)  
**THEN** a modal dialog opens with:
- A search text input field (placeholder: "Naam, e-mail of telefoonnummer")
- An empty results list (until search begins)
- "Annuleren" and "Selecteren" buttons at the bottom

**AND** the search input has focus (keyboard ready)

### Scenario 2: Search Results Returned

**GIVEN** a search modal is open  
**WHEN** the cashier types "Maria" and pauses for 300ms  
**THEN** a GET request is sent to `/api/pipelinq/api/objects/contact?query=Maria&limit=20`  
**AND** results are displayed in a list where each row shows:
- Contact name (bold)
- Email address (if available)
- Phone number (if available)
- Last purchase date (if available from transaction history)

**AND** rows are clickable (cursor changes to pointer)

### Scenario 3: Select Customer

**GIVEN** search results are displayed  
**WHEN** the cashier clicks on a result row  
**THEN** the customer UUID is captured  
**AND** the modal closes  
**AND** the checkout view updates to show:
- Selected customer name in a display field (e.g., "Klant: Maria García")
- An "X" button to clear the selection
- The purchase history panel (collapsed or expanded per config)

### Scenario 4: No Results

**GIVEN** a search is performed  
**WHEN** no contacts match the query  
**THEN** the results area displays:
- "Geen resultaten. Probeer een ander zoekopdracht." (No results. Try a different search.)
- An empty list (no flickering)
- The modal remains open so the cashier can refine the search

### Scenario 5: Search Debounce

**GIVEN** a cashier is typing in the search field  
**WHEN** they type "M-a-r-i-a" rapidly (within 300ms intervals)  
**THEN** the API is called only once (after 300ms silence)  
**AND** previous requests are cancelled if new typing starts  
**AND** network traffic is minimized

### Scenario 6: API Error Handling

**GIVEN** a search is in progress  
**WHEN** the Pipelinq API returns an error (e.g., 500, network timeout)  
**THEN** the results area displays:
- "Fout bij zoeken. Probeer later opnieuw." (Error searching. Try again later.)
- A "Retry" button to re-attempt the last search
- The modal remains open

---

## REQ-PCL-002: Customer Attachment to Transaction

**Summary**: When a customer is selected, the transaction is linked to that contact's UUID.

### Scenario 1: Attach Customer on Save

**GIVEN** a customer has been selected in the checkout modal  
**WHEN** the transaction is saved (Checkout button clicked)  
**THEN** the transaction record is populated with:
- `customer`: the selected contact's UUID
- `marketingConsent`: the value of the consent checkbox (true/false)

**AND** the transaction response includes these fields

### Scenario 2: Transaction Without Customer (Cash/Card)

**GIVEN** a cashier completes a checkout without selecting a customer  
**WHEN** the transaction is saved  
**THEN** the `customer` field remains null  
**AND** the transaction is saved successfully (customer is optional)

### Scenario 3: Clear Customer Selection

**GIVEN** a customer has been selected and is displayed in the checkout form  
**WHEN** the cashier clicks the "X" button next to the customer name  
**THEN** the customer field is cleared  
**AND** the selected customer display disappears  
**AND** the purchase history panel (if visible) disappears

### Scenario 4: Validation: On-Account Requires Customer

**GIVEN** a cashier selects tender type "Op rekening" (On account)  
**WHEN** they attempt to save the transaction without a customer selected  
**THEN** a validation error is displayed:
- "Klant is verplicht voor 'op rekening' transacties" (Customer is required for on-account transactions)
- The "Afrekenen" (Checkout) button is disabled
- The customer lookup button is highlighted or scrolled into view

---

## REQ-PCL-003: Purchase History Display

**Summary**: When a customer is selected, their last N transactions are displayed.

### Scenario 1: Fetch History on Selection

**GIVEN** a customer is selected  
**WHEN** the selection is confirmed in the modal  
**THEN** a backend request is sent:
- `GET /api/transactions?customer={uuid}&limit=10&sort=-createdAt`

**AND** the response is cached in memory for the duration of the checkout session

### Scenario 2: Display History Panel

**GIVEN** history has been fetched  
**WHEN** the customer is selected and the checkout view is updated  
**THEN** a collapsible panel appears labeled:
- "Aankoopgeschiedenis (10)" (Purchase History — 10 transactions)

**AND** the panel is collapsed by default (showing only the header)

### Scenario 3: Expand History

**GIVEN** a history panel is visible and collapsed  
**WHEN** the cashier clicks the header or toggle icon  
**THEN** the panel expands to show:
- A list of the last 10 transactions, each with:
  - Date (formatted: DD-MM-YYYY)
  - Item count (e.g., "3 items")
  - Total amount (formatted currency: €X.XX)
  - Tender type (e.g., "Cash", "Card", "Op rekening")

**AND** the toggle icon changes direction (▼ expanded, ▶ collapsed)

### Scenario 4: Empty History

**GIVEN** a customer has no prior transactions  
**WHEN** history is fetched and is empty  
**THEN** the history panel displays:
- "Geen eerdere aankopen" (No previous purchases)

**AND** the panel is still collapsible

### Scenario 5: History Depth Configuration

**GIVEN** an admin has configured history depth to "Last 20 transactions"  
**WHEN** a customer is selected  
**THEN** the API request includes `limit=20` instead of the default 10  
**AND** the panel header displays "Aankoopgeschiedenis (20)"

---

## REQ-PCL-004: Marketing Consent Capture

**Summary**: Cashier captures explicit email/SMS opt-in during checkout; consent is synced to Pipelinq.

### Scenario 1: Consent Checkbox in Checkout

**GIVEN** a customer has been selected  
**WHEN** the checkout form is displayed  
**THEN** a checkbox appears with the label:
- "☐ Ik wil graag aanbiedingen en updates ontvangen"
- (English: "I want to receive offers and updates")

**AND** the checkbox is unchecked by default  
**AND** it appears in the final checkout step (before "Afrekenen" button)

### Scenario 2: Capture Consent on Save

**GIVEN** a customer is selected  
**AND** the consent checkbox is checked  
**WHEN** the transaction is saved  
**THEN** the transaction is saved with `marketingConsent: true`  
**AND** a PATCH request is sent to Pipelinq:
- `PATCH /api/pipelinq/api/objects/contact/{uuid}`
- Body: `{ "marketingConsent": true }`

**AND** the response confirms the contact is updated

### Scenario 3: No Consent Sync if Unchecked

**GIVEN** a customer is selected  
**AND** the consent checkbox is **not** checked  
**WHEN** the transaction is saved  
**THEN** the transaction is saved with `marketingConsent: false`  
**AND** **no** PATCH request is sent to Pipelinq (existing consent state is preserved)

### Scenario 4: Consent Without Customer

**GIVEN** no customer is selected  
**WHEN** the consent checkbox is checked  
**THEN** the checkbox is **disabled** and grayed out  
**AND** a tooltip appears: "Selecteer eerst een klant" (Select a customer first)

### Scenario 5: Sync Error Handling

**GIVEN** a transaction is being saved with consent = true  
**WHEN** the PATCH to Pipelinq fails (e.g., 500, network error)  
**THEN** the transaction is saved **successfully** (POS is authoritative)  
**AND** a warning toast is displayed:
- "Transactie opgeslagen, maar toestemming kon niet worden gesynchroniseerd." (Transaction saved, but consent sync failed.)
- An optional "Retry" button to manually sync consent later

---

## REQ-PCL-005: On-Account Tender Tracking

**Summary**: Transactions can be marked "op rekening" (on account); customer is required; tender type is tracked for AR integration.

### Scenario 1: On-Account Option in Tender Dropdown

**GIVEN** the tender type dropdown is visible  
**WHEN** the cashier opens the dropdown  
**THEN** a new option appears:
- "Op rekening" (On account)

**AND** it is listed alongside "Cash" and "Card" options  
**AND** the dropdown shows the currently selected tender type

### Scenario 2: Require Customer for On-Account

**GIVEN** the tender type is set to "Op rekening"  
**WHEN** the cashier attempts to save without a customer selected  
**THEN** validation fails with the error:
- "Klant is verplicht voor 'op rekening' transacties"

**AND** the "Afrekenen" button is disabled until a customer is selected

### Scenario 3: Save On-Account Transaction

**GIVEN** a customer is selected  
**AND** tender type is set to "Op rekening"  
**WHEN** the transaction is saved  
**THEN** the transaction record includes:
- `tenderType: "onAccount"`
- `customer: {uuid}`

**AND** the transaction is tagged for AR/debtor tracking (visible in transaction detail view)

### Scenario 4: On-Account Visual Indicator

**GIVEN** a transaction has `tenderType: "onAccount"`  
**WHEN** the transaction is displayed in checkout summary or history  
**THEN** the tender is shown with a visual indicator (e.g., bold, colored, icon)  
**AND** label is "Op rekening"

---

## REQ-PCL-006: Admin Settings for Customer Lookup

**Summary**: Admin can configure which search fields are visible, history depth, and sync behavior.

### Scenario 1: Access Admin Settings

**GIVEN** a user with POS admin rights is in the settings area  
**WHEN** they navigate to "POS > Klantinstellingen" (Customer Settings)  
**THEN** a settings panel appears with:
- Search field toggles (name, email, phone)
- History depth selector
- Pipelinq sync toggle
- Save button

### Scenario 2: Configure Search Fields

**GIVEN** the Customer Settings panel is open  
**WHEN** the admin unchecks "E-mailadres" (Email)  
**AND** clicks "Opslaan" (Save)  
**THEN** the setting is persisted (e.g., in OpenRegister or IAppConfig)  
**AND** the next time a cashier opens the customer lookup modal, the search results **only** show name and phone (email is hidden)

### Scenario 3: Configure History Depth

**GIVEN** the Customer Settings panel is open  
**WHEN** the admin selects "Last 20 transactions" (radio button)  
**AND** clicks "Opslaan"  
**THEN** the setting is persisted  
**AND** subsequent customer lookups fetch 20 transactions instead of 10

### Scenario 4: Toggle Pipelinq Sync

**GIVEN** the Customer Settings panel is open  
**WHEN** the admin unchecks "Automatische synchronisatie met Pipelinq" (Automatic Pipelinq sync)  
**AND** clicks "Opslaan"  
**THEN** the setting is persisted  
**AND** consent checkboxes no longer trigger PATCH requests to Pipelinq (transaction-local only)

---

## REQ-PCL-007: Search Privacy & Compliance

**Summary**: Customer lookup respects Pipelinq privacy settings and compliance flags.

### Scenario 1: Privacy Flag Respected in Search

**GIVEN** a Pipelinq contact has a privacy flag (e.g., "do not contact")  
**WHEN** a cashier searches and the contact matches  
**THEN** the contact is included in search results  
**BUT** a visual indicator appears next to the name (e.g., "🔒" icon or "Niet benaderen" tag)

### Scenario 2: Select Contact with Privacy Flag

**GIVEN** a contact with a privacy flag is displayed  
**WHEN** the cashier clicks to select it  
**THEN** the contact is selected successfully (cannot select; cashier can still link them)  
**AND** a warning is shown in the checkout view:
- "Deze klant wil niet worden benaderd." (This customer does not wish to be contacted.)

**AND** the marketing consent checkbox is **disabled** and unchecked

### Scenario 3: Audit Logging

**GIVEN** any customer lookup or consent action occurs  
**WHEN** the action completes  
**THEN** the action is logged to an audit trail with:
- Timestamp
- Cashier ID
- Customer ID
- Action type (search, select, consent capture)
- IP address

---

## Cross-App Integration Notes

### Pipelinq Contact Schema (Affected Fields)

The following Pipelinq contact properties are used:

| Field | Type | Source | Usage |
|-------|------|--------|-------|
| `uuid` | UUID | Existing | POS transaction customer reference |
| `name` | string | Existing | Lookup results display |
| `email` | string | Existing | Lookup results display, search field |
| `phone` | string | Existing | Lookup results display, search field |
| `marketingConsent` | boolean | Existing | Synced from POS on consent capture |
| `privacyFlags` | array | Existing | Respected in search results (visual indicator) |

### POS Transaction Schema (Extended)

New fields added to `posTransaction`:

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `customer` | UUID ref | Conditional | Required if `tenderType === "onAccount"` |
| `marketingConsent` | boolean | No | Defaults to false; synced to Pipelinq contact |
| `tenderType` | string enum | No | New value: `"onAccount"` |

### Shillinq Integration (Future Change)

This change creates the data foundation for AR/debtor tracking:
- POS module provides `customer` UUID and `tenderType: "onAccount"` on transactions
- Shillinq module (separate change) subscribes to transaction events and creates debtor records
- Shillinq module reads customer from Pipelinq and creates AR ledger entries

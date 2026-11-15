# pos-customer-link Specification

## Purpose

Linking a point of sale transaction to a customer: looking the customer up at the register, showing their purchase history, capturing marketing consent, an on-account tender and the privacy rules on the search. Synced from the archived change `2026-06-14-pos-customer-link`.

## Requirements

### Requirement: Customer Lookup Modal (REQ-PCL-001)

The point of sale SHALL let the cashier search for and select a customer from a lookup dialog.

**Summary**: Cashier can search for and select a customer from Pipelinq contacts before checkout.

#### Scenario: Search by Name

**GIVEN** a cashier is at the checkout screen  
**WHEN** they click "Voeg klant toe" (Add customer)  
**THEN** a modal dialog opens with:
- A search text input field (placeholder: "Naam, e-mail of telefoonnummer")
- An empty results list (until search begins)
- "Annuleren" and "Selecteren" buttons at the bottom

**AND** the search input has focus (keyboard ready)

#### Scenario: Search Results Returned

**GIVEN** a search modal is open  
**WHEN** the cashier types "Maria" and pauses for 300ms  
**THEN** a GET request is sent to `/api/pipelinq/api/objects/contact?query=Maria&limit=20`  
**AND** results are displayed in a list where each row shows:
- Contact name (bold)
- Email address (if available)
- Phone number (if available)
- Last purchase date (if available from transaction history)

**AND** rows are clickable (cursor changes to pointer)

#### Scenario: Select Customer

**GIVEN** search results are displayed  
**WHEN** the cashier clicks on a result row  
**THEN** the customer UUID is captured  
**AND** the modal closes  
**AND** the checkout view updates to show:
- Selected customer name in a display field (e.g., "Klant: Maria García")
- An "X" button to clear the selection
- The purchase history panel (collapsed or expanded per config)

#### Scenario: No Results

**GIVEN** a search is performed  
**WHEN** no contacts match the query  
**THEN** the results area displays:
- "Geen resultaten. Probeer een ander zoekopdracht." (No results. Try a different search.)
- An empty list (no flickering)
- The modal remains open so the cashier can refine the search

#### Scenario: Search Debounce

**GIVEN** a cashier is typing in the search field  
**WHEN** they type "M-a-r-i-a" rapidly (within 300ms intervals)  
**THEN** the API is called only once (after 300ms silence)  
**AND** previous requests are cancelled if new typing starts  
**AND** network traffic is minimized

#### Scenario: API Error Handling

**GIVEN** a search is in progress  
**WHEN** the Pipelinq API returns an error (e.g., 500, network timeout)  
**THEN** the results area displays:
- "Fout bij zoeken. Probeer later opnieuw." (Error searching. Try again later.)
- A "Retry" button to re-attempt the last search
- The modal remains open

### Requirement: Customer Attachment to Transaction (REQ-PCL-002)

The system SHALL store the selected customer on the transaction when it is saved.

**Summary**: When a customer is selected, the transaction is linked to that contact's UUID.

#### Scenario: Attach Customer on Save

**GIVEN** a customer has been selected in the checkout modal  
**WHEN** the transaction is saved (Checkout button clicked)  
**THEN** the transaction record is populated with:
- `customer`: the selected contact's UUID
- `marketingConsent`: the value of the consent checkbox (true/false)

**AND** the transaction response includes these fields

#### Scenario: Transaction Without Customer (Cash/Card)

**GIVEN** a cashier completes a checkout without selecting a customer  
**WHEN** the transaction is saved  
**THEN** the `customer` field remains null  
**AND** the transaction is saved successfully (customer is optional)

#### Scenario: Clear Customer Selection

**GIVEN** a customer has been selected and is displayed in the checkout form  
**WHEN** the cashier clicks the "X" button next to the customer name  
**THEN** the customer field is cleared  
**AND** the selected customer display disappears  
**AND** the purchase history panel (if visible) disappears

#### Scenario: Validation: On-Account Requires Customer

**GIVEN** a cashier selects tender type "Op rekening" (On account)  
**WHEN** they attempt to save the transaction without a customer selected  
**THEN** a validation error is displayed:
- "Klant is verplicht voor 'op rekening' transacties" (Customer is required for on-account transactions)
- The "Afrekenen" (Checkout) button is disabled
- The customer lookup button is highlighted or scrolled into view

### Requirement: Purchase History Display (REQ-PCL-003)

The point of sale SHALL show the selected customer's recent purchases, to a configured depth.

**Summary**: When a customer is selected, their last N transactions are displayed.

#### Scenario: Fetch History on Selection

**GIVEN** a customer is selected  
**WHEN** the selection is confirmed in the modal  
**THEN** a backend request is sent:
- `GET /api/transactions?customer={uuid}&limit=10&sort=-createdAt`

**AND** the response is cached in memory for the duration of the checkout session

#### Scenario: Display History Panel

**GIVEN** history has been fetched  
**WHEN** the customer is selected and the checkout view is updated  
**THEN** a collapsible panel appears labeled:
- "Aankoopgeschiedenis (10)" (Purchase History — 10 transactions)

**AND** the panel is collapsed by default (showing only the header)

#### Scenario: Expand History

**GIVEN** a history panel is visible and collapsed  
**WHEN** the cashier clicks the header or toggle icon  
**THEN** the panel expands to show:
- A list of the last 10 transactions, each with:
  - Date (formatted: DD-MM-YYYY)
  - Item count (e.g., "3 items")
  - Total amount (formatted currency: €X.XX)
  - Tender type (e.g., "Cash", "Card", "Op rekening")

**AND** the toggle icon changes direction (▼ expanded, ▶ collapsed)

#### Scenario: Empty History

**GIVEN** a customer has no prior transactions  
**WHEN** history is fetched and is empty  
**THEN** the history panel displays:
- "Geen eerdere aankopen" (No previous purchases)

**AND** the panel is still collapsible

#### Scenario: History Depth Configuration

**GIVEN** an admin has configured history depth to "Last 20 transactions"  
**WHEN** a customer is selected  
**THEN** the API request includes `limit=20` instead of the default 10  
**AND** the panel header displays "Aankoopgeschiedenis (20)"

### Requirement: Marketing Consent Capture (REQ-PCL-004)

The point of sale SHALL capture marketing consent at checkout and record it only when given.

**Summary**: Cashier captures explicit email/SMS opt-in during checkout; consent is synced to Pipelinq.

#### Scenario: Consent Checkbox in Checkout

**GIVEN** a customer has been selected  
**WHEN** the checkout form is displayed  
**THEN** a checkbox appears with the label:
- "☐ Ik wil graag aanbiedingen en updates ontvangen"
- (English: "I want to receive offers and updates")

**AND** the checkbox is unchecked by default  
**AND** it appears in the final checkout step (before "Afrekenen" button)

#### Scenario: Capture Consent on Save

**GIVEN** a customer is selected  
**AND** the consent checkbox is checked  
**WHEN** the transaction is saved  
**THEN** the transaction is saved with `marketingConsent: true`  
**AND** a PATCH request is sent to Pipelinq:
- `PATCH /api/pipelinq/api/objects/contact/{uuid}`
- Body: `{ "marketingConsent": true }`

**AND** the response confirms the contact is updated

#### Scenario: No Consent Sync if Unchecked

**GIVEN** a customer is selected  
**AND** the consent checkbox is **not** checked  
**WHEN** the transaction is saved  
**THEN** the transaction is saved with `marketingConsent: false`  
**AND** **no** PATCH request is sent to Pipelinq (existing consent state is preserved)

#### Scenario: Consent Without Customer

**GIVEN** no customer is selected  
**WHEN** the consent checkbox is checked  
**THEN** the checkbox is **disabled** and grayed out  
**AND** a tooltip appears: "Selecteer eerst een klant" (Select a customer first)

#### Scenario: Sync Error Handling

**GIVEN** a transaction is being saved with consent = true  
**WHEN** the PATCH to Pipelinq fails (e.g., 500, network error)  
**THEN** the transaction is saved **successfully** (POS is authoritative)  
**AND** a warning toast is displayed:
- "Transactie opgeslagen, maar toestemming kon niet worden gesynchroniseerd." (Transaction saved, but consent sync failed.)
- An optional "Retry" button to manually sync consent later

### Requirement: On-Account Tender Tracking (REQ-PCL-005)

The system SHALL offer an on-account tender that requires a customer.

**Summary**: Transactions can be marked "op rekening" (on account); customer is required; tender type is tracked for AR integration.

#### Scenario: On-Account Option in Tender Dropdown

**GIVEN** the tender type dropdown is visible  
**WHEN** the cashier opens the dropdown  
**THEN** a new option appears:
- "Op rekening" (On account)

**AND** it is listed alongside "Cash" and "Card" options  
**AND** the dropdown shows the currently selected tender type

#### Scenario: Require Customer for On-Account

**GIVEN** the tender type is set to "Op rekening"  
**WHEN** the cashier attempts to save without a customer selected  
**THEN** validation fails with the error:
- "Klant is verplicht voor 'op rekening' transacties"

**AND** the "Afrekenen" button is disabled until a customer is selected

#### Scenario: Save On-Account Transaction

**GIVEN** a customer is selected  
**AND** tender type is set to "Op rekening"  
**WHEN** the transaction is saved  
**THEN** the transaction record includes:
- `tenderType: "onAccount"`
- `customer: {uuid}`

**AND** the transaction is tagged for AR/debtor tracking (visible in transaction detail view)

#### Scenario: On-Account Visual Indicator

**GIVEN** a transaction has `tenderType: "onAccount"`  
**WHEN** the transaction is displayed in checkout summary or history  
**THEN** the tender is shown with a visual indicator (e.g., bold, colored, icon)  
**AND** label is "Op rekening"

### Requirement: Admin Settings for Customer Lookup (REQ-PCL-006)

An administrator SHALL be able to configure the customer lookup's search fields, history depth and sync.

**Summary**: Admin can configure which search fields are visible, history depth, and sync behavior.

#### Scenario: Access Admin Settings

**GIVEN** a user with POS admin rights is in the settings area  
**WHEN** they navigate to "POS > Klantinstellingen" (Customer Settings)  
**THEN** a settings panel appears with:
- Search field toggles (name, email, phone)
- History depth selector
- Pipelinq sync toggle
- Save button

#### Scenario: Configure Search Fields

**GIVEN** the Customer Settings panel is open  
**WHEN** the admin unchecks "E-mailadres" (Email)  
**AND** clicks "Opslaan" (Save)  
**THEN** the setting is persisted (e.g., in OpenRegister or IAppConfig)  
**AND** the next time a cashier opens the customer lookup modal, the search results **only** show name and phone (email is hidden)

#### Scenario: Configure History Depth

**GIVEN** the Customer Settings panel is open  
**WHEN** the admin selects "Last 20 transactions" (radio button)  
**AND** clicks "Opslaan"  
**THEN** the setting is persisted  
**AND** subsequent customer lookups fetch 20 transactions instead of 10

#### Scenario: Toggle Pipelinq Sync

**GIVEN** the Customer Settings panel is open  
**WHEN** the admin unchecks "Automatische synchronisatie met Pipelinq" (Automatic Pipelinq sync)  
**AND** clicks "Opslaan"  
**THEN** the setting is persisted  
**AND** consent checkboxes no longer trigger PATCH requests to Pipelinq (transaction-local only)

### Requirement: Search Privacy & Compliance (REQ-PCL-007)

The customer search SHALL respect a person's privacy flag and audit each lookup.

**Summary**: Customer lookup respects Pipelinq privacy settings and compliance flags.

#### Scenario: Privacy Flag Respected in Search

**GIVEN** a Pipelinq contact has a privacy flag (e.g., "do not contact")  
**WHEN** a cashier searches and the contact matches  
**THEN** the contact is included in search results  
**BUT** a visual indicator appears next to the name (e.g., "🔒" icon or "Niet benaderen" tag)

#### Scenario: Select Contact with Privacy Flag

**GIVEN** a contact with a privacy flag is displayed  
**WHEN** the cashier clicks to select it  
**THEN** the contact is selected successfully (cannot select; cashier can still link them)  
**AND** a warning is shown in the checkout view:
- "Deze klant wil niet worden benaderd." (This customer does not wish to be contacted.)

**AND** the marketing consent checkbox is **disabled** and unchecked

#### Scenario: Audit Logging

**GIVEN** any customer lookup or consent action occurs  
**WHEN** the action completes  
**THEN** the action is logged to an audit trail with:
- Timestamp
- Cashier ID
- Customer ID
- Action type (search, select, consent capture)
- IP address


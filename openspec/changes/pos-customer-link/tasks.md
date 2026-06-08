# Tasks: POS Attach Customer to Ticket

## Deduplication Check

- [ ] DC-1: Verify no overlap with existing POS or Pipelinq specs
  - Search `openspec/specs/` for any existing customer-link or debtor-tracking specs
  - Search `openspec/changes/` for completed pos-customer or pipelinq-contact-link changes
  - Confirm this is the first implementation of POS-Pipelinq customer linking
  - **Status**: Proceed if no conflicts found

---

## 1. Backend: Transaction Schema Extension

- [x] 1.1 Extend posTransaction schema with customer fields
  - **spec_ref**: `specs/pos-customer-link/spec.md#REQ-PCL-002`
  - **files**: `pos/lib/Db/Transaction.php`, `pos/openregister/pipelinq_pos_register.json` (or equivalent schema definition)
  - **acceptance_criteria**:
    - Add `customer` field (type: string/UUID, nullable)
    - Add `marketingConsent` field (type: boolean, default: false)
    - Extend `tenderType` enum to include `"onAccount"`
    - Schema must validate customer UUID format if provided
    - Schema must validate on-account tender requires non-null customer
    - Existing transactions without customer continue to work (backward compatible)

- [x] 1.2 Create database migration (if applicable)
  - **files**: N/A — pipelinq's POS uses OpenRegister's magic-table objects (`oc_openregister_table_<reg>_posTransaction`); columns derive from the JSON schema in `lib/Settings/pipelinq_register.json`. No `oc_pipelinq_*` table exists, so no NC `Migration/Version*` class is required. Existing rows are forward-compatible (new fields default to null / false / 'cash').
  - **acceptance_criteria**:
    - Customer column: derived from schema (no migration)
    - marketing_consent column: derived from schema (no migration)
    - tender_type enum: declared in schema (no migration)
    - Reversibility: schema rollback removes the fields
    - Existing transactions continue to work (fields are optional / defaulted)

- [ ] 1.3 Update TransactionController to handle customer and consent
  - **spec_ref**: `specs/pos-customer-link/spec.md#REQ-PCL-002, REQ-PCL-004`
  - **files**: `pos/lib/Controller/TransactionController.php`
  - **acceptance_criteria**:
    - `POST /api/transactions` accepts `customer` (UUID string, optional)
    - `POST /api/transactions` accepts `marketingConsent` (boolean, optional)
    - Input validation: if customer is provided, validate UUID format
    - Input validation: if tenderType is "onAccount", require non-null customer
    - Transaction is saved to database with customer and consent fields
    - Response includes customer and consent fields in JSON

- [ ] 1.4 Add Pipelinq consent sync service
  - **spec_ref**: `specs/pos-customer-link/spec.md#REQ-PCL-004 Scenario 2`
  - **files**: `pos/lib/Service/PipelinqConsentService.php` (new)
  - **acceptance_criteria**:
    - `public function syncConsentToContact(string $contactUuid, bool $consent): bool`
    - Makes PATCH request to Pipelinq: `PATCH /api/pipelinq/api/objects/contact/{uuid}`
    - Body: `{ "marketingConsent": true }` if consent is true
    - Returns true on success, false on error
    - Logs sync attempts and failures to PHP error log or app logs
    - Includes timeout (5 seconds) to prevent checkout slowdown
    - Catches and handles network errors without raising exceptions

- [ ] 1.5 Integrate consent sync into transaction save
  - **spec_ref**: `specs/pos-customer-link/spec.md#REQ-PCL-004 Scenario 5`
  - **files**: `pos/lib/Controller/TransactionController.php`, `pos/lib/Service/PipelinqConsentService.php`
  - **acceptance_criteria**:
    - After transaction is saved, if `marketingConsent === true` and `customer` is set, call `PipelinqConsentService::syncConsentToContact()`
    - If sync fails, log warning but do not fail transaction save (transaction is already committed)
    - Return response includes `consentSyncStatus` field: "success" or "failed"
    - Consent sync is optional (controlled by admin setting)

---

## 2. Backend: Customer Lookup API

- [ ] 2.1 Create Pipelinq search proxy service
  - **spec_ref**: `specs/pos-customer-link/spec.md#REQ-PCL-001`
  - **files**: `pos/lib/Service/PipelinqSearchService.php` (new)
  - **acceptance_criteria**:
    - `public function searchContacts(string $query, int $limit = 20): array`
    - Calls Pipelinq API: `GET /api/objects/contact?query={query}&limit={limit}`
    - Authenticates via service account token (from env: `PIPELINQ_SERVICE_TOKEN`)
    - Returns array of contact objects (id, name, email, phone, createdAt, etc.)
    - Includes error handling: if API unreachable, returns empty array with logged error
    - Implements 5-second timeout (prevent long checkout waits)
    - Filters results by admin-configured search fields (name, email, phone)

- [ ] 2.2 Create search endpoint in TransactionController
  - **spec_ref**: `specs/pos-customer-link/spec.md#REQ-PCL-001`
  - **files**: `pos/lib/Controller/TransactionController.php`
  - **acceptance_criteria**:
    - `GET /api/customers/search?query={query}&limit=20`
    - Calls `PipelinqSearchService::searchContacts()`
    - Returns JSON array of matching contacts
    - Includes decorators from admin settings (highlight privacy flags, show last purchase date if available)
    - Returns 200 on success, 500 on Pipelinq API error (with error message)
    - Query parameter validation: query must be 2+ characters, limit 1-100

- [ ] 2.3 Fetch transaction history for customer
  - **spec_ref**: `specs/pos-customer-link/spec.md#REQ-PCL-003`
  - **files**: `pos/lib/Controller/TransactionController.php`
  - **acceptance_criteria**:
    - `GET /api/transactions?customer={uuid}&limit=10&sort=-createdAt`
    - Filters transactions by customer UUID
    - Returns transactions in descending date order (newest first)
    - Includes `itemCount`, `total`, `tenderType`, `createdAt` in response
    - Respects admin-configured history depth (default 10, configurable)
    - Pagination support (limit, offset)

---

## 3. Frontend: Customer Lookup Modal

- [ ] 3.1 Create CustomerLookupModal.vue component
  - **spec_ref**: `specs/pos-customer-link/spec.md#REQ-PCL-001`
  - **files**: `pos/src/components/CustomerLookupModal.vue` (new)
  - **acceptance_criteria**:
    - Component is a modal dialog (uses NcModal from @nextcloud/vue)
    - Includes text input for search query (placeholder: "Naam, e-mail of telefoonnummer")
    - Implements 300ms debounce on search input change
    - Calls `GET /api/customers/search?query={query}` on search
    - Shows loading spinner while fetching
    - Displays results as clickable list rows
    - Each row shows: name (bold), email, phone, last purchase date (if available)
    - Shows "Geen resultaten" message if no matches found
    - Emits `@select` event with selected contact UUID when a row is clicked
    - Emits `@cancel` event when modal is closed or Cancel button clicked
    - Implements error handling: shows error message if API fails, includes Retry button
    - Component is keyboard accessible (WCAG AA): Enter to select, Esc to cancel

- [ ] 3.2 Add accessibility to modal results
  - **spec_ref**: `specs/pos-customer-link/spec.md#REQ-PCL-001 (accessibility implicit)`
  - **files**: `pos/src/components/CustomerLookupModal.vue`
  - **acceptance_criteria**:
    - Result list has `role="listbox"` and proper ARIA labels
    - Each result row has `role="option"` and is keyboard selectable (arrow keys)
    - Loading and error states have appropriate aria-live announcements
    - Search input has associated label or clear placeholder text

---

## 4. Frontend: Checkout View Enhancement

- [ ] 4.1 Add customer lookup button to CheckoutView.vue
  - **spec_ref**: `specs/pos-customer-link/spec.md#REQ-PCL-002, REQ-PCL-003`
  - **files**: `pos/src/views/CheckoutView.vue`
  - **acceptance_criteria**:
    - Button labeled "Voeg klant toe" appears in checkout form (above line items or in header)
    - Clicking button opens CustomerLookupModal
    - Button is disabled during transaction save (loading state)
    - Button text/icon clearly conveys "add customer" action

- [ ] 4.2 Display selected customer in checkout form
  - **spec_ref**: `specs/pos-customer-link/spec.md#REQ-PCL-002`
  - **files**: `pos/src/views/CheckoutView.vue`
  - **acceptance_criteria**:
    - When customer is selected, display: "Klant: {CustomerName}"
    - Show "X" button next to customer name to clear selection
    - Clicking "X" clears `selectedCustomer` and hides purchase history panel
    - Selected customer is retained during checkout (survives component re-renders)

- [ ] 4.3 Integrate PurchaseHistory.vue component
  - **spec_ref**: `specs/pos-customer-link/spec.md#REQ-PCL-003`
  - **files**: `pos/src/views/CheckoutView.vue`, `pos/src/components/PurchaseHistory.vue` (new)
  - **acceptance_criteria**:
    - When customer is selected, fetch transaction history via `GET /api/transactions?customer={uuid}`
    - Pass history to PurchaseHistory component as prop
    - Component displays collapsed/expandable panel with transaction list
    - Panel shows: "Aankoopgeschiedenis (X)" with transaction count
    - Panel is collapsible (toggle on header click)
    - Each transaction shows: date, item count, total, tender type

- [ ] 4.4 Add marketing consent checkbox
  - **spec_ref**: `specs/pos-customer-link/spec.md#REQ-PCL-004`
  - **files**: `pos/src/views/CheckoutView.vue`
  - **acceptance_criteria**:
    - Checkbox appears in final checkout step (before "Afrekenen" button)
    - Label: "☐ Ik wil graag aanbiedingen en updates ontvangen"
    - Checkbox is disabled if no customer is selected (visual disabled state + tooltip)
    - Tooltip (when disabled): "Selecteer eerst een klant"
    - Checkbox value (true/false) is bound to component data and sent with transaction

- [ ] 4.5 Extend tender type dropdown with "Op rekening"
  - **spec_ref**: `specs/pos-customer-link/spec.md#REQ-PCL-005`
  - **files**: `pos/src/views/CheckoutView.vue` (or CheckoutSummary component)
  - **acceptance_criteria**:
    - Tender type dropdown includes new option: "Op rekening"
    - Option is listed alongside existing "Cash", "Card" options
    - Selecting "Op rekening" triggers validation that customer is required
    - If on-account is selected without customer, "Afrekenen" button is disabled
    - Error message displays: "Klant is verplicht voor 'op rekening' transacties"

- [ ] 4.6 Add validation for on-account + customer requirement
  - **spec_ref**: `specs/pos-customer-link/spec.md#REQ-PCL-005 Scenario 2`
  - **files**: `pos/src/views/CheckoutView.vue`
  - **acceptance_criteria**:
    - Computed property: `isOnAccountValid` returns true only if (tenderType !== "onAccount" OR customer is selected)
    - "Afrekenen" button `:disabled="!isOnAccountValid"`
    - Error message is displayed when validation fails
    - Error message is cleared when validation passes

---

## 5. Frontend: Customer Lookup Service

- [ ] 5.1 Create customerService.js API client
  - **spec_ref**: `specs/pos-customer-link/spec.md#REQ-PCL-001, REQ-PCL-003`
  - **files**: `pos/src/services/customerService.js` (new)
  - **acceptance_criteria**:
    - `searchContacts(query, limit = 20)`: returns Promise<Contact[]>
    - `getTransactionHistory(customerId, limit = 10)`: returns Promise<Transaction[]>
    - Implements error handling: catches network errors, returns empty array or error object
    - Methods are async (return Promises for use in Vue components with async/await)
    - Includes type hints or JSDoc comments for IDE support

---

## 6. Backend: Admin Settings

- [ ] 6.1 Create admin settings for customer lookup configuration
  - **spec_ref**: `specs/pos-customer-link/spec.md#REQ-PCL-006`
  - **files**: `pos/lib/Controller/SettingsController.php`, `pos/openregister/pipelinq_pos_register.json` (or similar)
  - **acceptance_criteria**:
    - Settings include:
      - `customerSearchFields` (array: "name", "email", "phone" — checkboxes)
      - `customerHistoryDepth` (integer: 10, 20, or 50 — radio/dropdown)
      - `enablePipelinqSync` (boolean: true/false — toggle)
      - `requireCustomerForOnAccount` (boolean: default true)
    - Settings are persisted to database (IAppConfig or OpenRegister)
    - Settings endpoint `GET /api/admin/settings` returns all configuration
    - Admin can update settings via `POST /api/admin/settings` with JSON body
    - Settings changes take effect immediately (no restart required)

- [ ] 6.2 Create admin settings UI component
  - **spec_ref**: `specs/pos-customer-link/spec.md#REQ-PCL-006`
  - **files**: `pos/src/views/settings/CustomerSettings.vue` (new)
  - **acceptance_criteria**:
    - Component loads from `GET /api/admin/settings`
    - Displays search field checkboxes: ☑ Naam, ☑ E-mailadres, ☑ Telefoonnummer
    - Displays history depth selector (radio or dropdown): Last 10 / 20 / 50 transactions
    - Displays Pipelinq sync toggle: ☑ Enable automatic Pipelinq sync
    - Displays require-customer toggle: ☑ Require customer for on-account transactions
    - Save button persists changes via `POST /api/admin/settings`
    - Success message on save
    - Error message if save fails (with retry option)
    - Translations: English UI text with Dutch (nl.json) translations

---

## 7. Backend: Privacy & Compliance

- [ ] 7.1 Add privacy flag decoration to search results
  - **spec_ref**: `specs/pos-customer-link/spec.md#REQ-PCL-007`
  - **files**: `pos/lib/Service/PipelinqSearchService.php`
  - **acceptance_criteria**:
    - Search results include `privacyFlags` array from Pipelinq contact (if present)
    - If contact has "do not contact" flag, results include `doNotContact: true`
    - Frontend decorates such results with visual indicator (icon or badge)

- [ ] 7.2 Block consent sync if do-not-contact flag is set
  - **spec_ref**: `specs/pos-customer-link/spec.md#REQ-PCL-007 Scenario 2`
  - **files**: `pos/lib/Service/PipelinqConsentService.php`
  - **acceptance_criteria**:
    - When fetching contact before consent sync, check `privacyFlags` or `doNotContact`
    - If flag is set, skip PATCH request (do not override customer's privacy preference)
    - Log this action: "Consent sync skipped for contact {uuid}: do not contact flag set"

- [ ] 7.3 Add audit logging for customer lookups and consent
  - **spec_ref**: `specs/pos-customer-link/spec.md#REQ-PCL-007 Scenario 3`
  - **files**: `pos/lib/Service/AuditLogService.php` (extend if exists)
  - **acceptance_criteria**:
    - Log entry on each customer search: timestamp, cashier UID, query, result count
    - Log entry on customer selection: timestamp, cashier UID, customer UUID, transaction UUID
    - Log entry on consent capture: timestamp, cashier UID, customer UUID, consent value
    - Logs are persisted to database or audit log file
    - Logs include IP address for compliance audits
    - Logs can be queried via admin UI (optional V2 feature)

---

## 8. Testing & Verification

- [ ] 8.1 Unit tests: PipelinqSearchService
  - **spec_ref**: `specs/pos-customer-link/spec.md#REQ-PCL-001`
  - **files**: `pos/tests/Unit/Service/PipelinqSearchServiceTest.php`
  - **acceptance_criteria**:
    - Test: searchContacts("Maria", 20) returns array of contacts with correct properties
    - Test: searchContacts with empty query returns empty array
    - Test: API error (500) returns empty array with logged error
    - Test: API timeout (>5s) returns empty array
    - Test: Results are filtered by admin-configured search fields

- [ ] 8.2 Unit tests: PipelinqConsentService
  - **spec_ref**: `specs/pos-customer-link/spec.md#REQ-PCL-004`
  - **files**: `pos/tests/Unit/Service/PipelinqConsentServiceTest.php`
  - **acceptance_criteria**:
    - Test: syncConsentToContact() sends PATCH with correct payload
    - Test: Returns true on success, false on error
    - Test: Network error is caught (no exception raised)
    - Test: Skip sync if contact has do-not-contact flag
    - Test: Logs sync attempts and failures

- [ ] 8.3 Integration tests: Transaction save with customer + consent
  - **spec_ref**: `specs/pos-customer-link/spec.md#REQ-PCL-002, REQ-PCL-004`
  - **files**: `pos/tests/Integration/TransactionWithCustomerTest.php`
  - **acceptance_criteria**:
    - Test: POST /api/transactions with customer UUID saves successfully
    - Test: Consent sync is triggered when marketingConsent = true
    - Test: On-account validation: transaction fails if tenderType="onAccount" and no customer
    - Test: Transaction without customer succeeds (backward compatibility)
    - Test: Transaction response includes customer and marketingConsent fields

- [ ] 8.4 Frontend component tests: CustomerLookupModal
  - **spec_ref**: `specs/pos-customer-link/spec.md#REQ-PCL-001`
  - **files**: `pos/tests/Unit/components/CustomerLookupModal.spec.js` (or .vue test)
  - **acceptance_criteria**:
    - Test: Modal renders with search input
    - Test: Debounce works (API call happens only after 300ms silence)
    - Test: Results are displayed when API returns data
    - Test: Error message shown on API failure
    - Test: @select event emitted when row is clicked
    - Test: @cancel event emitted on modal close

- [ ] 8.5 Frontend component tests: CheckoutView customer integration
  - **spec_ref**: `specs/pos-customer-link/spec.md#REQ-PCL-002, REQ-PCL-003, REQ-PCL-004, REQ-PCL-005`
  - **files**: `pos/tests/Unit/views/CheckoutView.spec.js`
  - **acceptance_criteria**:
    - Test: Customer lookup button opens modal
    - Test: Selected customer is displayed
    - Test: Clear customer ("X" button) works
    - Test: History panel fetches and displays transactions
    - Test: Consent checkbox is disabled without customer, enabled with customer
    - Test: On-account validation disables Checkout button without customer
    - Test: Marketing consent is included in transaction payload

- [ ] 8.6 Manual testing: E2E checkout flow
  - **spec_ref**: All requirements
  - **files**: N/A (manual testing checklist)
  - **acceptance_criteria**:
    - [ ] Cashier can search for customer by name
    - [ ] Cashier can search for customer by email
    - [ ] Cashier can search for customer by phone
    - [ ] Search results are accurate (no incorrect matches)
    - [ ] Select customer and view purchase history
    - [ ] History shows correct transactions in correct order
    - [ ] Check marketing consent checkbox
    - [ ] Complete transaction; verify customer + consent are saved
    - [ ] Select on-account tender; verify customer is required (validation works)
    - [ ] Admin can change search field visibility; verify changes take effect
    - [ ] Admin can change history depth; verify new depth is applied
    - [ ] Verify Pipelinq contact is updated with consent flag (in Pipelinq UI or database)
    - [ ] Privacy flags are visible in search results (visual indicator)
    - [ ] Do-not-contact customers prevent consent sync

---

## 9. Documentation & Translation

- [ ] 9.1 Add UI translations (Dutch)
  - **files**: `pos/l10n/nl.json`
  - **acceptance_criteria**:
    - "Voeg klant toe" — Add customer button label
    - "Naam, e-mail of telefoonnummer" — Search placeholder
    - "Geen resultaten..." — No results message
    - "Annuleren" — Cancel button
    - "Selecteren" — Select button
    - "Fout bij zoeken..." — Error message
    - "Aankoopgeschiedenis" — Purchase history header
    - "Geen eerdere aankopen" — No history message
    - "Ik wil graag aanbiedingen en updates ontvangen" — Consent checkbox label
    - "Op rekening" — On account tender option
    - "Klant is verplicht voor 'op rekening' transacties" — On-account validation error
    - All admin settings labels and help text

- [ ] 9.2 Update developer documentation
  - **files**: `.claude/openspec/pos-customer-link-notes.md` (optional) or main README
  - **acceptance_criteria**:
    - Document Pipelinq service account token setup (PIPELINQ_SERVICE_TOKEN env var)
    - Document API endpoints: /api/customers/search, /api/transactions (customer parameter)
    - Document admin settings structure
    - Diagram: POS ↔ Pipelinq data flow
    - Example curl commands for testing

---

## 10. Cross-App Coordination

- [ ] 10.1 Coordinate Pipelinq contact schema (verify compatibility)
  - **spec_ref**: All requirements
  - **files**: Pipelinq data model review
  - **acceptance_criteria**:
    - Confirm Pipelinq `contact` schema includes `marketingConsent` field
    - Confirm Pipelinq `contact` schema includes `privacyFlags` field (or similar)
    - Confirm Pipelinq REST API supports PATCH /api/objects/contact/{uuid}
    - Confirm Pipelinq REST API full-text search works on name, email, phone
    - If any fields/endpoints are missing, coordinate with Pipelinq team

- [ ] 10.2 Set up service-to-service authentication
  - **spec_ref**: All backend integration
  - **files**: `.env` configuration, documentation
  - **acceptance_criteria**:
    - Create service account in Pipelinq (if not exists)
    - Issue API token for service account
    - Configure `PIPELINQ_SERVICE_TOKEN` in POS `.env` (or env config)
    - Test service account can search contacts and update consent
    - Document token rotation procedure

- [ ] 10.3 Define transaction event for Pipelinq (future integration point)
  - **spec_ref**: Design section "Cross-App Integration: POS ↔ Pipelinq ↔ Shillinq"
  - **files**: `pos/lib/Events/TransactionSavedEvent.php` (new)
  - **acceptance_criteria**:
    - Event includes transaction UUID, customer UUID, amount, tender type, timestamp
    - Event is dispatched after every successful transaction save
    - Pipelinq (in a future change) can subscribe to this event to build contact snapshots
    - Shillinq (in a future change) can subscribe to this event to create AR entries

---

## Summary

- **Total Tasks**: ~30 checkboxes across 10 sections
- **Backend**: ~11 tasks (schema, API, services, settings, logging)
- **Frontend**: ~8 tasks (modal, checkout UI, services, admin settings)
- **Testing**: ~6 tasks (unit, integration, E2E)
- **Coordination**: ~3 tasks (documentation, translations, cross-app setup)

Estimated effort: 5–7 developer-weeks (1 full-stack engineer) or 3–4 weeks (1 backend + 1 frontend in parallel).

# Design: POS Attach Customer to Ticket

## Architecture Overview

This change spans two modules:

```
POS Module (Frontend + Backend)
├── Frontend (Vue)
│   ├── CheckoutView.vue — add customer lookup button and consent checkbox
│   ├── CustomerLookupModal.vue — search interface for Pipelinq contacts
│   ├── PurchaseHistory.vue — display recent transactions for selected customer
│   └── src/services/customerService.js — API client for Pipelinq search
├── Backend (PHP)
│   └── lib/Controller/TransactionController.php — extend transaction schema to include customer field
└── Schemas
    └── posTransaction — add `customer` field (UUID ref), `marketingConsent` (bool), `tenderType: onAccount` variant

Pipelinq Module (Coordination)
├── ContactService — provides /api/objects/contact and /api/objects/client search (existing)
└── Integration point — (future change) subscribe to POS transaction events for history snapshots
```

## Key Design Decisions

### 1. Customer Lookup via Pipelinq Full-Text Search

Instead of building a custom customer search in the POS module, we call Pipelinq's existing `/api/objects/contact` and `/api/objects/client` endpoints with full-text search. Both endpoints support:

- Full-text search by name, email, phone (native to OpenRegister)
- Filtering by status, tags, or custom properties
- Pagination (limit, offset)
- Sorting (default: by `_dateModified`)

**Rationale**: Pipelinq already owns customer data and search infrastructure. POS is a consumer, not a duplicate. This avoids data sync complexity and ensures one source of truth.

**API call**:
```http
GET /api/pipelinq/api/objects/contact?query=John&limit=20
Authorization: Bearer [service-account-token]
```

Returns:
```json
[
  {
    "uuid": "550e8400-e29b-41d4-a716-446655440000",
    "name": "John Smith",
    "email": "john@example.com",
    "phone": "+31612345678",
    "createdAt": "2025-10-15T10:00:00Z"
  },
  ...
]
```

### 2. Transaction Schema Extension: `customer` Field

The `posTransaction` schema gains three new fields:

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `customer` | string (UUID) | No | Reference to Pipelinq contact or client |
| `marketingConsent` | boolean | No | Email/SMS opt-in flag; synced to contact on save |
| `tenderType` | string enum | No | Existing field; new variant: `"onAccount"` for AR tracking |

**Rationale**: Minimal schema changes. Customer attachment is optional (cash/card sales without a customer should still work). Tender type is already extensible.

### 3. Customer Lookup Modal (Modal-First UX)

The checkout page gets a new button "Voeg klant toe" (Add customer) that opens a modal dialog. The modal:

1. **Search form**: text input with debounce (300ms), searches across name + email + phone fields
2. **Results list**: clickable rows with name, email, phone, last purchase date (if available)
3. **Action buttons**: "Selecteren" (select) to attach to transaction, "Annuleren" (cancel) to close
4. **Clear selection**: button or "X" in the checkout summary to deselect customer

**Rationale**: Modal isolation prevents accidental selection and keeps checkout UI clean. Debounce reduces API calls during typing.

### 4. Purchase History Panel (Sidebar or Collapsible)

When a customer is selected, a collapsible panel shows:

- **Last N transactions** (default 10, configurable): date, item count, total, tender type
- **Link to detail**: optionally navigate to Pipelinq contact detail for full history/notes
- **Summary**: total spend (lifetime or last year, if available from Pipelinq contact snapshot)

**Rationale**: History is read-only at the register; full CRM detail is in Pipelinq. POS shows the essentials without cluttering checkout.

### 5. Consent Capture at Checkout

A checkbox appears in the final checkout step:

```
☐ Ik wil graag aanbiedingen en updates ontvangen
  (I want to receive offers and updates)
```

When checked and the transaction saves:

1. The `marketingConsent` flag is set to `true` in the transaction
2. The `contact.marketingConsent` flag is updated in Pipelinq (via separate service-to-service call)
3. A sync record is logged (for audit/compliance)

**Rationale**: Capture at the moment of transaction, when customer context is clear. Explicit checkbox provides clear consent trail for GDPR compliance.

### 6. On-Account Tender Integration

The tender type dropdown gains a new option: "Op rekening" (On account). When selected:

1. The transaction's `tenderType` is set to `"onAccount"`
2. The customer field is **required** (validation prevents on-account without a customer)
3. A visual flag appears: "Creditsaldo: €X" (if customer has an outstanding balance in shillinq, shown in future integration)

**Rationale**: On-account sales require customer identification for AR matching. Shillinq feed logic is deferred.

### 7. Admin Settings for Lookup Configuration

A new admin settings panel (in POS settings):

```
Customer Lookup Configuration
├── Search fields (checkboxes)
│   ☑ Naam (Name)
│   ☑ E-mailadres (Email)
│   ☑ Telefoonnummer (Phone)
├── History depth
│   ○ Last 10 transactions (default)
│   ○ Last 20 transactions
│   ○ Last year
├── Require customer for on-account
│   ☑ Prevent on-account sales without customer (default: true)
└── Pipelinq integration
    ☑ Enable automatic Pipelinq sync (default: true)
```

Settings stored in OpenRegister (e.g., `posSettings` schema or embedded in transaction schema defaults).

## Component Design

### CheckoutView.vue Enhancements

**New elements**:
- Button "Voeg klant toe" (top of checkout form, or in a separate section)
- Selected customer display: `<span>{{ transaction.customer?.name }}</span>` with "X" to clear
- Marketing consent checkbox in final checkout step
- Tender type dropdown: add "Op rekening" option; validate that customer is set if on-account

**New data**:
- `selectedCustomer`: ref (initially null, set by CustomerLookupModal)
- `marketingConsent`: boolean ref (form control)

### CustomerLookupModal.vue (New)

**Props**:
- `open`: boolean (v-model)
- `onSelect`: callback when customer is selected
- `onCancel`: callback to close

**Data**:
- `searchQuery`: string (debounced)
- `results`: array of contact objects from Pipelinq API
- `loading`: boolean (while fetching)
- `error`: string (if API fails)

**Methods**:
- `handleSearch(query)`: call Pipelinq API, debounce at 300ms
- `selectCustomer(contact)`: emit or callback with selected contact UUID; close modal
- `handleCancel()`: close modal without selection

**Template**:
```vue
<NcModal>
  <template #header>
    <span>Klant toevoegen</span>
  </template>
  <input v-model="searchQuery" placeholder="Naam, e-mail of telefoonnummer">
  <div v-if="loading">Zoeken...</div>
  <ul v-else-if="results.length">
    <li v-for="contact in results" :key="contact.uuid" @click="selectCustomer(contact)">
      <strong>{{ contact.name }}</strong><br>
      {{ contact.email || '' }} {{ contact.phone || '' }}
    </li>
  </ul>
  <div v-else>Geen resultaten. Probeer een ander zoekopdracht.</div>
  <template #actions>
    <NcButton @click="handleCancel" type="secondary">Annuleren</NcButton>
  </template>
</NcModal>
```

### PurchaseHistory.vue (New)

**Props**:
- `customer`: contact object (or UUID)
- `transactions`: array of past transactions (fetched via backend or passed from parent)

**Data**:
- `collapsed`: boolean (default true; user can expand)

**Methods**:
- `toggleCollapse()`: toggle expanded state

**Template**:
```vue
<div class="purchase-history">
  <button @click="toggleCollapse">
    {{ collapsed ? '▶' : '▼' }} Aankoopgeschiedenis ({{ transactions.length }})
  </button>
  <ul v-if="!collapsed">
    <li v-for="tx in transactions" :key="tx.uuid">
      {{ tx.date | format('DD-MM-YYYY') }} | {{ tx.itemCount }} items | €{{ tx.total | currency }}
      <span class="tender">{{ tx.tenderType }}</span>
    </li>
  </ul>
</div>
```

### TransactionController.php (Backend Enhancement)

**Changes**:
- Add `customer` field to transaction validation schema (optional, UUID format)
- Add `marketingConsent` field (optional, boolean)
- Extend `POST /api/transactions` to accept and validate customer + consent
- On save: if `marketingConsent` is true, call Pipelinq API to update contact.marketingConsent

**Pseudo-code**:
```php
public function createTransaction(Request $request): JsonResponse {
    $data = $request->getJSON();
    
    // Validate customer UUID if provided
    if (isset($data['customer'])) {
        $this->validateUuid($data['customer']);
    }
    
    // Save transaction
    $transaction = $this->transactionService->create($data);
    
    // Sync marketing consent to Pipelinq if checked
    if ($data['marketingConsent'] && $data['customer']) {
        $this->pipelinqService->updateContactConsent(
            $data['customer'],
            true
        );
    }
    
    return new JsonResponse($transaction, 201);
}
```

### CustomerService.js (Frontend Client)

**Methods**:
- `searchContacts(query: string, limit = 20): Promise<Contact[]>` — search Pipelinq contacts
- `getTransactionHistory(customerId: UUID, limit = 10): Promise<Transaction[]>` — fetch past transactions (backend aggregation)

**Implementation**:
```javascript
export const customerService = {
  async searchContacts(query, limit = 20) {
    const resp = await axios.get(`/api/pipelinq/api/objects/contact`, {
      params: { query, limit }
    });
    return resp.data;
  },
  
  async getTransactionHistory(customerId, limit = 10) {
    const resp = await axios.get(`/api/transactions`, {
      params: { customer: customerId, limit, sort: '-createdAt' }
    });
    return resp.data;
  }
};
```

## Data Flow

### Customer Selection and Transaction Save

```
1. Cashier clicks "Voeg klant toe"
   ↓
2. CustomerLookupModal opens
   ↓
3. Cashier types search query (debounced 300ms)
   ↓
4. Frontend calls /api/pipelinq/api/objects/contact?query=...
   ↓
5. Results displayed; cashier selects one
   ↓
6. Modal closes; selectedCustomer is set in CheckoutView
   ↓
7. Cashier checks marketing consent (optional)
   ↓
8. Cashier clicks "Afrekenen" (Checkout)
   ↓
9. Frontend POSTs /api/transactions { customer: UUID, marketingConsent: true, ... }
   ↓
10. Backend saves transaction
   ↓
11. If marketingConsent, backend calls /api/pipelinq/api/objects/contact/{uuid} PATCH { marketingConsent: true }
   ↓
12. Success response returned
```

## Seed Data

Example transactions and customers for development/testing:

### Pipelinq Contacts (existing, referenced by POS)

```json
[
  {
    "uuid": "c1234567-abcd-abcd-abcd-123456789abc",
    "name": "Maria García",
    "email": "maria@example.nl",
    "phone": "+31 6 1234 5678",
    "type": "person",
    "address": "Straatweg 42, 1234 AB Amsterdam",
    "marketingConsent": false,
    "createdAt": "2025-10-15T10:00:00Z"
  },
  {
    "uuid": "c2234567-abcd-abcd-abcd-123456789abc",
    "name": "Henk de Vries",
    "email": "henk.devries@zakelijk.nl",
    "phone": "+31 6 8765 4321",
    "type": "person",
    "address": "Bakkerstraat 15, 5678 CD Rotterdam",
    "marketingConsent": true,
    "createdAt": "2025-09-01T14:30:00Z"
  }
]
```

### POS Transactions (with Customer Link)

```json
[
  {
    "uuid": "t5678901-abcd-abcd-abcd-123456789abc",
    "itemCount": 3,
    "total": 45.50,
    "tenderType": "cash",
    "customer": "c1234567-abcd-abcd-abcd-123456789abc",
    "marketingConsent": true,
    "createdAt": "2026-05-20T15:45:00Z"
  },
  {
    "uuid": "t5678902-abcd-abcd-abcd-123456789abc",
    "itemCount": 2,
    "total": 23.75,
    "tenderType": "onAccount",
    "customer": "c2234567-abcd-abcd-abcd-123456789abc",
    "marketingConsent": false,
    "createdAt": "2026-05-19T10:15:00Z"
  }
]
```

## Compliance & Privacy

- **GDPR consent**: Marketing consent flag must be explicit and logged (audit trail)
- **Data retention**: Customer search respects Pipelinq's privacy settings (e.g., "do not contact" flagged contacts are still searchable but visually marked)
- **Service-to-service auth**: POS module authenticates to Pipelinq via service account token (configured in .env)
- **Audit logging**: Every customer lookup and consent capture is logged for compliance audits

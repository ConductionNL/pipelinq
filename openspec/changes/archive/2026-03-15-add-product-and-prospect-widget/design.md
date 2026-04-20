# Design: add-product-and-prospect-widget

## Architecture Overview

This change adds three new schemas to the Pipelinq register (`product`, `productCategory`, `leadProduct`), a new backend service for prospect discovery, and frontend components for product management and the prospect widget.

```
┌─────────────────────────────────────────────────────────────────┐
│ Pipelinq Frontend (Vue 2)                                       │
│                                                                 │
│  ┌──────────────┐ ┌──────────────┐ ┌────────────────────────┐  │
│  │ ProductList   │ │ ProductDetail│ │ ProspectWidget          │  │
│  │ ProductCreate │ │ LeadProducts │ │ (Dashboard integration) │  │
│  └──────┬───────┘ └──────┬───────┘ └────────────┬───────────┘  │
│         │                │                       │              │
│         ▼                ▼                       ▼              │
│  OpenRegister API    OpenRegister API    Pipelinq Prospect API  │
│  (CRUD products,     (CRUD leadProducts, (PHP backend)          │
│   categories)         read products)                            │
└─────────────────────────────────────────────────────────────────┘
                                                   │
                                                   ▼
                                    ┌──────────────────────────┐
                                    │ ProspectDiscoveryService  │
                                    │ (PHP)                     │
                                    │                           │
                                    │  ┌─────────┐ ┌─────────┐ │
                                    │  │ KVK API │ │ OC API  │ │
                                    │  │ Client  │ │ Client  │ │
                                    │  └─────────┘ └─────────┘ │
                                    │                           │
                                    │  APCu Cache (1h TTL)      │
                                    └──────────────────────────┘
```

**Key decisions:**
- Product/Category/LeadProduct CRUD goes through OpenRegister API directly from the frontend (thin client pattern)
- Prospect discovery requires server-side API calls (KVK/OpenCorporates) → new PHP controller + service
- Results are cached in APCu (matching existing Pipelinq caching pattern)
- Client exclusion is done server-side to avoid exposing client list to the frontend prospect service

---

## API Design

### Prospect Discovery (new PHP endpoints)

#### `GET /api/prospects`

Fetch prospect results based on configured ICP. Serves cached results if available.

**Request:**
```
GET /api/prospects?refresh=false
```

| Param | Type | Default | Description |
|-------|------|---------|-------------|
| `refresh` | boolean | false | If true, bypass cache and fetch fresh results |

**Response (200):**
```json
{
  "prospects": [
    {
      "kvkNumber": "12345678",
      "tradeName": "Acme Software B.V.",
      "legalForm": "BV",
      "sbiCode": "6201",
      "sbiDescription": "Ontwikkeling en productie van software",
      "employeeCount": 45,
      "address": {
        "street": "Keizersgracht 123",
        "city": "Amsterdam",
        "province": "Noord-Holland",
        "postalCode": "1015CJ"
      },
      "website": null,
      "registrationDate": "2018-03-15",
      "isActive": true,
      "fitScore": 95,
      "fitBreakdown": {
        "sbiMatch": 30,
        "employeeMatch": 25,
        "locationMatch": 20,
        "legalFormMatch": 15,
        "activeMatch": 10
      },
      "source": "kvk"
    }
  ],
  "total": 42,
  "displayed": 10,
  "cachedAt": "2026-03-03T14:30:00+00:00",
  "icpHash": "a1b2c3d4"
}
```

**Response (400) — No ICP configured:**
```json
{
  "error": "no_icp_configured",
  "message": "Configure your Ideal Customer Profile in admin settings first"
}
```

**Response (503) — API unavailable:**
```json
{
  "error": "api_unavailable",
  "message": "KVK API is currently unavailable. Showing cached results.",
  "prospects": [...],
  "cachedAt": "2026-03-03T13:00:00+00:00"
}
```

---

#### `POST /api/prospects/create-lead`

Create a Client + Lead from a prospect result.

**Request:**
```json
{
  "kvkNumber": "12345678",
  "tradeName": "Acme Software B.V.",
  "address": "Keizersgracht 123, 1015CJ Amsterdam",
  "sbiDescription": "Ontwikkeling en productie van software"
}
```

**Response (201):**
```json
{
  "client": {
    "id": "uuid-of-new-client",
    "name": "Acme Software B.V."
  },
  "lead": {
    "id": "uuid-of-new-lead",
    "title": "Acme Software B.V."
  }
}
```

---

#### `GET /api/prospects/settings`

Get current ICP configuration (admin only).

**Response (200):**
```json
{
  "sbiCodes": ["62", "72"],
  "employeeCountMin": 10,
  "employeeCountMax": 500,
  "provinces": ["Noord-Holland", "Zuid-Holland"],
  "cities": [],
  "legalForms": ["BV", "NV"],
  "excludeInactive": true,
  "keywords": [],
  "kvkApiKey": "***configured***",
  "openCorporatesEnabled": false
}
```

---

#### `PUT /api/prospects/settings`

Save ICP configuration (admin only).

**Request:**
```json
{
  "sbiCodes": ["62", "72"],
  "employeeCountMin": 10,
  "employeeCountMax": 500,
  "provinces": ["Noord-Holland"],
  "cities": [],
  "legalForms": ["BV"],
  "excludeInactive": true,
  "keywords": ["software"],
  "kvkApiKey": "YOUR_KVK_API_KEY_HERE",
  "openCorporatesEnabled": false
}
```

**Response (200):**
```json
{
  "status": "saved",
  "icpHash": "e5f6g7h8"
}
```

---

## Database Changes

No new database tables. All data is stored as OpenRegister objects.

### Register Schema Additions (`pipelinq_register.json`)

**New schemas to add:**

1. **`product`** — `schema:Product`
   - Properties: name, description, sku, unitPrice, cost, category (uuid), type (enum), status (enum), unit, taxRate, image (uri)
   - Required: name, unitPrice, type
   - Facetable: type, status, category

2. **`productCategory`** — `schema:DefinedTermSet`
   - Properties: name, description, parent (uuid), order (integer)
   - Required: name

3. **`leadProduct`** — `schema:Offer`
   - Properties: lead (uuid), product (uuid), quantity (number), unitPrice (number), discount (number), total (number), notes (string)
   - Required: lead, product, quantity, unitPrice

### Register Update

Add `"product"`, `"productCategory"`, `"leadProduct"` to the register's `schemas` array:

```json
"schemas": ["client", "contact", "lead", "request", "pipeline", "product", "productCategory", "leadProduct"]
```

### Default Pipeline View Update

Add `"leadProduct"` should NOT be in the default pipeline view (it's a supporting entity, not a board item). The view stays as-is:

```json
"query": {
  "registers": ["pipelinq"],
  "schemas": ["lead", "request"]
}
```

---

## Nextcloud Integration

- **Controllers:**
  - `ProspectController` — handles GET /api/prospects, POST /api/prospects/create-lead
  - `ProspectSettingsController` — handles GET/PUT /api/prospects/settings (admin-only)

- **Services:**
  - `ProspectDiscoveryService` — orchestrates KVK + OpenCorporates API calls, scoring, caching, client exclusion
  - `KvkApiClient` — HTTP client for KVK Handelsregister Zoeken API (uses IClient from OCP\Http)
  - `OpenCorporatesApiClient` — HTTP client for OpenCorporates API (optional)
  - `ProspectScoringService` — calculates fit scores based on ICP criteria
  - `IcpConfigService` — reads/writes ICP settings from IAppConfig

- **Mappers/Entities:**
  - No custom mappers — all data through OpenRegister ObjectService

- **Events/Hooks:**
  - No new events. Product/LeadProduct CRUD triggers standard OpenRegister events.

---

## File Structure

```
lib/
  Controller/
    ProspectController.php          # GET /api/prospects, POST create-lead
    ProspectSettingsController.php   # GET/PUT /api/prospects/settings
  Service/
    ProspectDiscoveryService.php     # Orchestrator: search, score, cache, exclude
    KvkApiClient.php                 # KVK Handelsregister Zoeken API client
    OpenCorporatesApiClient.php      # OpenCorporates API client
    ProspectScoringService.php       # Fit score calculation
    IcpConfigService.php             # ICP settings read/write via IAppConfig
  Settings/
    pipelinq_register.json           # (modified) Add product, productCategory, leadProduct schemas

src/
  views/
    products/
      ProductList.vue                # Product list view (table with search/filter)
      ProductDetail.vue              # Product detail/edit view
      ProductCreateDialog.vue        # Quick product creation modal
    settings/
      ProductCategoryManager.vue     # Admin: category CRUD
      ProspectSettings.vue           # Admin: ICP configuration + API keys
  components/
    LeadProducts.vue                 # Line items section in lead detail
    ProspectWidget.vue               # Dashboard prospect discovery widget
    ProspectCard.vue                 # Single prospect result card
    ProductRevenue.vue               # Top Products KPI card
  store/modules/
    product.js                       # Product Pinia store (CRUD via OpenRegister API)
    prospect.js                      # Prospect Pinia store (fetch/cache via Pipelinq API)
  router/
    index.js                         # (modified) Add /products and /products/:id routes

appinfo/
  routes.php                         # (modified) Add prospect API routes
```

---

## Security Considerations

- **KVK API key**: Stored as sensitive IAppConfig value (encrypted at rest by Nextcloud). Only exposed as `***configured***` in GET settings response. Admin-only access.
- **Prospect API**: Authenticated endpoints — requires Nextcloud login. No public/CORS access.
- **Client exclusion**: Server-side only — prospect results never include client-identifying data in the exclusion check (client names are compared server-side, not sent to the frontend in a "known clients" list).
- **Input validation**: ICP criteria validated server-side before API calls. SBI codes validated against pattern `^\d{1,5}$`. Employee counts validated as non-negative integers.
- **Rate limiting**: Prospect API endpoint protected by Nextcloud's brute force protection (tagged as non-sensitive). Additional APCu-based rate limiting: max 1 fresh search per user per 5 minutes.

---

## NL Design System

- Product list/detail views: Use standard Nextcloud `NcTable`, `NcButton`, `NcTextField` components (NL Design System compatible via nldesign app)
- Prospect widget: Use `NcNoteCard` pattern for prospect cards, consistent with existing dashboard card patterns
- Fit score badge: CSS custom property `--color-success` for high (>70), `--color-warning` for medium (40-70), `--color-error` for low (<40)
- All new components MUST meet WCAG AA contrast requirements
- Prospect cards MUST be keyboard navigable (Tab through cards, Enter to create lead)

---

## Trade-offs

### Decision: Server-side prospect discovery vs. client-side API calls
- **Chosen**: Server-side (PHP service calls KVK/OC APIs)
- **Why**: API keys must stay server-side; client exclusion check requires access to client data; caching is simpler in APCu
- **Alternative**: Frontend calling APIs directly — rejected due to CORS, API key exposure, and client exclusion complexity

### Decision: APCu cache vs. register objects for prospects
- **Chosen**: APCu cache (ephemeral, 1h TTL)
- **Why**: Prospects are transient search results, not persistent CRM data. Storing as register objects would add unnecessary complexity and data cleanup burden.
- **Alternative**: Store as `prospect` schema objects — deferred to future iteration if users want to track/annotate prospects before converting to leads

### Decision: LeadProduct as separate schema vs. embedded array on Lead
- **Chosen**: Separate `leadProduct` schema
- **Why**: OpenRegister handles object lifecycle (audit trail, events); line items need individual CRUD; separate schema enables querying "which leads include product X?"
- **Alternative**: Embedded `products` array on Lead — rejected because it prevents independent queries and loses audit granularity

### Decision: Product value auto-calculation with manual override
- **Chosen**: Auto-calculate from line items, allow manual override
- **Why**: Respects existing leads that have manual values; provides flexibility when actual deal value differs from product list pricing
- **Alternative**: Force value = sum of line items — rejected because some leads don't have formal product breakdowns

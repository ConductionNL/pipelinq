# Design: Loyalty Program

## Architecture Overview

All loyalty data is stored in OpenRegister as new schemas. The system integrates with existing
pipelinq services via event hooks (pos-transaction-core) and direct API calls (klantbeeld-360 lookups).
Points ledger uses an append-only pattern for complete audit traceability. Gift card issuance
is atomic with POS transaction completion to prevent fraud.

## Key Design Decisions

### 1. Append-Only Points Ledger

**Decision**: PointsLedgerEntry is immutable after creation. All point movements (credit, debit,
expiry, adjustment) create new ledger entries with running balance.

**Rationale**: Financial audit requirement — every point credit/debit must be traceable to a source
(transaction, manual reason, redemption, expiry). Immutable log prevents retroactive data loss and
simplifies compliance reporting. Balances are denormalized on KlantLoyaltyAccount for query performance
but ledger is source of truth.

### 2. Multi-Programme Architecture

**Decision**: Each LoyaltyProgramme is independent. KlantLoyaltyAccount links one customer to one
programme via (klantId, programmeId) composite. Cross-programme pooling is a future capability.

**Rationale**: Simplicity — allows brands within a holding to run independent economics. Reduces
schema complexity. Cross-programme features (shared point pools, family plans) are valuable but
low-priority for MVP.

### 3. Rules-Based Points Calculation

**Decision**: PointsRule defines trigger (purchase, signup, birthday, review, referral, visit, manual),
conditional JSON (category filter, segment, time/channel), and formula (fixed amount, percentage,
stepped). Rules are evaluated in priority order; highest match wins (not cumulative).

**Rationale**: Flexibility without complexity. JSON conditions avoid schema explosion. Non-cumulative
prevents accidental double-counting (e.g., purchase rule + special event rule firing on same transaction).

### 4. Tier Upgrade/Downgrade Policies

**Decision**: TierRule defines threshold type (lifetimePoints, rollingPoints12m, jaarlijkseSpend),
threshold value, benefits[], and upgrade policy (immediate or end_of_period). Downgrades follow
a separate policy (optional).

**Rationale**: Immediate upgrade creates excitement. End-of-period downgrade prevents negative
surprise (customer loses tier mid-year after spending drops). Separate policies allow asymmetric
behaviour.

### 5. Gift Card as Separate But Linked Entity

**Decision**: GiftCard is independent of LoyaltyProgramme but linked via programmeId. GiftCard tracks
serial (unique), pin (hashed), balance, and expiry. GiftCardTransaction logs every operation
(issue, redeem, partial_redeem, refund, topup, block).

**Rationale**: Gift cards are not loyalty points but often redemption options. Separate entity allows
gift cards to be sold standalone, issued as promotional rewards, or used as currency top-up. Hashed PIN
and serial-only storage meets PCI-DSS level 1 requirement (no clear card number storage).

### 6. Atomicity and POS Integration

**Decision**: Points credits and gift card redeems are triggered by pos-transaction-core at transaction
completion. Both operations check account status (actief/geblokkerd) synchronously before commit.
If account is disabled mid-transaction, redemption fails but points credit still commits (customer
gets points, redemption is rejected).

**Rationale**: Ensures consistency with POS flow. Points are the primary reward; redemption is optional.
If a customer blocks themselves mid-transaction, they still earn points — prevents gaming.

## Reuse Analysis

| Capability | Provided by | How Used |
|------------|-------------|----------|
| CRUD operations | `ObjectService` + `CnDetailPage` / `CnIndexPage` | All CRUD for loyalty entities; no custom controllers |
| Audit trail | `AuditTrailService` (OpenRegister built-in) | Automatic tracking of all changes to loyalty objects |
| Relations | `RelationService` (OpenRegister) | Link KlantLoyaltyAccount → Customer (klantId), → LoyaltyProgramme |
| Import/export | `ImportService` / `ExportService` | Programme setup, bulk account creation |
| Notifications | `NotificationService` | 30-day expiry warning, tier change notifications |
| Webhooks | `WebhookService` | Trigger external integrations (email, SMS) for redemption codes |
| Search/filter | `IndexService` + `CnFilterBar` | Full-text search on programme names, account balances |
| Dashboard | `CnDashboardPage` + `CnChartWidget` | Reporting dashboard (programme economics, tier distribution) |

No custom PHP services are required. No new Pinia stores. Frontend uses standard OpenRegister CRUD flows.

## Seed Data

### LoyaltyProgramme

```json
{
  "@self": { "register": "pipelinq", "schema": "LoyaltyProgramme", "slug": "programma-premium-grocer" },
  "programmeId": "uuid-001",
  "naam": "Premium Rewards",
  "merk": "De Groene Grocer",
  "beschrijving": "Earn points on every purchase. Redeem for discounts, gifts, or special offers.",
  "startdatum": "2026-01-01",
  "einddatum": null,
  "status": "actief",
  "valuta": "EUR",
  "taal": "nl",
  "termsUrl": "https://example.com/loyalty-terms",
  "brandingProfileId": "uuid-branding-001"
}
```

```json
{
  "@self": { "register": "pipelinq", "schema": "LoyaltyProgramme", "slug": "programma-pizzeria-marco" },
  "programmeId": "uuid-002",
  "naam": "Marco's Loyalty",
  "merk": "Pizzeria Marco",
  "beschrijving": "Order a pizza, earn points. 10 points = 1 free pizza.",
  "startdatum": "2025-06-01",
  "einddatum": null,
  "status": "actief",
  "valuta": "EUR",
  "taal": "nl",
  "termsUrl": "https://example.com/marco-terms",
  "brandingProfileId": "uuid-branding-002"
}
```

### PointsRule

```json
{
  "@self": { "register": "pipelinq", "schema": "PointsRule", "slug": "rule-purchase-standard" },
  "ruleId": "uuid-rule-001",
  "programmeId": "uuid-001",
  "naam": "Standard Purchase",
  "trigger": "purchase",
  "conditie": { "excludeCategory": ["gift-card"] },
  "formule": { "type": "percentage", "value": 1 },
  "maxPerKlantPerPeriode": null,
  "geldigVan": "2026-01-01",
  "geldigTot": null,
  "prioriteit": 1
}
```

```json
{
  "@self": { "register": "pipelinq", "schema": "PointsRule", "slug": "rule-tuesday-bonus" },
  "ruleId": "uuid-rule-002",
  "programmeId": "uuid-001",
  "naam": "Double Points Tuesday",
  "trigger": "purchase",
  "conditie": { "dayOfWeek": "tuesday" },
  "formule": { "type": "percentage", "value": 2 },
  "maxPerKlantPerPeriode": 100,
  "geldigVan": "2026-01-01",
  "geldigTot": null,
  "prioriteit": 2
}
```

### TierRule

```json
{
  "@self": { "register": "pipelinq", "schema": "TierRule", "slug": "tier-zilver" },
  "tierId": "uuid-tier-001",
  "programmeId": "uuid-001",
  "naam": "Zilver",
  "sequence": 1,
  "drempelType": "lifetimePoints",
  "drempelWaarde": 0,
  "benefits": { "pointsMultiplier": 1.0, "exclusiveOffers": false },
  "upgradeBeleid": "immediate",
  "downgradeBeleid": null
}
```

```json
{
  "@self": { "register": "pipelinq", "schema": "TierRule", "slug": "tier-goud" },
  "tierId": "uuid-tier-002",
  "programmeId": "uuid-001",
  "naam": "Goud",
  "sequence": 2,
  "drempelType": "lifetimePoints",
  "drempelWaarde": 500,
  "benefits": { "pointsMultiplier": 1.25, "exclusiveOffers": true, "freeShipping": true },
  "upgradeBeleid": "immediate",
  "downgradeBeleid": "end_of_year"
}
```

### KlantLoyaltyAccount

```json
{
  "@self": { "register": "pipelinq", "schema": "KlantLoyaltyAccount", "slug": "account-anna-002" },
  "accountId": "uuid-acc-001",
  "klantId": "uuid-customer-anna",
  "programmeId": "uuid-001",
  "currentBalance": 1250,
  "lifetimePoints": 2500,
  "currentTierId": "uuid-tier-002",
  "tierBehaaldOp": "2026-02-15",
  "tierGeldigTot": "2026-12-31",
  "status": "actief",
  "aangemaaktOp": "2025-06-01"
}
```

```json
{
  "@self": { "register": "pipelinq", "schema": "KlantLoyaltyAccount", "slug": "account-mario-001" },
  "accountId": "uuid-acc-002",
  "klantId": "uuid-customer-mario",
  "programmeId": "uuid-002",
  "currentBalance": 45,
  "lifetimePoints": 95,
  "currentTierId": "uuid-tier-bronze",
  "tierBehaaldOp": "2025-08-01",
  "tierGeldigTot": "2026-08-01",
  "status": "actief",
  "aangemaaktOp": "2025-08-01"
}
```

### PointsLedgerEntry

```json
{
  "@self": { "register": "pipelinq", "schema": "PointsLedgerEntry", "slug": "ledger-001" },
  "entryId": "uuid-ledger-001",
  "accountId": "uuid-acc-001",
  "type": "credit",
  "aantal": 150,
  "balansNa": 1250,
  "brondocument": { "transactionId": "pos-tx-42001" },
  "regelId": "uuid-rule-001",
  "timestamp": "2026-05-20T14:32:00Z",
  "verwerktDoor": "pos-terminal-01"
}
```

### RedemptionOption

```json
{
  "@self": { "register": "pipelinq", "schema": "RedemptionOption", "slug": "option-discount-5eur" },
  "optionId": "uuid-opt-001",
  "programmeId": "uuid-001",
  "naam": "€5 Discount",
  "kostenInPunten": 250,
  "beloningType": "discount",
  "beloningWaarde": 5.00,
  "voorraad": null,
  "geldigVan": "2026-01-01",
  "geldigTot": null,
  "perKlantLimiet": 1
}
```

```json
{
  "@self": { "register": "pipelinq", "schema": "RedemptionOption", "slug": "option-free-pizza" },
  "optionId": "uuid-opt-002",
  "programmeId": "uuid-002",
  "naam": "Free Margherita Pizza",
  "kostenInPunten": 100,
  "beloningType": "free_product",
  "beloningWaarde": "Margherita Pizza",
  "voorraad": null,
  "geldigVan": "2025-06-01",
  "geldigTot": null,
  "perKlantLimiet": null
}
```

### GiftCard

```json
{
  "@self": { "register": "pipelinq", "schema": "GiftCard", "slug": "gc-premium-0001" },
  "giftCardId": "uuid-gc-001",
  "programmeId": "uuid-001",
  "serial": "GC-00000001",
  "pin": "$2y$10$...",
  "initialeBalans": 50.00,
  "currentBalans": 23.50,
  "valuta": "EUR",
  "status": "active",
  "uitgegevenOp": "2026-01-15",
  "uitgegevenAan": "Bedrijfsadministrator",
  "vervaltOp": "2027-01-15",
  "kanaal": "purchased"
}
```

## Dutch API Mapping

**Applies to**: KlantLoyaltyAccount (maps to customer profile in klantbeeld-360)

| Schema Property | Dutch API (Klantinteracties) |
|-----------------|------------------------------|
| klantId | DeelnemerIdentificatie (FK to customer) |
| currentBalance | (Computed; not exposed directly) |
| lifetimePoints | (Derived; reported in analytics) |
| currentTierId | (Not exposed; tier is computed from threshold) |
| status | DeelnemerStatus |

**No custom Dutch API mapping required** — loyalty accounts are internal; Dutch API integration
happens at the customer/contact level via klantbeeld-360 link.

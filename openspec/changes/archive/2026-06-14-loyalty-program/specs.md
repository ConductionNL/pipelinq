# Specs: Loyalty Program

**Feature tier**: MVP
**Spec refs**: `openspec/changes/loyalty-program/context-brief.md`
**Standards**: IFRS 15 / RJ 270 (outstanding liability), PCI-DSS (gift cards), AVG/GDPR (customer opt-in),
EMV tokenization (payment terminal integration)

---

## REQ-LOY-001: Programme Activation

A loyalty programme MUST validate its configuration before activation. Status transitions from
concept to actief MUST trigger validation of rules and redemption options for consistency.

**Feature tier**: MVP
**Files**: `pipelinq/app/Service/LoyaltyProgrammeService.php`

### Scenario REQ-LOY-001-01: Valid programme activates

- GIVEN a programme "Premium Rewards" with status "concept"
- AND at least one PointsRule (purchase, 1 point per EUR)
- AND at least one RedemptionOption (€5 discount for 250 points)
- AND all dates are consistent (startdatum < einddatum or einddatum is null)
- WHEN an administrator activates the programme
- THEN the system MUST validate rules (no conflicts, no circular definitions)
- AND MUST validate redemption options (costs are positive, values are realistic)
- AND MUST set status to "actief"
- AND MUST write an audit record with activation timestamp and user ID

### Scenario REQ-LOY-001-02: Programme with missing rules rejects activation

- GIVEN a programme with status "concept"
- AND no PointsRule defined
- WHEN an administrator attempts activation
- THEN the system MUST reject with error "Cannot activate: no points rules configured"
- AND status MUST remain "concept"

### Scenario REQ-LOY-001-03: Programme with invalid date range rejects activation

- GIVEN a programme with startdatum "2026-06-01" and einddatum "2026-01-01"
- WHEN activation is attempted
- THEN the system MUST reject with error "Cannot activate: einddatum must be after startdatum"

---

## REQ-LOY-002: Points Credit on Transaction

Points MUST be awarded atomically when a POS transaction completes for a registered customer
with an active loyalty account. All matching rules MUST be evaluated in priority order; the
highest-priority match wins (non-cumulative). Credits MUST be immutable ledger entries.

**Feature tier**: MVP
**Files**: `pipelinq/app/Service/PointsLedgerService.php`, `openconnector/pos-transaction-core` trigger

### Scenario REQ-LOY-002-01: Standard purchase awards points

- GIVEN a customer "Anna Jansen" with an active account in programme "Premium Rewards"
- AND the programme has rule "purchase" with formula 1 point per EUR
- AND the customer makes a purchase of EUR 45.50 at POS
- WHEN the transaction completes
- THEN a PointsLedgerEntry MUST be created with type "credit", aantal 45 (rounded), balansNa updated
- AND currentBalance and lifetimePoints on KlantLoyaltyAccount MUST increase atomically
- AND a CloudEvent MUST be emitted for downstream listeners (notifications, reporting)

### Scenario REQ-LOY-002-02: Multiple matching rules — highest priority wins

- GIVEN a programme with two rules:
  - Rule 1: "purchase", priority 1, 1 point per EUR
  - Rule 2: "tuesday bonus", priority 2, 2 points per EUR, condition dayOfWeek="tuesday"
- AND today is Tuesday
- AND customer makes EUR 50 purchase
- THEN only Rule 2 (highest priority) MUST apply → 100 points awarded (not 50+100)
- AND ledger entry regelId MUST reference Rule 2

### Scenario REQ-LOY-002-03: Conditional rule filters by category

- GIVEN a rule "Purchase exclusion" with trigger "purchase" and conditie `{ "excludeCategory": ["gift-card"] }`
- AND customer buys EUR 30 gift card
- WHEN transaction completes
- THEN points MUST NOT be awarded (rule condition filters out gift cards)

### Scenario REQ-LOY-002-04: Max per customer per period enforced

- GIVEN a rule "Double points Tuesday" with maxPerKlantPerPeriode 100
- AND the customer has already earned 100 points on Tuesday this week
- AND customer makes another EUR 50 purchase on Tuesday
- WHEN transaction completes
- THEN no additional points MUST be awarded (max reached)
- AND a log entry MUST record "max reached for this customer this period"

### Scenario REQ-LOY-002-05: Disabled account blocks points

- GIVEN a customer's loyalty account with status "geblokkerd"
- AND a purchase transaction completes
- THEN NO PointsLedgerEntry MUST be created
- AND transaction MUST succeed (POS flow unaffected)
- AND account status reason MUST be recorded in error log

---

## REQ-LOY-003: Tier Reclassification

When a customer's lifetimePoints or rolling-window spend crosses a tier threshold, the system
MUST reclassify them automatically. Tier changes MUST emit events for notifications, reporting,
and downstream integrations.

**Feature tier**: MVP
**Files**: `pipelinq/app/Service/TierService.php`

### Scenario REQ-LOY-003-01: Immediate upgrade on threshold

- GIVEN a customer "Mario" with lifetimePoints 450 in tier "Zilver" (threshold 0)
- AND a tier "Goud" with threshold 500 lifetimePoints, upgradeBeleid "immediate"
- WHEN customer earns 60 points, reaching lifetimePoints 510
- THEN currentTierId on KlantLoyaltyAccount MUST update to "Goud" immediately
- AND tierBehaaldOp MUST be set to current timestamp
- AND tierGeldigTot MUST be calculated per tier policy
- AND a tier-changed event MUST be emitted (for email/notification triggers)

### Scenario REQ-LOY-003-02: End-of-period downgrade

- GIVEN a customer "Lisa" in tier "Goud" with policy downgradeBeleid "end_of_year"
- AND lifetimePoints drops to 400 (below "Goud" threshold 500) due to expiry deduction
- AND we are in May (not end of year)
- WHEN points expiry batch runs
- THEN currentTierId MUST remain "Goud" until end of year
- AND a scheduled task MUST be queued to demote her on 2026-12-31

### Scenario REQ-LOY-003-03: Tier benefits applied correctly

- GIVEN a customer in tier "Goud" with benefits `{ "pointsMultiplier": 1.25 }`
- AND a purchase rule with formula "1 point per EUR"
- AND customer makes EUR 100 purchase
- WHEN points calculation happens
- THEN 125 points MUST be awarded (100 * 1.25)
- AND the multiplier MUST be applied dynamically based on currentTierId at transaction time

### Scenario REQ-LOY-003-04: Tier change notification sent

- GIVEN a customer upgrades from "Zilver" to "Goud"
- WHEN tier-changed event is emitted
- THEN a notification MUST be queued (e.g., email via openconnector)
- AND notification content MUST reference tier name and benefits (exclusive offers, points multiplier)

---

## REQ-LOY-004: Redemption with Points Debit

A customer MUST be able to redeem points for rewards. The system MUST reserve points
atomically, generate a unique redemption code, and track redemption status through to
completion (used, expired, cancelled).

**Feature tier**: MVP
**Files**: `pipelinq/app/Service/RedemptionService.php`

### Scenario REQ-LOY-004-01: Valid redemption reserves points

- GIVEN a customer "Anna" with currentBalance 300 points
- AND a RedemptionOption "€5 Discount" costing 250 points
- WHEN Anna initiates redemption for the option
- THEN a Redemption object MUST be created with status "gereserveerd"
- AND currentBalance MUST decrease to 50 points atomically
- AND a PointsLedgerEntry of type "debit", aantal 250 MUST be recorded
- AND a unique beloningCode MUST be generated and returned to the POS/app

### Scenario REQ-LOY-004-02: Insufficient balance rejects redemption

- GIVEN a customer with currentBalance 100 points
- AND a redemption option costing 250 points
- WHEN customer attempts redemption
- THEN the system MUST reject with error "Insufficient balance"
- AND status MUST remain as-is (no debit, no code generation)
- AND currentBalance MUST not change

### Scenario REQ-LOY-004-03: Redemption code marks as used on POS transaction

- GIVEN a redemption "€5 Discount" with code "RDM-ABC123" and status "gereserveerd"
- WHEN the code is scanned at POS and the transaction completes
- THEN the Redemption MUST be marked with status "gebruikt"
- AND used timestamp MUST be recorded
- AND the discount MUST be applied to the transaction

### Scenario REQ-LOY-004-04: Per-customer limit enforced

- GIVEN a RedemptionOption with perKlantLimiet 1
- AND a customer has already redeemed it once (status "gebruikt")
- WHEN the customer attempts another redemption
- THEN the system MUST reject with error "Redemption limit reached for this option"

### Scenario REQ-LOY-004-05: Expired redemption not usable

- GIVEN a Redemption with status "gereserveerd" and geldigTot "2026-05-15"
- AND today is 2026-05-20
- WHEN the customer attempts to use the code at POS
- THEN the system MUST reject with error "Redemption code expired"
- AND status MUST change to "vervallen"

---

## REQ-LOY-005: Points Expiry with Advance Notice

Points MUST expire according to the programme's expiry policy (e.g., 12 months of inactivity).
Customers MUST receive advance notification (30 days) before expiry. Expiry MUST be recorded
as immutable ledger entries, never deletion.

**Feature tier**: MVP
**Files**: `pipelinq/app/Job/PointsExpiryBatchJob.php`, `NotificationService`

### Scenario REQ-LOY-005-01: Expiry batch processes eligible accounts

- GIVEN a programme with expiry policy "12 months inactivity"
- AND an account with lastActivityDate "2024-05-01" (13 months ago)
- AND 500 points in currentBalance
- WHEN the daily expiry batch runs
- THEN a PointsLedgerEntry MUST be created with type "expiry", aantal 500
- AND currentBalance MUST decrease to 0
- AND ledger entry MUST reference the expiry policy (no specific regel)

### Scenario REQ-LOY-005-02: 30-day advance notice sent

- GIVEN an account with points expiring in exactly 30 days
- WHEN the daily batch runs and detects imminent expiry
- THEN a notification MUST be sent to the customer (email, SMS if configured)
- AND notification MUST state "Your X points will expire on YYYY-MM-DD. Use them now!"

### Scenario REQ-LOY-005-03: Recent activity extends expiry

- GIVEN an account with expiry "12 months inactivity"
- AND lastActivityDate 12 months ago
- BUT customer makes a purchase today
- WHEN the batch runs
- THEN the expiry window MUST reset
- AND no points MUST be deducted
- AND lastActivityDate MUST be updated to today

### Scenario REQ-LOY-005-04: Partial expiry if account has mixed activity

- GIVEN an account with two separate groups of points:
  - Group A: 200 points from June 2024 (eligible for expiry)
  - Group B: 300 points from June 2025 (not yet eligible)
- WHEN the batch runs
- THEN only Group A (200 points) MUST be marked for expiry
- AND Group B MUST remain in currentBalance
- (Note: Implementation may use ledger timestamps for this determination)

---

## REQ-LOY-006: Gift Card Issuance

Gift cards MUST be created with a unique serial number and hashed PIN. The card MUST remain
in "issued" status until the issuing POS transaction completes (to prevent fraudulent refund
issuance). PCI-DSS compliance MUST be ensured (no clear card numbers stored, only hashed PIN).

**Feature tier**: MVP
**Files**: `pipelinq/app/Service/GiftCardService.php`

### Scenario REQ-LOY-006-01: Gift card issued with unique serial

- GIVEN an administrator issues a gift card for EUR 50 from programme "Premium Rewards"
- WHEN the gift card is created
- THEN a GiftCard object MUST be created with:
  - Unique serial number (e.g., "GC-00000001")
  - Hashed PIN (using bcrypt or similar, never plain text)
  - initialeBalans 50.00
  - currentBalans 50.00
  - status "issued"
  - vervaltOp calculated as 1 year from issuance
- AND the serial MUST NOT be reused

### Scenario REQ-LOY-006-02: Gift card status only changes on transaction completion

- GIVEN a gift card with status "issued" and a POS transaction in progress
- WHEN the customer declines or cancels the transaction
- THEN the gift card MUST remain with status "issued"
- AND NO GiftCardTransaction MUST be created

### Scenario REQ-LOY-006-03: Gift card activation on successful transaction

- GIVEN a gift card with status "issued"
- AND a POS transaction completes successfully with the gift card payment
- WHEN the transaction is finalized
- THEN the gift card MUST transition to status "active"
- AND a GiftCardTransaction of type "issue" MUST be recorded with:
  - bedrag: 50.00
  - balansNa: 50.00
  - posTransactionId: linked to the POS transaction

### Scenario REQ-LOY-006-04: PIN hashing prevents plaintext storage

- GIVEN a gift card with PIN "1234"
- WHEN the card is stored
- THEN the PIN field MUST contain a bcrypt hash (e.g., "$2y$10$...")
- AND NO plaintext "1234" MUST appear in the database
- AND verification MUST use bcrypt::check() at redemption time

---

## REQ-LOY-007: Partial Gift Card Redemption

Gift cards MUST support partial redemption. If a transaction amount is less than card balance,
the remainder MUST be kept. If greater, the full balance is used and a separate payment method
covers the difference. Balance tracking MUST be immutable (ledger entries only).

**Feature tier**: MVP
**Files**: `pipelinq/app/Service/GiftCardService.php`

### Scenario REQ-LOY-007-01: Full balance consumption

- GIVEN a gift card with currentBalans EUR 50
- AND a POS transaction of EUR 50
- WHEN the gift card is applied
- THEN the full EUR 50 MUST be consumed
- AND currentBalans MUST become 0
- AND a GiftCardTransaction of type "redeem" MUST be recorded with bedrag 50, balansNa 0
- AND status MUST change to "depleted"

### Scenario REQ-LOY-007-02: Partial balance consumption with remainder

- GIVEN a gift card with currentBalans EUR 50
- AND a POS transaction of EUR 30
- WHEN the gift card is applied
- THEN EUR 30 MUST be consumed
- AND currentBalans MUST become EUR 20
- AND a GiftCardTransaction of type "partial_redeem" MUST be recorded with bedrag 30, balansNa 20
- AND status MUST remain "active"

### Scenario REQ-LOY-007-03: Full balance not enough — split payment

- GIVEN a gift card with currentBalans EUR 20
- AND a POS transaction of EUR 50
- WHEN the gift card is applied
- THEN the full EUR 20 MUST be consumed (towards the EUR 50 total)
- AND currentBalans MUST become 0
- AND the POS system MUST show EUR 30 outstanding to be paid by alternative method (card, cash, points)
- AND a GiftCardTransaction of type "partial_redeem" MUST be recorded
- AND status MUST change to "depleted"

### Scenario REQ-LOY-007-04: Refund creates new gift card entry

- GIVEN a transaction that was paid partly with a gift card (bedrag EUR 30 consumed)
- WHEN the transaction is refunded
- THEN a GiftCardTransaction of type "refund" MUST be recorded with bedrag 30
- AND currentBalans MUST increase by 30
- AND status MAY change back to "active" if was "depleted"

---

## REQ-LOY-008: Programme Reporting Dashboard

The reporting dashboard MUST display key programme economics and customer value metrics.
Data MUST be aggregated from ledger entries and redemptions; no denormalization except for
query performance caching (which is refreshed daily).

**Feature tier**: MVP (core metrics), V1.5 (advanced cohort analysis)
**Files**: `pipelinq/app/Service/LoyaltyReportingService.php`, `launchpad` dashboard widget

### Scenario REQ-LOY-008-01: Core KPIs displayed

- GIVEN a programme with 30+ days of transaction history
- WHEN an administrator opens the reporting dashboard for that programme
- THEN the dashboard MUST display:
  - **Active Accounts**: count of accounts with status "actief"
  - **Points Issued (Period)**: sum of credit ledger entries for the selected period
  - **Points Redeemed (Period)**: sum of debit ledger entries for the selected period
  - **Points Expired (Period)**: sum of expiry ledger entries
  - **Breakage %**: expired points / issued points * 100
  - **Redemption Rate %**: redeemed points / issued points * 100
  - **Programme Cost %**: (estimated cost of redemptions as % of associated sales)
  - **Tier Distribution**: count of active accounts per tier
  - **CLV Comparison**: avg customer value (lifetime spend) with vs. without loyalty account

### Scenario REQ-LOY-008-02: Period filtering works

- GIVEN the dashboard with a default period (last 90 days)
- WHEN an administrator selects "Last 12 Months"
- THEN all metrics MUST recalculate for the 12-month window
- AND data MUST be pulled from ledger entries within the date range
- AND charts MUST update without page reload

### Scenario REQ-LOY-008-03: Programme cost estimation

- GIVEN a programme with:
  - Redemption option "€5 Discount" (cost 5 EUR to business)
  - 100 redemptions in the period
  - Associated sales value EUR 5000
- WHEN cost % is calculated
- THEN cost % MUST be (100 * 5 / 5000) * 100 = 100%
- AND a tooltip MUST explain the calculation
- AND the metric MUST be configurable per redemption option (cost basis)

### Scenario REQ-LOY-008-04: Tier distribution shows benefits

- GIVEN a programme with tiers "Zilver" (0 points), "Goud" (500 points), "Platina" (2000 points)
- AND 1000 "Zilver", 200 "Goud", 50 "Platina" active accounts
- WHEN the tier distribution widget renders
- THEN a stacked bar or donut chart MUST show:
  - 1000 in "Zilver" (64%)
  - 200 in "Goud" (13%)
  - 50 in "Platina" (3%)
- AND clicking a segment MUST show the top accounts in that tier

---

## REQ-LOY-009: IFRS 15 / RJ 270 Compliance

Outstanding loyalty points MUST be tracked as a liability on the balance sheet. The system
MUST provide a liability report quantifying outstanding points-in-circulation that have not
yet been redeemed or expired.

**Feature tier**: MVP (reporting only, no automated GL posting)
**Files**: `financeq` integration / `pipelinq/app/Service/LoyaltyLiabilityService.php`

### Scenario REQ-LOY-009-01: Liability snapshot calculated

- GIVEN a programme with:
  - Total points issued (lifetime): 100,000 points
  - Total points redeemed: 40,000 points
  - Total points expired: 5,000 points
  - Current outstanding balance (sum across all accounts): 55,000 points
- WHEN a liability report is generated
- THEN it MUST show:
  - Outstanding Points: 55,000
  - Estimated Liability (assuming EUR 0.01 per point): EUR 550
  - Calculation Method: (sum of all account currentBalance) * average point value
- AND the report MUST be exportable as CSV/PDF for accounting

### Scenario REQ-LOY-009-02: Point valuation configurable

- GIVEN a programme manager who wants to track liability at a custom point value
- WHEN the programme settings are edited
- AND point value is set to EUR 0.02 (instead of 0.01)
- THEN the liability MUST recalculate:
  - Outstanding Points: 55,000 (unchanged)
  - Estimated Liability: 55,000 * 0.02 = EUR 1,100

---

## REQ-LOY-010: AVG/GDPR Compliance

Loyalty accounts MUST require explicit customer opt-in. Customer loyalty data MUST be
linkable to klantbeeld-360 and subject to GDPR data subject access and deletion rights.

**Feature tier**: MVP
**Files**: `pipelinq/app/Service/GdprService.php`, customer opt-in form

### Scenario REQ-LOY-010-01: Account creation requires opt-in

- GIVEN a customer enrolling in a loyalty programme
- WHEN they are presented with the account creation form
- THEN a mandatory checkbox MUST be displayed:
  "I agree to store my loyalty data and contact me with offers"
- AND account creation MUST fail if unchecked
- AND opt-in acceptance timestamp and terms version MUST be recorded

### Scenario REQ-LOY-010-02: Data subject access request returns loyalty data

- GIVEN a customer requesting a data subject access export
- WHEN the request is processed
- THEN the export MUST include:
  - All KlantLoyaltyAccount records linked to their klantId
  - Full PointsLedgerEntry history
  - All Redemption records
  - All GiftCard records (if issued to them)
  - Opt-in records and terms accepted

### Scenario REQ-LOY-010-03: Account deletion cascades correctly

- GIVEN a customer requesting deletion of their loyalty account
- WHEN the deletion request is approved
- THEN the system MUST:
  - Anonymize klantId on all PointsLedgerEntry records (set to null)
  - Anonymize accountId on KlantLoyaltyAccount (mark deleted, retain for audit)
  - Set GiftCard status to "blocked" if owned by customer
  - Retain ledger for audit trail (immutable)
  - NOT delete any records (soft-delete / anonymization only)

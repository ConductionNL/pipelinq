# Tasks: Loyalty Program

## 0. Deduplication Check

- [ ] 0.1 Search for existing loyalty/points tracking capabilities
  - Search `pipelinq/src/`, `pipelinq/app/`, and `openregister/lib/` for "loyalty", "points", "tier",
    "redemption", "gift-card"
  - Check if any prior app (openklant, openzaak, valtimo) has reward/loyalty features
  - Verify no overlapping Open APIs (Gegevensmagazijn, etc.) define loyalty models
  - **Finding**: [Document findings — no overlap found / minor overlap with X / requires coordination]

- [ ] 0.2 Check OpenRegister platform for reusable transaction/ledger patterns
  - Search `ObjectService`, `AuditTrailService`, `TransactionService` for append-only ledger support
  - Verify immutability patterns are available for PointsLedgerEntry
  - Confirm relation linking (accountId → customerId → contact) is supported
  - **Finding**: [Document what platform provides vs. what must be built]

## 1. Schema Design & Migrations

- [ ] 1.1 Create OpenRegister schemas for 9 entities
  - **files**: `pipelinq/lib/Settings/pipelinq_register.json`
  - **spec_ref**: REQ-LOY-001 through REQ-LOY-010
  - Create schemas with all properties from context-brief.md:
    - LoyaltyProgramme (programmeId, naam, merk, status, startdatum, valuta, etc.)
    - PointsRule (trigger, conditie JSON, formule, priority, maxPerCustomerPerPeriod)
    - TierRule (drempelType, drempelWaarde, benefits JSON, upgradeBeleid, downgradeBeleid)
    - KlantLoyaltyAccount (currentBalance, lifetimePoints, currentTierId, tierBehaaldOp)
    - PointsLedgerEntry (type, aantal, balansNa, brondocument, timestamp) — mark immutable
    - RedemptionOption (kostenInPunten, beloningType, perKlantLimiet, geldigVan/Tot)
    - Redemption (status: gereserveerd/gebruikt/vervallen/geannuleerd, beloningCode, timestamp)
    - GiftCard (serial unique, pin hashed, status, vervaltOp)
    - GiftCardTransaction (type: issue/redeem/partial_redeem/refund/block, bedrag, balansNa)
  - **acceptance_criteria**:
    - All 9 schemas defined in OpenRegister format (PascalCase, explicit types, required flags)
    - PointsLedgerEntry flagged as immutable (no-update permission)
    - GiftCard PIN marked as hashed (no plaintext)
    - Seed data provided (3-5 example objects per schema per design.md)
    - Relations defined: KlantLoyaltyAccount → klantId, → programmeId; etc.

- [ ] 1.2 Create database migrations for any custom indexes
  - **files**: `pipelinq/app/Migration/`, if needed
  - Index on (accountId, timestamp) for ledger queries by account
  - Unique index on GiftCard.serial
  - Unique composite index on (klantId, programmeId) for KlantLoyaltyAccount
  - **acceptance_criteria**:
    - Migrations idempotent (check if index exists before creating)
    - No breaking changes to existing schema

## 2. Core Service Layer — Points Ledger & Account Management

- [ ] 2.1 Implement LoyaltyAccountService
  - **files**: `pipelinq/app/Service/LoyaltyAccountService.php`
  - **spec_ref**: REQ-LOY-002, REQ-LOY-003
  - Methods:
    - `createAccount(klantId, programmeId)` — create KlantLoyaltyAccount, initialize balances to 0
    - `getAccount(accountId)` → KlantLoyaltyAccount or throw NotFound
    - `getOrCreateAccount(klantId, programmeId)` — idempotent account creation
    - `disableAccount(accountId, reason)` — set status to "geblokkerd"
    - `deleteAccount(accountId)` — GDPR: soft-delete (anonymize klantId, keep for audit)
  - **acceptance_criteria**:
    - Accounts created with currentBalance=0, lifetimePoints=0, status="actief"
    - All operations logged to AuditTrailService
    - Tier calculated on first account creation (should start at lowest tier)
    - GDPR compliance: deletion does not remove record, only anonymizes

- [ ] 2.2 Implement PointsLedgerService (immutable append-only)
  - **files**: `pipelinq/app/Service/PointsLedgerService.php`
  - **spec_ref**: REQ-LOY-002, REQ-LOY-005
  - Methods:
    - `creditPoints(accountId, amount, ruleId, brondocument, verwerktDoor)` → PointsLedgerEntry
    - `debitPoints(accountId, amount, redemptionId, brondocument, verwerktDoor)` → PointsLedgerEntry
    - `expirePoints(accountId, amount, reason)` → PointsLedgerEntry (type="expiry")
    - `adjustPoints(accountId, amount, reason, verwerktDoor)` → PointsLedgerEntry (type="adjustment")
    - `getAccountBalance(accountId)` → sum of all ledger entries for account
    - `getLedgerHistory(accountId, from, to)` → list of PointsLedgerEntry
  - Atomic updates:
    - Fetch current balance
    - Create ledger entry with new balansNa
    - Update KlantLoyaltyAccount.currentBalance atomically
    - If update fails, rollback ledger entry
  - **acceptance_criteria**:
    - PointsLedgerEntry immutable after creation (no UPDATE allowed)
    - Balances always calculated from ledger (not stored)
    - Atomic credit → account balance update
    - All entries include timestamp, verwerktDoor, and brondocument
    - Ledger entries searchable/filterable by account, type, date range

- [ ] 2.3 Implement TierService (automatic reclassification)
  - **files**: `pipelinq/app/Service/TierService.php`
  - **spec_ref**: REQ-LOY-003
  - Methods:
    - `getTierRules(programmeId)` → list of TierRule sorted by sequence
    - `calculateTier(accountId, programmeId, lifetimePoints)` → TierRule matching threshold
    - `updateTierIfNeeded(accountId)` → checks current tier against rules, updates if needed
    - `handleTierUpgrade(accountId, newTierId)` → set currentTierId, tierBehaaldOp=now, tierGeldigTot per rule
    - `handleTierDowngrade(accountId, newTierId)` → set currentTierId, emit event for scheduled downgrade
    - `applyTierBenefits(ruleData)` → extract benefits (multiplier, exclusive offers) for points calculation
    - `emitTierChangedEvent(accountId, fromTierId, toTierId)` → CloudEvent with tier names and benefits
  - **acceptance_criteria**:
    - Tier lookup uses thresholdType (lifetimePoints / rollingPoints12m / jaarlijkseSpend)
    - Immediate upgrades happen synchronously
    - End-of-period downgrades scheduled as background job (not sync)
    - Tier benefits (multiplier) applied on next transaction
    - Tier change events emitted with full context (old tier, new tier, benefits)

## 3. Points Calculation & POS Integration

- [ ] 3.1 Implement PointsRuleEngine
  - **files**: `pipelinq/app/Service/PointsRuleEngine.php`
  - **spec_ref**: REQ-LOY-002
  - Methods:
    - `evaluateRules(programmeId, trigger, context)` → list of matching rules sorted by priority
    - `evaluateCondition(rule.conditie, context)` → boolean (filter by category, segment, day/time)
    - `calculatePoints(rule.formule, amount, tier)` → integer points awarded
    - `getHighestPriorityRule(rules)` → single rule to apply (non-cumulative)
    - `applyMaxPerCustomer(accountId, ruleId, pointsToAward, period)` → capped amount (respecting max)
  - Conditions (evaluateCondition must handle):
    - `{ excludeCategory: [...] }` — filter out transactions in these categories
    - `{ category: [...] }` — include only these categories
    - `{ segment: [...] }` — filter by customer segment (via klantbeeld-360 link)
    - `{ dayOfWeek: "tuesday" }` — only on specific days
    - `{ timeRange: "14:00-18:00" }` — only during specific hours
    - `{ channel: "online" | "offline" }` — filter by POS channel
  - Formulas (calculatePoints must handle):
    - `{ type: "fixed", value: 50 }` — 50 points flat
    - `{ type: "percentage", value: 1 }` — 1 point per EUR
    - `{ type: "stepped", brackets: [{ amount: 100, points: 50 }, ...] }` — tiered formula
  - **acceptance_criteria**:
    - Rules evaluated in priority order (lowest priority num = highest priority)
    - Highest-priority matching rule wins (no cumulative)
    - Conditions correctly filter (categories, segments, time, channel)
    - Points capped by maxPerCustomerPerPeriode if set
    - Tier multiplier applied before rounding
    - Non-integer results rounded down (floor)

- [ ] 3.2 Create POS transaction hook / event listener
  - **files**: `pipelinq/app/EventListener/PosTransactionCompletedListener.php` OR integration in `openconnector`
  - **spec_ref**: REQ-LOY-002
  - Trigger: When `pos-transaction-core` emits "transaction.completed" event
  - Handler:
    1. Extract transaction: amount, posChannel, productCategory, timestamp, posTerminalId
    2. Look up customer from transaction (phone, loyaltyCardId, email match)
    3. For each programme the customer is enrolled in:
       - Get or create KlantLoyaltyAccount
       - Evaluate PointsRuleEngine with trigger="purchase", context=transaction
       - If rule matches: creditPoints(accountId, points, ruleId, brondocument={transactionId})
       - Check if tier changed; emit tier-changed event if so
    4. On success: emit "loyalty.points.credited" CloudEvent
    5. On failure: log error, do NOT block POS transaction
  - **acceptance_criteria**:
    - Customer lookup works (phone, email, loyaltyCardId)
    - Rules evaluated and points awarded atomically with account update
    - Tier reclassification triggered after credit
    - Failure does not rollback or halt POS flow
    - Events emitted for downstream listeners (notifications, reporting)
    - Account disabled ("geblokkerd") is checked before awarding points (but POS succeeds)

## 4. Redemption Workflow

- [ ] 4.1 Implement RedemptionService
  - **files**: `pipelinq/app/Service/RedemptionService.php`
  - **spec_ref**: REQ-LOY-004
  - Methods:
    - `initiateRedemption(accountId, optionId)` → Redemption (status="gereserveerd")
      - Check: account balance >= option.kostenInPunten
      - Check: option is valid (geldigVan <= now <= geldigTot or geldigTot is null)
      - Check: perKlantLimiet not reached (query all Redemption for this account+option with status="gebruikt")
      - Debit points via PointsLedgerService
      - Generate unique beloningCode (UUID or merchant code format)
      - Create Redemption object with status="gereserveerd"
      - Emit "loyalty.redemption.initiated" event
    - `markRedemptionUsed(redemptionId, posTransactionId)` → update Redemption
      - Set status="gebruikt", used timestamp=now
      - Link posTransactionId if provided
    - `cancelRedemption(redemptionId)` → refund points, set status="geannuleerd"
    - `expireRedemption(redemptionId)` → set status="vervallen"
    - `getValidRedemptionOptions(accountId, programmeId)` → list of options customer can afford
  - **acceptance_criteria**:
    - Balance check is atomic (no race condition allowing over-redemption)
    - Per-customer limit enforced (query existing "gebruikt" records)
    - Expiry date check prevents use of expired options
    - Unique codes not reused
    - Failed debit prevents Redemption creation
    - Cancellation reverses ledger debit

- [ ] 4.2 Create POS redemption code validator endpoint
  - **files**: `pipelinq/app/Controller/RedemptionController.php`
  - **spec_ref**: REQ-LOY-004
  - Endpoints:
    - `POST /api/loyalty/redemption/{code}/validate` → check code is valid, return reward details
    - `POST /api/loyalty/redemption/{code}/use` → mark as used, confirm discount/reward applied
    - `GET /api/loyalty/redemption/options/{programmeId}` → list available options for a programme
  - Validate request (POS terminal auth token)
  - Return status, reward details, and discount/product to apply
  - **acceptance_criteria**:
    - Code validation is fast (< 500ms for POS terminal)
    - Invalid/expired codes rejected with clear error
    - Use endpoint idempotent (marking used twice is safe)
    - Auth required (POS terminal token or API key)

## 5. Gift Card Management

- [ ] 5.1 Implement GiftCardService
  - **files**: `pipelinq/app/Service/GiftCardService.php`
  - **spec_ref**: REQ-LOY-006, REQ-LOY-007
  - Methods:
    - `issueGiftCard(programmeId, initialBalance, expiryDays, channel)` → GiftCard (status="issued")
      - Generate unique serial (e.g., "GC-" + 8-digit number, check for duplicates)
      - Generate random PIN (e.g., 4-6 digits)
      - Hash PIN with bcrypt (cost 10)
      - Set initialBalance = currentBalance = amount
      - Set status="issued" (not "active" yet)
      - Calculate vervaltOp = now + expiryDays
      - Create GiftCardTransaction of type="issue"
    - `activateGiftCard(giftCardId, posTransactionId)` → change status to "active"
      - Called when POS transaction completes
      - Do NOT activate if POS transaction was cancelled
    - `redeemGiftCard(giftCardId, pin, amount)` → (balance, balanceAfter, transactionId)
      - Hash provided PIN, bcrypt_verify against stored hash
      - Check currentBalance >= amount (or allow partial)
      - Check status is "active" or "depleted"
      - Check vervaltOp > now
      - Deduct amount from currentBalance atomically
      - Create GiftCardTransaction of type="redeem" or "partial_redeem"
      - If currentBalance becomes 0, set status="depleted"
      - Return remaining balance to POS
    - `blockGiftCard(giftCardId, reason)` → set status="blocked"
    - `getCardDetails(giftCardId, pin)` → return balance (auth via PIN hash)
    - `refundGiftCard(transactionId, amount)` → increase balance, create "refund" transaction
  - **acceptance_criteria**:
    - Serial is globally unique (check for duplicates)
    - PIN hashed (never stored plaintext)
    - Status transitions are sequential: issued → active → (depleted or blocked)
    - Partial redemption supported (balance decreases, card remains active)
    - PIN verification uses bcrypt::verify
    - Expired cards (vervaltOp < now) rejected at redemption

- [ ] 5.2 Create gift card POS integration
  - **files**: `pipelinq/app/Controller/GiftCardController.php` OR pos-transaction-core hook
  - **spec_ref**: REQ-LOY-006, REQ-LOY-007
  - Endpoints:
    - `POST /api/loyalty/gift-card/validate` → check serial + PIN, return balance
    - `POST /api/loyalty/gift-card/redeem` → deduct amount, return balance + change
    - `POST /api/loyalty/gift-card/activate/{giftCardId}` → called after POS transaction finalizes
  - Integration with pos-transaction-core:
    - On transaction completion, call activate endpoint for any gift cards used
  - Handle split payment (if card balance < transaction amount):
    - Deduct full card balance
    - Return "outstanding balance = total - card balance" for POS to handle with card/cash
  - **acceptance_criteria**:
    - Validation response includes: valid (boolean), balance, expiryDate, remaining_uses (optional)
    - Redemption response includes: amountApplied, balanceAfter, changeAmount
    - Activation only happens on successful POS transaction
    - Failed POS transaction does NOT activate card
    - Partial redemption correctly reported (e.g., "EUR 20 of EUR 50 applied")

## 6. Expiry & Cleanup

- [ ] 6.1 Implement points expiry batch job
  - **files**: `pipelinq/app/Job/PointsExpiryBatchJob.php`
  - **spec_ref**: REQ-LOY-005
  - Scheduled: Daily at 02:00 UTC
  - Logic:
    1. For each active LoyaltyProgramme with expiry policy:
       2. Get expiry parameters (e.g., "12 months inactivity", "fixed annual")
       3. For each KlantLoyaltyAccount in the programme:
          - Calculate expiry eligibility (lastActivityDate, inactivity window, etc.)
          - For each PointsLedgerEntry eligible for expiry:
            * Create new PointsLedgerEntry of type="expiry" with the amount
            * Update KlantLoyaltyAccount.currentBalance
          - If points are expiring in exactly 30 days:
            * Queue notification: "Your X points will expire on YYYY-MM-DD. Use them now!"
  - **acceptance_criteria**:
    - Inactivity window calculated correctly (e.g., no transactions for 12 months)
    - Only eligible points are expired (not all points at once)
    - Expiry creates immutable ledger entries (never deletes)
    - 30-day advance notice queued via NotificationService
    - Batch idempotent (running twice on same day is safe)
    - Failed account does not block remaining accounts

- [ ] 6.2 Implement scheduled tier downgrade job (if applicable)
  - **files**: `pipelinq/app/Job/TierDowngradeJob.php`
  - **spec_ref**: REQ-LOY-003
  - Scheduled: Daily, checks for accounts with scheduled downgrade
  - Logic:
    1. Find all KlantLoyaltyAccount with tierGeldigTot <= today
    2. For each account:
       - Re-evaluate tier based on current lifetimePoints and downgrade rules
       - If tier should downgrade: call TierService.handleTierDowngrade()
       - Emit tier-changed event
  - **acceptance_criteria**:
    - Downgrades happen at the scheduled date/time
    - Current tier rechecked (in case customer earned more points)
    - Event emitted so notifications can be sent

## 7. Reporting & Analytics

- [ ] 7.1 Implement LoyaltyReportingService
  - **files**: `pipelinq/app/Service/LoyaltyReportingService.php`
  - **spec_ref**: REQ-LOY-008, REQ-LOY-009
  - Methods:
    - `getKpis(programmeId, from, to)` → object with all dashboard metrics:
      ```php
      {
        activeAccounts: 150,
        pointsIssued: 25000,
        pointsRedeemed: 10000,
        pointsExpired: 2000,
        breakagePercent: 8.0,
        redemptionRate: 40.0,
        programmeCostPercent: 5.5,
        tierDistribution: { 'Zilver': 1000, 'Goud': 150, 'Platina': 20 },
        cLVWithLoyalty: 850.50,
        cLVWithoutLoyalty: 620.00
      }
      ```
    - `getLiabilitySnapshot(programmeId)` → outstanding points + estimated liability
      ```php
      {
        outstandingPoints: 55000,
        estimatedLiability: 1100.00,  // based on point value (0.02 EUR/point)
        pointValue: 0.02,
        calculationDate: '2026-05-22'
      }
      ```
    - `getTierReport(programmeId)` → array of tiers with account counts and benefits
    - `getCLVComparison(programmeId, from, to)` → compare spending of loyalty vs. non-loyalty customers
    - `getExpiryForecast(programmeId, days=30)` → how many points will expire in next N days
  - Data aggregation (read-only, no writes):
    - Active accounts: count KlantLoyaltyAccount where status="actief"
    - Points issued: sum PointsLedgerEntry where type="credit" and timestamp in range
    - Points redeemed: sum PointsLedgerEntry where type="debit" (redemption source)
    - Points expired: sum PointsLedgerEntry where type="expiry"
    - Tier distribution: count KlantLoyaltyAccount grouped by currentTierId
  - Performance: Cache KPIs (daily refresh at 03:00 UTC after batch jobs)
  - **acceptance_criteria**:
    - KPIs calculated from immutable ledger entries (not denormalized fields)
    - Period filtering by date range works correctly
    - Calculations match business definitions (no rounding errors)
    - Liability calculated as sum(currentBalance) * pointValue
    - CLV comparison uses customer spend data from pos-transaction-core

- [ ] 7.2 Create reporting dashboard widget / view
  - **files**: `pipelinq/src/views/loyalty/LoyaltyReporting.vue` OR `mydash` integration
  - **spec_ref**: REQ-LOY-008
  - Display:
    - KPI cards (ActiveAccounts, PointsIssued, Redeemed, Expired, BreakageRate, RedemptionRate, Cost%)
    - Tier distribution chart (pie/bar)
    - CLV comparison chart
    - Liability snapshot widget
    - Period selector (last 30 days, 90 days, 12 months, custom)
    - Export to CSV/PDF button
  - Call LoyaltyReportingService.getKpis() on mount and when period changes
  - Format numbers with locale-aware thousands separators and currency (EUR)
  - **acceptance_criteria**:
    - Dashboard loads in < 2 seconds (uses cached KPIs)
    - All 9 KPIs displayed and formatted correctly
    - Charts responsive and readable on mobile
    - Period selector filters data correctly
    - Export includes all metrics and charts
    - Accessible (alt text, color contrast, keyboard navigation)

## 8. GDPR & Compliance

- [ ] 8.1 Implement GDPR data access & deletion
  - **files**: `pipelinq/app/Service/GdprService.php` or extend existing service
  - **spec_ref**: REQ-LOY-010
  - Methods:
    - `getCustomerLoyaltyData(klantId)` → full export of all loyalty objects linked to customer
      - KlantLoyaltyAccount records
      - PointsLedgerEntry (full history)
      - Redemption records
      - GiftCard records (issued to them)
      - Opt-in/terms acceptance records
    - `deleteLoyaltyData(klantId)` → GDPR soft-delete
      - Find all KlantLoyaltyAccount where klantId = provided ID
      - Anonymize klantId on all PointsLedgerEntry (set to null or hash)
      - Anonymize on all Redemption records
      - Mark GiftCard as "blocked" (not "deleted")
      - Do NOT delete ledger entries (keep for financial audit)
      - Create audit record of deletion request
  - **acceptance_criteria**:
    - Export includes all customer loyalty data in human-readable format
    - Deletion does not remove ledger entries (only anonymize)
    - Audit trail records deletion request and timestamp
    - No plaintext personal data in anonymized records
    - Cascading deletion handles all related entities

- [ ] 8.2 Add opt-in capture to account creation
  - **files**: `pipelinq/src/views/loyalty/AccountCreation.vue` or form dialog
  - **spec_ref**: REQ-LOY-010
  - Form field:
    - Mandatory checkbox: "I agree to store my loyalty data and contact me with offers"
    - Link to terms of service
    - Capture accepted: true/false, timestamp, terms version
  - Store opt-in record (e.g., new schema LoyaltyOptIn or metadata on KlantLoyaltyAccount)
  - Account creation fails if checkbox unchecked
  - **acceptance_criteria**:
    - Checkbox is visible and mandatory (form cannot submit if unchecked)
    - Opt-in timestamp recorded
    - Terms version recorded (for historical tracking of which version was accepted)
    - Account creation blocked until accepted

## 9. Testing & Verification

- [ ] 9.1 Write unit tests for core services
  - **files**: `tests/Unit/Service/PointsLedgerServiceTest.php`, etc.
  - **tier**: MVP (core functionality)
  - Test classes:
    - PointsLedgerService: credit, debit, balance calculation, immutability
    - TierService: tier lookup, upgrade/downgrade, benefits application
    - PointsRuleEngine: rule evaluation, condition filtering, points calculation
    - RedemptionService: initiate, mark used, cancel, expiry
    - GiftCardService: issue, activate, redeem, partial redemption, PIN hashing
  - Coverage targets: 80% of business logic
  - **acceptance_criteria**:
    - All tests green (0 failures)
    - Edge cases covered (insufficient balance, max reached, expired, etc.)
    - Atomicity verified (no race conditions in parallel inserts)
    - Immutability enforced (no UPDATE on ledger entries)

- [ ] 9.2 Write integration tests for POS flow
  - **files**: `tests/Integration/LoyaltyPosFlowTest.php`
  - **tier**: MVP
  - Test scenarios:
    - Complete flow: create account → make purchase → points awarded → tier change → redemption
    - Partial gift card redemption (card balance < transaction amount)
    - Expiry batch run (points expired, notification sent)
    - GDPR deletion (customer data anonymized, ledger retained)
  - **acceptance_criteria**:
    - End-to-end flows execute without error
    - Data integrity maintained (balances, ledger entries, audit trail)
    - Events emitted correctly
    - Notifications queued (not sent immediately)

- [ ] 9.3 Manual browser testing (via /run or test-app skill)
  - **files**: Test scenarios in `openspec/test-scenarios/` or manual checklist
  - **tier**: MVP
  - Scenarios:
    - Admin creates a new loyalty programme (name, tiers, rules, redemption options)
    - Customer enrolls in programme (opt-in checkbox checked)
    - Customer makes a purchase → points awarded on receipt
    - Customer views account (balance, tier, history)
    - Customer redeems points for discount
    - Customer uses gift card (full, partial, and overage scenarios)
    - Admin views reporting dashboard (KPIs, charts, period filter)
    - Admin exports report to CSV
  - **acceptance_criteria**:
    - All UI flows work without errors
    - Data displayed correctly (balances, tier name, transaction history)
    - Notifications sent (or visible in queue)
    - Exports contain expected data
    - Mobile responsive (if applicable)

- [ ] 9.4 Verify seed data loads correctly
  - **files**: `pipelinq/lib/Settings/pipelinq_register.json` (seed data section)
  - **spec_ref**: ADR-001 (seed data requirement)
  - On app install:
    - Run `ConfigurationService::importFromApp('pipelinq', data, version)`
    - Verify 5 LoyaltyProgramme objects created
    - Verify 5+ PointsRule objects created
    - Verify KlantLoyaltyAccount objects linked to mock customers (via klantId)
    - Verify seed data is idempotent (re-import does not create duplicates)
  - **acceptance_criteria**:
    - Seed data loads on install (no manual setup needed)
    - Mock objects are realistic and linked correctly
    - Objects appear in app (searchable, viewable in detail pages)
    - Re-import is idempotent

## 10. Documentation & Knowledge Base

- [ ] 10.1 Document programme setup guide
  - **files**: `docs/user-guides/loyalty-program-setup.md` or knowledge article
  - Content:
    - How to create a new programme (step-by-step)
    - How to define points rules (triggers, conditions, formulas)
    - How to set up tiers and benefits
    - How to add redemption options
    - How to manage gift cards (issue, activate, block)
    - How to view reporting dashboard and interpret KPIs
  - Format: Markdown with screenshots
  - **acceptance_criteria**:
    - Non-technical manager can follow guide to create a working programme
    - Screenshots match current UI
    - Examples provided (Dutch businesses: grocer, pizzeria, salon)
    - Troubleshooting section included (common issues, FAQ)

- [ ] 10.2 Document API reference (for integrators)
  - **files**: `docs/api/loyalty-program.md` or OpenAPI spec
  - Content:
    - REST endpoints for POS terminals (validate code, redeem, activate gift card)
    - Webhook events (points.credited, tier.changed, redemption.initiated)
    - Error codes and handling
  - Format: OpenAPI 3.0 spec (auto-generated from code comments)
  - **acceptance_criteria**:
    - All endpoints documented
    - Request/response examples provided
    - Auth requirements clear (API key, POS token, OAuth)
    - Integrators can build POS terminal client from spec

- [ ] 10.3 Create knowledge base articles (for agents/support)
  - **files**: `kennisartikel` objects in OpenRegister
  - Articles:
    - "How to help a customer enroll in loyalty programme"
    - "How to redeem points on the customer's behalf (manual redemption)"
    - "How to block a customer's account (fraud, dispute)"
    - "How to issue a replacement gift card"
    - "Why did my points expire?"
    - "How is my tier calculated?"
  - Format: Markdown, accessible language
  - Link to: pipelinq "loyalty" knowledge category
  - **acceptance_criteria**:
    - 5+ articles written and visible in knowledge base
    - Articles are searchable by agent
    - Articles include step-by-step instructions with screenshots
    - Tone is customer-friendly (not jargon-heavy)

## 11. Final Integration & Launch Checklist

- [ ] 11.1 Integration with openconnector (email/SMS notifications)
  - Verify notifications can be sent via openconnector for:
    - 30-day points expiry warning
    - Tier change (upgrade/downgrade)
    - Redemption confirmation
  - Test end-to-end (trigger event → notification queued → sent)

- [ ] 11.2 Integration with financeq (liability reporting)
  - Verify LoyaltyLiabilityService exports liability snapshot
  - financeq can import and display outstanding points liability on balance sheet
  - Test monthly export workflow

- [ ] 11.3 Integration with mydash (reporting widgets)
  - Loyalty KPI dashboard widget available in mydash
  - Period selector and drill-down links work
  - Export to PDF/CSV functional

- [ ] 11.4 Performance & load testing
  - Simulate 1000+ concurrent customers redeeming points
  - Verify response time < 500ms for POS redemption validation
  - Verify reporting dashboard KPI load time < 2s
  - Verify batch job (expiry) completes within SLA (< 5 min for 100K accounts)

- [ ] 11.5 Security review
  - Gift card PIN hashing verified (no plaintext in logs, database, network)
  - POS endpoint auth (API key) enforced
  - SQL injection / XSS prevention verified in controllers
  - Rate limiting on redemption endpoint (prevent brute-force code guessing)
  - GDPR deletion tested (no orphaned personal data)

- [ ] 11.6 Production checklist
  - Migrations run successfully on production DB
  - Seed data imported (or skipped if already exists)
  - All background jobs scheduled and running
  - Monitoring & alerting configured (expiry job failures, reporting latency)
  - Rollback plan documented (in case of critical issue)
  - Release notes drafted and reviewed

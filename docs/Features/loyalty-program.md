---
title: Loyalty Program
sidebar_position: 50
---

# Loyalty Program

Pipelinq ships a native loyalty engine for brands, retailers, horeca and webshops
that want a "stempelkaart" / points / gift-card system fully integrated with their
POS, customer (`klant`) and contact data — no third-party SaaS, no scattered data.

## What it does

- **Programmes per brand** — every brand/retailer runs its own programme with its
  own rules, tiers and rewards.
- **Points ledger** — every credit / debit / expiry / adjustment is recorded as
  an immutable `PointsLedgerEntry`. Balances are denormalised on the account but
  the ledger is always the source of truth (financial audit, RJ 270).
- **Tier classification** — `lifetimePoints`, rolling 12-month points or annual
  spend; upgrades fire immediately, downgrades respect a programme policy
  (`end_of_year` / `end_of_quarter` / `none`).
- **Redemption** — customers reserve a reward; points are debited atomically and
  a unique `beloningCode` is generated. POS validates and consumes.
- **Gift cards** — unique serial + bcrypt-hashed PIN (never plaintext). Supports
  full, partial and split redemption. PCI-DSS posture: no card number storage.
- **Reporting & liability** — programme KPIs (active accounts, points issued /
  redeemed / expired, breakage %, redemption rate, cost %), tier distribution,
  outstanding-points liability per IFRS 15 / RJ 270.

## How a programme manager sets one up

1. Open **Loyalty → Reporting** (admin menu). The dashboard is empty until
   programmes exist.
2. Create a `loyaltyProgramme` object via OpenRegister (or its CRUD API). Set
   `naam`, `merk`, `valuta` (default EUR), `pointValue` (default 0.01 EUR/point)
   and an `expiryPolicy` (e.g. `{type: "inactivityMonths", value: 12,
   advanceNoticeDays: 30}`).
3. Create one or more `pointsRule` objects for the programme. Pick a trigger
   (`purchase` is the most common), a `formule` (`{type: "percentage", value: 1}`
   = 1 point per EUR) and an optional `conditie`
   (`{excludeCategory: ["gift-card"]}` keeps points off gift-card purchases).
4. Create at least one `redemptionOption` (`kostenInPunten`, `beloningType`,
   `beloningWaarde`, optional `perKlantLimiet`).
5. Optionally create `tierRule` objects (Zilver=0, Goud=500, etc.) with
   `benefits.pointsMultiplier` to give higher tiers a boost.
6. POST to `/api/loyalty/programme/{programmeId}/activate` (admin-only). The
   server validates that rules and redemption options exist, then flips
   `status` to `actief`. A misconfigured programme stays in `concept`.

## How enrollment works (GDPR opt-in)

The **Loyalty → Enroll** view is a small form (`klantId`, programme, terms
version). The customer MUST tick "I agree to store my loyalty data and contact
me with offers" — the form will not submit otherwise. The acceptance flag,
timestamp and terms version are written to the `klantLoyaltyAccount` object as
`optInAccepted`, `optInTimestamp`, `optInTermsVersion`.

## How POS integration works

Whenever a `posTransaction` reaches `status` `completed` / `settled` / `paid`,
`PosTransactionCompletedListener` resolves the customer (`klantId` /
`customerId` / `contactUid` field) and asks `LoyaltyEngineService` to award
points. The engine:

1. Finds the customer's account in each active programme.
2. Evaluates `pointsRule` objects for the `purchase` trigger and the
   transaction context (amount, category, channel, segment, timestamp).
3. The highest-priority matching rule wins (non-cumulative).
4. The customer's current tier multiplier is applied; result is floored.
5. `maxPerKlantPerPeriode` is enforced against the ledger for the period
   (day / week / month / year).
6. `PointsLedgerService::creditPoints` writes an immutable ledger entry and
   updates the denormalised balance. The tier is re-evaluated; an upgrade fires
   immediately, a scheduled downgrade is queued via `TierDowngradeJob`.

Engine failures NEVER halt the POS flow — they are caught and logged.

## Gift cards in 30 seconds

- **Issue**: `GiftCardService::issueGiftCard($programmeId, $initialBalance,
  $expiryDays)` returns the new card object **and** the plaintext PIN (the only
  time it is ever returned). The PIN is bcrypt-hashed in storage.
- **Activate**: cards are issued in `status=issued` and activated on POS
  settlement (`POST /api/loyalty/gift-card/activate/{giftCardId}`).
- **Redeem**: `POST /api/loyalty/gift-card/redeem` with `{giftCardId, pin,
  amount, posTransactionId}`. Partial redemption is supported — the response
  carries `amountApplied`, `balanceAfter`, `changeAmount`.
- **Refund**: `GiftCardService::refundGiftCard` increases the balance and
  re-activates a depleted card.

## Expiry and notifications

`PointsExpiryBatchJob` runs daily. For every active programme with policy
`inactivityMonths`, accounts whose `lastActivityDate` is older than the window
have their balance moved to a `PointsLedgerEntry` of type `expiry`. Accounts
within `advanceNoticeDays` receive a notification ("Use your X points within Y
days").

## Reporting

`LoyaltyReportingService::getKpis($programmeId, $from, $to)` returns the
dashboard payload: active accounts, points issued / redeemed / expired,
breakage %, redemption rate, programme cost %, tier distribution, outstanding
points + estimated liability. `getLiabilitySnapshot` is the IFRS 15 / RJ 270
view; export it to your accounting tool.

## GDPR

- Subject access: `GET /api/loyalty/gdpr/{klantId}/export` returns every loyalty
  object linked to the customer (accounts, ledger, redemptions, gift cards).
- Deletion: `DELETE /api/loyalty/gdpr/{klantId}` anonymises `klantId` on accounts,
  ledger entries and redemptions; blocks the customer's gift cards. Ledger
  entries are NEVER deleted — they're retained for financial audit (RJ 270).

## API quick reference

| Verb   | Path                                                                | Auth          | What it does                       |
|--------|---------------------------------------------------------------------|---------------|-----------------------------------|
| GET    | `/api/loyalty/accounts/{accountId}`                                 | Authenticated | Account snapshot                  |
| GET    | `/api/loyalty/accounts/{accountId}/history`                         | Authenticated | Ledger history                    |
| GET    | `/api/loyalty/redemption/options/{programmeId}/{accountId}`         | Authenticated | Affordable options                |
| POST   | `/api/loyalty/redemption/initiate/{accountId}/{optionId}`           | Authenticated | Reserve points + return code      |
| POST   | `/api/loyalty/redemption/{code}/validate`                           | Authenticated | Validate (don't consume) a code   |
| POST   | `/api/loyalty/redemption/{code}/use`                                | Authenticated | Mark a code as used               |
| POST   | `/api/loyalty/gift-card/validate`                                   | Authenticated | Validate serial + PIN, get balance |
| POST   | `/api/loyalty/gift-card/redeem`                                     | Authenticated | Debit (partial / full / split)    |
| POST   | `/api/loyalty/gift-card/activate/{giftCardId}`                      | Authenticated | Activate after POS settlement     |
| POST   | `/api/loyalty/programme/{programmeId}/activate`                     | Admin         | Validate + activate a programme   |
| GET    | `/api/loyalty/reporting/{programmeId}/kpis?from=&to=`               | Authenticated | KPI dashboard payload             |
| GET    | `/api/loyalty/reporting/{programmeId}/liability`                    | Authenticated | IFRS 15 / RJ 270 snapshot         |
| GET    | `/api/loyalty/reporting/{programmeId}/tiers`                        | Authenticated | Tier distribution                 |
| GET    | `/api/loyalty/reporting/{programmeId}/expiry-forecast?days=30`      | Authenticated | Points scheduled to expire        |
| GET    | `/api/loyalty/gdpr/{klantId}/export`                                | Admin         | GDPR data subject export          |
| DELETE | `/api/loyalty/gdpr/{klantId}`                                       | Admin         | GDPR soft-delete (anonymisation)  |

## Events emitted

- `loyalty.points.credited` — fired after a successful credit; payload
  `{accountId, programmeId, aantal, balansNa}`.
- `loyalty.tier.changed` — fired on tier upgrade or downgrade; payload
  `{accountId, fromTierId, toTierId, toTierNaam, benefits}`.

External notifications (email/SMS) are wired by openconnector / launchpad on top
of these events.

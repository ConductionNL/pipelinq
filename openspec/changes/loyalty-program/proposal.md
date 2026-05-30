# Proposal: Loyalty Program

## Problem

Retailers, horeca operators, service providers, and webshops on pipelinq currently lack native
loyalty program functionality. Today they either:
- Integrate expensive third-party SaaS (LoyaltyLion, Smile.io, Piggy) through openconnector
- Build custom implementations per customer (expensive, fragmented)
- Use basic external tools (stempel-cards) that don't integrate with POS and customer data

This leaves customer-identifying data scattered across systems and blocks the MKB segment seeking
a native "stempel-kaart" replacement on mobile — no POS integration, no unified customer view.

## Proposed Change

Implement a native loyalty engine with:
- **Loyalty programmes** — define per-brand/retailer with configurable points rules, tiers, and redemption options
- **Points ledger** — append-only transaction history (purchase-triggered credits, redemptions, expirations)
- **Tier management** — automatic classification by lifetime/rolling-window points or annual spend
- **Redemption** — redeem points for discounts, free products, gift cards, or partner vouchers
- **Gift cards** — issue, redeem with partial balance tracking, PCI-DSS compliant (no card number storage)
- **Reporting** — programme economics (cost %, redemption rate, breakage), customer value (CLV), tier distribution

The engine is **multi-programme** — a holding with multiple brands can run separate programmes per brand
with optional cross-brand points pooling.

### Out of Scope

- V2: Cross-brand points pooling and balance transfers
- V2: Advanced tier rules (rolling windows, rolling 12-month calculation logic)
- V2: Integration with external loyalty SaaS (API bridge)
- Enterprise: Fraud detection and velocity rules
- Enterprise: Advanced redemption workflows (approval chains, manual adjustments)
- Enterprise: Custom point formulas with business rule engine

## Impact

- **New entities**: LoyaltyProgramme, PointsRule, TierRule, KlantLoyaltyAccount, PointsLedgerEntry,
  RedemptionOption, Redemption, GiftCard, GiftCardTransaction (9 schemas in OpenRegister)
- **Modified entities**: None — loyalty data is isolated
- **Schema changes**: 9 new OpenRegister schemas; no breaking changes
- **Cross-app integration**: pos-transaction-core (trigger), klantbeeld-360 (customer link),
  voucher-engine (redemption codes), openconnector (email/SMS), financeq (liability booking),
  launchpad (dashboards)
- **Risk**: Medium — introduces new data model with financial implications (IFRS 15 / RJ 270
  outstanding points liability); requires seed data, migrations, and audit trail integration
- **Feature tier**: MVP — core points, tiers, redemption, gift cards; reporting in V1.5
- **Target users**: Retail owners, marketing managers, horeca operators, webshop managers,
  financial admin (liability reporting), MKB entrepreneurs

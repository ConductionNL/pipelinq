---
status: draft
---

# POS Attach Customer to Ticket

## Purpose

Link transaction to pipelinq Contact; show purchase history; marketing consent.

## Priority & Demand

- **Priority:** P0-must
- **Demand evidence:** 13/13 competitors
- **Dependencies:** pos-transaction-core

## Cross-app integration

Customer becomes shillinq debtor when 'on account' tender is used.

## Competitor Evidence (from intelligence-db)

- chromis-pos :: Customer master + loyalty discount :: Customer card; tier discount
- chromis-pos :: Park ticket + resume :: Hold and resume sales
- chromis-pos :: Refund + void with permission :: Manager override for refund/void
- dvi-salonsoftware :: Afspraak naar kassa (1-klik) :: Reservering converteert naar kassabon
- dvi-salonsoftware :: Correctiefactuur + retourbon :: Correctie + creditfactuur NL-compliant
- dvi-salonsoftware :: Klantenkaart met behandelhistorie + kleurnotities :: Kleurformule, allergieen, voorkeuren
- dvi-salonsoftware :: Spaarpunten / cadeaubon module :: Eigen loyalty + cadeaubon uitgifte/inwisseling
- erpnext-pos :: Customer = ERP Customer doctype :: Single source customer; AR ledger view
- erpnext-pos :: Hold + retrieve invoice :: Park sale, pick up later, multi-checkout
- erpnext-pos :: Loyalty programme + coupons :: Points-per-rupee, redemption, expiry
- erpnext-pos :: Return invoice with stock back-in :: Negative invoice; stock + GL reversed
- korona-cloud :: Customer database + email lookup :: Master customer file; lifetime spend
- korona-cloud :: Customer loyalty (points, tiers, store credit) :: Configurable loyalty programmes; bonus credit
- korona-cloud :: Hold ticket + recall :: Park sale, resume on any register
- korona-cloud :: Returns, exchanges, partial refund :: Refund original tender; reason codes
- lightspeed-retail :: CRM + sale history per customer :: Customer-of-the-day, lifetime value
- lightspeed-retail :: Layby / layaway + deposits :: Partial pay over time; deposit tracking
- lightspeed-retail :: Loyalty (Lightspeed Loyalty add-on) :: Points programme, marketing campaigns, segmentation
- lightspeed-retail :: Workorders / service tickets (Retail X) :: Repair/service workflow, deposits, status
- mews-pos :: Guest profile linked to PMS :: Single source guest record across PMS + POS
- mews-pos :: Loyalty via PMS (Mews Loyalty) :: Stays + outlet spend in one programme
- mews-pos :: Split bill (per seat / per item / share) :: Multi-payer cheque split
- mews-pos :: Tabs + table-service mode :: Open table, transfer table, split bill
- odoo-pos :: Customer linked to ERP partner record :: POS partner = Odoo res.partner = invoiced customer
- odoo-pos :: Loyalty programme module (points, ewallet, discounts) :: Native Odoo loyalty; coupons, eWallet, gift cards

## Notes

This spec was triaged from market intelligence research dated 2026-05-20 covering 30 competitor implementations. See `/tmp/pipelinq-research-gap-report.md` for full landscape, feature coverage matrix, and risk analysis.

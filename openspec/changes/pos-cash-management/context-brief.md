---
status: draft
---

# POS Cash Drawer Management (float, drop, blind count, EOD)

## Placement & Information Architecture

**Placement type:** `SUB_PAGE` — Sub-page beneath a top-level menu entry. Renders as a page inside the parent surface (usually reachable via a router child route or a tab on the parent index page).

**Lives at:** Kassa → Kasbeheer

**Rationale:** Cash-drawer UI.  
_Source: /tmp/ia-pipelinq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

Open shift declared float, mid-shift drops, blind close count, cash diff report.

## Priority & Demand

- **Priority:** P0-must
- **Demand evidence:** 9/13 competitors
- **Dependencies:** pos-transaction-core

## Cross-app integration

Cash diff posts to shillinq as adjustment journal.

## Competitor Evidence (from intelligence-db)

- chromis-pos :: Cash + external terminal entry :: Manual amount confirm for pinpad
- chromis-pos :: Multi-tender per ticket :: Cash + card + voucher split
- chromis-pos :: Park ticket + resume :: Hold and resume sales
- chromis-pos :: Refund + void with permission :: Manager override for refund/void
- dvi-salonsoftware :: Afspraak naar kassa (1-klik) :: Reservering converteert naar kassabon
- dvi-salonsoftware :: Contant + PIN + factuur op rekening :: Meerdere betaalmethoden per bon
- dvi-salonsoftware :: Correctiefactuur + retourbon :: Correctie + creditfactuur NL-compliant
- dvi-salonsoftware :: PIN-koppeling CCV/Worldline :: Directe PIN-terminal integratie NL
- erpnext-pos :: Hold + retrieve invoice :: Park sale, pick up later, multi-checkout
- erpnext-pos :: Multiple Mode of Payment per invoice :: Configurable payment methods; cash, card, UPI, gift card
- erpnext-pos :: Razorpay / Stripe / Mpesa connectors :: India-strong; EU via Stripe; manual entry for local terminals
- erpnext-pos :: Return invoice with stock back-in :: Negative invoice; stock + GL reversed
- korona-cloud :: Cash management with float, drop, count :: Cash drawer float, mid-shift drops, blind count
- korona-cloud :: Hold ticket + recall :: Park sale, resume on any register
- korona-cloud :: Integrated payments (Worldline, Adyen, US gateways) :: EU-friendly integrations incl. Worldline/CCV; US gateways
- korona-cloud :: Returns, exchanges, partial refund :: Refund original tender; reason codes
- lightspeed-retail :: Integrated payments (Lightspeed Payments) :: Built-in card processing; chip/tap; surcharging
- lightspeed-retail :: Layby / layaway + deposits :: Partial pay over time; deposit tracking
- lightspeed-retail :: Workorders / service tickets (Retail X) :: Repair/service workflow, deposits, status
- mews-pos :: Charge to room / folio routing :: Post POS sale to guest folio in PMS
- mews-pos :: Integrated payments + 3DS :: Mews Payments; SCA compliant
- mews-pos :: Split bill (per seat / per item / share) :: Multi-payer cheque split
- mews-pos :: Tabs + table-service mode :: Open table, transfer table, split bill
- odoo-pos :: Adyen / Stripe / Six / Vantiv integrated terminals :: Multiple terminal integrations; EU + NL Adyen native
- odoo-pos :: Cash in/out + cash control journal :: Open/close cash drawer with declared amount; auto cash diff

## Notes

This spec was triaged from market intelligence research dated 2026-05-20 covering 30 competitor implementations. See `/tmp/pipelinq-research-gap-report.md` for full landscape, feature coverage matrix, and risk analysis.

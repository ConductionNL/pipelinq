---
status: draft
---

# POS Transaction Core (cart, lines, totals, taxes)

## Purpose

Cart entity with line items, qty, discount, tax breakdown, totals; lifecycle draft → confirmed → settled / refunded. Foundation of POS module.

## Priority & Demand

- **Priority:** P0-must
- **Demand evidence:** 13/13 competitors
- **Dependencies:** none

## Cross-app integration

Emits `pipelinq.PosTransaction.confirmed` event consumed by shillinq to draft a journal entry.

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

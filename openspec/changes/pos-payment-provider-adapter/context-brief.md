---
status: draft
---

# POS Pluggable Payment Provider (Mollie, CCV, Adyen, Stripe)

## Placement & Information Architecture

**Placement type:** `SETTING` — Setting under the app's Beheer/Admin/Configuration surface. Lives in the existing settings UI; no top-level menu entry.

**Lives at:** Beheer → Adapters

**Rationale:** Mollie/CCV/Adyen/Stripe config.  
_Source: /tmp/ia-pipelinq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

Adapter interface; ship Mollie (NL iDEAL/Bancontact) + CCV (NL PIN terminal) + Stripe.

## Priority & Demand

- **Priority:** P0-must
- **Demand evidence:** 13/13 competitors
- **Dependencies:** pos-transaction-core

## Cross-app integration

Settlement webhook still posts via pos-transaction-core.

## Competitor Evidence (from intelligence-db)

- chromis-pos :: Cash + external terminal entry :: Manual amount confirm for pinpad
- chromis-pos :: Multi-tender per ticket :: Cash + card + voucher split
- dvi-salonsoftware :: Contant + PIN + factuur op rekening :: Meerdere betaalmethoden per bon
- dvi-salonsoftware :: PIN-koppeling CCV/Worldline :: Directe PIN-terminal integratie NL
- erpnext-pos :: Multiple Mode of Payment per invoice :: Configurable payment methods; cash, card, UPI, gift card
- erpnext-pos :: Razorpay / Stripe / Mpesa connectors :: India-strong; EU via Stripe; manual entry for local terminals
- korona-cloud :: Cash management with float, drop, count :: Cash drawer float, mid-shift drops, blind count
- korona-cloud :: Integrated payments (Worldline, Adyen, US gateways) :: EU-friendly integrations incl. Worldline/CCV; US gateways
- lightspeed-retail :: Integrated payments (Lightspeed Payments) :: Built-in card processing; chip/tap; surcharging
- mews-pos :: Charge to room / folio routing :: Post POS sale to guest folio in PMS
- mews-pos :: Integrated payments + 3DS :: Mews Payments; SCA compliant
- odoo-pos :: Adyen / Stripe / Six / Vantiv integrated terminals :: Multiple terminal integrations; EU + NL Adyen native
- odoo-pos :: Cash in/out + cash control journal :: Open/close cash drawer with declared amount; auto cash diff
- salonized :: Mollie integration (iDEAL, Bancontact, cards) :: Native Mollie + Stripe; iDEAL native for NL
- salonized :: PIN/cash distinction at checkout :: NL pinbetaling vs contant tracked separately
- salonkee-pos :: Cash, card, gift card split tender :: Multi-tender per ticket
- salonkee-pos :: SumUp / Stripe integrated payments :: EU payment providers built-in
- shopify-pos :: Split tender + custom tenders :: Cash, card, gift card, store credit combos
- shopify-pos :: Tap to Pay on iPhone / contactless reader :: Shopify Tap to Pay (iOS/Android) + chip reader
- square-pos :: Card-present checkout (chip/tap/swipe) :: Square Reader for chip + contactless; built-in NFC on Square Terminal/Register
- square-pos :: Cash drawer + cash tendering :: Cash drawer kick on sale; cash drop/payout tracking
- square-pos :: Split tender (partial cash, partial card) :: Multiple payment methods on one ticket
- toast-pos :: Toast Payments (integrated) :: Bundled card processing; chip/tap/swipe; surcharge
- unicenta-opos :: Cash + card (manual or external terminal) :: Records sale; external pinpad with manual amount entry
- unicenta-opos :: Split payment + change calculation :: Multiple tenders per ticket

## Notes

This spec was triaged from market intelligence research dated 2026-05-20 covering 25 competitor implementations. See `/tmp/pipelinq-research-gap-report.md` for full landscape, feature coverage matrix, and risk analysis.

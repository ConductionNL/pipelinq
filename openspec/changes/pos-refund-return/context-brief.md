---
status: draft
---

# POS Refund + Return with Stock Back-In

## Placement & Information Architecture

**Placement type:** `SUB_PAGE+ACTION` (compound — implement all of the following):

- **`SUB_PAGE`** — Sub-page beneath a top-level menu entry. Renders as a page inside the parent surface (usually reachable via a router child route or a tab on the parent index page).
- **`ACTION`** — Action button or menu item on an existing surface. Implemented as a single button / context-menu entry that opens a modal/wizard or runs a backend operation — NOT a page.

**Lives at:** Kassa → Bonnen → Retour + Bon-detail action

**Rationale:** Retour-flow.  
_Source: /tmp/ia-pipelinq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

Refund line or whole ticket; reason code; reverse to original tender; restock controlled flag.

## Priority & Demand

- **Priority:** P0-must
- **Demand evidence:** 13/13 competitors
- **Dependencies:** pos-transaction-core

## Cross-app integration

Reverse journal entry + stock movement event to shillinq.

## Competitor Evidence (from intelligence-db)

- chromis-pos :: Park ticket + resume :: Hold and resume sales
- chromis-pos :: Refund + void with permission :: Manager override for refund/void
- dvi-salonsoftware :: Afspraak naar kassa (1-klik) :: Reservering converteert naar kassabon
- dvi-salonsoftware :: Correctiefactuur + retourbon :: Correctie + creditfactuur NL-compliant
- erpnext-pos :: Hold + retrieve invoice :: Park sale, pick up later, multi-checkout
- erpnext-pos :: Return invoice with stock back-in :: Negative invoice; stock + GL reversed
- korona-cloud :: Hold ticket + recall :: Park sale, resume on any register
- korona-cloud :: Returns, exchanges, partial refund :: Refund original tender; reason codes
- lightspeed-retail :: Layby / layaway + deposits :: Partial pay over time; deposit tracking
- lightspeed-retail :: Workorders / service tickets (Retail X) :: Repair/service workflow, deposits, status
- mews-pos :: Split bill (per seat / per item / share) :: Multi-payer cheque split
- mews-pos :: Tabs + table-service mode :: Open table, transfer table, split bill
- odoo-pos :: Order parking + split ticket + transfer :: Float order, split per item/seat, table transfer
- odoo-pos :: Refund + return to original journal :: Refund line items; auto stock return; correct VAT
- salonized :: Appointment to invoice (one click) :: Convert booked appointment to invoice automatically
- salonized :: Invoice edit + correctie-factuur :: Edit invoice + issue credit note for NL bookkeeping
- salonized :: Online booking widget for website :: Public booking page feeding into the POS pipeline
- salonkee-pos :: Appointment to checkout one click :: Booking auto-converted into POS cart
- salonkee-pos :: Refund + credit note :: Refund to original tender; credit note
- shopify-pos :: Buy online pickup in store (BOPIS) + ship from store :: Cross-channel order routing
- shopify-pos :: Returns with QR code / order lookup :: Online order returned in-store; refund to original tender
- shopify-pos :: Save cart and email cart link :: Sales associate emails cart to customer to complete online
- square-pos :: Open ticket / parked sale :: Save & resume ticket, table service, tabs
- square-pos :: Refund + partial refund to original tender :: Refund line item or whole ticket, EOD reconcile
- toast-pos :: Coursing + send/hold by seat :: F&B specific course timing

## Notes

This spec was triaged from market intelligence research dated 2026-05-20 covering 29 competitor implementations. See `/tmp/pipelinq-research-gap-report.md` for full landscape, feature coverage matrix, and risk analysis.

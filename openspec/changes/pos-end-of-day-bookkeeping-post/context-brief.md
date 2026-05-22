---
status: draft
---

# POS End-of-day Bookkeeping Post Pipeline

## Placement & Information Architecture

**Placement type:** `SUB_PAGE+CROSS_APP` (compound — implement all of the following):

- **`SUB_PAGE`** — Sub-page beneath a top-level menu entry. Renders as a page inside the parent surface (usually reachable via a router child route or a tab on the parent index page).
- **`CROSS_APP`** — Cross-app coordination — primary surface lives in another app. This spec contributes shared schema, services, or an integration entry rather than a UI surface in this app.

**Lives at:** Kassa → Einde-dag

**Rationale:** EOD-rapport + posting feed to shillinq.  
_Source: /tmp/ia-pipelinq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

Aggregate Z-report → outbound message → shillinq JournalEntry; retry with idempotency key. Defines the canonical POS↔ERP contract.

## Priority & Demand

- **Priority:** P0-must
- **Demand evidence:** 13/13 competitors
- **Dependencies:** pos-transaction-core, pos-cash-management

## Cross-app integration

Direct integration; shillinq.JournalEntry.post with idempotency on transaction UUID.

## Notes

This spec was triaged from market intelligence research dated 2026-05-20 covering 0 competitor implementations. See `/tmp/pipelinq-research-gap-report.md` for full landscape, feature coverage matrix, and risk analysis.

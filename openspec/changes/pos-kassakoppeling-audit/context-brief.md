---
status: draft
---

# POS Kassakoppeling-compliant Audit Log

## Placement & Information Architecture

**Placement type:** `DETAIL_TAB+SETTING` (compound — implement all of the following):

- **`DETAIL_TAB`** — Tab on the detail view of an existing object. NOT a standalone page — appears inside the parent record's detail surface (e.g. an extra tab on the existing detail header).
- **`SETTING`** — Setting under the app's Beheer/Admin/Configuration surface. Lives in the existing settings UI; no top-level menu entry.

**Lives at:** Kassa → Bon-detail → Audit + Beheer → Kassa

**Rationale:** Audit-tab on bon; export config in Beheer.  
_Source: /tmp/ia-pipelinq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

Append-only signed ledger of every register action (sale, void, refund, no-sale); hash chain; Belastingdienst export. **Unique NL moat — no OSS competitor packages this cleanly.**

## Priority & Demand

- **Priority:** P0-must
- **Demand evidence:** 5/13 competitors
- **Dependencies:** pos-transaction-core

## Cross-app integration

Audit log linkable to each shillinq journal entry via transaction UUID.

## Notes

This spec was triaged from market intelligence research dated 2026-05-20 covering 0 competitor implementations. See `/tmp/pipelinq-research-gap-report.md` for full landscape, feature coverage matrix, and risk analysis.

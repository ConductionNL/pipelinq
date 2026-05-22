---
status: draft
---

# Cross-app: Expense → shillinq AP

## Placement & Information Architecture

**Placement type:** `CROSS_APP` — Cross-app coordination — primary surface lives in another app. This spec contributes shared schema, services, or an integration entry rather than a UI surface in this app.

**Lives at:** Projecten & Tijd → Project-detail → Onkosten + Beheer → Integraties

**Rationale:** Bridge surfaced as detail-tab + status under integraties.  
_Source: /tmp/ia-pipelinq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

Captured expense in pipelinq lands as AP voucher in shillinq (employee reimbursement) or billable cost on project (pass-through).

## Priority & Demand

- **Priority:** P0-must
- **Demand evidence:** cross-app contract
- **Dependencies:** expense-capture-core

## Notes

This spec was triaged from market intelligence research dated 2026-05-20 covering 0 competitor implementations. See `/tmp/pipelinq-research-gap-report.md` for full landscape, feature coverage matrix, and risk analysis.

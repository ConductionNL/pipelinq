<!-- ⚠️ EXTENSION NOTICE (auto-inserted by fix_extension_artifacts.py)
     Parent capability: pipeline (Pipeline)
     This spec extends the existing `pipeline` capability. Do NOT define new entities or build new CRUD — reuse what `pipeline` already provides. Your job is to add configuration, seed data, or workflow templates on top of that capability.
-->

# Proposal: Lead Product Link

## Problem

Three market signals from tender analysis point to the same gap (combined demand score: 1,726):

1. **CRM with sales pipeline management and opportunity tracking** (demand: 1,664 — 550 tender mentions) — the market expects accurate pipeline valuation driven by product line items, not manual value entry. Without complete product-to-lead linking, Pipelinq cannot produce credible sales forecasts or stage-level breakdowns. This is the highest-demand feature in the Pipelinq backlog.

2. **Sales Pipeline** (demand: 40 — 1 tender mention) — sales managers need pipeline boards that reflect actual deal composition. Currently the pipeline board and list view show lead values but those values are decoupled from product line items: auto-recalculation only fires when a lead value is 0 or null, not on every line item change.

3. **Pipeline Breakdowns by Sales Stage** (demand: 22 — 1 tender mention) — buyers expect stage-level product value summaries so they can see which products are in each stage of their funnel and forecast revenue by product mix.

The existing `LeadProducts.vue` component covers basic CRUD for line items, but several V1 requirements from `specs/lead-product-link/spec.md` remain unimplemented:

- SKU search is unavailable in the Add Product dialog (name-only matching)
- The `notes` field on line items is saved but not displayed or editable inline in the table
- Lead value auto-recalculation only fires when the lead value is 0 or null, not on every line item change
- Pipeline stage columns show totals but do not include a product-value breakdown per stage
- Product detail has no "Linked Leads" section for product interest tracking

## Solution

Complete the V1 lead-product-link feature set:

1. **SKU search** — include SKU in product option labels and filter matching in the Add Product dialog
2. **Notes column** — display and inline-edit the `notes` field per line item in the product table
3. **Auto-recalculation** — always auto-update lead value when line items change unless a manual override exists, removing the 0/null guard
4. **Pipeline stage breakdown** — surface product-based value aggregation per pipeline stage on the pipeline board (top products by aggregate value per stage)
5. **Product interest tracking** — add a "Linked Leads" `CnDetailCard` section to the product detail view via reverse lookup

## Scope

- SKU included in product search dropdown option labels
- Notes column displayed and inline-editable in the line items table
- Full auto-recalculation: lead value syncs to line item total on any add/edit/remove, with manual override tracking
- Pipeline stage column product-value breakdown (hover/click reveals top products per stage)
- Product detail: linked leads count and list via `fetchUsed` reverse lookup on LeadProduct
- Seed data: `productCategory`, `product`, and `leadProduct` objects in `pipelinq_register.json`

## Out of scope

- Enterprise features: product-based lead scoring, cross-sell recommendations (separate Enterprise spec)
- Currency configuration (EUR assumed throughout)
- Multi-currency support
- Bulk product operations across multiple leads (deferred to V2)
- Copy products from another lead (deferred to V2)
- Lead deletion cascade for line items (handled by OpenRegister object lifecycle)
- Duplicate product warning on the same lead

# Proposal: add-product-and-prospect-widget

## Summary

Add a **Product** entity to Pipelinq and a **Prospect Discovery** dashboard widget that searches open company databases (KVK, OpenCorporates) for companies matching a user-configurable Ideal Customer Profile (ICP), excluding existing clients. This enables sales teams to manage what they sell (products/services), link products to leads for accurate pipeline valuation, and proactively discover new business opportunities from public data.

## Motivation

Pipelinq currently tracks clients, leads, and requests but has no concept of *what* is being sold. Without Products:
- Lead values are manual guesses with no breakdown by product/service
- Pipeline reporting cannot show revenue per product line
- There is no structured way to define the company's offerings

Additionally, finding new prospects is entirely manual — sales reps must search for companies themselves. A prospect discovery widget that matches companies from public registries against an ICP would surface warm leads automatically, directly on the dashboard.

## Affected Projects

- [x] Project: `pipelinq` — New Product + ProductCategory schemas, LeadProduct line item schema, Prospect widget (backend service + frontend), ICP admin settings, dashboard widget, register JSON update, repair step update

## Scope

### In Scope

- **Product entity**: CRUD for products/services with standard CRM fields (name, description, SKU, price, cost, category, type, status, pricing model)
- **Product Category**: Hierarchical product grouping (schema-level, simple parent/child)
- **Lead-Product linking**: Line items connecting products to leads with quantity, unit price, discount, total — replacing or supplementing the manual lead value field
- **Product list/detail views**: Standard CRUD UI matching existing Pipelinq patterns
- **ICP configuration**: Admin settings for defining the ideal customer profile (industry/SBI codes, company size range, location, company type)
- **Prospect discovery backend**: PHP service calling KVK Handelsregister Zoeken API and OpenCorporates API, filtering against existing clients
- **Prospect widget**: Dashboard widget showing top matching prospects with fit score, company details, and "Create Lead" action
- **Dashboard integration**: New widget in the dashboard layout alongside existing KPI cards

### Out of Scope

- Price Books / multi-currency pricing (Enterprise tier feature)
- Product variants/bundles (Enterprise tier)
- CPQ (Configure, Price, Quote) workflow
- Full lead scoring system (behavioral + firmographic) — only ICP-based prospect fit scoring
- Paid enrichment APIs (Apollo, ZoomInfo, Clearbit)
- Automated prospect-to-lead conversion (manual "Create Lead" button only)
- Quote/Invoice generation from products
- GLEIF/LEI ownership hierarchy traversal
- Wikidata enrichment

## Approach

1. **Register schema extension**: Add `product`, `productCategory`, and `leadProduct` schemas to `pipelinq_register.json`. Products use `schema:Product` type, categories use `schema:DefinedTermSet`, line items use `schema:Offer`.
2. **Frontend CRUD**: Product list + detail views following existing Client/Lead patterns. Product category management in admin settings.
3. **Lead-Product linking**: LeadProduct line items displayed in lead detail view with add/remove/edit. Auto-calculate lead value from sum of line item totals.
4. **Backend prospect service**: New `ProspectDiscoveryService` in PHP calling KVK Zoeken API (primary, NL companies) with OpenCorporates as fallback/international source. Results cached in APCu (TTL: 1 hour).
5. **ICP admin settings**: New settings tab for ICP criteria — stored via IAppConfig. Criteria: SBI codes (multi-select), employee count range (min/max), location (province/city), legal form.
6. **Prospect dashboard widget**: New Vue component fetching prospect results from backend, rendering as a card list with fit score, company name, SBI description, employee count, location, and a "Create Lead" action button.
7. **Existing client exclusion**: Match prospects against existing client KVK numbers or names to filter out paying customers.

## Capabilities

### New Capabilities
- `product-catalog` — Product entity, product categories, CRUD views, admin settings
- `prospect-discovery` — ICP configuration, external API integration (KVK/OpenCorporates), prospect widget, fit scoring
- `lead-product-link` — Line items connecting products to leads, value calculation

### Modified Capabilities
- `dashboard` — Add prospect discovery widget to the dashboard layout

## Cross-Project Dependencies

- **OpenRegister**: Requires register schema import support (existing). No changes needed to OpenRegister itself.
- **KVK API**: Requires API key (stored in admin settings via IAppConfig). The KVK Zoeken API has rate limits (100 results/page, authentication required).
- **OpenCorporates API**: Free tier available for open-data projects. API key optional for higher rate limits.

## Rollback Strategy

- Remove `product`, `productCategory`, and `leadProduct` schemas from register JSON
- Revert repair step to not create product-related default data
- Remove Product/Prospect Vue components and routes
- Remove ProspectDiscoveryService and related controllers
- Lead `value` field remains functional (was always user-editable, line items only supplement it)

## Open Questions

1. Should lead value be auto-calculated exclusively from line items, or should manual override remain possible? (Recommend: auto-calculate with manual override toggle)
2. Should the KVK API key be per-user or per-instance? (Recommend: per-instance, set by admin)
3. How many prospect results should the widget show? (Recommend: top 10 by fit score)
4. Should prospects be cacheable as a separate schema (for follow-up/tracking), or purely ephemeral API results? (Recommend: ephemeral first, cacheable later)

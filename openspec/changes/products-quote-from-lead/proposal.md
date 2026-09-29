---
kind: code
depends_on: [work-letter-from-filinq-template]
---

# Build a quote from your product list and send it to the client as a PDF

## Why

Prices, tiers, variants and VAT classes are built (`lib/Service/ProductCatalogService.php`, `POST /api/products/resolve-price`), and a lead already carries priced lines: the `leadProduct` schema has `quantity`, `unitPrice`, `discount` and a materialised `total`, and the lead detail page lists them. What is missing is the quote itself: nothing produces a document from those lines and nothing sends it. The archived change `product-catalog-quoting` (2026-03-21) left only its main spec, `openspec/specs/product-catalog-quoting`, and the matrix was corrected on 2026-09-28 to `none` because no change carries it. HubSpot and Odoo rate `yes`, so the rule builds it. This change is deliberately the smallest quote that a salesperson would use: pick products, produce a PDF, send it, record that it was sent. Quote versions, acceptance workflow and conversion to an order stay in the main spec as later work.

This change covers 1 row(s) of the parity matrix (`openspec/parity/capabilities.json`), decided `build` on 2026-09-28.

**prod-quote** (matrix `pipelinq`, area `products`), "Build a quote from your product list and send it to the client" Rated `partial` for us, `built.state` `none`.
- Demand: None. No demand row; the row comes from our own code or our own matrix.
- HubSpot CRM, yes: https://knowledge.hubspot.com/quotes/create-and-send-quotes quotes built from line items in the product library and sent to the buyer; "A Revenue Hub seat is required to create quotes" (Revenue Hub Professional, Enterprise); legacy quotes remain in Sales Hub Starter and up (https://knowledge.hubspot.com/quotes/use-e-signatures-w
- Pipedrive, partial: https://support.pipedrive.com/en/article/products "Once a product is linked, you can also generate quote documents that pull in its data" with Smart Docs; https://support.pipedrive.com/en/article/what-features-do-the-pipedrive-plans-have "Smart Docs: Included on Premium and higher plans", paid add-on on Lite and Growth. Partial 
- EspoCRM, partial: No Quote entity in core 10.0.8; paid Sales Pack: https://docs.espocrm.com/user-guide/quotes/ "When creating a new quote linked to an opportunity, it transfers opportunity items to the quote", with automatic numbering, PDF printing and sending by email. Note: Paid Sales Pack; changed from yes under the paid extension rule.
- Odoo CRM, yes: addons/sale_crm/views/crm_lead_views.xml:10 "New Quotation" from the opportunity opens a sales order with product lines priced by the pricelist, and addons/sale/models/sale_order.py:1069 action_quotation_send behind "Send by Email" (addons/sale/views/sale_order_views.xml:291) mails the PDF and portal link to the client.
- Matrix note: The prices and the tiers are built. Producing a quote document from them is specified and not built. Corrections round 9 (2026-09-28): built.state specified to none, because no change carries the quote document. The archived openspec/changes/archive/2026-03-21-product-catalog-quoting left only the main spec openspec/specs/product-catalog-quoting, which says quote generation and PDF export are not 

## What changes

- On the lead detail page, adding a line picks a product from the catalogue and fills the unit price, unit and VAT rate from the price resolver for that quantity.
- A "Send quote" action renders the lead's lines to a PDF through filinq, stores it in the sender's Files, mails it to the lead's contact (or client) with the PDF attached, and logs an outbound contact moment.
- The quote shows a quote number, a valid-until date, lines with VAT per rate, and totals excluding and including VAT.
- If filinq is not installed the action is hidden, not disabled.

## Capabilities

### Modified capabilities
- `product-catalog-quoting`: adds the smallest working slice of the quote (lines from the catalogue, PDF, send, log).

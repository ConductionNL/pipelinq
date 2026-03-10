## Context

Pipelinq has leads with pipeline stages and a product catalog with line items (LeadProduct). When a lead reaches "Proposal" or "Negotiation" stage, sales reps need to produce formal quotations. Currently there is no quotation entity — reps must use external tools to create quotes, breaking the CRM workflow.

The product catalog (V1) and lead-product link (V1) specs already define products and line items on leads. The quotation system builds on these by allowing reps to generate a formal, versioned document from a lead's products with additional quotation-specific fields (validity, terms, numbering).

**Current state**: Lead → LeadProduct line items → (manual external quoting) → Won/Lost
**Target state**: Lead → LeadProduct line items → Quotation(s) → Accepted/Rejected → Won/Lost

## Goals / Non-Goals

**Goals:**
- Enable creating quotations from leads with pre-populated line items
- Track quotation lifecycle (draft → sent → accepted → rejected → expired)
- Generate PDF exports of quotations for client distribution
- Display linked quotations on the lead detail view
- Auto-increment quotation reference numbers
- Support standalone quotations (not linked to a lead)

**Non-Goals:**
- Invoice generation (post-sale, out of scope for CRM)
- E-signature integration (future enhancement)
- Quotation approval workflows (internal approval chains)
- Multi-currency quotations (single currency per quote, matching lead currency)
- Template designer (admin-customizable PDF layouts — future)
- Email sending from within the app (use Nextcloud Mail or download PDF)

## Decisions

### 1. Quotation as OpenRegister object with `schema:Offer`

**Decision**: Store quotations as OpenRegister objects with `@type: schema:Offer`.

**Rationale**: Schema.org `Offer` represents "an offer to transfer some rights to an item or to provide a service" — exact match for a sales quotation. It provides `price`, `priceCurrency`, `validFrom`, `validThrough`, `itemOffered` mappings. Consistent with the existing pattern where Lead uses `schema:Demand` (demand side) and Quotation uses `schema:Offer` (supply side).

**Alternative considered**: `schema:Invoice` — rejected because an invoice implies a completed transaction; a quotation is a proposal.

### 2. QuotationLineItem as separate entity (not embedded)

**Decision**: Store line items as separate OpenRegister objects (`schema:OfferItem` → closest: `schema:Offer` with `schema:itemOffered`) referencing the parent quotation by UUID.

**Rationale**: Matches the existing LeadProduct pattern. Separate objects allow independent CRUD, pagination for large quotes, and audit trail per line item. OpenRegister handles the relationship via UUID references.

**Alternative considered**: Embedded array on quotation object — rejected because it prevents independent line item audit trails and complicates concurrent editing.

### 3. Copy-on-create from LeadProduct (not live reference)

**Decision**: When creating a quotation from a lead, copy LeadProduct line items into QuotationLineItems. The quotation line items are independent from that point.

**Rationale**: A quotation is a snapshot in time. If lead products change after quoting, the existing quotation must remain unchanged. This is standard CRM behavior (HubSpot, Salesforce).

### 4. PDF generation via server-side HTML-to-PDF

**Decision**: Generate quotation PDFs on the backend using a PHP HTML-to-PDF approach. The backend renders an HTML template with quotation data and converts it to PDF.

**Rationale**: Keeps PDF generation server-side where it's reliable across browsers. The thin-client architecture means minimal backend, but PDF generation is a legitimate backend concern. Use `dompdf` (pure PHP, no system dependencies) which is already available in the Nextcloud ecosystem.

**Alternative considered**: Frontend-only PDF (jsPDF/html2canvas) — rejected due to inconsistent rendering across browsers and inability to match print-quality layouts.

### 5. Auto-increment numbering with configurable prefix

**Decision**: Quotation numbers use format `{prefix}-{year}-{sequence}` (e.g., `Q-2026-00042`). The sequence auto-increments per year. Prefix and starting number are configurable in admin settings.

**Rationale**: Industry standard pattern. Year-based reset keeps numbers manageable. Prefix allows organizations to distinguish quotation types or departments.

### 6. Quotation status lifecycle

**Decision**: Five statuses: `draft`, `sent`, `accepted`, `rejected`, `expired`.

- `draft`: Being composed, editable
- `sent`: Sent to client, still editable (can revise)
- `accepted`: Client accepted — triggers prompt to move lead to "Won"
- `rejected`: Client rejected — no automatic lead change
- `expired`: Past validity date without acceptance

**Rationale**: Maps to standard sales quotation workflows. Kept simple (no sub-statuses). Expiration is checked on access (compare `validUntil` with current date).

### 7. No separate quotation pipeline

**Decision**: Quotations do NOT have their own pipeline/kanban board. They are managed via list view and accessed from lead detail.

**Rationale**: Quotations are tied to leads. Adding a separate pipeline would create confusion about where to track the sales process. The lead pipeline already handles workflow stages. Quotation status is a property filter on the list view.

## Risks / Trade-offs

- **[PDF library dependency]** → dompdf is pure PHP with no system dependencies. If rendering quality is insufficient, can upgrade to wkhtmltopdf later without changing the data model.
- **[Quotation-lead coupling]** → Quotations can exist without a lead (standalone), but the primary flow is lead-driven. Risk: standalone quotations may get less UX polish. Mitigation: ensure list view works well for both cases.
- **[Line item duplication]** → Copying from LeadProduct means data duplication. Mitigation: this is intentional (snapshot semantics). No sync mechanism needed.
- **[Numbering gaps]** → If a draft quotation is deleted, its number is not reused. This is acceptable and standard practice (gaps in numbering are normal for audit purposes).
- **[Expiration check performance]** → Checking `validUntil < now` on every access could be slow at scale. Mitigation: check only on detail view load and list render; optionally add a cron job to batch-update expired status for large installations.

## Migration Plan

No migration needed — this is a net-new feature. The repair step will:
1. Add `quotation` and `quotation-line-item` schemas to the pipelinq register
2. Add admin settings defaults (numbering prefix, validity period)
3. No existing data is modified

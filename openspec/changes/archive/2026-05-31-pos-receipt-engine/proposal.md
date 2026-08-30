# Proposal: POS Receipt Engine (print + email)

## Problem

Pipelinq's POS transaction system has no receipt capability — transactions are registered and settled but customers receive no proof of purchase. Competitors (13/13 surveyed) all provide:

1. **Customizable receipt templates** — retailers customize header/footer, company details, and field layout per location without code changes
2. **Thermal printer output** — ESC/POS driver integration for standard thermal printers (Star, Epson, AURA)
3. **Email receipts** — email delivery to customer with configurable sender and template
4. **Legal invoice format** — transactions ≥ EUR 100 must print as a legal invoice with BTW breakdown and compliance metadata per Dutch VAT rules

The absence of receipts blocks full POS checkout flows, prevents customer reconciliation, and creates a non-compliance gap for high-value sales that require formal invoices.

## Solution

Implement a POS Receipt Engine with three components:

1. **Template system** — Twig/Jinja templates stored as OpenRegister `receiptTemplate` objects with configurable layout, fields, header/footer, company branding
2. **Printer driver** — ESC/POS printer controller (`PosReceiptPrinter` service) with support for line width, font weight, barcode encoding, and cut commands
3. **Email bridge** — `PosReceiptMailer` service integrated with Pipelinq's email service (`pipelinq-mailer`) to send rendered receipts as PDFs or plain-text
4. **Print & Email workflow** — UI buttons on transaction detail to Trigger print, email, or both; job queue for batch operations
5. **Legal invoice mode** — when transaction ≥ EUR 100, receipt auto-renders BTW breakdown and compliance footer using `invoiceMode: true` flag

## Scope

- `receiptTemplate` schema (CRUD, version control, test/production toggle)
- `PosReceiptPrinter` service: ESC/POS command generation, device state polling
- `PosReceiptMailer` service: Twig template rendering, PDF generation, SMTP submission
- Receipt detail view: template selector, print preview, device status
- Transaction detail actions: "Print Receipt" and "Email Receipt" buttons
- Admin settings: configure default template, printer IP/port, email sender address
- Seed data: 3 Dutch receipt templates (standard, invoice-mode, F&B)
- Job queue integration for batch email operations

## Out of scope

- Fiscal machine / RKSV integration (Enterprise)
- Barcode label printing from product catalog
- SMS receipt delivery (V2)
- Receipt archival and reprinting from history (V1 allows reprint of current transaction only)
- Multi-printer routing (kitchen display, prep tickets) — separate change
- Payment terminal integration — separate change
- Stock-driven reprinting (requires inventory module)

## Success Criteria

- A transaction ≥ EUR 100 generates a legal invoice receipt with BTW breakdown and footer
- A transaction < EUR 100 generates a simple receipt without invoice mode
- Receipt template can be customized with custom fields and re-ordered without code changes
- Print button sends receipt to a thermal printer and returns status (success / device offline / error)
- Email button renders receipt and submits to Pipelinq Mail for delivery
- Each printed/emailed receipt is logged to `receiptPrintLog` for compliance audit

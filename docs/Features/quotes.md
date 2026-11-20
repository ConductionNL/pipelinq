# Quotes from a lead

Send a client a quote built from the products on a lead. filinq turns the lead's lines into a PDF, a copy lands in your Files, pipelinq mails the PDF to the client, and the quote shows on the lead's activity as an outgoing contact moment.

## Specs

- `openspec/specs/product-catalog-quoting/spec.md`
- `openspec/changes/products-quote-from-lead/specs/product-catalog-quoting/spec.md`

## Send a quote

1. Open a lead and add its products under **Products on this lead**.
2. Choose **Send quote** at the top of the lead.
3. Pick the quote template and the date until which the quote holds. Without a date it holds for thirty days.
4. Check who the quote goes to. The dialog names the recipient before you send.
5. Press **Send quote**.

The quote goes to the lead's contact person. When the contact person has no email address, it goes to the client. When neither has one, the dialog asks you to add an email address first, and nothing is sent.

Every quote gets its own number, such as `OFF-2026-0042`. The numbers count up per year. The quote shows each VAT rate on the lines with its net amount, VAT and gross amount, and the totals excluding and including VAT.

A sent quote does not change. If you change a line afterwards and send again, you get a new PDF with the next number. The earlier quote and its PDF stay as they were.

The contact moment on the lead is titled after the quote, for example "Quote OFF-2026-0042". It names the recipient, the total, the valid-until date and where the copy is filed.

If the mail cannot be sent, the dialog says so. The PDF is already in your Files, and no contact moment is logged.

## Without filinq

Quotes are made by filinq. When filinq is not installed, **Send quote** is not shown on the lead. A direct call to the quote endpoint answers 503 and names filinq.

## For administrators: write the quote template

Write the quote template in filinq's template editor.

- Set the namespace to `pipelinq` and the category to `quote`. Templates in the `pipelinq` namespace appear in the dialog. pipelinq ships no quote template.
- The template is Twig. It reads these keys:
  - `lead`: the lead, for example `{{ lead.title }}`.
  - `client` and `contact`: the client and the contact person, for example `{{ client.name }}` and `{{ contact.email }}`.
  - `lines`: the lead's lines as they were when the quote was sent. Each line has `name`, `quantity`, `unit`, `unitPrice`, `discount` and `total`, and its product under `product`, for example `{{ line.product.sku }}`.
  - `totals`: `totals.rates` lists each VAT rate with `rate`, `net`, `vat` and `gross`; `totals.totalExclVat`, `totals.totalVat` and `totals.totalInclVat` hold the totals.
  - `quote`: `quote.number`, `quote.date`, `quote.validUntil` and `quote.recipient` (`email`, `name`).

A table of the lines, then one row per VAT rate:

```twig
{% for line in lines %}{{ line.name }}  {{ line.quantity }} × {{ line.unitPrice }}  {{ line.total }}
{% endfor %}
{% for rate in totals.rates %}VAT {{ rate.rate }}%: {{ rate.vat }}
{% endfor %}
Total excluding VAT {{ totals.totalExclVat }}, including VAT {{ totals.totalInclVat }}
```

## API

| Method | URL | Purpose |
|--------|-----|---------|
| GET | `/api/leads/{id}/quotes/recipient` | Who a quote for this lead would go to |
| POST | `/api/leads/{id}/quotes` | Send a quote: `templateId`, optional `validUntil` (`Y-m-d`) |

The lead is read with your own rights. A lead you cannot read answers 404. A lead without a recipient answers 422, and a render or mail that failed answers 502.

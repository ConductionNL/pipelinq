# Proposal: contract-figures

## Why

Board PqContract's three tiles read "Terugkerende waarde € 3.500, per maand, uit € 42.000 per jaar", "Dagen tot einddatum 85, opzegdatum 1 nov" and "Dit jaar gefactureerd € 42.000, 1 van 1 jaarfacturen verstuurd". Live summed the value per period, counted down to the end date and showed the contract value. Nothing recorded more than the last send to invoicing, so this year's invoicing could not be read.

## What changes

- Every send to invoicing is kept on the contract (`invoiceHistory`: reference, sentAt, sentBy, amount), beside the last one (`invoicedAt`, `invoicedBy`, DQ-L3b's contract-billing-as-drawn).
- `GET /api/contracts/{id}/figures` (ContractFiguresService): monthly and annual value, the notice date (end less the notice period) and days to the end, this year's invoiced amount, the invoices sent and the invoices the billing interval makes due within the term. Owner or privileged users only, like the other contract endpoints.
- The three tiles read it, with captions that use formatted tokens (nc-vue stat-caption-formats).

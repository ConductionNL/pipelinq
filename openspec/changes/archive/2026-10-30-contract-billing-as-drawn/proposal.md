# Send an expiring contract to invoicing, and show the billing as drawn

## Why

PqContract draws an expiring contract with "Naar facturering sturen" in its header and a Facturering card: the billing fields, the invoice reference with a copy button and the line "Op 2 januari 2026 naar facturering gestuurd door Pieter Jansen", and the line items as a table. Live hid the button, because only an active contract could be sent, although an expiring contract runs and bills until its end date. It also listed the reference and the lines as raw fields, and recorded neither when the contract went to invoicing nor who sent it.

## What changes

- "Send to invoicing" is available on a running contract: active or expiring. Draft and the terminal statuses still refuse it.
- A successful send also writes `invoicedAt` and `invoicedBy` (salesContract 1.1.0, `register.d/99-zz-contract-invoiced-by.json`).
- The contract's Overview shows the billing fields, the invoice reference with a copy button and its sent line, and the line items as a table (product, description, quantity, unit value).

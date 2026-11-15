---
kind: code
---

## Why

A quote's subtotal, VAT and total and a lead's value are read by lists, the pipeline board and the quote pdf, but nothing on the server wrote them when a line changed. The quote schema says the totals are "stored as the editor last computed them", and the line table in the form (adopt-library-child-records-lines) shows totals without handing them to the parent. Decision 356: the server works them out.

## What Changes

- `LineTotalsListener` (ObjectCreated, ObjectUpdated, ObjectDeleted) hands every saved or deleted object to `LineRollUp`, which acts on quote lines (`salesQuoteLine`) and lead lines (`leadProduct`) only.
- A quote line change writes the quote's `subtotal` (sum of quantity x unit price less the discount in percent, per line), `vatAmount` (at the quote's `vatRate`, 21 when empty) and `total`. A quote without lines gets 0.
- A lead line change writes the lead's `value` as the sum of the line totals OpenRegister calculated. A lead without lines keeps the value typed by hand.
- `salesQuoteLine_schema` is written at import, so the listener can tell a quote line.

## Impact

- A value typed by hand on a lead with lines stays until the next line change; the "Use calculated value" hint is not built.
- Needs a fresh import before `salesQuoteLine_schema` exists; until then quote lines are not rolled up.

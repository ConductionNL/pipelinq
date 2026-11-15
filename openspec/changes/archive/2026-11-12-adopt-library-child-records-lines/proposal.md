---
kind: code
---

## Why

The boards PqOfferte and PqLead draw the lines of a quote and of a lead as a table you edit in place: pick a product, change the quantity, price and discount, and see the line total and the sum. nextcloud-vue now offers this as `CnChildRecordsField` (the `child-records` form widget), with computed columns, totals and adding rows from a product search.

## What Changes

- `salesQuote` and `lead` gain `lines`, an inverse relation (`inversedBy`) to their `salesQuoteLine` and `leadProduct` objects. The quote and lead form now show the lines as a table and save them as their own objects after the parent; nothing is stored on the parent.
- A quote line's total is worked out in the table (quantity times unit price, less the discount in percent) and stored on the line. The table sums the lines into the subtotal excl. VAT.
- A lead line's total stays the server calculation (`x-openregister-calculations`, discount as percent or amount). The table sums the saved line totals.
- `leadProduct` gains `name`, the product name the table shows.
- Register 1.13.0 -> 1.14.0; salesQuote 1.2.0, lead 1.6.0, leadProduct 1.2.0.

## Impact

- The register needs a fresh import before the form shows the table.
- The quote's own `subtotal`, `vatAmount` and `total` and the lead's `value` are not written by the form; the form's totals are shown only. The quote and lead pages keep their line lists.
- The VAT line is not in the table: the VAT rate lives on the quote, and a table total cannot read the parent.

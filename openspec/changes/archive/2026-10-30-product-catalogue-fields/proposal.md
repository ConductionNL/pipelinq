---
kind: code
---

# Proposal: product-catalogue-fields

## Summary

Board PqProduct draws a fee table, a publication card and a weight in grams that the product schema could not hold. Product schema 1.1.0 adds generic, optional fields:

- `tariffs` (label, price, validFrom, note) and `tariffSource`: the fee table. The page shows per label the tariff in force today and names the day the next tariffs go live.
- `publishedAt`, `languages` (two-letter codes) and `audience` (resident, business): the publication card, a data widget beside status.
- `weightGrams`: OpenRegister stores a `number` with two decimals, so 0.005 kg is lost. The supply card shows grams under a kilogram, from `weightGrams` first, else from `weight`.

Not added, on purpose:

- Stock count: pipelinq keeps no copy of stock (products-stock-on-hand, D3); the Stock tracked line reads shillinq. The board's "1.860 blanco kaarten" is shillinq data.
- Images: the "Images & spec sheets" card lists the files attached to the product; front and back images are attached files.
- Supplier: `vendor` exists.

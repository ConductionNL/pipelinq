---
kind: code
---

## Why

The till (PqKassa, PqKassaAfrekenen) priced a sale in the browser. `posTotals.js` copied `PosTransactionService::recalculateLine` and `computeTotals`, the till showed and stored the browser's line VAT and line total, and handed the browser's total to the payment dialog and the gift card balance. The server recomputed only the sale totals on confirm. Receipts, refunds and analytics read the stored line figures, so a browser figure reached the books. Build round 4 (decision 371): the server is the only place that prices a sale.

## What Changes

- `PosLinePricingListener` (ObjectCreating, ObjectUpdating) writes the VAT and total of every posTransactionLine as it is stored, in its sale's price display, through `PosTransactionService::recalculateLine`. A figure a client sends is overwritten in the same write.
- `POST /api/pos-transactions/{id}/recalculate` (`PosCartController`, `PosCartRepricer`) prices an open sale: lines whose stored figures differ from the server's are stored again (the price display changed), and the sale's totals and VAT per rate are stored through `PosTransactionService::recalculateTotals`. It returns the sale and its lines. Only the sale's cashier, the POS group or an admin may call it, and only on a draft or parked sale.
- The till stores each line change as an object, calls recalculate and shows only the server's figures: line totals, VAT per rate, totals, the payment total, the receipt preview and the balance of a sold gift card. A new sale is stored as a draft at its first line. The sale header is saved without totals.
- `recalculateLine`, `computeTotals`, `rateDescription` and `normalizePriceMode` leave `src/services/posTotals.js`.

## Impact

- The confirm step is unchanged: it recomputes from the stored lines, so the booked CloudEvent, the BTW report and the day totals read the same code as before. A golden test holds the figures development produced for four sales (mixed rates, incl. and excl., gift card, rounding).
- A sale abandoned after its first line stays as a draft.
- The till table stays the till's own: nextcloud-vue CnChildRecordsField has no cell sub-line, no currency or percent display for plain columns and no save per row with the server's figures written back.

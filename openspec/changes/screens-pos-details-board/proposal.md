# Proposal: screens-pos-details-board

## Why

The drawn boards are the UI canon. The transaction (PqKassabon), cash shift (PqKassadienst) and Z-report (PqZRapport) pages were grids of cards with stat tiles; the boards draw them on the relation frame: a breadcrumb, a status pill, one tab strip and a side column.

## What changes

- Each page is named by its reference, shows its status as a pill and a breadcrumb back to its list.
- One tab strip (Overview, History) fills the body. Overview stacks the main cards: the transaction data, lines and payment; the shift data, the cash difference and the drops; the Z-report data and the bookkeeping card.
- The side column holds the totals and related records (transaction), the opening float, drop count and related records (shift), and the VAT breakdown and payment methods (Z-report), each above the history.
- The Z-report section can render one block at a time (`part`), so its bookkeeping, VAT and payment blocks are cards of their own (`ZReportBookkeepingWidget`).

Nothing is removed: the stat tiles that repeated fields of the record (the Z-report day total, VAT and transaction count, the transaction's line count) are dropped because the data card and the lines table show the same values.

# Proposal: pos-shift-summary

## Why

Boards PqKassadiensten and PqKassadienst draw per shift what the shift record does not hold: the number of drops, the
cash difference, an outcome pill (Open, Goedgekeurd, Binnen de marge, Buiten de marge), the margin, the amount taken out
of the drawer, the sales booked in the shift and the Z report of its till on its day.

## What changes

- `GET /api/pos-shifts/summary/{measure}?keys[]=` answers one measure (dropCount, dropTotal, difference, outcome) per
  shift, in the shape a list column's endpoint aggregate reads.
- `GET /api/pos-shifts/{id}/summary` answers the page summary: drops, difference, margin, outcome, sales, Z reports.
- The shifts list, the shift card, the opening float card and the related card read them. The cash difference card
  draws its figures, a band only outside the margin, and an inline rejection reason.

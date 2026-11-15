# Proposal: card-table-heads-contacts-zreport

## Why

pl8 table head and ground items, checked per table:
- PqTicketContactmomenten 4: the ticket's contact moments table is the library's object list; the board draws a sort
  and a filter button in every head (head row 42.5px, live 37.5px), as PQ-T9 found on the other card tables.
- PqZRapport 7/8: the VAT and payment tables are the app's own markup; the board draws them at 14px on the normal line
  with 12px row padding, 18px outer insets and a rule under every row, live drew 15px on a 1.5 line with a rule between
  rows only.

## What changes

- The ticket's contact moments object list sets `sortable` and `filterable`.
- The z-report tables take the board's row text, padding and rules.

## Not in this change

- PqVerbindingscontrole 4 and PqServices 4 are index pages: the Pq boards inset the table in the card where the Dq
  index boards draw it flush with 16px insets, as live does (board call, dossiq wins).
- PqSocial 3/6 (title weight 600 vs 700, the sorted head label's ink) are library table looks.

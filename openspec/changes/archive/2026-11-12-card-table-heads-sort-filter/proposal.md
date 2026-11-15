# Proposal: card-table-heads-sort-filter

## Why

pl8 on boards PqLoyaliteit, PqOperationeel, PqOfferte and PqKassa (1 Nov): the framed card tables draw a head row of
37.5px, the boards 42.5px. The boards draw a sort button and a filter button in every head cell; live heads are text
only. Probed on :8080: the Klantoverzicht head with the library's sort and filter buttons measures 42.5px, as the
Klachten table next to it, which already sorts and filters.

## What changes

- Loyalty reporting "Distribution over tiers" and the operational "Client Overview" tables sort and filter from their
  heads (`sortable`, `filterable` on the object-table widget).
- The quote "Lines" list sorts and filters from its heads (`sortable`, `filterable` on the object-list widget).
- The till's purchase history and payments tables sort and filter the rows they hold from their heads
  (`localHeaders`, `filterable`, sortable columns).

## Not in this change

- The till's line editor: the board draws a read table, users keep the editor.
- The payments table's third column (change) and widths: separate items.

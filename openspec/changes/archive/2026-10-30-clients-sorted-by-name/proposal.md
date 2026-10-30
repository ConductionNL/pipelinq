# Proposal: clients-sorted-by-name

## Why

pl4 on board PqOrganisaties: the board draws the client list sorted by name, A to Z, with Naam as the sorted column
(main ink, solid chevron). Live opened the list unsorted, so the Naam head stayed in the muted head ink and the rows
came in storage order.

## What changes

- The Clients page opens sorted by name, ascending (`sortKey: name`, `sortOrder: asc`). A column the user sorts by
  still wins.

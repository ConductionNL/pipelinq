# Proposal: product-page-board-rest

## Why

pl3 PqProduct, after pq-b5/product-page-as-board: the side card read "Verzending" where the board draws "Levering"
("Delivery" is "Verzending" in the catalogue; "Supply" is "Levering"), the tiers and variants tables had the compact
grid head (15/400 and 13/400) where the board draws the data-table head with sort and filter controls, the product card
showed the description the board leaves out, the publication sat in two columns where the board draws three, Related
drew object and file tabs with a count and an add button where the board draws links, and the history listed read
events.

## What changes

Manifest config only: the supply card is titled Supply, tiers and variants are data tables whose heads sort and filter,
the product card lists the board's ten fields, the publication sits in three columns, Related shows links naming the
category without files or an add footer, and both history views drop read events.

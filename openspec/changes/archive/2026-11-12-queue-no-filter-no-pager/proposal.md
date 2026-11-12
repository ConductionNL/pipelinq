# Proposal: queue-no-filter-no-pager

## Why

pl6 on board PqWachtrij (30 Oct): live still draws a Filter button beside the kind lenses and ends the list card in
"9 van 9 · Oudste eerst. ..." with "1 Volgende". The board, and the page's own note, have neither: a queue is worked
from the top, and it ends in the note alone.

## What changes

- The simple profile's queue turns its filter sidebar off (`sidebar: { enabled: false }`), so the toolbar has no
  Filter button. The full profile keeps it.
- The queue sets `pagerOnOnePage: false` (nextcloud-vue index-footer-note-on-one-page): on one page the card ends in
  its note alone. With more tickets than a page the count and the pages come back. Before the library change the key
  is ignored.

## Not in this change

- The rows as a list instead of a table (the live list is still a table without heads, its title column narrow).

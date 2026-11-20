# Proposal: queue-rows-board

## Why

pl4 on board PqWachtrij: each queue row ends in "In behandeling nemen" and an eye, and the list has no search field,
no "Ticket toevoegen" and no column filters. Live drew a three-dot menu per row, a search field, the add button and a
funnel on every column head. The page's `inlineActionCount: 2` only reaches the page header.

## What changes

- The simple profile's queue sets `rowInlineActions: 2` (nextcloud-vue index-row-inline-actions): Take it on as a pill
  and View as an eye (`iconOnly`) in the row, no menu.
- `search: false`, `showAdd: false` and `headerFilters: false`: a queue is worked from the top, a ticket comes in
  through its channel.

## Not in this change

- The type lenses as a segmented control inside the list card, with the channel strip under them: the index page draws
  its quick filters above the list.
- The row look without column heads and the footer note inside the card.
- The third tile "Binnen de termijn 9 van 12".

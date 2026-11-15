---
kind: code
screens: [LijstBord]
---

## Why

The LijstBord board draws the questions and reports list as a board by status: one column per status, a card per
ticket, and a drag that changes the status. On /tickets the Bord segment was drawn disabled ("not available in this
list") because the page named no board. The lenses the board draws (all open, mine, unassigned, waiting for the
customer, past the deadline) were not there either; the list only had a tab per ticket type.

## What changes

- The tickets list offers table, cards and board. The board's columns come from `status`; a "Columns:" switch also
  offers type, channel and handler. Type and channel are read-only columns.
- A drop runs `moveTicketStatus`: it checks the move against the ticket lifecycle (`requestStatus.js`, the rules the
  ticket page already uses) and writes the status. A move the lifecycle does not allow is refused with a sentence and
  the card goes back. `ticketStatusTargets` tells the board where a card may go, so other columns fade during a drag.
- The lenses are All open (default), Mine, Unassigned, Waiting for customer and Past the deadline. The ticket type
  tabs stay, after them, so nobody loses the filter.
- The page title follows the board: "Questions and reports".

The board shows every status the enum holds. Hiding the legacy statuses and folding the closing ones waits on
nextcloud-vue `board.states` (board-declared-states), which this change adopts once it is released.

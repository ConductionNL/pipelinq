# Tasks: tickets-board-view

### Task 1: Board view and lenses on the tickets list
- **spec_ref**: `openspec/changes/tickets-board-view/specs/unify-ticket-supertype/spec.md#requirement-the-tickets-list-opens-as-a-board-by-status`
- **files**: `src/manifest.json`, `src/utils/ticketBoardMoves.js`, `src/registry.js`
- [x] Implement: `viewModes`, `board` with `columnFields`, `moveTicketStatus`, `ticketStatusTargets`, lenses
- [x] Test: `tests/vitest/ticketsBoardView.spec.js`

### Task 2: Declared columns
- [x] Adopt nextcloud-vue `board.states` (board-declared-states) once released: New, In progress, Waiting for customer, Completed, then Converted and Rejected folded; Resolved and Closed hidden
  - done: `board.states` on the Tickets board in `src/manifest.json` (nextcloud-vue ^2.180 ships it): the eight ticket statuses in that order, Converted and Rejected `closed`, Resolved and Closed `hidden`; `tests/vitest/ticketsBoardView.spec.js` asserts the order, the folded and hidden ones, and that the list matches the ticket schema's status enum.

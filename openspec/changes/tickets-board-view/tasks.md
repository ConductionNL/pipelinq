# Tasks: tickets-board-view

### Task 1: Board view and lenses on the tickets list
- **spec_ref**: `openspec/changes/tickets-board-view/specs/unify-ticket-supertype/spec.md#requirement-the-tickets-list-opens-as-a-board-by-status`
- **files**: `src/manifest.json`, `src/utils/ticketBoardMoves.js`, `src/registry.js`
- [x] Implement: `viewModes`, `board` with `columnFields`, `moveTicketStatus`, `ticketStatusTargets`, lenses
- [x] Test: `tests/vitest/ticketsBoardView.spec.js`

### Task 2: Declared columns
- [ ] Adopt nextcloud-vue `board.states` (board-declared-states) once released: New, In progress, Waiting for customer, Completed, then Converted and Rejected folded; Resolved and Closed hidden

# Tasks: walk-in-queue-page

### Task 1: The walk-in queue page
- **spec_ref**: `openspec/changes/walk-in-queue-page/specs/appointment-booking/spec.md#requirement-a-walk-in-operator-works-the-queue-on-a-manifest-page`
- **files**: `src/manifest.d/80-appointment-booking-admin.json`
- [x] Implement: a manifest page over walkInTicket with call next, serve and abandon
  - Built: page `WalkInQueue` at `/walk-in` in `src/manifest.d/81-walk-in-queue.json` (an index over `walkInTicket`, today's tickets oldest first, header action Call next and row actions Call, Help and Left through `createWalkInHandlers` in `src/services/walkInQueue.js`), not in `80-appointment-booking-admin.json`.
- [ ] Test: a vitest over the page config, and an e2e walk through the queue
  - Vitest built: `tests/vitest/walkInQueue.spec.js` and `tests/vitest/walkInQueueFailures.spec.js` (Call next calls the longest waiting ticket, Help serves, Left abandons, a failed write is reported, the page config). Left: the e2e walk through the queue, which needs a live instance.

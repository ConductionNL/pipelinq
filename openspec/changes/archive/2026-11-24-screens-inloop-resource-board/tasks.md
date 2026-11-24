# Tasks: screens-inloop-resource-board

This change was built without a task list. Checked against development on 24 Nov 2026 before archiving.

- [x] 1. The walk-in queue and the resource page follow their boards
  - Code: `src/manifest.d/81-walk-in-queue.json` with `src/services/walkInQueue.js`, `lib/Settings/register.d/99-zz-walk-in-board.json`, `src/views/bookings/ResourceDetail.vue`
  - Tests: `tests/vitest/walkInQueue.spec.js`, `tests/vitest/walkInQueueFailures.spec.js`, `tests/vitest/resourceDetailBoard.spec.js`; `tests/e2e/walk-in-queue.spec.ts` written, runs in nightly

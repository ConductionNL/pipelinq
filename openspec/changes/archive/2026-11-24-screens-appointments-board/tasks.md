# Tasks: screens-appointments-board

This change was built without a task list. Checked against development on 24 Nov 2026 before archiving.

- [x] 1. The appointments list reads as its board (REQ-APT-BOARD-001)
  - Code: `lib/Settings/register.d/99-zz-appointment-board.json` (subject, location, assignee, status and source labels), the list in `src/manifest.d/80-appointment-booking-admin.json` (chips Today, This week, Mine, Unconfirmed; the board's columns)
  - Tests: `tests/vitest/appointmentsBoard.spec.js`, `tests/vitest/bookingTime.spec.js`
- [x] 2. The appointment page keeps its actions in view (REQ-APT-BOARD-002)
  - Code: `src/components/bookings/BookingHeaderActions.vue` (the five status actions, disabled while they do not apply); Send reminder in `src/components/bookings/BookingDetailSection.vue` on the When and where card
  - Tests: `tests/vitest/bookingHeaderActionsAvailability.spec.js`, `tests/vitest/bookingDetailSectionActions.spec.js`
  - Decision (24 Nov 2026): the requirement first put Send reminder beside the row. The round 2 and 3 pixel check against board PqAfspraak (commit ee82fd41) moved it to the When and where card, where the board draws it. The board is the canon, so the requirement now says the card.
- [x] 3. The reschedule and cancel dialogs say what happens (REQ-APT-BOARD-003)
  - Code: `src/dialogs/RescheduleBookingDialog.vue`, `src/dialogs/CancelBookingDialog.vue`
  - Tests: `tests/vitest/rescheduleSlotsBoard.spec.js`, `tests/vitest/rescheduleBookingDialogMounted.spec.js`

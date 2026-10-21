# Tasks: pl2-booking-header-actions

### Task 1: Declared booking header actions
- **spec_ref**: `openspec/changes/pl2-booking-header-actions/specs/appointment-booking/spec.md#requirement-the-booking-header-declares-its-time-gated-actions`
- **files**: `src/manifest.d/80-appointment-booking-admin.json`, `src/registry.js`
- [x] Implement: headerActions and primaryActionByStage on BookingDetail; BookingHeaderActions, RescheduleBookingDialog and CancelBookingDialog deleted
- [x] Test: `tests/vitest/bookingHeaderActions.spec.js`, e2e `tests/e2e/spec-coverage/appointment-booking.spec.ts`

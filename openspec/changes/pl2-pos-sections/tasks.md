# Tasks: pl2-pos-sections

### Task 1: Cash drawer list as configuration
- **spec_ref**: `openspec/changes/pl2-pos-sections/specs/pos-cash-management/spec.md#requirement-a-shift-is-opened-from-the-cash-drawer-list`
- [x] Implement: `src/manifest.json` CashShifts index; CashShiftList.vue and CashShiftOpenDialog.vue deleted
- [x] Test: `tests/vitest/posSections.spec.js`

### Task 2: Refund totals as a facts card
- **spec_ref**: `openspec/changes/pl2-pos-sections/specs/pos-refund-return/spec.md#requirement-refund-totals-show-what-the-server-booked`
- [x] Implement: `src/manifest.json` pos-refund-totals; PosRefundTotalsWidget.vue deleted
- [x] Test: `tests/vitest/posSections.spec.js`

### Task 3: Booking context as a facts card
- **spec_ref**: `openspec/changes/pl2-pos-sections/specs/appointment-booking/spec.md#requirement-the-booking-context-names-the-service-and-the-customer`
- [x] Implement: `src/manifest.d/80-appointment-booking-admin.json` booking-context; BookingDetailSection.vue replaced by BookingAssignments.vue
- [x] Test: `tests/vitest/posSections.spec.js`

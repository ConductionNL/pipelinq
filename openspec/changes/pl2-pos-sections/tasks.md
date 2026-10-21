# Tasks: pl2-pos-sections

### Task 1: Cash drawer list as configuration
- **spec_ref**: `openspec/changes/pl2-pos-sections/specs/pos-cash-management/spec.md#requirement-a-shift-is-opened-from-the-cash-drawer-list`
- [x] Implement: `src/manifest.json` CashShifts index; CashShiftList.vue and CashShiftOpenDialog.vue deleted
- [x] Test: `tests/vitest/posSections.spec.js`

### Task 2: Refund totals as a facts card
- **spec_ref**: `openspec/changes/pl2-pos-sections/specs/pos-refund-return/spec.md#requirement-refund-totals-show-what-the-server-booked`
- [x] Implement: `src/manifest.json` pos-refund-totals; PosRefundTotalsWidget.vue deleted
- [x] Test: `tests/vitest/posSections.spec.js`

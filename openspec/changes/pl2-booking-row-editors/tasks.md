# Tasks: pl2-booking-row-editors

### Task 1: Booking rows on CnSubObjectsField
- **spec_ref**: `openspec/changes/pl2-booking-row-editors/specs/appointment-booking/spec.md#requirement-booking-rows-are-edited-with-the-library-row-editor`
- **files**: `src/views/bookings/ResourceDetail.vue`, `src/views/bookings/ServiceDetail.vue`, `src/utils/resourceValidation.js`, `src/services/serviceSteps.js`
- [x] Implement: row overrides, create through the edit dialog; BookingRowsEditor, ResourceHoursEditor, ResourceVacationsEditor, ServiceStepsEditor, ResourceForm and ServiceForm deleted
- [x] Test: `tests/vitest/serviceFormSteps.spec.js`, `tests/vitest/serviceStepProductNames.spec.js`

# Tasks: booking-and-service-pages

## 1. Service composition (E5)

- [x] 1.1 `multiStep[]` items: `productId`, `quantity`, `unit`; schema version 1.1.0
  - Verify: `node scripts/check-schema-l10n.js` exit 0; live, a saved step with product, quantity and unit reads back
- [x] 1.2 `src/services/serviceSteps.js` (units, labels, amount text) and ServiceStepsEditor product, quantity and unit columns
  - Verify: `tests/vitest/serviceSteps.spec.js`
- [x] 1.3 ServiceDetail: Edit steps in the composition card; Service information and Policies side by side
  - Verify: live, Edit steps, add a product step, Save, the table shows it

## 2. Booking page (E6, E7)

- [x] 2.1 BookingDetailSection renders one part; notes editor and audit table removed; status changes in the timeline
- [x] 2.2 Register BookingContextWidget, BookingAssignmentsWidget, BookingTimelineWidget
- [x] 2.3 BookingDetail manifest: tabs Timeline, Resources, Notes (notes leaf), Documents; no body section
  - Verify: live, every tab renders on a demo booking
- [ ] 2.4 Swap the Timeline tab to the library `timeline` widget once nextcloud-vue releases it

## 3. Text

- [x] 3.1 English and Dutch strings, `npm run l10n:build`
  - Verify: `npm run test:l10n` exit 0

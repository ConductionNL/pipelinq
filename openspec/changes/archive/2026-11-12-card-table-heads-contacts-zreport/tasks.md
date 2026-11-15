# Tasks: card-table-heads-contacts-zreport

### Task 1: Sort and filter heads on the ticket contact moments
- **spec_ref**: `openspec/changes/card-table-heads-contacts-zreport/specs/dashboard-widgets/spec.md#requirement-the-ticket-contact-moments-table-sorts-and-filters-from-its-heads`
- **files**: `src/menu-layout.simple.json`
- [x] Implement: `sortable` + `filterable` on ticket-contactmoments
- [x] Test: `tests/vitest/cardTableHeadsContactsZreport.spec.js`

### Task 2: Board table rows on the z-report
- **spec_ref**: `openspec/changes/card-table-heads-contacts-zreport/specs/dashboard-widgets/spec.md#requirement-the-z-report-tables-draw-the-board-table-rows`
- **files**: `src/components/pos/ZReportBookkeepingWidget.vue`
- [x] Implement: 14px normal line, 12px rows with 18px outer insets, rule under every row
- [x] Test: `tests/vitest/cardTableHeadsContactsZreport.spec.js`

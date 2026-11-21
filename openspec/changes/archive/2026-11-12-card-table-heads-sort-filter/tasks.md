# Tasks: card-table-heads-sort-filter

### Task 1: Sort and filter buttons in the card table heads
- **spec_ref**: `openspec/changes/card-table-heads-sort-filter/specs/dashboard-widgets/spec.md#requirement-card-tables-sort-and-filter-from-their-heads`
- **files**: `src/manifest.d/70-loyalty-program.json`, `src/manifest.json`, `src/manifest.d/82-quotes.json`, `src/views/pos/PosTransactionForm.vue`
- [x] Implement: `sortable` + `filterable` on the three widgets; `localHeaders` + `filterable` + sortable columns on the till's two tables
- [x] Test: `tests/vitest/cardTableHeadsSortFilter.spec.js`

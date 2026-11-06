# Tasks: pl2-pos-header-actions

### Task 1: Declared POS header actions
- **spec_ref**: `openspec/changes/pl2-pos-header-actions/specs/pos-lifecycle-guard-adoption/spec.md#requirement-the-pos-header-shows-one-primary-action-per-state`
- **files**: `src/manifest.json`, `src/registry.js`, `src/modals/PrintReceiptModal.vue`, `src/modals/EmailReceiptModal.vue`, `src/services/receiptTemplates.js`, `src/icons.js`
- [x] Implement: headerActions, primaryActionByStage and quickActions on the three pages; the three components and three dialogs deleted
- [x] Test: `tests/vitest/posHeaderActions.spec.js`

### Task 2: A count needs an amount
- **spec_ref**: `openspec/changes/pl2-pos-header-actions/specs/pos-cash-management/spec.md#requirement-a-shift-is-counted-only-with-an-amount`
- **files**: `lib/Controller/CashShiftController.php`
- [x] Implement: 422 without a numeric amount
- [x] Test: `tests/Unit/Controller/CashShiftControllerTest.php`

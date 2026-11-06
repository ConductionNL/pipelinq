# Tasks: tickets-bulk-assign

### Task 1: Assign the selected tickets
- **spec_ref**: `openspec/changes/tickets-bulk-assign/specs/unify-ticket-supertype/spec.md#requirement-the-tickets-list-assigns-a-selection-to-one-handler`
- **files**: `src/manifest.json`, `l10n/*`
- [x] Implement: bulk action `assign` opening CnAssignDialog with `bulkJob { register: pipelinq, schema: ticket }`
- [x] Test: `tests/vitest/ticketsBulkAssign.spec.js`

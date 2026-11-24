# Tasks: advanced-hub-and-connection-audit-board

This change was built without a task list. Checked against development on 24 Nov 2026 before archiving.

- [x] 1. The Advanced page is a links page at /advanced with the board's groups
  - Code: `src/manifest.d/99-advanced.json`
  - Tests: `tests/vitest/advancedAndConnectionAudit.spec.js`
- [x] 2. The follow audit is a board index over the stored rows
  - Code: index page over `socialConnection` in `src/manifest.d/79-marketing-search-intelligence.json` (stat counts, quick filters, columns, reason), the `auditVerdict` cell formatter and the Check again handler in `src/services/connectionAudit.js`
  - Tests: `tests/vitest/advancedAndConnectionAudit.spec.js`, `tests/vitest/searchQueriesRendered.spec.js`
- [x] 3. Retire the custom page
  - `src/views/marketing/ConnectionAudit.vue`, its `ConnectionAuditView` registry entry and `tests/vitest/connectionAuditPage.spec.js` are removed (24 Nov 2026); no manifest page used them since the board index took over.

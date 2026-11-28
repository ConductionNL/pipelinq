# Tasks: advanced-hub-status-panel

This change was built without a task list. Checked against development on 24 Nov 2026 before archiving.

- [x] 1. The Advanced cards show their status and the panel the menu
  - Code: `src/manifest.d/99-advanced.json` (card statuses, panel facts), `src/utils/menuRuntime.js`, wired in `src/main.js`
  - Tests: `tests/vitest/menuRuntime.spec.js`, `tests/vitest/advancedAndConnectionAudit.spec.js`

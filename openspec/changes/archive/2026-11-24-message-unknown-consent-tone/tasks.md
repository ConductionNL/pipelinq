# Tasks: message-unknown-consent-tone

This change was built without a task list. Checked against development on 24 Nov 2026 before archiving.

- [x] 1. An unknown consent reads as unknown
  - Code: consent line in `src/modals/SendMessageModal.vue` (ok tone and check only for `opted-in`, warning tone otherwise; sending rules unchanged)
  - Tests: `tests/vitest/sendMessageBoardDialog.spec.js`

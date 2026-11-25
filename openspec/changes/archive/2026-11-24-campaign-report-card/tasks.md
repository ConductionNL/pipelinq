# Tasks: campaign-report-card

This change was built without a task list. Checked against development on 24 Nov 2026 before archiving.

- [x] 1. The campaign page draws its report as a card
  - Code: embedded card styles in `src/views/marketing/CampaignReport.vue` (padding, border, radius, tiles that grow)
  - Tests: `tests/vitest/campaignReportEmbedded.spec.js`
  - Note: The proposal kept the model label above its select; campaign-page-board-cards later put it beside the select as board PqCampagne draws, and the spec names neither, so the code follows the later change.

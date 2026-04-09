# Proposal: Locale-Aware Formatting

## Problem
Currency and date formatting across all Vue components is hardcoded to `nl-NL` locale. The spec requires formatting to follow the user's Nextcloud locale.

## Solution
Create a shared `localeUtils.js` utility that detects the user's Nextcloud locale and provides `formatCurrency()` and `formatDate()` helpers. Update all components to use these shared helpers instead of hardcoded `nl-NL`.

## Scope
- `src/services/localeUtils.js` — new shared formatting utility
- Update `Dashboard.vue`, `LeadList.vue`, `LeadDetail.vue`, `MyWork.vue`, `PipelineBoard.vue`, `PipelineCard.vue` and widget files to import shared helpers
- No backend changes needed — locale detection uses `OC.getLocale()`

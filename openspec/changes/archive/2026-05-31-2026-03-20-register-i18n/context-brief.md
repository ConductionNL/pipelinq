# Proposal: Locale-Aware Formatting

## Problem
Currency and date formatting across all Vue components is hardcoded to `nl-NL` locale. The spec requires formatting to follow the user's Nextcloud locale.

## Solution
Create a shared `localeUtils.js` utility that detects the user's Nextcloud locale and provides `formatCurrency()` and `formatDate()` helpers. Update all components to use these shared helpers instead of hardcoded `nl-NL`.

## Scope
- `src/services/localeUtils.js` — new shared formatting utility
- Update `Dashboard.vue`, `LeadList.vue`, `LeadDetail.vue`, `MyWork.vue`, `PipelineBoard.vue`, `PipelineCard.vue` and widget files to import shared helpers
- No backend changes needed — locale detection uses `OC.getLocale()`



## Design

# Design: Locale-Aware Formatting

## Architecture
A single shared utility module `src/services/localeUtils.js` provides:
- `getUserLocale()` — returns the Nextcloud locale or falls back to `nl-NL`
- `formatCurrency(value, currency)` — locale-aware EUR formatting
- `formatDate(dateStr, options)` — locale-aware date formatting
- `formatRelativeTime(dateStr)` — locale-aware relative time (e.g., "5m ago")

Components import these helpers instead of duplicating `toLocaleString('nl-NL')`.



## Tasks

# Tasks: Locale-Aware Formatting

## Tasks

1. [x] Create `src/services/localeUtils.js` with locale-aware formatting helpers
2. [x] Update Dashboard.vue to use shared formatCurrency and formatDate
3. [x] Update LeadList.vue, LeadDetail.vue to use shared formatCurrency
4. [x] Update MyWork.vue to use shared formatCurrency and formatDate
5. [x] Update PipelineBoard.vue and PipelineCard.vue to use shared helpers
6. [x] Update widget files to use shared helpers
7. [x] Update ProductRevenue.vue and LeadProducts.vue to use shared formatCurrency
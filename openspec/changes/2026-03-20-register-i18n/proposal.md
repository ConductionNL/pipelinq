# Proposal: Locale-Aware Formatting

## Problem

Currency and date formatting across all Vue components is hardcoded to the `nl-NL` locale via direct `toLocaleString('nl-NL', ...)` calls. This means every user — regardless of their Nextcloud language and region setting — sees amounts formatted as Dutch euros (e.g. `€ 12.500,00`) and dates in Dutch order.

This violates ADR-007's requirement to respect user locale via Nextcloud core, and produces incorrect output for non-Dutch Nextcloud instances that host Pipelinq.

Affected components include: `Dashboard.vue`, `LeadList.vue`, `LeadDetail.vue`, `MyWork.vue`, `PipelineBoard.vue`, `PipelineCard.vue`, widget files (`ProductRevenue.vue`, `LeadProducts.vue`), and any other file that calls `toLocaleString('nl-NL')` directly.

## Solution

Create a single shared utility module `src/services/localeUtils.js` that:

1. Reads the active Nextcloud locale via `OC.getLocale()` with a safe fallback to `nl-NL`.
2. Exports `formatCurrency(value, currency)` — formats monetary values using `Intl.NumberFormat` with the user's locale and currency symbol.
3. Exports `formatDate(dateStr, options)` — formats ISO date strings using `Intl.DateTimeFormat` with the user's locale.
4. Exports `formatRelativeTime(dateStr)` — returns locale-aware relative time strings (e.g. "5 minuten geleden" in Dutch, "5 minutes ago" in English).

Update all affected components to import and use these shared helpers instead of their own hardcoded `toLocaleString('nl-NL')` calls.

## Scope

- `src/services/localeUtils.js` — new shared formatting utility module
- `src/views/Dashboard.vue` — replace hardcoded locale calls with `formatCurrency` and `formatDate`
- `src/views/leads/LeadList.vue` — replace hardcoded locale calls with `formatCurrency`
- `src/views/leads/LeadDetail.vue` — replace hardcoded locale calls with `formatCurrency`
- `src/views/MyWork.vue` — replace hardcoded locale calls with `formatCurrency` and `formatDate`
- `src/views/pipeline/PipelineBoard.vue` — replace hardcoded locale calls with shared helpers
- `src/views/pipeline/PipelineCard.vue` — replace hardcoded locale calls with shared helpers
- `src/views/widgets/ProductRevenue.vue` — replace hardcoded locale calls with `formatCurrency`
- `src/views/widgets/LeadProducts.vue` — replace hardcoded locale calls with `formatCurrency`
- Any additional widget files containing `toLocaleString('nl-NL')`

## Out of scope

- Backend locale changes (PHP controllers return raw values; formatting is always a frontend concern)
- Adding new locale files or translation keys (no new UI strings are introduced)
- Locale-aware sorting of list data
- Number formatting for non-monetary values (e.g. percentage, integer counts)

## Success Criteria

- All formatted currency and date values follow the active Nextcloud user locale
- Dutch users (`nl-NL`) see no visual change — output is identical to before
- English locale users see amounts formatted as `€12,500.00` (English number format) instead of `€ 12.500,00`
- No component duplicates the `toLocaleString('nl-NL')` pattern after this change
- `src/services/localeUtils.js` is the single source of truth for all locale-aware formatting
- `OC.getLocale()` unavailability (e.g. in tests) falls back gracefully to `nl-NL`

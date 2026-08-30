# Tasks: Locale-Aware Formatting

## 1. Shared Locale Utility

- [x] 1.1 Create `src/services/localeUtils.js` with `getUserLocale()`, `formatCurrency()`, `formatDate()`, and `formatRelativeTime()` helpers.
- [x] 1.2 Implement `getUserLocale()` to read from `OC.getLocale()` with `'nl-NL'` fallback for null, empty, or unavailable values.
- [x] 1.3 Implement `formatCurrency(value, currency = 'EUR')` using `Intl.NumberFormat` with the user locale; treat null/undefined value as 0.
- [x] 1.4 Implement `formatDate(dateStr, options = {})` using `Intl.DateTimeFormat`; return `''` for empty input.
- [x] 1.5 Implement `formatRelativeTime(dateStr)` using `Intl.RelativeTimeFormat`; bucket by minutes → hours → days; return `''` for empty input.

## 2. Dashboard

- [x] 2.1 Update `src/views/dashboard/Dashboard.vue` to import `formatCurrency` and `formatDate` from `localeUtils.js`.
- [x] 2.2 Replace all `toLocaleString('nl-NL', ...)` calls in Dashboard.vue with the shared helpers.

## 3. Lead Views

- [x] 3.1 Update `src/views/leads/LeadList.vue` to import `formatCurrency` and replace hardcoded locale calls.
- [x] 3.2 Update `src/views/leads/LeadDetail.vue` to import `formatCurrency` and replace hardcoded locale calls.

## 4. MyWork

- [x] 4.1 Update `src/views/mywork/MyWork.vue` to import `formatCurrency` and `formatDate` from `localeUtils.js`.
- [x] 4.2 Replace all hardcoded `nl-NL` locale references in MyWork.vue.

## 5. Pipeline Views

- [x] 5.1 Update `src/views/pipeline/PipelineBoard.vue` to import shared helpers and replace hardcoded locale calls.
- [x] 5.2 Update `src/components/PipelineCard.vue` to import shared helpers and replace hardcoded locale calls.

## 6. Widgets

- [x] 6.1 Audit all widget `.vue` files for `toLocaleString('nl-NL')` calls.
- [x] 6.2 Update each widget file found to import and use `formatCurrency` or `formatDate` from `localeUtils.js`.
- [x] 6.3 Update `src/views/dashboard/ProductRevenue.vue` to use `formatCurrency` from `localeUtils.js`.

## 7. Product Line Items

- [x] 7.1 Update `src/components/LeadProducts.vue` to import `formatCurrency` and replace all hardcoded locale formatting.

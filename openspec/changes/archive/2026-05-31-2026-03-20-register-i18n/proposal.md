# Proposal: Locale-Aware Formatting

## Problem

Currency and date formatting across all Vue components is hardcoded to the `nl-NL` locale via `toLocaleString('nl-NL')`. This violates the requirement that display formatting follow the user's actual Nextcloud locale preference. A user running Nextcloud with an English or French locale sees Dutch-formatted numbers and dates regardless of their configuration.

Additionally, the formatting logic is duplicated across many components, making future corrections or currency changes require edits in eight or more places.

## Solution

Create a shared `src/services/localeUtils.js` utility module that:
- Detects the user's Nextcloud locale via the existing `OC.getLocale()` API
- Falls back gracefully to `nl-NL` if the locale is unavailable
- Exports named helpers: `getUserLocale()`, `formatCurrency()`, `formatDate()`, and `formatRelativeTime()`

Update all affected Vue components to import from this shared module instead of duplicating `toLocaleString('nl-NL')` calls inline.

## Scope

- `src/services/localeUtils.js` — new shared formatting utility (new file)
- `src/views/dashboard/Dashboard.vue` — replace hardcoded locale in currency and date formatting
- `src/views/leads/LeadList.vue` — replace hardcoded locale in currency formatting
- `src/views/leads/LeadDetail.vue` — replace hardcoded locale in currency formatting
- `src/views/mywork/MyWork.vue` — replace hardcoded locale in currency and date formatting
- `src/views/pipeline/PipelineBoard.vue` — replace hardcoded locale in stage value formatting
- `src/components/PipelineCard.vue` — replace hardcoded locale in value formatting
- `src/components/LeadProducts.vue` — replace hardcoded locale in line-item currency formatting
- `src/views/dashboard/ProductRevenue.vue` — replace hardcoded locale in revenue formatting
- Widget files referencing `toLocaleString` — replace with shared helpers

## Out of Scope

- Timezone detection or conversion (separate concern)
- Number formatting for non-currency values
- Backend translation changes (`l10n/` files are unaffected)
- New OpenRegister entities or schema changes
- Multi-currency support (EUR is the only currency in scope)

## Decision Rationale

Using `OC.getLocale()` requires no new backend endpoints and no PHP changes. The Nextcloud platform already exposes the user's locale to the frontend via the `OC` global, making this a pure client-side fix. A single shared module ensures the fallback logic and format options are tested and maintained in one place.

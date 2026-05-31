---
status: proposed
---

# Spec: Locale-Aware Formatting

## Purpose

Replace all hardcoded `nl-NL` locale formatting calls across Vue components with a shared `localeUtils.js` utility that respects the active Nextcloud user locale. Monetary values and dates must render correctly for any locale configured in the user's Nextcloud profile.

---

## REQ-I18N-001: Locale detection [MVP]

The `getUserLocale()` function in `src/services/localeUtils.js` MUST return the active Nextcloud user locale as a BCP 47 language tag (e.g. `nl-NL`, `en-GB`, `de-DE`).

### Scenario: Locale read from Nextcloud

- GIVEN a Nextcloud instance with user locale set to `nl_NL`
- WHEN `getUserLocale()` is called
- THEN it MUST return `"nl-NL"` (underscore converted to hyphen for Intl API compatibility)

### Scenario: Locale read for non-Dutch user

- GIVEN a Nextcloud instance with user locale set to `en_GB`
- WHEN `getUserLocale()` is called
- THEN it MUST return `"en-GB"`

### Scenario: Fallback when OC global is unavailable

- GIVEN `OC.getLocale()` throws an error or `OC` is undefined (e.g. unit test environment)
- WHEN `getUserLocale()` is called
- THEN it MUST return `"nl-NL"` as the default fallback
- AND MUST NOT throw an unhandled exception

### Scenario: Empty or falsy locale returned

- GIVEN `OC.getLocale()` returns an empty string or null
- WHEN `getUserLocale()` is called
- THEN it MUST return `"nl-NL"` as the fallback

---

## REQ-I18N-002: Currency formatting [MVP]

The `formatCurrency(value, currency)` function MUST format numeric monetary values using the active user locale and the `Intl.NumberFormat` API with `style: 'currency'`.

### Scenario: Dutch locale formats with comma decimal separator

- GIVEN the user locale is `nl-NL`
- WHEN `formatCurrency(12500)` is called
- THEN it MUST return a string with `12.500` (dot as thousands separator) and `,00` (comma as decimal separator)
- AND the EUR currency symbol MUST be present

### Scenario: English locale formats with period decimal separator

- GIVEN the user locale is `en-GB`
- WHEN `formatCurrency(12500)` is called
- THEN it MUST return a string with `12,500.00` (comma as thousands separator, period as decimal separator)
- AND the EUR currency symbol MUST be present

### Scenario: Null or undefined value renders as zero

- GIVEN any user locale
- WHEN `formatCurrency(null)` or `formatCurrency(undefined)` is called
- THEN it MUST return the formatted representation of `0` (e.g. `€ 0,00` for nl-NL)
- AND MUST NOT throw an exception

### Scenario: Custom currency code is respected

- GIVEN the user locale is `en-US`
- WHEN `formatCurrency(100, 'USD')` is called
- THEN the result MUST use the `USD` currency symbol, not EUR

### Scenario: Two decimal places always shown

- GIVEN any user locale
- WHEN `formatCurrency(1250.5)` is called
- THEN the result MUST display exactly two decimal places (e.g. `1.250,50` for nl-NL)

---

## REQ-I18N-003: Date formatting [MVP]

The `formatDate(dateStr, options)` function MUST format ISO 8601 date strings using the active user locale and `Intl.DateTimeFormat`.

### Scenario: Dutch locale formats date in day/month/year order

- GIVEN the user locale is `nl-NL`
- WHEN `formatDate("2026-03-20")` is called with default options
- THEN it MUST return `"20-03-2026"` or equivalent Dutch date format

### Scenario: English locale formats date in month/day/year order

- GIVEN the user locale is `en-US`
- WHEN `formatDate("2026-03-20")` is called with default options
- THEN it MUST return `"03/20/2026"` or equivalent US date format

### Scenario: Empty or null input returns empty string

- GIVEN any user locale
- WHEN `formatDate(null)` or `formatDate("")` is called
- THEN it MUST return `""` (empty string)
- AND MUST NOT throw an exception

### Scenario: Custom Intl options are applied

- GIVEN the user locale is `nl-NL`
- WHEN `formatDate("2026-03-20", { month: 'long', year: 'numeric' })` is called
- THEN the month name MUST be rendered in Dutch (e.g. `"maart 2026"`)

---

## REQ-I18N-004: Relative time formatting [MVP]

The `formatRelativeTime(dateStr)` function MUST return a locale-aware relative time string for ISO 8601 date strings.

### Scenario: Recent past renders as relative minutes

- GIVEN the user locale is `nl-NL` and the current time is 10 minutes after the input date
- WHEN `formatRelativeTime(dateStr)` is called
- THEN it MUST return a string equivalent to "10 minuten geleden"

### Scenario: English locale returns English relative string

- GIVEN the user locale is `en-GB` and the current time is 2 hours after the input date
- WHEN `formatRelativeTime(dateStr)` is called
- THEN it MUST return a string equivalent to "2 hours ago"

### Scenario: Empty or null input returns empty string

- GIVEN any user locale
- WHEN `formatRelativeTime(null)` or `formatRelativeTime("")` is called
- THEN it MUST return `""` (empty string)
- AND MUST NOT throw an exception

### Scenario: RelativeTimeFormat unavailable falls back to absolute date

- GIVEN `Intl.RelativeTimeFormat` throws (very old browser)
- WHEN `formatRelativeTime(dateStr)` is called
- THEN it MUST fall back to `formatDate(dateStr)` and return an absolute date string
- AND MUST NOT surface an unhandled exception

---

## REQ-I18N-005: Component migration — no hardcoded nl-NL [MVP]

All Vue components that previously called `toLocaleString('nl-NL', ...)` MUST be updated to import and use the shared helpers from `src/services/localeUtils.js`. No component MAY contain a hardcoded `'nl-NL'` locale string after this change is implemented.

### Scenario: Dashboard.vue uses shared helpers

- GIVEN `Dashboard.vue` is inspected
- THEN it MUST NOT contain `toLocaleString('nl-NL')`
- AND MUST import `formatCurrency` or `formatDate` from `../../services/localeUtils.js`

### Scenario: LeadList.vue uses shared helpers

- GIVEN `LeadList.vue` is inspected
- THEN it MUST NOT contain `toLocaleString('nl-NL')`
- AND MUST import `formatCurrency` from `../../services/localeUtils.js`

### Scenario: LeadDetail.vue uses shared helpers

- GIVEN `LeadDetail.vue` is inspected
- THEN it MUST NOT contain `toLocaleString('nl-NL')`
- AND MUST import `formatCurrency` from `../../services/localeUtils.js`

### Scenario: MyWork.vue uses shared helpers

- GIVEN `MyWork.vue` is inspected
- THEN it MUST NOT contain `toLocaleString('nl-NL')`
- AND MUST import `formatCurrency` or `formatDate` from `../../services/localeUtils.js`

### Scenario: PipelineBoard.vue and PipelineCard.vue use shared helpers

- GIVEN `PipelineBoard.vue` and `PipelineCard.vue` are inspected
- THEN neither file MUST contain `toLocaleString('nl-NL')`
- AND both MUST import helpers from `../../services/localeUtils.js`

### Scenario: Widget files use shared helpers

- GIVEN `ProductRevenue.vue` and `LeadProducts.vue` are inspected
- THEN neither file MUST contain `toLocaleString('nl-NL')`
- AND both MUST import `formatCurrency` from `../../services/localeUtils.js`

### Scenario: No other files contain hardcoded nl-NL locale

- GIVEN a grep search for `toLocaleString.*nl-NL` across `src/`
- THEN it MUST return zero matches

---

## REQ-I18N-006: Dutch user experience unchanged [MVP]

For users with `nl-NL` locale, the visual output of all formatted values MUST be identical to the output produced by the previous hardcoded `toLocaleString('nl-NL', ...)` calls. No regression in Dutch formatting is acceptable.

### Scenario: Currency output identical for Dutch users

- GIVEN a lead with `value: 12500`
- AND the user locale is `nl-NL`
- WHEN the value is rendered via `formatCurrency(12500)`
- THEN the displayed string MUST match the previous `(12500).toLocaleString('nl-NL', { style: 'currency', currency: 'EUR' })` output exactly

### Scenario: Date output identical for Dutch users

- GIVEN a lead with `expectedCloseDate: "2026-06-30"`
- AND the user locale is `nl-NL`
- WHEN the date is rendered via `formatDate("2026-06-30")`
- THEN the displayed string MUST match the previous `new Date("2026-06-30").toLocaleDateString('nl-NL')` output exactly

# Spec: Locale-Aware Formatting

## Purpose

Define requirements for locale-aware currency and date formatting in Pipelinq's Vue frontend. All monetary values and date strings displayed to users MUST use the user's Nextcloud locale rather than a hardcoded `nl-NL` locale.

**Feature tier**: Core quality
**Main spec ref**: This is the primary spec for the `register-i18n` change.

---

## Requirements

### REQ-I18N-001: Shared Locale Utility Module

The app MUST provide a shared `src/services/localeUtils.js` module that centralizes locale detection and all number/date formatting. No component MAY duplicate locale-formatting logic inline.

#### Scenario 1: Locale detection from Nextcloud

- GIVEN a Nextcloud user whose locale is set to `en` in their account settings
- WHEN `getUserLocale()` is called in any Vue component
- THEN it MUST return the string `'en'`
- AND all subsequent format calls in that session MUST apply English locale conventions

#### Scenario 2: Locale detection — Dutch user

- GIVEN a Nextcloud user whose locale is set to `nl`
- WHEN `getUserLocale()` is called
- THEN it MUST return `'nl'`

#### Scenario 3: Fallback when OC is unavailable

- GIVEN `OC.getLocale()` returns `null` or the `OC` global is not defined
- WHEN `getUserLocale()` is called
- THEN it MUST NOT throw an exception
- AND it MUST return `'nl-NL'` as the default fallback

#### Scenario 4: Fallback when locale string is empty

- GIVEN `OC.getLocale()` returns an empty string `''`
- WHEN `getUserLocale()` is called
- THEN it MUST return `'nl-NL'`

---

### REQ-I18N-002: formatCurrency Helper

The `formatCurrency(value, currency)` helper MUST format numeric values as locale-aware currency strings.

#### Scenario 1: Dutch locale EUR formatting

- GIVEN a user with locale `nl`
- WHEN `formatCurrency(1234.56)` is called
- THEN the result MUST use Dutch number conventions: period as thousands separator, comma as decimal
- AND the euro symbol or code MUST be present in the output

#### Scenario 2: English locale EUR formatting

- GIVEN a user with locale `en`
- WHEN `formatCurrency(1234.56)` is called
- THEN the result MUST use English number conventions: comma as thousands separator, period as decimal
- AND the euro symbol or code MUST be present in the output

#### Scenario 3: Null or undefined value

- GIVEN `value` is `null` or `undefined`
- WHEN `formatCurrency(value)` is called
- THEN the result MUST format `0` rather than throwing an exception
- AND the output MUST be a valid currency string (e.g., `€ 0,00`)

#### Scenario 4: Custom currency code

- GIVEN a user with locale `en`
- WHEN `formatCurrency(500, 'USD')` is called
- THEN the result MUST use USD currency formatting

---

### REQ-I18N-003: formatDate Helper

The `formatDate(dateStr, options)` helper MUST format ISO date strings using the user's locale.

#### Scenario 1: Dutch date format

- GIVEN a user with locale `nl`
- WHEN `formatDate('2026-03-20')` is called
- THEN the result MUST use Dutch date ordering (day-month-year)

#### Scenario 2: English date format

- GIVEN a user with locale `en`
- WHEN `formatDate('2026-03-20')` is called
- THEN the result MUST use English date ordering (month/day/year)

#### Scenario 3: Custom format options

- GIVEN a user with locale `nl`
- WHEN `formatDate('2026-03-20', { year: 'numeric', month: 'long', day: 'numeric' })` is called
- THEN the result MUST include the full month name in Dutch (e.g., `20 maart 2026`)

#### Scenario 4: Empty or null date string

- GIVEN `dateStr` is `null`, `undefined`, or `''`
- WHEN `formatDate(dateStr)` is called
- THEN the result MUST return an empty string `''`
- AND MUST NOT throw an exception

---

### REQ-I18N-004: formatRelativeTime Helper

The `formatRelativeTime(dateStr)` helper MUST format a past or future ISO date as a locale-aware relative time string.

#### Scenario 1: Minutes ago — Dutch

- GIVEN a user with locale `nl`
- AND a date that is 5 minutes in the past
- WHEN `formatRelativeTime(dateStr)` is called
- THEN the result MUST be a Dutch relative time string indicating approximately 5 minutes ago

#### Scenario 2: Minutes ago — English

- GIVEN a user with locale `en`
- AND a date that is 5 minutes in the past
- WHEN `formatRelativeTime(dateStr)` is called
- THEN the result MUST be an English relative time string (e.g., `"5 minutes ago"`)

#### Scenario 3: Hours ago

- GIVEN a date that is 3 hours in the past
- WHEN `formatRelativeTime(dateStr)` is called
- THEN the result MUST indicate hours rather than minutes

#### Scenario 4: Days ago

- GIVEN a date that is 2 days in the past
- WHEN `formatRelativeTime(dateStr)` is called
- THEN the result MUST indicate days rather than hours

#### Scenario 5: Empty date

- GIVEN `dateStr` is `null` or `''`
- WHEN `formatRelativeTime(dateStr)` is called
- THEN the result MUST return `''` without throwing

---

### REQ-I18N-005: No Hardcoded nl-NL in Components

All Vue components that display currency values or formatted dates MUST use helpers from `localeUtils.js`. Direct calls to `toLocaleString('nl-NL')` in component code are a violation.

#### Scenario 1: Dashboard currency values

- GIVEN a user with locale `en`
- WHEN the Dashboard renders lead totals or revenue figures
- THEN each monetary value MUST be formatted by `formatCurrency()` from `localeUtils.js`
- AND MUST NOT contain `toLocaleString('nl-NL')` in the component source

#### Scenario 2: LeadList value column

- GIVEN a user with locale `en`
- AND a lead with value `5000`
- WHEN the LeadList renders the value column
- THEN the displayed value MUST use English locale formatting
- AND MUST NOT appear as Dutch-formatted (e.g., `€ 5.000,00` would indicate a violation)

#### Scenario 3: PipelineBoard stage totals

- GIVEN a user with locale `en`
- WHEN the PipelineBoard renders per-stage value totals
- THEN totals MUST be formatted using `formatCurrency()` from `localeUtils.js`

#### Scenario 4: MyWork date display

- GIVEN a user with locale `en`
- WHEN MyWork renders task deadlines or follow-up dates
- THEN dates MUST be formatted using `formatDate()` from `localeUtils.js`

#### Scenario 5: Widget currency display

- GIVEN a dashboard widget (e.g., ProductRevenue) displaying monetary values
- WHEN the widget renders
- THEN all monetary values MUST use `formatCurrency()` from `localeUtils.js`

#### Scenario 6: LeadProducts line-item totals

- GIVEN a lead with line items in LeadProducts.vue
- WHEN currency values (unitPrice, total) are rendered
- THEN they MUST be formatted using `formatCurrency()` from `localeUtils.js`

---

### REQ-I18N-006: OC.getLocale() Integration — No Backend Calls

The locale detection mechanism MUST use the `OC.getLocale()` JavaScript API exclusively. No new PHP controllers, backend endpoints, or l10n file changes are required or permitted for this feature.

#### Scenario 1: Locale resolved client-side only

- GIVEN the Nextcloud app shell has initialized
- WHEN any component calls `getUserLocale()`
- THEN the locale MUST be resolved from `OC.getLocale()` synchronously
- AND MUST NOT trigger any HTTP requests

#### Scenario 2: No l10n file changes required

- GIVEN the `localeUtils.js` module is in use
- THEN `l10n/en.json` and `l10n/nl.json` MUST NOT require new keys
- AND no PHP translation strings are added

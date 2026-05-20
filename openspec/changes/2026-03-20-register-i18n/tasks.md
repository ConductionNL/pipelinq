# Tasks: Locale-Aware Formatting

## 0. Pre-implementation check

- [ ] 0.1 Search all `src/` files for `toLocaleString('nl-NL'` to get a full list of affected locations:
  ```bash
  grep -rn "toLocaleString.*nl-NL" src/
  ```
  Confirm the list matches the components in scope (Dashboard, LeadList, LeadDetail, MyWork, PipelineBoard, PipelineCard, ProductRevenue, LeadProducts). Record any additional files found.

- [ ] 0.2 Verify `OC.getLocale()` is available in the Nextcloud Vue app context by checking an existing component that uses it, or confirming via Nextcloud documentation that the global is injected before Vue mounts.

- [ ] 0.3 Confirm `Intl.NumberFormat` and `Intl.DateTimeFormat` with currency support are available in the Nextcloud supported browser matrix (no polyfill needed for modern Nextcloud versions).

## 1. Create shared utility: `src/services/localeUtils.js` [MVP]

- [ ] 1.1 Create the file `src/services/localeUtils.js` with the following exports:
  - `getUserLocale()` — calls `OC.getLocale().replace('_', '-')` wrapped in try/catch; fallback to `'nl-NL'`
  - `formatCurrency(value, currency = 'EUR')` — uses `new Intl.NumberFormat(locale, { style: 'currency', currency, minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value ?? 0)`
  - `formatDate(dateStr, options)` — returns `''` for falsy input; otherwise `new Intl.DateTimeFormat(locale, options).format(new Date(dateStr))` with default options `{ day: '2-digit', month: '2-digit', year: 'numeric' }`
  - `formatRelativeTime(dateStr)` — returns `''` for falsy input; computes `diffSeconds` from `Date.now()`, selects unit (second/minute/hour/day), calls `new Intl.RelativeTimeFormat(locale, { numeric: 'auto' }).format(value, unit)` in try/catch; fallback to `formatDate(dateStr)`

- [ ] 1.2 Verify the file is created at the correct path and exports are named exports (not default export).

## 2. Update Dashboard.vue [MVP]

- [ ] 2.1 Add import at the top of `<script>`:
  ```js
  import { formatCurrency, formatDate } from '../../services/localeUtils.js'
  ```

- [ ] 2.2 Replace every `toLocaleString('nl-NL', { style: 'currency', currency: 'EUR' })` call with `formatCurrency(value)`.

- [ ] 2.3 Replace every `toLocaleDateString('nl-NL')` or `toLocaleString('nl-NL')` date call with `formatDate(dateStr)`.

- [ ] 2.4 Verify no `'nl-NL'` string remains in the file:
  ```bash
  grep -n "nl-NL" src/views/Dashboard.vue
  ```
  MUST return zero matches.

## 3. Update LeadList.vue and LeadDetail.vue [MVP]

- [ ] 3.1 In `LeadList.vue`, add import and replace all currency `toLocaleString('nl-NL')` calls with `formatCurrency(value)`.

- [ ] 3.2 In `LeadDetail.vue`, add import and replace all currency `toLocaleString('nl-NL')` calls with `formatCurrency(value)`.

- [ ] 3.3 Verify no `'nl-NL'` string remains in either file:
  ```bash
  grep -n "nl-NL" src/views/leads/LeadList.vue src/views/leads/LeadDetail.vue
  ```
  MUST return zero matches.

## 4. Update MyWork.vue [MVP]

- [ ] 4.1 Add import for `formatCurrency` and `formatDate` from `localeUtils.js`.

- [ ] 4.2 Replace all currency and date `toLocaleString('nl-NL')` calls with the shared helpers.

- [ ] 4.3 Verify no `'nl-NL'` string remains:
  ```bash
  grep -n "nl-NL" src/views/MyWork.vue
  ```
  MUST return zero matches.

## 5. Update PipelineBoard.vue and PipelineCard.vue [MVP]

- [ ] 5.1 In `PipelineBoard.vue`, add import and replace all `toLocaleString('nl-NL')` calls with the appropriate shared helpers.

- [ ] 5.2 In `PipelineCard.vue`, add import and replace all `toLocaleString('nl-NL')` calls with the appropriate shared helpers.

- [ ] 5.3 Verify no `'nl-NL'` string remains in either file:
  ```bash
  grep -n "nl-NL" src/views/pipeline/PipelineBoard.vue src/views/pipeline/PipelineCard.vue
  ```
  MUST return zero matches.

## 6. Update widget files [MVP]

- [ ] 6.1 In `ProductRevenue.vue`, add import for `formatCurrency` and replace all `toLocaleString('nl-NL')` currency calls.

- [ ] 6.2 In `LeadProducts.vue`, add import for `formatCurrency` and replace all `toLocaleString('nl-NL')` currency calls.

- [ ] 6.3 If the pre-implementation grep (task 0.1) revealed additional widget files with `toLocaleString('nl-NL')`, update each one with the same import-and-replace pattern.

- [ ] 6.4 Verify no `'nl-NL'` string remains in any widget file:
  ```bash
  grep -rn "nl-NL" src/views/widgets/
  ```
  MUST return zero matches.

## 7. Final verification [MVP]

- [ ] 7.1 Run global grep to confirm zero remaining hardcoded `nl-NL` formatting calls across all of `src/`:
  ```bash
  grep -rn "toLocaleString.*nl-NL" src/
  ```
  MUST return zero matches.

- [ ] 7.2 Manual smoke test — Dutch locale: Load the Pipelinq app in a Nextcloud instance with `nl-NL` locale → open Dashboard, LeadList, a LeadDetail, MyWork, and PipelineBoard → verify all currency values render as `€ X.XXX,XX` and dates as `DD-MM-YYYY`.

- [ ] 7.3 Manual smoke test — English locale: Switch Nextcloud user language to English (en-GB or en-US) → repeat the tour above → verify currency renders as `€X,XXX.XX` and dates in the expected English format.

- [ ] 7.4 Verify `localeUtils.js` exports are correctly imported and available (no `undefined` in console) by opening the browser devtools and confirming formatted values appear in the UI.

- [ ] 7.5 Confirm `src/services/localeUtils.js` is the only file in `src/` that references `OC.getLocale()`:
  ```bash
  grep -rn "OC.getLocale" src/
  ```
  MUST show only `src/services/localeUtils.js`.

# Design: Locale-Aware Formatting

## Architecture

### Data Layer

No OpenRegister schemas, entities, or register configuration are changed by this feature. All monetary and date values are already stored as raw numbers and ISO 8601 strings in the existing `lead`, `leadProduct`, `product`, and related entities — formatting is exclusively a presentation concern.

Relevant entity fields that are formatted in the UI:

| Entity | Field | Type | Formatted as |
|--------|-------|------|--------------|
| `lead` | `value` | number | Currency (EUR) |
| `leadProduct` | `unitPrice`, `total` | number | Currency (EUR) |
| `product` | `unitPrice`, `cost` | number | Currency (EUR) |
| `lead` | `expectedCloseDate` | string (ISO 8601) | Date |
| `request` | `requestedAt` | string (ISO 8601) | Date / relative time |
| `complaint` | `slaDeadline`, `resolvedAt` | string (ISO 8601) | Date / relative time |

No data model changes are needed.

### Frontend

A single new module replaces all scattered `toLocaleString('nl-NL', ...)` calls across components.

**`src/services/localeUtils.js`**

```js
/**
 * Returns the active Nextcloud user locale (e.g. 'nl-NL', 'en-GB').
 * Falls back to 'nl-NL' if OC global is unavailable (e.g. unit tests).
 */
export function getUserLocale() {
  try {
    return OC.getLocale().replace('_', '-') || 'nl-NL'
  } catch {
    return 'nl-NL'
  }
}

/**
 * Format a numeric value as a currency string using the user's locale.
 * @param {number} value
 * @param {string} [currency='EUR']
 * @returns {string}
 */
export function formatCurrency(value, currency = 'EUR') {
  const locale = getUserLocale()
  return new Intl.NumberFormat(locale, {
    style: 'currency',
    currency,
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(value ?? 0)
}

/**
 * Format an ISO date string using the user's locale.
 * @param {string} dateStr - ISO 8601 date string
 * @param {Intl.DateTimeFormatOptions} [options]
 * @returns {string}
 */
export function formatDate(dateStr, options = { day: '2-digit', month: '2-digit', year: 'numeric' }) {
  if (!dateStr) return ''
  const locale = getUserLocale()
  return new Intl.DateTimeFormat(locale, options).format(new Date(dateStr))
}

/**
 * Format an ISO date string as a locale-aware relative time string.
 * E.g. "5 minuten geleden" (nl-NL) or "5 minutes ago" (en-GB).
 * Falls back to absolute date if Intl.RelativeTimeFormat is unavailable.
 * @param {string} dateStr - ISO 8601 date string
 * @returns {string}
 */
export function formatRelativeTime(dateStr) {
  if (!dateStr) return ''
  const locale = getUserLocale()
  const now = Date.now()
  const then = new Date(dateStr).getTime()
  const diffSeconds = Math.round((then - now) / 1000)
  const abs = Math.abs(diffSeconds)
  let value, unit
  if (abs < 60) { value = diffSeconds; unit = 'second' }
  else if (abs < 3600) { value = Math.round(diffSeconds / 60); unit = 'minute' }
  else if (abs < 86400) { value = Math.round(diffSeconds / 3600); unit = 'hour' }
  else { value = Math.round(diffSeconds / 86400); unit = 'day' }
  try {
    return new Intl.RelativeTimeFormat(locale, { numeric: 'auto' }).format(value, unit)
  } catch {
    return formatDate(dateStr)
  }
}
```

**Component updates (pattern — same for all affected components):**

Before:
```js
// Inside component method or computed
amount.toLocaleString('nl-NL', { style: 'currency', currency: 'EUR' })
```

After:
```js
import { formatCurrency, formatDate } from '../../services/localeUtils.js'
// ...
formatCurrency(amount)
```

No new props, emits, or store actions are needed. Imports are added to the `<script>` section; template bindings remain the same since the helper returns a string.

### Backend

No backend changes. Locale detection uses the `OC.getLocale()` JavaScript global provided by Nextcloud core — this is available on every authenticated Nextcloud page and does not require a new API call.

### Integration Points

- **Nextcloud core**: `OC.getLocale()` — reads the user's active locale string (e.g. `nl_NL`, `en_GB`). The underscore separator is normalized to a hyphen for `Intl` API compatibility.
- **Browser Intl API**: `Intl.NumberFormat`, `Intl.DateTimeFormat`, `Intl.RelativeTimeFormat` — all supported in all browsers that support Nextcloud.

## Components

See `specs/register-i18n-locale-formatting/spec.md` for detailed requirements and BDD scenarios.

## i18n

No new translation keys are introduced by this change. The formatting output is locale-derived from `Intl` APIs, not from `t()` keys. ADR-007 compliance note: locale-sensitive _display formatting_ (numbers, dates) uses `Intl` APIs via user locale — this is distinct from UI string translation which uses `t()`.

## Files Changed

### New Files

| File | Purpose |
|------|---------|
| `src/services/localeUtils.js` | Shared locale-aware formatting utilities |
| `specs/register-i18n-locale-formatting/spec.md` | Formal requirements and BDD scenarios |

### Modified Files

| File | Change |
|------|--------|
| `src/views/Dashboard.vue` | Import `formatCurrency`, `formatDate`; replace `toLocaleString('nl-NL')` calls |
| `src/views/leads/LeadList.vue` | Import `formatCurrency`; replace `toLocaleString('nl-NL')` calls |
| `src/views/leads/LeadDetail.vue` | Import `formatCurrency`; replace `toLocaleString('nl-NL')` calls |
| `src/views/MyWork.vue` | Import `formatCurrency`, `formatDate`; replace `toLocaleString('nl-NL')` calls |
| `src/views/pipeline/PipelineBoard.vue` | Import shared helpers; replace `toLocaleString('nl-NL')` calls |
| `src/views/pipeline/PipelineCard.vue` | Import shared helpers; replace `toLocaleString('nl-NL')` calls |
| `src/views/widgets/ProductRevenue.vue` | Import `formatCurrency`; replace `toLocaleString('nl-NL')` calls |
| `src/views/widgets/LeadProducts.vue` | Import `formatCurrency`; replace `toLocaleString('nl-NL')` calls |

## Seed Data

This change introduces no new OpenRegister entities or schemas. No seed data is required.

For reference, the following are representative values from existing entities that will be formatted through the new utility:

**`lead` (value field — Dutch locale)**

| title | value (raw) | formatted nl-NL | formatted en-GB |
|-------|-------------|-----------------|-----------------|
| Vergunning aanvraag Pietersen BV | 4500 | € 4.500,00 | €4,500.00 |
| WMO ondersteuning De Vries | 1250.50 | € 1.250,50 | €1,250.50 |
| ICT-infrastructuur gemeente Haarlem | 87500 | € 87.500,00 | €87,500.00 |
| Adviestraject reorganisatie | 12000 | € 12.000,00 | €12,000.00 |
| Subsidieaanvraag sporthal Noord | 350000 | € 350.000,00 | €350,000.00 |

**`leadProduct` (unitPrice, total fields)**

| product | quantity | unitPrice (raw) | total (raw) | formatted nl-NL |
|---------|----------|-----------------|-------------|-----------------|
| Licentie Pipelinq CRM | 10 | 49.95 | 499.50 | € 499,50 |
| Implementatiedag | 3 | 1200 | 3600 | € 3.600,00 |
| Jaarlijks onderhoud | 1 | 850 | 850 | € 850,00 |
| Training gebruikers | 2 | 650 | 1300 | € 1.300,00 |
| Maandabonnement support | 12 | 125 | 1500 | € 1.500,00 |

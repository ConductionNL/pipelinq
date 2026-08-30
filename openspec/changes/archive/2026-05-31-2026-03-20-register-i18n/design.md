# Design: Locale-Aware Formatting

## Architecture

A single shared utility module `src/services/localeUtils.js` centralizes locale detection and formatting for all Vue components. Components import named helpers instead of duplicating `toLocaleString('nl-NL')` inline.

This is a pure frontend change. No new entities, no OpenRegister schemas, and no backend endpoints are introduced or modified.

---

## localeUtils.js — Module API

```js
/**
 * Returns the user's Nextcloud locale (BCP 47 tag, e.g. 'nl', 'en', 'fr').
 * Falls back to 'nl-NL' if OC.getLocale() is unavailable or returns null.
 */
export function getUserLocale()

/**
 * Formats a numeric value as a currency string using the user's locale.
 * currency defaults to 'EUR'.
 *
 * Examples:
 *   formatCurrency(1234.56)        → "€ 1.234,56"  (nl)
 *   formatCurrency(1234.56)        → "€1,234.56"   (en)
 *   formatCurrency(1234.56, 'USD') → "$1,234.56"   (en)
 */
export function formatCurrency(value, currency = 'EUR')

/**
 * Formats an ISO date string using the user's locale.
 * options follows Intl.DateTimeFormat options (optional).
 *
 * Examples:
 *   formatDate('2026-03-20') → "20-3-2026"  (nl)
 *   formatDate('2026-03-20') → "3/20/2026"  (en)
 */
export function formatDate(dateStr, options = {})

/**
 * Formats a date as locale-aware relative time.
 * Uses Intl.RelativeTimeFormat — supported in all modern browsers.
 *
 * Examples:
 *   formatRelativeTime('2026-03-19T10:00:00') → "1 dag geleden"   (nl)
 *   formatRelativeTime('2026-03-19T10:00:00') → "1 day ago"        (en)
 */
export function formatRelativeTime(dateStr)
```

---

## Implementation Details

### Locale Detection

```js
export function getUserLocale() {
  if (typeof OC !== 'undefined' && typeof OC.getLocale === 'function') {
    const locale = OC.getLocale()
    if (locale) return locale
  }
  return 'nl-NL'
}
```

`OC.getLocale()` returns a two-letter code like `'nl'` or `'en'`. The `Intl` APIs accept both two-letter codes and full BCP 47 tags, so no normalization is needed.

### formatCurrency

```js
export function formatCurrency(value, currency = 'EUR') {
  const locale = getUserLocale()
  return new Intl.NumberFormat(locale, {
    style: 'currency',
    currency,
  }).format(value ?? 0)
}
```

### formatDate

```js
export function formatDate(dateStr, options = {}) {
  if (!dateStr) return ''
  const locale = getUserLocale()
  return new Intl.DateTimeFormat(locale, options).format(new Date(dateStr))
}
```

### formatRelativeTime

```js
export function formatRelativeTime(dateStr) {
  if (!dateStr) return ''
  const locale = getUserLocale()
  const rtf = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' })
  const diffMs = new Date(dateStr) - Date.now()
  const diffMinutes = Math.round(diffMs / 60000)
  if (Math.abs(diffMinutes) < 60) return rtf.format(diffMinutes, 'minute')
  const diffHours = Math.round(diffMinutes / 60)
  if (Math.abs(diffHours) < 24) return rtf.format(diffHours, 'hour')
  const diffDays = Math.round(diffHours / 24)
  return rtf.format(diffDays, 'day')
}
```

---

## Component Migration Pattern

**Before** (each component, duplicated):
```js
value.toLocaleString('nl-NL', { style: 'currency', currency: 'EUR' })
```

**After** (import once, use everywhere):
```js
import { formatCurrency, formatDate } from '../../services/localeUtils.js'

// In template or computed:
formatCurrency(lead.value)
formatDate(lead.expectedCloseDate)
```

---

## Files Changed

**New:**
- `src/services/localeUtils.js`

**Modified (replace hardcoded `nl-NL`):**
- `src/views/dashboard/Dashboard.vue`
- `src/views/leads/LeadList.vue`
- `src/views/leads/LeadDetail.vue`
- `src/views/mywork/MyWork.vue`
- `src/views/pipeline/PipelineBoard.vue`
- `src/components/PipelineCard.vue`
- `src/components/LeadProducts.vue`
- `src/views/dashboard/ProductRevenue.vue`
- Widget files containing `toLocaleString` references

---

## No Seed Data

This change introduces no new OpenRegister entities and modifies no schemas. No seed data is required.

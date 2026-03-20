# Design: Locale-Aware Formatting

## Architecture
A single shared utility module `src/services/localeUtils.js` provides:
- `getUserLocale()` — returns the Nextcloud locale or falls back to `nl-NL`
- `formatCurrency(value, currency)` — locale-aware EUR formatting
- `formatDate(dateStr, options)` — locale-aware date formatting
- `formatRelativeTime(dateStr)` — locale-aware relative time (e.g., "5m ago")

Components import these helpers instead of duplicating `toLocaleString('nl-NL')`.

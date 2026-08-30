/**
 * Shared locale-aware formatting utilities for Pipelinq.
 *
 * Detects the user's Nextcloud locale and provides consistent
 * currency, date, and number formatting across all components.
 */

/**
 * Get the user's Nextcloud locale, falling back to 'nl-NL'.
 *
 * @return {string} BCP 47 locale tag (e.g., 'nl-NL', 'en-US')
 * @spec openspec/changes/reverse-2026-05-26-fe-services/tasks.md#task-27
 */
export function getUserLocale() {
	if (typeof OC !== 'undefined' && OC.getLocale) {
		const locale = OC.getLocale()
		// OC.getLocale() returns e.g. 'nl' or 'en'; convert to BCP 47
		if (locale && locale.length === 2) {
			return locale
		}
		if (locale) {
			return locale.replace('_', '-')
		}
	}
	return 'nl-NL'
}

/**
 * Format a numeric value as EUR currency using the user's locale.
 *
 * @param {number|string} value The numeric value to format
 * @param {string} [currency] The currency code
 * @return {string} Formatted currency string (e.g., "EUR 12.500,50" or "EUR 12,500.50")
 * @spec openspec/changes/reverse-2026-05-26-fe-services/tasks.md#task-23
 */
export function formatCurrency(value, currency = 'EUR') {
	if (value === null || value === undefined || value === '') return currency + ' 0'
	const num = Number(value)
	if (isNaN(num)) return currency + ' 0'

	const locale = getUserLocale()
	const formatted = num.toLocaleString(locale, {
		minimumFractionDigits: 0,
		maximumFractionDigits: 2,
	})
	return currency + ' ' + formatted
}

/**
 * Format a number using the user's locale (no currency prefix).
 *
 * @param {number|string} value The numeric value to format
 * @return {string} Formatted number string
 * @spec openspec/changes/reverse-2026-05-26-fe-services/tasks.md#task-26
 */
export function formatNumber(value) {
	if (value === null || value === undefined || value === '') return '0'
	const num = Number(value)
	if (isNaN(num)) return '0'
	return num.toLocaleString(getUserLocale())
}

/**
 * Format a date string using the user's locale.
 *
 * @param {string} dateStr ISO date string
 * @param {object} [options] Intl.DateTimeFormat options
 * @return {string} Formatted date string
 * @spec openspec/changes/reverse-2026-05-26-fe-services/tasks.md#task-24
 */
export function formatDate(dateStr, options = { month: 'short', day: 'numeric' }) {
	if (!dateStr) return ''
	try {
		return new Date(dateStr).toLocaleDateString(getUserLocale(), options)
	} catch {
		return dateStr
	}
}

/**
 * Format a date string with year using the user's locale.
 *
 * @param {string} dateStr ISO date string
 * @return {string} Formatted date string with year
 * @spec openspec/changes/reverse-2026-05-26-fe-services/tasks.md#task-25
 */
export function formatDateFull(dateStr) {
	return formatDate(dateStr, { month: 'short', day: 'numeric', year: 'numeric' })
}

/**
 * Parse a stored date string (ISO or YYYY-MM-DD) into a local Date for
 * NcDateTimePickerNative's `value` prop. Builds the Date from local parts so
 * the day doesn't shift across timezones (unlike `new Date('YYYY-MM-DD')`,
 * which parses as UTC midnight).
 *
 * @param {string|null} dateStr Stored date string, or empty/null.
 * @return {Date|null} Local Date, or null when empty/invalid.
 */
export function toDateObject(dateStr) {
	if (!dateStr) return null
	const [y, m, d] = String(dateStr).slice(0, 10).split('-').map(Number)
	if (!y || !m || !d) return null
	return new Date(y, m - 1, d)
}

/**
 * Format a Date from NcDateTimePickerNative back to a YYYY-MM-DD string for
 * storage, using local date parts so the day matches what the user picked.
 *
 * @param {Date|null} date Date from the picker, or null.
 * @return {string|null} YYYY-MM-DD string, or null when empty/invalid.
 */
export function toDateInputString(date) {
	if (!(date instanceof Date) || isNaN(date.getTime())) return null
	const y = date.getFullYear()
	const m = String(date.getMonth() + 1).padStart(2, '0')
	const d = String(date.getDate()).padStart(2, '0')
	return `${y}-${m}-${d}`
}

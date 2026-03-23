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
 * @param {string} [currency='EUR'] The currency code
 * @return {string} Formatted currency string (e.g., "EUR 12.500,50" or "EUR 12,500.50")
 */
export function formatCurrency(value, currency = 'EUR') {
	if (value === null || value === undefined || value === '') return currency + ' 0'
	const num = Number(value)
	if (isNaN(num)) return currency + ' 0'

	const locale = getUserLocale()
	const formatted = num.toLocaleString(locale, { minimumFractionDigits: 0, maximumFractionDigits: 2 })
	return currency + ' ' + formatted
}

/**
 * Format a number using the user's locale (no currency prefix).
 *
 * @param {number|string} value The numeric value to format
 * @return {string} Formatted number string
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
 */
export function formatDateFull(dateStr) {
	return formatDate(dateStr, { month: 'short', day: 'numeric', year: 'numeric' })
}

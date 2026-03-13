/**
 * Shared pipeline utilities for aging, stale detection, and formatting.
 */

export function getDaysAge(item) {
	if (!item._dateModified) return 0
	return Math.floor((Date.now() - new Date(item._dateModified).getTime()) / 86400000)
}

export function isStale(item, entityType) {
	if (entityType !== 'lead') return false
	return getDaysAge(item) >= 14
}

export function getAgingClass(days) {
	if (days >= 14) return 'aging-alert'
	if (days >= 7) return 'aging-warning'
	return ''
}

export function formatAge(days) {
	if (days === 0) return 'Today'
	if (days === 1) return '1d'
	return `${days}d`
}

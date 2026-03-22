/**
 * Shared pipeline utilities for aging, stale detection, and formatting.
 */

export function getDaysAge(item) {
	if (!item._dateModified) return 0
	return Math.floor((Date.now() - new Date(item._dateModified).getTime()) / 86400000)
}

export function isStale(item, entityType, threshold = 14) {
	if (entityType !== 'lead') return false
	return getDaysAge(item) >= threshold
}

export function getAgingClass(days, threshold = 14) {
	if (days >= threshold) return 'aging-alert'
	if (days >= Math.floor(threshold / 2)) return 'aging-warning'
	return ''
}

export function formatAge(days) {
	if (days === 0) return 'Today'
	if (days === 1) return '1d'
	return `${days}d`
}

/**
 * Check if an item is overdue based on entity type and dates.
 * Closed/terminal stages are never overdue.
 * Requests: overdue if requestedAt > 30 days AND status is new/in_progress.
 * Leads: overdue if expectedCloseDate has passed.
 *
 * @param {object} item The pipeline item
 * @param {string} entityType The entity type ('lead' or 'request')
 * @param {object|null} stage The item's current pipeline stage (if known)
 * @returns {boolean} Whether the item is overdue
 */
export function isItemOverdue(item, entityType, stage = null) {
	// Closed/terminal stages are never overdue
	if (stage && stage.isClosed) return false

	if (entityType === 'request') {
		// Requests with terminal status are not overdue
		const status = item.status || ''
		if (status !== 'new' && status !== 'in_progress') return false
		const dateStr = item.requestedAt
		if (!dateStr) return false
		const daysSince = Math.floor((Date.now() - new Date(dateStr).getTime()) / 86400000)
		return daysSince > 30
	}

	// Leads: overdue if expectedCloseDate has passed
	const dateStr = item.expectedCloseDate
	if (!dateStr) return false
	return new Date(dateStr) < new Date()
}

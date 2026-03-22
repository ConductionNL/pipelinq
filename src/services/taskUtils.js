/**
 * Task utility functions for Pipelinq callback management.
 */

/**
 * Task type labels (Dutch).
 */
export const TASK_TYPE_LABELS = {
	terugbelverzoek: 'Terugbelverzoek',
	opvolgtaak: 'Opvolgtaak',
	informatievraag: 'Informatievraag',
}

/**
 * Task status labels (Dutch).
 */
export const TASK_STATUS_LABELS = {
	open: 'Open',
	in_behandeling: 'In behandeling',
	afgerond: 'Afgerond',
	verlopen: 'Verlopen',
}

/**
 * Task priority labels (Dutch).
 */
export const TASK_PRIORITY_LABELS = {
	laag: 'Laag',
	normaal: 'Normaal',
	hoog: 'Hoog',
}

/**
 * Priority sort order (lower = higher priority).
 */
export const TASK_PRIORITY_ORDER = {
	hoog: 0,
	normaal: 1,
	laag: 2,
}

/**
 * Get the display label for a task type.
 *
 * @param {string} type The task type key.
 * @return {string} The label.
 */
export function getTaskTypeLabel(type) {
	return TASK_TYPE_LABELS[type] || type || '-'
}

/**
 * Get the display label for a task status.
 *
 * @param {string} status The task status key.
 * @return {string} The label.
 */
export function getTaskStatusLabel(status) {
	return TASK_STATUS_LABELS[status] || status || '-'
}

/**
 * Get the display label for a task priority.
 *
 * @param {string} priority The task priority key.
 * @return {string} The label.
 */
export function getTaskPriorityLabel(priority) {
	return TASK_PRIORITY_LABELS[priority] || priority || '-'
}

/**
 * Get the color for a task priority badge.
 *
 * @param {string} priority The task priority key.
 * @return {string} CSS color string.
 */
export function getTaskPriorityColor(priority) {
	switch (priority) {
	case 'hoog':
		return 'var(--color-error)'
	case 'normaal':
		return 'var(--color-text-maxcontrast)'
	case 'laag':
		return 'var(--color-text-lighter)'
	default:
		return 'var(--color-text-maxcontrast)'
	}
}

/**
 * Check whether a task is overdue based on its deadline.
 *
 * @param {object} task The task object.
 * @return {boolean} True if overdue.
 */
export function isTaskOverdue(task) {
	if (!task.deadline) return false
	if (task.status === 'afgerond' || task.status === 'verlopen') return false
	return new Date(task.deadline) < new Date()
}

/**
 * Get the default deadline (next business day at 17:00).
 *
 * @return {string} ISO datetime string.
 */
export function getDefaultDeadline() {
	const now = new Date()
	const day = now.getDay()
	const daysToAdd = day === 5 ? 3 : day === 6 ? 2 : 1
	const deadline = new Date(now)
	deadline.setDate(deadline.getDate() + daysToAdd)
	deadline.setHours(17, 0, 0, 0)
	return deadline.toISOString().slice(0, 16)
}

/**
 * Fetch the current user's Nextcloud group IDs via OCS API.
 *
 * @return {Promise<string[]>} Array of group IDs.
 */
export async function fetchUserGroups() {
	try {
		const response = await fetch(
			'/ocs/v2.php/cloud/users/' + encodeURIComponent(OC.currentUser) + '/groups',
			{
				headers: {
					Accept: 'application/json',
					'OCS-APIREQUEST': 'true',
					requesttoken: OC.requestToken,
				},
			},
		)
		if (!response.ok) return []
		const data = await response.json()
		return data?.ocs?.data?.groups || []
	} catch {
		return []
	}
}

/**
 * Search Nextcloud users and groups for assignment autocomplete.
 *
 * @param {string} query The search query.
 * @return {Promise<Array>} Array of {id, label, type, icon} objects.
 */
export async function searchAssignees(query) {
	if (!query || query.length < 1) return []
	try {
		const url = '/ocs/v2.php/apps/files_sharing/api/v1/sharees'
			+ '?search=' + encodeURIComponent(query)
			+ '&itemType=file&perPage=20&format=json'
		const response = await fetch(url, {
			headers: {
				Accept: 'application/json',
				'OCS-APIREQUEST': 'true',
				requesttoken: OC.requestToken,
			},
		})
		if (!response.ok) return []
		const data = await response.json()
		const results = []

		// Users (shareType 0)
		const users = data?.ocs?.data?.exact?.users?.concat(data?.ocs?.data?.users || []) || []
		for (const u of users) {
			results.push({
				id: u.value?.shareWith || u.label,
				label: u.label || u.value?.shareWith,
				type: 'user',
			})
		}

		// Groups (shareType 1)
		const groups = data?.ocs?.data?.exact?.groups?.concat(data?.ocs?.data?.groups || []) || []
		for (const g of groups) {
			results.push({
				id: g.value?.shareWith || g.label,
				label: g.label || g.value?.shareWith,
				type: 'group',
			})
		}

		return results
	} catch {
		return []
	}
}

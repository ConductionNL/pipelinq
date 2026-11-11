/**
 * Task utility functions for Pipelinq callback management.
 */

import { translate as t } from '@nextcloud/l10n'

/**
 * Task type labels.
 *
 * @spec openspec/changes/reverse-2026-05-26-fe-services/tasks.md#task-55
 */
export function getTaskTypeLabels() {
	return {
		terugbelverzoek: t('pipelinq', 'Callback request'),
		opvolgtaak: t('pipelinq', 'Follow-up task'),
		informatievraag: t('pipelinq', 'Information request'),
	}
}

/**
 * Task status labels.
 *
 * @spec openspec/changes/reverse-2026-05-26-fe-services/tasks.md#task-53
 */
export function getTaskStatusLabels() {
	return {
		open: t('pipelinq', 'Open'),
		in_behandeling: t('pipelinq', 'In progress'),
		afgerond: t('pipelinq', 'Completed'),
		verlopen: t('pipelinq', 'Expired'),
	}
}

/**
 * Task priority labels.
 *
 * @spec openspec/changes/reverse-2026-05-26-fe-services/tasks.md#task-51
 */
export function getTaskPriorityLabels() {
	return {
		laag: t('pipelinq', 'Low'),
		normaal: t('pipelinq', 'Normal'),
		hoog: t('pipelinq', 'High'),
	}
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
 * @spec openspec/changes/reverse-2026-05-26-fe-services/tasks.md#task-54
 */
export function getTaskTypeLabel(type) {
	return getTaskTypeLabels()[type] || type || '-'
}

/**
 * Get the display label for a task status.
 *
 * @param {string} status The task status key.
 * @return {string} The label.
 * @spec openspec/changes/reverse-2026-05-26-fe-services/tasks.md#task-52
 */
export function getTaskStatusLabel(status) {
	return getTaskStatusLabels()[status] || status || '-'
}

/**
 * Get the display label for a task priority.
 *
 * @param {string} priority The task priority key.
 * @return {string} The label.
 * @spec openspec/changes/reverse-2026-05-26-fe-services/tasks.md#task-50
 */
export function getTaskPriorityLabel(priority) {
	return getTaskPriorityLabels()[priority] || priority || '-'
}

/**
 * The badge colour per task priority.
 */
const TASK_PRIORITY_COLORS = {
	high: 'var(--color-error)',
	normal: 'var(--color-text-maxcontrast)',
	low: 'var(--color-text-lighter)',
}

/**
 * Get the color for a task priority badge.
 *
 * @param {string} priority The task priority key.
 * @return {string} CSS color string.
 * @spec openspec/changes/reverse-2026-05-26-fe-services/tasks.md#task-49
 */
export function getTaskPriorityColor(priority) {
	if (typeof priority === 'string' && Object.hasOwn(TASK_PRIORITY_COLORS, priority)) {
		return TASK_PRIORITY_COLORS[priority]
	}
	return 'var(--color-text-maxcontrast)'
}

/**
 * Check whether a task is overdue based on its deadline.
 *
 * @param {object} task The task object.
 * @return {boolean} True if overdue.
 * @spec openspec/changes/reverse-2026-05-26-fe-services/tasks.md#task-56
 */
export function isTaskOverdue(task) {
	if (!task.deadline) return false
	if (task.status === 'completed' || task.status === 'expired') return false
	return new Date(task.deadline) < new Date()
}

/**
 * Get the default deadline (next business day at 17:00).
 *
 * @return {string} ISO datetime string.
 * @spec openspec/changes/reverse-2026-05-26-fe-services/tasks.md#task-48
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
 * @spec openspec/changes/reverse-2026-05-26-fe-services/tasks.md#task-47
 */
export async function fetchUserGroups() {
	try {
		const response = await fetch(
			'/ocs/v2.php/cloud/users/'
				+ encodeURIComponent(window.OC?.getCurrentUser?.()?.uid)
				+ '/groups',
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
 * @spec openspec/changes/reverse-2026-05-26-fe-services/tasks.md#task-57
 */
export async function searchAssignees(query) {
	if (!query || query.length < 1) return []
	try {
		const response = await fetch(shareeSearchUrl(query), {
			headers: {
				Accept: 'application/json',
				'OCS-APIREQUEST': 'true',
				requesttoken: OC.requestToken,
			},
		})
		if (!response.ok) return []
		const data = await response.json()
		const sharees = data?.ocs?.data
		// Users (shareType 0), then groups (shareType 1).
		return [
			...asAssignees(shareesOf(sharees, 'users'), 'user'),
			...asAssignees(shareesOf(sharees, 'groups'), 'group'),
		]
	} catch {
		return []
	}
}

/**
 * @param {string} query The search query.
 * @return {string} The sharee search URL.
 */
function shareeSearchUrl(query) {
	return '/ocs/v2.php/apps/files_sharing/api/v1/sharees'
		+ '?search='
		+ encodeURIComponent(query)
		+ '&itemType=file&perPage=20&format=json'
}

/**
 * The exact matches of a kind, then the other matches; none without exact matches.
 *
 * @param {(object|undefined)} sharees The `ocs.data` of the answer.
 * @param {string} kind `users` or `groups`.
 * @return {Array<object>} The sharees.
 */
function shareesOf(sharees, kind) {
	return sharees?.exact?.[kind]?.concat(sharees?.[kind] || []) || []
}

/**
 * @param {Array<object>} sharees The sharees.
 * @param {string} type `user` or `group`.
 * @return {Array<{id: string, label: string, type: string}>} The assignee options.
 */
function asAssignees(sharees, type) {
	return sharees.map((sharee) => ({
		id: sharee.value?.shareWith || sharee.label,
		label: sharee.label || sharee.value?.shareWith,
		type,
	}))
}

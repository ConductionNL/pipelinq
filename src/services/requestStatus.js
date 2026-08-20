/**
 * Request status lifecycle service.
 *
 * Defines allowed transitions and provides validation helpers.
 */

const STATUS_TRANSITIONS = {
	new: ['in_progress', 'rejected', 'completed'],
	in_progress: ['completed', 'rejected', 'converted'],
	completed: [],
	rejected: [],
	converted: [],
}

const STATUS_LABELS = {
	new: t('pipelinq', 'New'),
	in_progress: t('pipelinq', 'In progress'),
	completed: t('pipelinq', 'Completed'),
	rejected: t('pipelinq', 'Rejected'),
	converted: t('pipelinq', 'Converted to case'),
}

const STATUS_COLORS = {
	new: '#0082c9',
	in_progress: '#e9a400',
	completed: '#46ba61',
	rejected: '#e9322d',
	converted: '#745bca',
}

const PRIORITY_LABELS = {
	low: t('pipelinq', 'Low'),
	normal: t('pipelinq', 'Normal'),
	high: t('pipelinq', 'High'),
	urgent: t('pipelinq', 'Urgent'),
}

const PRIORITY_COLORS = {
	low: '#999',
	normal: 'var(--color-text-maxcontrast)',
	high: '#e9a400',
	urgent: '#e9322d',
}

const VALID_PRIORITIES = ['low', 'normal', 'high', 'urgent']

/**
 * Get allowed target statuses for a given current status.
 *
 * @param {string} currentStatus
 * @return {string[]}
 * @spec openspec/changes/reverse-2026-05-26-fe-services/tasks.md#task-39
 */
export function getAllowedTransitions(currentStatus) {
	return STATUS_TRANSITIONS[currentStatus] || []
}

/**
 * Check if a status transition is valid.
 *
 * @param {string} from Current status
 * @param {string} to Target status
 * @return {boolean}
 * @spec openspec/changes/reverse-2026-05-26-fe-services/tasks.md#task-46
 */
export function isValidTransition(from, to) {
	if (from === to) return true
	return getAllowedTransitions(from).includes(to)
}

/**
 * Check if a status is terminal (no further transitions allowed).
 *
 * @param {string} status
 * @return {boolean}
 * @spec openspec/changes/reverse-2026-05-26-fe-services/tasks.md#task-44
 */
export function isTerminalStatus(status) {
	return getAllowedTransitions(status).length === 0
}

/**
 * Get human-readable label for a status.
 *
 * @param {string} status
 * @return {string}
 * @spec openspec/changes/reverse-2026-05-26-fe-services/tasks.md#task-43
 */
export function getStatusLabel(status) {
	return STATUS_LABELS[status] || status
}

/**
 * Get color for a status.
 *
 * @param {string} status
 * @return {string}
 * @spec openspec/changes/reverse-2026-05-26-fe-services/tasks.md#task-42
 */
export function getStatusColor(status) {
	return STATUS_COLORS[status] || '#999'
}

/**
 * Get human-readable label for a priority.
 *
 * @param {string} priority
 * @return {string}
 * @spec openspec/changes/reverse-2026-05-26-fe-services/tasks.md#task-41
 */
export function getPriorityLabel(priority) {
	return PRIORITY_LABELS[priority] || priority
}

/**
 * Get color for a priority.
 *
 * @param {string} priority
 * @return {string}
 * @spec openspec/changes/reverse-2026-05-26-fe-services/tasks.md#task-40
 */
export function getPriorityColor(priority) {
	return PRIORITY_COLORS[priority] || 'var(--color-text-maxcontrast)'
}

/**
 * Check if a priority value is valid.
 *
 * @param {string} priority
 * @return {boolean}
 * @spec openspec/changes/reverse-2026-05-26-fe-services/tasks.md#task-45
 */
export function isValidPriority(priority) {
	return VALID_PRIORITIES.includes(priority)
}

export {
	PRIORITY_COLORS,
	PRIORITY_LABELS,
	STATUS_COLORS,
	STATUS_LABELS,
	STATUS_TRANSITIONS,
	VALID_PRIORITIES,
}

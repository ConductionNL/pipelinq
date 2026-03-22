/**
 * Complaint status lifecycle service.
 *
 * Defines allowed transitions and provides validation helpers
 * for the complaint registration workflow.
 */

const STATUS_TRANSITIONS = {
	new: ['in_progress'],
	in_progress: ['resolved', 'rejected'],
	resolved: [],
	rejected: [],
}

const STATUS_LABELS = {
	new: t('pipelinq', 'New'),
	in_progress: t('pipelinq', 'In progress'),
	resolved: t('pipelinq', 'Resolved'),
	rejected: t('pipelinq', 'Rejected'),
}

const STATUS_COLORS = {
	new: '#0082c9',
	in_progress: '#e9a400',
	resolved: '#46ba61',
	rejected: '#e9322d',
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

const CATEGORY_LABELS = {
	service: t('pipelinq', 'Service'),
	product: t('pipelinq', 'Product'),
	communication: t('pipelinq', 'Communication'),
	billing: t('pipelinq', 'Billing'),
	other: t('pipelinq', 'Other'),
}

const CHANNEL_LABELS = {
	phone: t('pipelinq', 'Phone'),
	email: t('pipelinq', 'Email'),
	web: t('pipelinq', 'Web'),
	counter: t('pipelinq', 'Counter'),
	letter: t('pipelinq', 'Letter'),
	other: t('pipelinq', 'Other'),
}

const VALID_PRIORITIES = ['low', 'normal', 'high', 'urgent']
const VALID_CATEGORIES = ['service', 'product', 'communication', 'billing', 'other']
const VALID_CHANNELS = ['phone', 'email', 'web', 'counter', 'letter', 'other']

/**
 * Get allowed target statuses for a given current status.
 *
 * @param {string} currentStatus
 * @return {string[]}
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
 */
export function isTerminalStatus(status) {
	return getAllowedTransitions(status).length === 0
}

/**
 * Check if a transition requires resolution text.
 *
 * @param {string} targetStatus
 * @return {boolean}
 */
export function requiresResolution(targetStatus) {
	return targetStatus === 'resolved' || targetStatus === 'rejected'
}

/**
 * Get human-readable label for a status.
 *
 * @param {string} status
 * @return {string}
 */
export function getStatusLabel(status) {
	return STATUS_LABELS[status] || status
}

/**
 * Get color for a status.
 *
 * @param {string} status
 * @return {string}
 */
export function getStatusColor(status) {
	return STATUS_COLORS[status] || '#999'
}

/**
 * Get human-readable label for a priority.
 *
 * @param {string} priority
 * @return {string}
 */
export function getPriorityLabel(priority) {
	return PRIORITY_LABELS[priority] || priority
}

/**
 * Get color for a priority.
 *
 * @param {string} priority
 * @return {string}
 */
export function getPriorityColor(priority) {
	return PRIORITY_COLORS[priority] || 'var(--color-text-maxcontrast)'
}

/**
 * Get human-readable label for a category.
 *
 * @param {string} category
 * @return {string}
 */
export function getCategoryLabel(category) {
	return CATEGORY_LABELS[category] || category
}

/**
 * Get human-readable label for a channel.
 *
 * @param {string} channel
 * @return {string}
 */
export function getChannelLabel(channel) {
	return CHANNEL_LABELS[channel] || channel
}

/**
 * Check if a priority value is valid.
 *
 * @param {string} priority
 * @return {boolean}
 */
export function isValidPriority(priority) {
	return VALID_PRIORITIES.includes(priority)
}

/**
 * Determine the SLA indicator status.
 *
 * @param {string|null} slaDeadline ISO 8601 deadline
 * @param {string} status Current complaint status
 * @return {'met'|'on_track'|'approaching'|'overdue'|null}
 */
export function getSlaIndicator(slaDeadline, status) {
	if (!slaDeadline) return null

	const deadline = new Date(slaDeadline)
	const now = new Date()

	// Terminal states
	if (status === 'resolved' || status === 'rejected') {
		return 'met'
	}

	if (deadline < now) {
		return 'overdue'
	}

	const hoursRemaining = (deadline - now) / (1000 * 60 * 60)
	if (hoursRemaining < 4) {
		return 'approaching'
	}

	return 'on_track'
}

/**
 * Get the CSS color for an SLA indicator status.
 *
 * @param {'met'|'on_track'|'approaching'|'overdue'|null} indicator
 * @return {string}
 */
export function getSlaColor(indicator) {
	switch (indicator) {
	case 'met':
	case 'on_track':
		return '#46ba61'
	case 'approaching':
		return '#e9a400'
	case 'overdue':
		return '#e9322d'
	default:
		return 'var(--color-text-maxcontrast)'
	}
}

export {
	STATUS_TRANSITIONS,
	STATUS_LABELS,
	STATUS_COLORS,
	PRIORITY_LABELS,
	PRIORITY_COLORS,
	CATEGORY_LABELS,
	CHANNEL_LABELS,
	VALID_PRIORITIES,
	VALID_CATEGORIES,
	VALID_CHANNELS,
}

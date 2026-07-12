/**
 * Complaint status lifecycle service.
 *
 * Defines allowed transitions and provides validation helpers
 * for the complaint registration workflow.
 *
 * Mirrors the `x-openregister-lifecycle` block declared on the `complaint`
 * schema — the Awb chapter-9 state machine. The register is the source of
 * truth; this map exists so the form can offer only reachable next states.
 */

const STATUS_TRANSITIONS = {
	new: ['acknowledged', 'in_progress', 'rejected', 'withdrawn'],
	acknowledged: ['in_progress', 'rejected', 'withdrawn'],
	in_progress: ['hearing_scheduled', 'resolved', 'rejected', 'withdrawn'],
	hearing_scheduled: ['hearing_completed', 'withdrawn'],
	hearing_completed: ['resolved', 'rejected', 'withdrawn'],
	resolved: [],
	rejected: [],
	withdrawn: [],
}

const STATUS_LABELS = {
	new: t('pipelinq', 'New'),
	acknowledged: t('pipelinq', 'Acknowledged'),
	in_progress: t('pipelinq', 'In progress'),
	hearing_scheduled: t('pipelinq', 'Hearing scheduled'),
	hearing_completed: t('pipelinq', 'Hearing completed'),
	resolved: t('pipelinq', 'Resolved'),
	rejected: t('pipelinq', 'Rejected'),
	withdrawn: t('pipelinq', 'Withdrawn'),
}

const STATUS_COLORS = {
	new: '#0082c9',
	acknowledged: '#00a2d9',
	in_progress: '#e9a400',
	hearing_scheduled: '#9b59b6',
	hearing_completed: '#7e57c2',
	resolved: '#46ba61',
	rejected: '#e9322d',
	withdrawn: '#95a5a6',
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
	social: t('pipelinq', 'Social media'),
	other: t('pipelinq', 'Other'),
}

const VALID_PRIORITIES = ['low', 'normal', 'high', 'urgent']

/**
 * The complaint categories seeded as `complaintCategory` objects. `category` is
 * now a reference to one of those objects, not an enum: these slugs are the
 * seeded objects' slugs, kept so a complaint carrying a former enum value still
 * resolves. The category picker moves to an object lookup in the chained
 * mapping-layer change.
 */
const VALID_CATEGORIES = ['service', 'product', 'communication', 'billing', 'other']
const VALID_CHANNELS = ['phone', 'email', 'web', 'counter', 'letter', 'social', 'other']

/**
 * Get allowed target statuses for a given current status.
 *
 * @param {string} currentStatus
 * @return {string[]}
 * @spec openspec/changes/reverse-2026-05-26-fe-services/tasks.md#task-1
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
 * @spec openspec/changes/reverse-2026-05-26-fe-services/tasks.md#task-12
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
 * @spec openspec/changes/reverse-2026-05-26-fe-services/tasks.md#task-10
 */
export function isTerminalStatus(status) {
	return getAllowedTransitions(status).length === 0
}

/**
 * Check if a transition requires resolution text.
 *
 * @param {string} targetStatus
 * @return {boolean}
 * @spec openspec/changes/reverse-2026-05-26-fe-services/tasks.md#task-13
 */
export function requiresResolution(targetStatus) {
	return targetStatus === 'resolved' || targetStatus === 'rejected'
}

/**
 * Get human-readable label for a status.
 *
 * @param {string} status
 * @return {string}
 * @spec openspec/changes/reverse-2026-05-26-fe-services/tasks.md#task-9
 */
export function getStatusLabel(status) {
	return STATUS_LABELS[status] || status
}

/**
 * Get color for a status.
 *
 * @param {string} status
 * @return {string}
 * @spec openspec/changes/reverse-2026-05-26-fe-services/tasks.md#task-8
 */
export function getStatusColor(status) {
	return STATUS_COLORS[status] || '#999'
}

/**
 * Get human-readable label for a priority.
 *
 * @param {string} priority
 * @return {string}
 * @spec openspec/changes/reverse-2026-05-26-fe-services/tasks.md#task-5
 */
export function getPriorityLabel(priority) {
	return PRIORITY_LABELS[priority] || priority
}

/**
 * Get color for a priority.
 *
 * @param {string} priority
 * @return {string}
 * @spec openspec/changes/reverse-2026-05-26-fe-services/tasks.md#task-4
 */
export function getPriorityColor(priority) {
	return PRIORITY_COLORS[priority] || 'var(--color-text-maxcontrast)'
}

/**
 * Get human-readable label for a category.
 *
 * @param {string} category
 * @return {string}
 * @spec openspec/changes/reverse-2026-05-26-fe-services/tasks.md#task-2
 */
export function getCategoryLabel(category) {
	return CATEGORY_LABELS[category] || category
}

/**
 * Get human-readable label for a channel.
 *
 * @param {string} channel
 * @return {string}
 * @spec openspec/changes/reverse-2026-05-26-fe-services/tasks.md#task-3
 */
export function getChannelLabel(channel) {
	return CHANNEL_LABELS[channel] || channel
}

/**
 * Check if a priority value is valid.
 *
 * @param {string} priority
 * @return {boolean}
 * @spec openspec/changes/reverse-2026-05-26-fe-services/tasks.md#task-11
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
 * @spec openspec/changes/reverse-2026-05-26-fe-services/tasks.md#task-7
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
 * @spec openspec/changes/reverse-2026-05-26-fe-services/tasks.md#task-6
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

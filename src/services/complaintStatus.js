/**
 * Complaint status lifecycle service.
 *
 * Defines allowed transitions and provides validation helpers
 * for the complaint registration workflow.
 */

import {
	PRIORITY_LABELS,
	statusLifecycle,
	VALID_PRIORITIES,
} from './statusLifecycle.js'

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

const PRIORITY_COLORS = {
	low: '#999',
	normal: 'var(--color-text-maxcontrast)',
	high: '#e9a400',
	urgent: '#e9322d',
}

/**
 * The lifecycle lookups — allowed and valid transitions, terminal statuses,
 * status and priority labels and colours — answered from the tables above.
 *
 * @spec openspec/changes/archive/2026-06-14-reverse-2026-05-26-fe-services/tasks.md#task-1
 * @spec openspec/changes/archive/2026-06-14-reverse-2026-05-26-fe-services/tasks.md#task-12
 * @spec openspec/changes/archive/2026-06-14-reverse-2026-05-26-fe-services/tasks.md#task-10
 * @spec openspec/changes/archive/2026-06-14-reverse-2026-05-26-fe-services/tasks.md#task-9
 * @spec openspec/changes/archive/2026-06-14-reverse-2026-05-26-fe-services/tasks.md#task-8
 * @spec openspec/changes/archive/2026-06-14-reverse-2026-05-26-fe-services/tasks.md#task-5
 * @spec openspec/changes/archive/2026-06-14-reverse-2026-05-26-fe-services/tasks.md#task-4
 * @spec openspec/changes/archive/2026-06-14-reverse-2026-05-26-fe-services/tasks.md#task-11
 */
export const {
	getAllowedTransitions,
	isValidTransition,
	isTerminalStatus,
	getStatusLabel,
	getStatusColor,
	getPriorityLabel,
	getPriorityColor,
	isValidPriority,
} = statusLifecycle({
	transitions: STATUS_TRANSITIONS,
	statusLabels: STATUS_LABELS,
	statusColors: STATUS_COLORS,
	priorityColors: PRIORITY_COLORS,
})

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

const VALID_CATEGORIES = ['service', 'product', 'communication', 'billing', 'other']
const VALID_CHANNELS = ['phone', 'email', 'web', 'counter', 'letter', 'other']

/**
 * Check if a transition requires resolution text.
 *
 * @param {string} targetStatus The status being transitioned to.
 * @return {boolean}
 * @spec openspec/changes/archive/2026-06-14-reverse-2026-05-26-fe-services/tasks.md#task-13
 */
export function requiresResolution(targetStatus) {
	return targetStatus === 'resolved' || targetStatus === 'rejected'
}

/**
 * Get human-readable label for a category.
 *
 * @param {string} category The stored category value.
 * @return {string}
 * @spec openspec/changes/archive/2026-06-14-reverse-2026-05-26-fe-services/tasks.md#task-2
 */
export function getCategoryLabel(category) {
	return CATEGORY_LABELS[category] || category
}

/**
 * Get human-readable label for a channel.
 *
 * @param {string} channel The stored channel value.
 * @return {string}
 * @spec openspec/changes/archive/2026-06-14-reverse-2026-05-26-fe-services/tasks.md#task-3
 */
export function getChannelLabel(channel) {
	return CHANNEL_LABELS[channel] || channel
}

/**
 * Determine the SLA indicator status.
 *
 * @param {string|null} slaDeadline ISO 8601 deadline
 * @param {string} status Current complaint status
 * @return {'met'|'on_track'|'approaching'|'overdue'|null}
 * @spec openspec/changes/archive/2026-06-14-reverse-2026-05-26-fe-services/tasks.md#task-7
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
 * @param {'met'|'on_track'|'approaching'|'overdue'|null} indicator The SLA indicator, or null when the record has no SLA.
 * @return {string}
 * @spec openspec/changes/archive/2026-06-14-reverse-2026-05-26-fe-services/tasks.md#task-6
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
	CATEGORY_LABELS,
	CHANNEL_LABELS,
	PRIORITY_COLORS,
	PRIORITY_LABELS,
	STATUS_COLORS,
	STATUS_LABELS,
	STATUS_TRANSITIONS,
	VALID_CATEGORIES,
	VALID_CHANNELS,
	VALID_PRIORITIES,
}

/**
 * Request status lifecycle service.
 *
 * Defines allowed transitions and provides validation helpers.
 */

import {
	PRIORITY_LABELS,
	statusLifecycle,
	VALID_PRIORITIES,
} from './statusLifecycle.js'

const STATUS_TRANSITIONS = {
	new: ['in_progress', 'awaiting_customer', 'rejected', 'completed'],
	in_progress: ['awaiting_customer', 'completed', 'rejected', 'converted'],
	// Waiting for the customer: the customer portal offers a reply only here,
	// and a reply moves the ticket back to in_progress (pipelinq#2038).
	awaiting_customer: ['in_progress', 'completed', 'rejected'],
	completed: [],
	rejected: [],
	converted: [],
}

const STATUS_LABELS = {
	new: t('pipelinq', 'New'),
	in_progress: t('pipelinq', 'In progress'),
	awaiting_customer: t('pipelinq', 'Waiting for customer'),
	completed: t('pipelinq', 'Completed'),
	rejected: t('pipelinq', 'Rejected'),
	converted: t('pipelinq', 'Converted to case'),
}

const STATUS_COLORS = {
	new: '#0082c9',
	in_progress: '#e9a400',
	awaiting_customer: '#a06900',
	completed: '#46ba61',
	rejected: '#e9322d',
	converted: '#745bca',
}

const PRIORITY_COLORS = {
	low: 'var(--color-text-maxcontrast)',
	normal: 'var(--color-text-maxcontrast)',
	high: 'var(--color-warning-text)',
	urgent: 'var(--color-text-error)',
}

/**
 * The lifecycle lookups — allowed and valid transitions, terminal statuses,
 * status and priority labels and colours — answered from the tables above.
 *
 * @spec openspec/changes/archive/2026-06-14-reverse-2026-05-26-fe-services/tasks.md#task-39
 * @spec openspec/changes/archive/2026-06-14-reverse-2026-05-26-fe-services/tasks.md#task-46
 * @spec openspec/changes/archive/2026-06-14-reverse-2026-05-26-fe-services/tasks.md#task-44
 * @spec openspec/changes/archive/2026-06-14-reverse-2026-05-26-fe-services/tasks.md#task-43
 * @spec openspec/changes/archive/2026-06-14-reverse-2026-05-26-fe-services/tasks.md#task-42
 * @spec openspec/changes/archive/2026-06-14-reverse-2026-05-26-fe-services/tasks.md#task-41
 * @spec openspec/changes/archive/2026-06-14-reverse-2026-05-26-fe-services/tasks.md#task-40
 * @spec openspec/changes/archive/2026-06-14-reverse-2026-05-26-fe-services/tasks.md#task-45
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

export {
	PRIORITY_COLORS,
	PRIORITY_LABELS,
	STATUS_COLORS,
	STATUS_LABELS,
	STATUS_TRANSITIONS,
	VALID_PRIORITIES,
}

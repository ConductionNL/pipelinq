// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Status-lifecycle lookups shared by the ticket-request and complaint
 * lifecycles (requestStatus.js, complaintStatus.js).
 *
 * Both lifecycles answer the same questions — which statuses follow this one,
 * is it terminal, what is its label and colour, and the same for a priority —
 * from their own tables. The priority vocabulary itself is the same for both.
 */

export const PRIORITY_LABELS = {
	low: t('pipelinq', 'Low'),
	normal: t('pipelinq', 'Normal'),
	high: t('pipelinq', 'High'),
	urgent: t('pipelinq', 'Urgent'),
}

export const VALID_PRIORITIES = ['low', 'normal', 'high', 'urgent']

/**
 * Build the lookup functions of one lifecycle from its tables.
 *
 * An unknown status has no transitions (so it is terminal), is labelled with
 * its own value and coloured `#999`; an unknown priority is labelled with its
 * own value and coloured with the muted text colour.
 *
 * @param {object} tables The lifecycle's tables.
 * @param {Object<string, string[]>} tables.transitions Status → allowed next statuses.
 * @param {Object<string, string>} tables.statusLabels Status → label.
 * @param {Object<string, string>} tables.statusColors Status → colour.
 * @param {Object<string, string>} tables.priorityColors Priority → colour.
 * @return {object} The lookup functions.
 */
export function statusLifecycle(tables) {
	const getAllowedTransitions = (status) => tables.transitions[status] || []
	return {
		getAllowedTransitions,
		isValidTransition: (from, to) =>
			from === to || getAllowedTransitions(from).includes(to),
		isTerminalStatus: (status) => getAllowedTransitions(status).length === 0,
		getStatusLabel: (status) => tables.statusLabels[status] || status,
		getStatusColor: (status) => tables.statusColors[status] || '#999',
		getPriorityLabel: (priority) => PRIORITY_LABELS[priority] || priority,
		getPriorityColor: (priority) =>
			tables.priorityColors[priority] || 'var(--color-text-maxcontrast)',
		isValidPriority: (priority) => VALID_PRIORITIES.includes(priority),
	}
}

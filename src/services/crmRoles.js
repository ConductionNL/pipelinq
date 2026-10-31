/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The roles a contact plays in a deal. A role is a relationship (category
 * "CRM Rol") from the lead to the contact; `value` is the stored type.
 *
 * @spec openspec/specs/lead-management/spec.md
 */

import { translate as t } from '@nextcloud/l10n'

export const CRM_ROLES = [
	{ value: 'beslisser', label: () => t('pipelinq', 'Decision maker'), order: 1 },
	{ value: 'kampioen', label: () => t('pipelinq', 'Champion'), order: 2 },
	{ value: 'gatekeeper', label: () => t('pipelinq', 'Gatekeeper'), order: 3 },
	{ value: 'beinvloeder', label: () => t('pipelinq', 'Influencer'), order: 4 },
	{ value: 'evaluator', label: () => t('pipelinq', 'Evaluator'), order: 5 },
	{ value: 'gebruiker', label: () => t('pipelinq', 'End user'), order: 6 },
]

/**
 * The options for a role picker, in board order.
 *
 * @return {Array<{value: string, label: string}>}
 * @spec openspec/specs/lead-management/spec.md
 */
export function crmRoleOptions() {
	return CRM_ROLES.map((r) => ({ value: r.value, label: r.label() }))
}

/**
 * The label of a stored role; an unknown value reads as itself.
 *
 * @param {string} value The stored relationship type.
 * @return {string}
 * @spec openspec/specs/lead-management/spec.md
 */
export function crmRoleLabel(value) {
	const role = CRM_ROLES.find((r) => r.value === value)
	return role ? role.label() : value
}

/**
 * The role relationships in board order, unknown types last.
 *
 * @param {Array<{type: string}>} rows The role relationships.
 * @return {Array<{type: string}>} A sorted copy.
 * @spec openspec/specs/lead-management/spec.md
 */
export function sortByCrmRole(rows) {
	const order = (type) => CRM_ROLES.find((r) => r.value === type)?.order || 99
	return [...rows].sort((a, b) => order(a.type) - order(b.type))
}

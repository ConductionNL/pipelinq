/**
 * Shared pipeline utilities for aging, stale detection, and formatting.
 */

import { translate as t } from '@nextcloud/l10n'

/**
 * @spec openspec/changes/reverse-2026-05-26-fe-services/tasks.md#task-30
 */
export function getDaysAge(item) {
	if (!item._dateModified) return 0
	return Math.floor((Date.now() - new Date(item._dateModified).getTime()) / 86400000)
}

/**
 * Whether a lead has had no activity for at least the stale threshold.
 *
 * @param {object} item The pipeline item.
 * @param {string} entityType The entity type (only `lead` ages).
 * @param {number} [threshold] Stale threshold in days (admin-configurable, defaults to 14).
 * @return {boolean} True when the lead is stale.
 *
 * @spec openspec/changes/reverse-2026-05-26-fe-services/tasks.md#task-31
 * @spec openspec/changes/lead-management/tasks.md#2.2
 */
export function isStale(item, entityType, threshold = 14) {
	if (entityType !== 'lead') return false
	const days = (Number.isFinite(threshold) && threshold > 0) ? threshold : 14
	return getDaysAge(item) >= days
}

/**
 * @spec openspec/changes/reverse-2026-05-26-fe-services/tasks.md#task-29
 */
export function getAgingClass(days) {
	if (days >= 14) return 'aging-alert'
	if (days >= 7) return 'aging-warning'
	return ''
}

/**
 * @spec openspec/changes/reverse-2026-05-26-fe-services/tasks.md#task-28
 */
export function formatAge(days) {
	if (days === 0) return t('pipelinq', 'Today')
	if (days === 1) return '1d'
	return `${days}d`
}

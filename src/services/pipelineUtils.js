/**
 * Shared pipeline utilities for aging, stale detection, and formatting.
 */

import { translate as t } from '@nextcloud/l10n'

/**
 * @param item
 * @spec openspec/changes/reverse-2026-05-26-fe-services/tasks.md#task-30
 */
export function getDaysAge(item) {
	if (!item._dateModified) return 0
	return Math.floor((Date.now() - new Date(item._dateModified).getTime()) / 86400000)
}

/**
 * @param item
 * @param entityType
 * @spec openspec/changes/reverse-2026-05-26-fe-services/tasks.md#task-31
 */
export function isStale(item, entityType) {
	if (entityType !== 'lead') return false
	return getDaysAge(item) >= 14
}

/**
 * @param days
 * @spec openspec/changes/reverse-2026-05-26-fe-services/tasks.md#task-29
 */
export function getAgingClass(days) {
	if (days >= 14) return 'aging-alert'
	if (days >= 7) return 'aging-warning'
	return ''
}

/**
 * @param days
 * @spec openspec/changes/reverse-2026-05-26-fe-services/tasks.md#task-28
 */
export function formatAge(days) {
	if (days === 0) return t('pipelinq', 'Today')
	if (days === 1) return '1d'
	return `${days}d`
}

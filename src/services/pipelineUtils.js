/**
 * Shared pipeline utilities for aging, stale detection, and formatting.
 */

import { translate as t } from '@nextcloud/l10n'

/**
 * @spec openspec/changes/reverse-2026-05-26-fe-services/tasks.md#task-30
 * @param {object} item The object with a `_dateModified` timestamp.
 * @return {number} Whole days since the object was last modified.
 */
export function getDaysAge(item) {
	if (!item._dateModified) return 0
	return Math.floor((Date.now() - new Date(item._dateModified).getTime()) / 86400000)
}

/**
 * Days the lead has spent in its current stage. Prefers the precise
 * `stageEnteredAt` field; falls back to `_dateModified` as a proxy.
 *
 * @spec openspec/changes/lead-management/tasks.md#3.1
 * @param {object} item The lead object.
 * @return {number} Whole days in the current stage.
 */
export function getDaysInStage(item) {
	const stamp = item.stageEnteredAt || item._dateModified
	if (!stamp) return 0
	const parsed = new Date(stamp).getTime()
	if (Number.isNaN(parsed)) return 0
	return Math.max(0, Math.floor((Date.now() - parsed) / 86400000))
}

/**
 * @spec openspec/changes/reverse-2026-05-26-fe-services/tasks.md#task-31
 * @param {object} item The lead object.
 * @param {string} entityType The entity type (only leads can be stale).
 * @param {number} threshold Days of inactivity before a lead is stale.
 * @return {boolean} Whether the lead is stale.
 */
export function isStale(item, entityType, threshold = 14) {
	if (entityType !== 'lead') return false
	return getDaysAge(item) >= threshold
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

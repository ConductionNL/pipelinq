/**
 * Shared pipeline utilities for aging, stale detection, and formatting.
 */

import { translate as t } from '@nextcloud/l10n'

/**
 * Logical entity types that were folded into the unified `ticket` schema by
 * unify-ticket-supertype. They survive as `ticketType` discriminator values —
 * and as `schemaSlug` values inside stored pipeline `propertyMappings` — so
 * anything that turns a logical slug into an OpenRegister object type must
 * route through resolveObjectType().
 */
const TICKET_SUBTYPES = ['request', 'complaint', 'contactmoment']

/**
 * Map a logical entity slug onto the OpenRegister object type it now lives in.
 *
 * The former `request` / `complaint` / `contactmoment` schemas are one `ticket`
 * schema discriminated by `ticketType`; every other slug maps to itself.
 *
 * @param {string} schemaSlug Logical slug ('lead', 'request', 'ticket', …).
 * @return {{objectType: string, ticketType: (string|null)}} Registered object
 *   type plus the `ticketType` filter/field to narrow it, or null when the type
 *   needs no discriminator.
 * @spec openspec/changes/unify-ticket-supertype/specs/unify-ticket-supertype/spec.md#requirement-unified-tickets-workspace
 */
export function resolveObjectType(schemaSlug) {
	if (TICKET_SUBTYPES.includes(schemaSlug)) {
		return { objectType: 'ticket', ticketType: schemaSlug }
	}
	return { objectType: schemaSlug, ticketType: null }
}

/**
 * @param item
 * @spec openspec/changes/reverse-2026-05-26-fe-services/tasks.md#task-30
 */
export function getDaysAge(item) {
	if (!item._dateModified) return 0
	return Math.floor(
		(Date.now() - new Date(item._dateModified).getTime()) / 86400000,
	)
}

/**
 * Resolve the stale-threshold (days) from the Pipelinq settings config.
 * Falls back to 14 when the value is missing or unparseable so consumers
 * never need to special-case an empty store.
 *
 * @param {object|null} config Pipelinq settings config (settingsStore.config).
 * @return {number}
 * @spec openspec/specs/lead-management/spec.md
 */
export function getStaleThreshold(config) {
	const raw = config && config.lead_stale_threshold_days
	const parsed = parseInt(raw, 10)
	if (Number.isFinite(parsed) && parsed > 0) {
		return parsed
	}
	return 14
}

/**
 * @param item
 * @param entityType
 * @param {number} [threshold] Optional explicit threshold (days); defaults to 14.
 * @spec openspec/changes/reverse-2026-05-26-fe-services/tasks.md#task-31
 */
export function isStale(item, entityType, threshold = 14) {
	if (entityType !== 'lead') return false
	return getDaysAge(item) >= threshold
}

/**
 * True when a lead has an expectedCloseDate in the past and the lead is
 * not in a closed/won/lost state. Pure function — used by LeadList row
 * highlighting and PipelineCard isOverdue logic.
 *
 * @param {object} lead The lead object.
 * @param {Array<{name:string,isClosed?:boolean}>} stages Pipeline stages.
 * @return {boolean}
 * @spec openspec/specs/lead-management/spec.md
 */
export function isLeadOverdue(lead, stages = []) {
	if (!lead || !lead.expectedCloseDate) return false
	if (lead.status === 'won' || lead.status === 'lost') return false
	const currentStage = stages.find((s) => s.name === lead.stage)
	if (currentStage && currentStage.isClosed) return false
	return new Date(lead.expectedCloseDate) < new Date()
}

/**
 * Days a lead has been overdue (always >= 0). Returns 0 when not overdue.
 *
 * @param {object} lead The lead object.
 * @param {Array<{name:string,isClosed?:boolean}>} stages Pipeline stages.
 * @return {number}
 * @spec openspec/specs/lead-management/spec.md
 */
export function getOverdueDays(lead, stages = []) {
	if (!isLeadOverdue(lead, stages)) return 0
	const due = new Date(lead.expectedCloseDate).getTime()
	const now = Date.now()
	const days = Math.floor((now - due) / 86400000)
	return days > 0 ? days : 0
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

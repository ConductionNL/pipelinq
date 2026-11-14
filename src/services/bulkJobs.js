/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A bulk change on clients or contacts runs as an OpenRegister bulk job: a
 * previewed, reversible write. This module is the only place pipelinq talks to
 * OpenRegister's `/api/bulk-jobs` routes, plus the pure helpers the dialog and
 * the Bulk changes page read a job with (ADR-022: OpenRegister owns the write
 * and the undo, pipelinq adds no controller).
 *
 * @spec openspec/changes/clients-bulk-edit-and-undo/specs/client-bulk-changes/spec.md
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** The set-properties action id (OpenRegister `SetPropertiesAction::ID`). */
export const SET_PROPERTIES_ACTION = 'openregister:set-properties'

const JOBS_BASE = '/apps/openregister/api/bulk-jobs'
const OBJECTS_BASE = '/apps/openregister/api/objects'

/**
 * The writable, user-settable properties of a schema, as the property picker
 * lists them: not computed, not read only, and never an identifier the write
 * must not touch (`id`, `uuid`, `contactsUid`). Each is `{ id, label, type }`.
 *
 * @param {object} schema The schema object, with a `properties` map.
 * @return {Array<{id: string, label: string, type: string}>} The properties, by label.
 * @spec openspec/changes/clients-bulk-edit-and-undo/specs/client-bulk-changes/spec.md#requirement-clients-and-contacts-can-be-changed-in-bulk-req-cbe-001
 */
export function writablePropertiesOf(schema) {
	const properties = (schema && typeof schema.properties === 'object' && schema.properties !== null)
		? schema.properties
		: {}
	const excluded = new Set(['id', 'uuid', 'contactsUid'])

	return Object.entries(properties)
		.filter(([name, definition]) => {
			if (excluded.has(name)) return false
			const d = definition || {}
			if (d.readOnly === true || d.readonly === true) return false
			if (d.computed === true || typeof d.calculation === 'string') return false
			if (d.writeOnly === true) return false
			return true
		})
		.map(([name, definition]) => ({
			id: name,
			label: (definition && typeof definition.title === 'string' && definition.title !== '')
				? definition.title
				: name,
			type: (definition && typeof definition.type === 'string') ? definition.type : 'string',
		}))
		.sort((a, b) => a.label.localeCompare(b.label))
}

/**
 * The counts a previewed or committed job carries: how many records the change
 * touches and how many already hold the value and are skipped.
 *
 * @param {object} job A bulk job as OpenRegister serialises it.
 * @return {{changed: number, skipped: number, refused: number, failed: number}} The counts.
 * @spec openspec/changes/clients-bulk-edit-and-undo/specs/client-bulk-changes/spec.md#requirement-clients-and-contacts-can-be-changed-in-bulk-req-cbe-001
 */
export function changeSummary(job) {
	const counts = (job && job.processed && typeof job.processed.counts === 'object' && job.processed.counts !== null)
		? job.processed.counts
		: ((job && typeof job.counts === 'object' && job.counts !== null) ? job.counts : {})
	const toInt = (value) => (Number.isFinite(Number(value)) ? Number(value) : 0)

	return {
		changed: toInt(counts.applied),
		skipped: toInt(counts.skipped),
		refused: toInt(counts.refused),
		failed: toInt(counts.failed),
	}
}

/**
 * The moment a job stops being reversible, as a Date, or null when the job
 * names none.
 *
 * @param {object} job A bulk job.
 * @return {Date|null} The reversal deadline.
 * @spec openspec/changes/clients-bulk-edit-and-undo/specs/client-bulk-changes/spec.md#requirement-a-user-can-undo-their-own-bulk-change-req-cbe-002
 */
export function reversibleUntil(job) {
	const raw = job ? job.reversibleUntil : null
	if (typeof raw !== 'string' || raw === '') return null
	const when = new Date(raw)
	return Number.isNaN(when.getTime()) ? null : when
}

/**
 * Whether a job can still be undone at `now`: it is marked reversible and its
 * window has not closed. A job with no window is treated as not reversible.
 *
 * @param {object} job A bulk job.
 * @param {Date} [now] The moment to judge against. Defaults to now.
 * @return {boolean} True while the change can be undone.
 * @spec openspec/changes/clients-bulk-edit-and-undo/specs/client-bulk-changes/spec.md#requirement-a-user-can-undo-their-own-bulk-change-req-cbe-002
 */
export function isReversible(job, now = new Date()) {
	if (!job || job.reversible === false) return false
	const until = reversibleUntil(job)
	if (until === null) return false
	return until.getTime() > now.getTime()
}

/**
 * The refusal reason OpenRegister answered, from a caught axios error, or a
 * generic fallback. A refused bulk job answers `reason`; a malformed request
 * answers `error`.
 *
 * @param {object} error A caught axios error.
 * @param {string} fallback The message to use when the response carries none.
 * @return {string} The reason to show.
 * @spec openspec/changes/clients-bulk-edit-and-undo/specs/client-bulk-changes/spec.md#requirement-clients-and-contacts-can-be-changed-in-bulk-req-cbe-001
 */
export function refusalReason(error, fallback) {
	const data = error && error.response ? error.response.data : null
	if (data && typeof data === 'object') {
		if (typeof data.reason === 'string' && data.reason !== '') return data.reason
		if (typeof data.error === 'string' && data.error !== '') return data.error
		if (typeof data.message === 'string' && data.message !== '') return data.message
	}
	return fallback
}

/**
 * Read one object to learn the numeric register and schema ids a bulk job
 * needs. OpenRegister's `@self` envelope carries both as ids.
 *
 * @param {string} register The register slug.
 * @param {string} schema The schema slug.
 * @param {string} id One object's uuid.
 * @return {Promise<{register: number, schema: number}>} The numeric ids.
 * @spec openspec/changes/clients-bulk-edit-and-undo/specs/client-bulk-changes/spec.md#requirement-clients-and-contacts-can-be-changed-in-bulk-req-cbe-001
 */
export async function resolveScope(register, schema, id) {
	const { data } = await axios.get(generateUrl(`${OBJECTS_BASE}/${register}/${schema}/${id}`))
	const self = (data && typeof data['@self'] === 'object' && data['@self'] !== null) ? data['@self'] : {}
	return {
		register: Number(self.register),
		schema: Number(self.schema),
	}
}

/**
 * Create a previewed set-properties job for the selection. OpenRegister
 * rehearses the write and commits nothing; the response carries the counts.
 *
 * @param {object} request The request.
 * @param {number} request.register The numeric register id.
 * @param {number} request.schema The numeric schema id.
 * @param {Array<string>} request.ids The selected object uuids.
 * @param {object} request.properties The property-to-value map to set.
 * @return {Promise<object>} The previewed job.
 * @spec openspec/changes/clients-bulk-edit-and-undo/specs/client-bulk-changes/spec.md#requirement-clients-and-contacts-can-be-changed-in-bulk-req-cbe-001
 */
export async function createSetPropertiesJob({ register, schema, ids, properties }) {
	const { data } = await axios.post(generateUrl(JOBS_BASE), {
		action: SET_PROPERTIES_ACTION,
		parameters: { properties },
		selection: { ids },
		register,
		schema,
	})
	return data
}

/**
 * Commit a previewed job, so OpenRegister writes it.
 *
 * @param {string|number} id The job id.
 * @return {Promise<object>} The committed job.
 * @spec openspec/changes/clients-bulk-edit-and-undo/specs/client-bulk-changes/spec.md#requirement-clients-and-contacts-can-be-changed-in-bulk-req-cbe-001
 */
export async function commitJob(id) {
	const { data } = await axios.post(generateUrl(`${JOBS_BASE}/${id}/commit`))
	return data
}

/**
 * Ask OpenRegister for the reversal of a job: a new previewed job that writes
 * the prior values back, not yet committed.
 *
 * @param {string|number} id The job id to undo.
 * @return {Promise<object>} The previewed reversal job.
 * @spec openspec/changes/clients-bulk-edit-and-undo/specs/client-bulk-changes/spec.md#requirement-a-user-can-undo-their-own-bulk-change-req-cbe-002
 */
export async function reverseJob(id) {
	const { data } = await axios.post(generateUrl(`${JOBS_BASE}/${id}/reverse`))
	return data
}

/**
 * List the caller's own bulk jobs.
 *
 * @return {Promise<Array<object>>} The jobs.
 * @spec openspec/changes/clients-bulk-edit-and-undo/specs/client-bulk-changes/spec.md#requirement-a-user-can-undo-their-own-bulk-change-req-cbe-002
 */
export async function listJobs() {
	const { data } = await axios.get(generateUrl(JOBS_BASE))
	if (Array.isArray(data)) return data
	if (data && Array.isArray(data.results)) return data.results
	if (data && Array.isArray(data.jobs)) return data.jobs
	return []
}

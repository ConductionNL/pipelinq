/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The bulk changes a person made to clients and contacts, as the Bulk changes
 * page lists them. OpenRegister owns the jobs and their reversal (design D1);
 * this reads its job JSON.
 *
 * @spec openspec/changes/clients-bulk-edit-and-undo/specs/client-bulk-changes/spec.md
 */

/**
 * The jobs on the client or contact schema of the pipelinq register, newest first.
 * A reversal job is left out: it is shown on the job it undid.
 *
 * @param {Array<object>} jobs The jobs OpenRegister returned.
 * @param {{register: (string|number), schemas: Array<(string|number)>}} scope The register id and the client and contact schema ids.
 * @return {Array<object>}
 */
export function clientJobs(jobs, scope) {
	const register = String(scope.register ?? '')
	const schemas = (scope.schemas || []).map(String).filter((id) => id !== '')
	return (Array.isArray(jobs) ? jobs : [])
		.filter((job) => job && String(job.registerId ?? '') === register && schemas.includes(String(job.schemaId ?? '')))
		.filter((job) => !job.reversesJobId)
		.sort((a, b) => String(b.created || '').localeCompare(String(a.created || '')))
}

/**
 * The properties a job writes.
 *
 * @param {object} job The job.
 * @return {Array<string>}
 */
export function changedProperties(job) {
	const properties = job?.parameters?.properties
	if (!properties || typeof properties !== 'object' || Array.isArray(properties)) {
		return []
	}
	return Object.keys(properties)
}

/**
 * How many records a job changed: what it applied once it ran, else how many it holds.
 *
 * @param {object} job The job.
 * @return {number}
 */
export function changedCount(job) {
	const applied = Number(job?.counts?.applied ?? job?.applied)
	if (job?.state === 'completed' && Number.isFinite(applied)) {
		return applied
	}
	const total = Number(job?.total)
	return Number.isFinite(total) ? total : 0
}

/**
 * Whether a job can still be undone, and until when.
 *
 * Only a completed, reversible job that nobody undid yet, whose window is still
 * open, offers Undo. Past the window the date stays visible.
 *
 * @param {object} job The job.
 * @param {Date} now The moment to compare with.
 * @return {{canUndo: boolean, until: (string|null), undone: boolean}}
 */
export function undoState(job, now) {
	const until = typeof job?.reversibleUntil === 'string' && job.reversibleUntil !== '' ? job.reversibleUntil : null
	const undone = Boolean(job?.reversedByJobId)
	const open = until !== null && new Date(until).getTime() > now.getTime()
	return {
		canUndo: job?.reversible === true && job?.state === 'completed' && !undone && open,
		until,
		undone,
	}
}

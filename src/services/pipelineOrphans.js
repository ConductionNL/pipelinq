// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Which pipeline the board opens on, and which leads no board shows.
 *
 * A lead whose pipeline was deleted, or whose stage is not a stage of its
 * pipeline, is on no column of any board, yet it counts in the open pipeline
 * and the forecast (pipelinq review F1). The board names those leads instead
 * of hiding them. And a board that opens on an empty default pipeline while
 * the leads sit on another reads as "you have no leads", so the board opens
 * where the open leads are.
 *
 * @spec openspec/changes/review-audit-fixes-b/specs/pipeline/spec.md
 */

/**
 * The stage names of a pipeline.
 *
 * @param {object} pipeline The pipeline.
 * @return {Set<string>} The names.
 */
function stageNames(pipeline) {
	return new Set(
		(pipeline?.stages || [])
			.map((s) => (s && typeof s.name === 'string' ? s.name : ''))
			.filter(Boolean),
	)
}

/**
 * Whether a lead is open (not won, lost or otherwise closed).
 *
 * @param {object} lead The lead.
 * @return {boolean}
 */
function isOpen(lead) {
	return !lead?.status || lead.status === 'open'
}

/**
 * Count the open leads per pipeline id.
 *
 * @param {Array<object>} leads The leads.
 * @return {Map<string, number>} Open leads by pipeline id.
 *
 * @spec openspec/changes/review-audit-fixes-b/specs/pipeline/spec.md#requirement-the-board-opens-where-the-open-leads-are-req-raf-021
 */
export function openLeadCounts(leads) {
	const counts = new Map()
	for (const lead of leads || []) {
		if (!isOpen(lead) || !lead.pipeline) continue
		counts.set(lead.pipeline, (counts.get(lead.pipeline) || 0) + 1)
	}
	return counts
}

/**
 * The pipeline the board opens on.
 *
 * The rule, in order:
 * 1. The default pipeline with the most open leads, when one has any.
 * 2. Otherwise the pipeline with the most open leads.
 * 3. Otherwise the first default pipeline, then the first pipeline.
 * Ties keep the order the pipelines came in.
 *
 * @param {Array<object>} pipelines The pipelines.
 * @param {Array<object>} leads The leads, of every pipeline.
 * @return {string|null} The pipeline id, or null without pipelines.
 *
 * @spec openspec/changes/review-audit-fixes-b/specs/pipeline/spec.md#requirement-the-board-opens-where-the-open-leads-are-req-raf-021
 */
export function choosePipeline(pipelines, leads) {
	const list = (pipelines || []).filter((p) => p && p.id)
	if (list.length === 0) return null

	const counts = openLeadCounts(leads)
	const busiest = (candidates) => {
		let best = null
		let bestCount = 0
		for (const p of candidates) {
			const n = counts.get(p.id) || 0
			if (n > bestCount) {
				best = p
				bestCount = n
			}
		}
		return best
	}

	const defaults = list.filter((p) => p.isDefault)
	const chosen = busiest(defaults) || busiest(list) || defaults[0] || list[0]
	return chosen.id
}

/**
 * The leads no board column shows: their pipeline does not exist, or their
 * stage is not a stage of their pipeline. A lead without a pipeline or stage
 * is not counted here; the board puts a stage-less lead in its first column.
 *
 * @param {Array<object>} leads The leads.
 * @param {Array<object>} pipelines The pipelines.
 * @return {Array<{lead: object, reason: 'pipeline'|'stage'}>} The leads and why.
 *
 * @spec openspec/changes/review-audit-fixes-b/specs/pipeline/spec.md#requirement-the-board-names-leads-that-are-on-no-board-req-raf-020
 */
export function findOrphanedLeads(leads, pipelines) {
	const byId = new Map(
		(pipelines || []).filter((p) => p && p.id).map((p) => [p.id, p]),
	)
	const out = []
	for (const lead of leads || []) {
		if (!lead || !lead.pipeline) continue
		const pipeline = byId.get(lead.pipeline)
		if (!pipeline) {
			out.push({ lead, reason: 'pipeline' })
			continue
		}
		if (lead.stage && !stageNames(pipeline).has(lead.stage)) {
			out.push({ lead, reason: 'stage' })
		}
	}
	return out
}

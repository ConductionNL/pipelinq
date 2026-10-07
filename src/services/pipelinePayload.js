// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Shape the pipeline form into what the pipeline schema accepts.
 *
 * The schema types `totalsProperty` as a string and a stage's `probability`
 * as an integer. Sending null for either made OpenRegister refuse the whole
 * pipeline ("propertyMappings.1.totalsProperty null", pipelinq review R5), so
 * an empty value is left out instead.
 *
 * @spec openspec/changes/review-finish/specs/pipeline/spec.md
 */

const isBlank = (value) => value === null || value === undefined || value === ''

/**
 * @param {Array<object>} mappings The form's property mappings.
 * @return {Array<object>} Mappings without empty optional fields.
 * @spec openspec/changes/review-finish/specs/pipeline/spec.md
 */
export function pipelineMappingsPayload(mappings) {
	return (mappings || []).map((m) => {
		const out = { schemaSlug: m.schemaSlug, columnProperty: m.columnProperty }
		if (!isBlank(m.totalsProperty)) {
			out.totalsProperty = m.totalsProperty
		}
		return out
	})
}

/**
 * @param {Array<object>} stages The form's stages.
 * @return {Array<object>} Stages without empty optional fields.
 * @spec openspec/changes/review-finish/specs/pipeline/spec.md
 */
export function pipelineStagesPayload(stages) {
	return (stages || []).map((s) => {
		const out = {
			name: s.name,
			order: s.order,
			isClosed: !!s.isClosed,
			isWon: !!s.isWon,
		}
		if (!isBlank(s.probability) && Number.isFinite(Number(s.probability))) {
			out.probability = Math.round(Number(s.probability))
		}
		if (!isBlank(s.color)) {
			out.color = s.color
		}
		return out
	})
}

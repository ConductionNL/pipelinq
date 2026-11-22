// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * The refusal of a save that moved a record into a full stage, or null.
 *
 * The server refuses a write into a stage at its limit with a sentence that
 * names the stage ("Stage Proposal is full (8 of 8)"). When the edit changed
 * the stage and the refusal names the stage it moved into, the refusal is
 * about that field, so the form shows it under the stage field. Any other
 * error stays a form-level error.
 *
 * @param {object|null} item The record as stored.
 * @param {object|null} data The values the form saved.
 * @param {unknown} error The error message the save came back with.
 * @return {string|null} The message for the stage field, or null.
 * @spec openspec/changes/pipeline-stage-limit/specs/pipeline-stage-limits/spec.md#requirement-no-write-puts-an-item-into-a-full-stage-req-psl-002
 */
export function stageRefusal(item, data, error) {
	const stage = typeof data?.stage === 'string' ? data.stage.trim() : ''
	if (stage === '' || stage === (item?.stage ?? '')) {
		return null
	}
	if (typeof error !== 'string' || error.trim() === '') {
		return null
	}
	return error.includes(stage) ? error : null
}

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The agent profile as the edit form saves it.
 *
 * @spec openspec/changes/pipeline-lead-rotation/specs/lead-rotation/spec.md
 */

/**
 * The profile to save: an empty, zero or non-numeric lead cap means no cap,
 * so the key is left out rather than stored as a value the schema refuses,
 * and a pause switch the form carries is saved as a boolean. A profile
 * without either key is saved as it is.
 *
 * @param {object} form The edit form.
 * @return {object} The profile to save.
 */
export function profileToSave(form) {
	const profile = { ...form }
	if ('leadCapPer30Days' in profile) {
		const cap = Number(profile.leadCapPer30Days)
		if (profile.leadCapPer30Days === '' || profile.leadCapPer30Days === null || !Number.isInteger(cap) || cap < 1) {
			delete profile.leadCapPer30Days
		} else {
			profile.leadCapPer30Days = cap
		}
	}

	if ('pauseLeadAssignment' in profile) {
		profile.pauseLeadAssignment = profile.pauseLeadAssignment === true
	}

	return profile
}

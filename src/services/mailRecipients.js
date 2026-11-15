// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
//
// The recipients of a mailed report as the destination stores them
// (`[{type: 'user'|'group', id}]`, validated by ExportDestinationService) and
// as the recipients picker shows them (`{id, label, type}` options, the shape
// searchAssignees() answers with).
//
// @spec openspec/changes/reports-compare-periods-and-scheduled-delivery/specs/report-periods-and-delivery/spec.md#REQ-RPD-003

const KINDS = ['user', 'group']

/**
 * The picker's key for a recipient: its kind and id, so a user and a group with the same id stay apart.
 *
 * @param {{type: string, id: string}} recipient The recipient or option.
 * @return {string} The key.
 */
export function recipientKey(recipient) {
	return `${recipient.type}:${recipient.id}`
}

/**
 * The stored recipients for the picked options: users and groups only, each once.
 *
 * @param {Array<{id: string, type: string}>|null} options The picked options.
 * @return {Array<{type: string, id: string}>} The recipients.
 */
export function toRecipients(options) {
	const seen = new Set()
	const recipients = []
	for (const option of options || []) {
		if (!option || !KINDS.includes(option.type) || !option.id) {
			continue
		}
		const recipient = { type: option.type, id: String(option.id) }
		if (seen.has(recipientKey(recipient))) {
			continue
		}
		seen.add(recipientKey(recipient))
		recipients.push(recipient)
	}
	return recipients
}

/**
 * The picker options for stored recipients, labelled from the options seen so far, else by their id.
 *
 * @param {Array<{type: string, id: string}>|null} recipients The stored recipients.
 * @param {Array<{id: string, type: string, label: string}>} known Options with display names.
 * @return {Array<{id: string, type: string, label: string, key: string}>} The options.
 */
export function toOptions(recipients, known = []) {
	const labels = new Map(known.map((option) => [recipientKey(option), option.label]))
	return toRecipients(recipients).map((recipient) => ({
		...recipient,
		key: recipientKey(recipient),
		label: labels.get(recipientKey(recipient)) || recipient.id,
	}))
}

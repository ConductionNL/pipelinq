// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * A ticket's exchange with the customer as one thread.
 *
 * A ticket keeps the two directions apart: `portalReplies` is what the
 * customer wrote on the portal, `portalAnswers` is what the employee answered.
 * Both hold `{ message, createdAt }`. This puts them in one list, oldest
 * first, in the shape the library's conversation thread draws.
 *
 * `customerMessage` is the current answer. A ticket answered before
 * `portalAnswers` existed has the message and no entry for it, so the message
 * is added as the last answer when no entry carries the same text.
 *
 * The ticket's `description` is not part of the thread. On a question from
 * the portal it is the customer's question, on a ticket an employee made it
 * is an internal note, and nothing on the ticket says which.
 *
 * @param {object|null} ticket The ticket.
 * @return {Array<{id: string, text: string, time: string, side: string}>}
 *   The messages, oldest first. `side` is `them` for the customer and `us`
 *   for the employee.
 *
 * @spec openspec/changes/simple-ticket-page/specs/request-management/spec.md#REQ-RM-203
 */
export function ticketConversation(ticket) {
	const entries = (list, side) =>
		(Array.isArray(list) ? list : [])
			.filter(
				(entry) =>
					entry
					&& typeof entry === 'object'
					&& typeof entry.message === 'string'
					&& entry.message.trim() !== '',
			)
			.map((entry, index) => ({
				id: `${side}-${index}`,
				text: entry.message,
				time: typeof entry.createdAt === 'string' ? entry.createdAt : '',
				side,
			}))

	const answers = entries(ticket?.portalAnswers, 'us')
	const current =
		typeof ticket?.customerMessage === 'string'
			? ticket.customerMessage.trim()
			: ''
	const dated = [...entries(ticket?.portalReplies, 'them'), ...answers].sort(
		(a, b) => a.time.localeCompare(b.time),
	)
	if (
		current !== ''
		&& !answers.some((answer) => answer.text.trim() === current)
	) {
		dated.push({ id: 'us-current', text: current, time: '', side: 'us' })
	}
	return dated
}

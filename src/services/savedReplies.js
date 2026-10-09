// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Saved replies (messaging-saved-replies-and-resend, D3 and D4): which
 * replies fit a channel, in which order, and how a reply's placeholders are
 * filled before the text lands in a composer. Pure functions, so the picker
 * and its tests share one implementation.
 */

/** The placeholders a saved reply may use. */
export const PLACEHOLDERS = [
	'client.name',
	'contact.name',
	'ticket.title',
	'agent.name',
]

/**
 * The active replies for one channel, the party's language first, then by title.
 *
 * @param {Array<object>} replies The savedReply records.
 * @param {string} channel `sms`, `whatsapp`, `email` or `portal`.
 * @param {string} [language] The party's correspondence language.
 * @return {Array<object>} The replies that fit, sorted.
 * @spec openspec/changes/messaging-saved-replies-and-resend/specs/messaging-saved-replies/spec.md#requirement-an-agent-inserts-a-saved-reply-where-they-answer-req-msr-003
 */
export function repliesForChannel(replies, channel, language = '') {
	const wanted = String(language || '').toLowerCase()
	return (Array.isArray(replies) ? replies : [])
		.filter((reply) => reply && typeof reply === 'object')
		.filter((reply) => reply.active !== false)
		.filter(
			(reply) =>
				Array.isArray(reply.channels) && reply.channels.includes(channel),
		)
		.slice()
		.sort((a, b) => {
			const aFirst =
				wanted !== '' && String(a.language || '').toLowerCase() === wanted
			const bFirst =
				wanted !== '' && String(b.language || '').toLowerCase() === wanted
			if (aFirst !== bFirst) {
				return aFirst ? -1 : 1
			}
			return String(a.title || '').localeCompare(String(b.title || ''))
		})
}

/**
 * Fill a reply's placeholders. A placeholder without a value stays as written,
 * so the agent sees it and fills it in by hand.
 *
 * @param {string} body The reply text.
 * @param {object} values Values keyed by placeholder, e.g. `{'contact.name': 'Jan'}`.
 * @return {string} The text with every known placeholder filled.
 * @spec openspec/changes/messaging-saved-replies-and-resend/specs/messaging-saved-replies/spec.md#requirement-an-agent-inserts-a-saved-reply-where-they-answer-req-msr-003
 */
export function fillPlaceholders(body, values = {}) {
	return String(body || '').replace(
		/\{\{\s*([a-z]+\.[a-z]+)\s*\}\}/g,
		(match, key) => {
			const value = values && values[key]
			return typeof value === 'string' && value.trim() !== ''
				? value
				: match
		},
	)
}

/**
 * The Mail compose URL, or a plain `mailto:` link when Mail is not enabled.
 *
 * @param {string} address The recipient.
 * @param {string} subject The subject.
 * @param {string} body The text.
 * @param {boolean} mailEnabled Whether the Nextcloud Mail app is enabled.
 * @param {(path: string) => string} generateUrl `@nextcloud/router`'s generateUrl.
 * @return {string} The URL to open.
 * @spec openspec/changes/messaging-saved-replies-and-resend/specs/messaging-saved-replies/spec.md#requirement-an-agent-starts-an-email-from-a-saved-reply-req-msr-005
 */
export function composeUrl(address, subject, body, mailEnabled, generateUrl) {
	const query = []
	if (subject) {
		query.push('subject=' + encodeURIComponent(subject))
	}
	if (body) {
		query.push('body=' + encodeURIComponent(body))
	}
	const mailto =
		'mailto:'
		+ encodeURIComponent(address || '').replace(/%40/g, '@')
		+ (query.length ? '?' + query.join('&') : '')
	if (!mailEnabled) {
		return mailto
	}
	return generateUrl('/apps/mail/compose') + '?uri=' + encodeURIComponent(mailto)
}

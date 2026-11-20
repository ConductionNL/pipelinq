/**
 * What the pane reads from the mail open in Outlook (contact-moments-mail-add-in).
 *
 * Only the sender, the subject, the date and the opening of the text: the
 * full body and the attachments never leave Outlook.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/contact-moments-mail-add-in/specs/mail-add-in/spec.md#requirement-a-mail-can-be-logged-as-a-contact-moment-req-mai-003
 */

/** The most characters of the mail's text a contact moment keeps. */
export const PREVIEW_MAX = 1000

/**
 * The sender of a mail item.
 *
 * @param {object} item Office.js `mailbox.item`.
 * @return {{name: string, email: string}} The display name and the lower-cased address.
 */
export function senderOf(item) {
	const from = item?.from ?? item?.sender ?? {}
	return {
		name: String(from.displayName ?? '').trim(),
		email: String(from.emailAddress ?? '').trim().toLowerCase(),
	}
}

/**
 * When the mail was sent, as an ISO 8601 instant, or '' when Outlook gives none.
 *
 * @param {object} item Office.js `mailbox.item`.
 * @return {string}
 */
export function sentAtOf(item) {
	const date = item?.dateTimeCreated
	if (!(date instanceof Date) || Number.isNaN(date.getTime())) {
		return ''
	}
	return date.toISOString()
}

/**
 * The opening of a text, whitespace collapsed, at most PREVIEW_MAX characters.
 *
 * @param {string} text The mail's text.
 * @return {string}
 */
export function clipPreview(text) {
	return String(text ?? '').replace(/\s+/g, ' ').trim().slice(0, PREVIEW_MAX)
}

/**
 * The opening of the mail's text, '' when Outlook cannot give it.
 *
 * @param {object} item Office.js `mailbox.item`.
 * @return {Promise<string>}
 */
export function previewOf(item) {
	return new Promise((resolve) => {
		if (typeof item?.body?.getAsync !== 'function') {
			resolve('')
			return
		}
		item.body.getAsync('text', (result) => {
			resolve(result?.status === 'succeeded' ? clipPreview(result.value) : '')
		})
	})
}

/**
 * Everything the pane needs of the open mail.
 *
 * @param {object} item Office.js `mailbox.item`.
 * @return {Promise<{name: string, email: string, subject: string, sentAt: string, preview: string}>}
 */
export async function readOpenMail(item) {
	return {
		...senderOf(item),
		subject: String(item?.subject ?? '').trim(),
		sentAt: sentAtOf(item),
		preview: await previewOf(item),
	}
}

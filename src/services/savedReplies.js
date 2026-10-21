// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Opening a written email (messaging-saved-replies-and-resend, D7). The saved
 * replies themselves are listed and filled by GET /api/saved-replies.
 */

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

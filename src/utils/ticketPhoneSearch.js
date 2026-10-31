// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

/**
 * Whether a search reads as a phone number: only digits, spaces, `+`, `-`,
 * dots and brackets, with at least eight digits.
 *
 * @param {string} term The search as typed.
 * @return {boolean} True for a phone number.
 * @spec openspec/changes/contact-moments-phone-lookup/specs/contact-history-by-phone/spec.md#requirement-a-ticket-is-found-by-phone-number-however-it-was-typed-req-chp-002
 */
export function looksLikePhoneNumber(term) {
	const text = String(term ?? '').trim()
	return /^[0-9+\-.() ]+$/.test(text) && text.replace(/[^0-9]/g, '').length >= 8
}

/**
 * The Tickets list's `searchHandler`: a phone number is normalised by the
 * server, as the screen pop does, and searched as a `phoneE164` filter with
 * the line "Searching by phone number". Other text is searched as typed.
 *
 * @param {string} term The search as typed.
 * @return {Promise<{filters: {phoneE164: string}, note: string}|null>} The reading, or null.
 * @spec openspec/changes/contact-moments-phone-lookup/specs/contact-history-by-phone/spec.md#requirement-a-ticket-is-found-by-phone-number-however-it-was-typed-req-chp-002
 */
export async function ticketPhoneSearch(term) {
	if (!looksLikePhoneNumber(term)) {
		return null
	}
	const { data } = await axios.get(generateUrl('/apps/pipelinq/api/tickets/by-phone'), { params: { number: String(term).trim() } })
	if (!data?.number) {
		return null
	}
	return {
		filters: { phoneE164: data.number },
		note: t('pipelinq', 'Searching by phone number'),
	}
}

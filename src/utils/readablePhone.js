// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * A Dutch phone number as people read it: "0641228790" or "+31641228790"
 * becomes "06 41 22 87 90", a landline "0201234567" becomes "020 123 4567".
 * Anything else is returned as given, trimmed.
 *
 * @param {string} number The number as stored or dialled.
 * @return {string} The number to show.
 * @spec openspec/changes/screen-pop-incoming-call/specs/cti-screenpop-adapter/spec.md#requirement-the-incoming-call-card-opens-the-screen-pop-req-cti-012
 */
export function readablePhone(number) {
	const raw = String(number || '').trim()
	const digits = raw.replace(/[\s.-]/g, '')
	const national = digits.startsWith('+31') ? '0' + digits.slice(3) : digits
	if (!/^0\d{9}$/.test(national)) {
		return raw
	}
	if (national.startsWith('06')) {
		return [national.slice(0, 2), national.slice(2, 4), national.slice(4, 6), national.slice(6, 8), national.slice(8)].join(' ')
	}
	return [national.slice(0, 3), national.slice(3, 6), national.slice(6)].join(' ')
}

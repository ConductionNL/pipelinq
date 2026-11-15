// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Links that hand a phone number, a mail address or a postal address to the
 * phone's own dialler, mail app or map app. The visible text stays as the
 * user typed it; only the link target is normalised.
 *
 * Imports nothing, so it runs in the node test environment.
 *
 * @spec openspec/specs/mobile-experience/spec.md#requirement-numbers-addresses-and-mail-are-links-req-mob-001
 */

/**
 * A `tel:` link for a number as typed: spaces, dashes, dots, slashes and
 * brackets dropped, a leading plus kept, "00" read as "+". Null when the
 * value holds no digits.
 *
 * @param {string|null|undefined} value The number as typed.
 * @return {string|null}
 * @spec openspec/specs/mobile-experience/spec.md#requirement-numbers-addresses-and-mail-are-links-req-mob-001
 */
export function telHref(value) {
	const raw = String(value ?? '').trim()
	if (!/\d/.test(raw)) {
		return null
	}
	let digits = raw.replace(/[^\d+]/g, '')
	const plus = digits.startsWith('+')
	digits = digits.replace(/\+/g, '')
	if (!plus && digits.startsWith('00')) {
		return 'tel:+' + digits.slice(2)
	}
	return 'tel:' + (plus ? '+' : '') + digits
}

/**
 * A `mailto:` link, or null when the value is not an address.
 *
 * @param {string|null|undefined} value The address as typed.
 * @return {string|null}
 * @spec openspec/specs/mobile-experience/spec.md#requirement-numbers-addresses-and-mail-are-links-req-mob-001
 */
export function mailtoHref(value) {
	const raw = String(value ?? '').trim()
	return /^[^\s@]+@[^\s@]+$/.test(raw) ? 'mailto:' + raw : null
}

/**
 * A `geo:` link (RFC 5870) with the address as its query, which a phone
 * hands to its map app. Line breaks become commas. Null when empty.
 *
 * @param {string|null|undefined} value The postal address as typed.
 * @return {string|null}
 * @spec openspec/specs/mobile-experience/spec.md#requirement-numbers-addresses-and-mail-are-links-req-mob-001
 */
export function mapHref(value) {
	const raw = String(value ?? '')
		.split(/\r?\n/)
		.map((line) => line.trim())
		.filter(Boolean)
		.join(', ')
	return raw ? 'geo:0,0?q=' + encodeURIComponent(raw) : null
}

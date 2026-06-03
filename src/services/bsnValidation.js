/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * Client-side BSN 11-proef validation (REQ-BSN-001).
 *
 * Mirrors the authoritative server-side `BsnValidationService` so the
 * "Ophalen uit BRP" button can be enabled/disabled instantly without a round
 * trip. The server re-validates on every lookup (defence in depth); this is a
 * UX affordance only. The raw BSN never leaves the input field for validation —
 * the check is purely numeric and local.
 */

export const BSN_ERROR_LENGTH = 'length'
export const BSN_ERROR_ELFPROEF = 'elfproef'

/**
 * Validate a BSN against the RvIG 11-proef.
 *
 * @param {string} input The raw BSN input (exactly 9 digits expected).
 * @return {{ valid: boolean, errorCode: (string|null) }} The validation result.
 */
export function validateBsn(input) {
	const bsn = String(input ?? '').trim()

	if (bsn.length !== 9 || !/^[0-9]{9}$/.test(bsn)) {
		return { valid: false, errorCode: BSN_ERROR_LENGTH }
	}

	// The all-zero BSN passes the checksum arithmetically but is never valid.
	if (bsn === '000000000') {
		return { valid: false, errorCode: BSN_ERROR_ELFPROEF }
	}

	let sum = 0
	for (let i = 0; i < 9; i++) {
		const digit = Number(bsn[i])
		const weight = i === 8 ? -1 : 9 - i
		sum += digit * weight
	}

	if (sum % 11 !== 0) {
		return { valid: false, errorCode: BSN_ERROR_ELFPROEF }
	}

	return { valid: true, errorCode: null }
}

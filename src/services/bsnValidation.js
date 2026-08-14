/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * Client-side BSN 11-proef validator. Mirrors lib/Service/BsnValidationService.php
 * so the UI can disable / enable the "Ophalen uit BRP" button without an HTTP roundtrip.
 *
 * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#5.1
 * @spec openspec/changes/bsn-validatie-en-brp-lookup/specs.md#REQ-BSN-001
 */

/**
 * Validate a BSN against the 11-proef.
 *
 * @param {string} bsnInput Raw 9-digit BSN.
 * @return {{isFormalValid: boolean, errorCode: ?string, errorMessage: ?string, maskedBsn: string}}
 */
export function validateBsn(bsnInput) {
	const input = String(bsnInput || '')
	if (input.length !== 9 || !/^\d{9}$/.test(input)) {
		return {
			isFormalValid: false,
			errorCode: 'length',
			errorMessage: 'Een BSN bestaat uit exact 9 cijfers',
			maskedBsn: maskBsn(input),
		}
	}
	let sum = 0
	for (let i = 0; i < 8; i++) {
		sum += parseInt(input.charAt(i), 10) * (9 - i)
	}
	sum -= parseInt(input.charAt(8), 10)
	const modulo = sum % 11
	if (modulo !== 0) {
		return {
			isFormalValid: false,
			errorCode: 'checksum',
			errorMessage: 'Dit BSN voldoet niet aan de 11-proef',
			maskedBsn: maskBsn(input),
		}
	}
	return {
		isFormalValid: true,
		errorCode: null,
		errorMessage: null,
		maskedBsn: maskBsn(input),
	}
}

/**
 * Mask a BSN as `***XXXX*` — reveals chars at index 3..6.
 *
 * @param {string} bsnInput
 * @return {string}
 */
export function maskBsn(bsnInput) {
	const input = String(bsnInput || '')
	if (input.length === 0) return ''
	if (input.length < 5) return '*'.repeat(input.length)
	return '***' + input.substring(3, 7) + '*'
}
